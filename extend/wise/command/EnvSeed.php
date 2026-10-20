<?php
declare(strict_types=1);

namespace wise\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\support\env\EnvSeedService;

/**
 * env:seed —— 按各包声明的契约幂等播种 .env
 *
 * 消费 {@see \wise\support\contracts\EnvSeedProvider}：扫描 vendor/composer/installed.json
 * 找出所有实现该契约的 ServiceProvider，收集其 envSeeds() 声明后合并进 .env。
 *
 * 为什么需要本命令（而非只在安装期播种）：
 *   安装流程（app/install 的 CLI 与 Web 两个入口）只在「安装时」跑一次播种。之后新增的
 *   包、后续引入的密钥契约，以及从旧版本升级上来的存量项目，都拿不到自动补种 —— 过去只能
 *   由人工往 .env 里补，容易漏、格式也不统一。本命令把播种能力开放为可随时重复执行的 CLI。
 *
 * 三个核心约束（与安装期播种完全一致，复用 EnvSeedService）：
 *   - 幂等：键已存在绝不覆盖 —— 重跑、重装都不会洗掉线上已生效的密钥；
 *   - 不造假：type=external 的外部凭证只写「空占位 + 注释」提示填写，绝不生成假值；
 *   - 不执行不可信逻辑：secret 只能引用 EnvValueGenerator 的封闭策略集。
 *
 * 归属说明：本命令属「框架级命令」，放在 extend/wise/command，由
 * extend/wise/extend.json 的 commands 声明、ExtendLoaderService 统一注册。
 *
 * 用法：
 *   php think env:seed                      # 幂等补种（已存在的键不动）
 *   php think env:seed --dry-run            # 仅预览将新增 / 跳过的键，不写文件
 *   php think env:seed --env=/path/.env     # 指定 .env 路径
 *   php think env:seed --vendor=/path/vendor # 指定 vendor 目录
 *
 * 退出码：
 *   0  正常（含「全部已存在、无需写入」）
 *   1  环境不满足（.env 缺失）或存在告警（密钥生成失败 / .env 写入失败 / 清单缺失）
 */
class EnvSeed extends Command
{
    protected function configure(): void
    {
        $this->setName('env:seed')
            ->setDescription('按各包声明的契约幂等播种 .env（secret 自动生成 / external 空占位 / static 默认值）')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅预览将新增与跳过的键，不修改 .env')
            ->addOption('env', null, Option::VALUE_REQUIRED, '自定义 .env 路径（默认项目根 .env）')
            ->addOption('vendor', null, Option::VALUE_REQUIRED, '自定义 vendor 目录（默认项目根 vendor）')
            ->setHelp(<<<EOT
扫描已安装包声明的 .env 契约并幂等合并进 .env（复用 wise-support 的 EnvSeedService）。

<info>php think env:seed</info>
<info>php think env:seed --dry-run</info>
<info>php think env:seed --env=/path/to/.env</info>
<info>php think env:seed --vendor=/path/to/vendor</info>

EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $rootPath  = $this->app->getRootPath();
        $envPath   = (string) ($input->getOption('env') ?: $rootPath . '.env');
        $vendorDir = (string) ($input->getOption('vendor') ?: $rootPath . 'vendor');
        $dryRun    = (bool) $input->getOption('dry-run');

        if (!is_file($envPath)) {
            $output->writeln('<error>[env:seed] .env 不存在：' . $envPath . '</error>');
            return 1;
        }

        $service = new EnvSeedService();

        return $dryRun
            ? $this->preview($output, $service, $envPath, $vendorDir)
            : $this->seed($output, $service, $envPath, $vendorDir);
    }

    /**
     * 实际播种：直接委托 EnvSeedService 完成「发现 → 收集 → 幂等合并」。
     */
    protected function seed(Output $output, EnvSeedService $service, string $envPath, string $vendorDir): int
    {
        try {
            $report = $service->seed($envPath, $vendorDir);
        } catch (\Throwable $e) {
            $output->writeln('<error>[env:seed] 播种失败：' . $e->getMessage() . '</error>');
            return 1;
        }

        $written  = $report['written'] ?? [];
        $skipped  = $report['skipped'] ?? [];
        $warnings = $report['warnings'] ?? [];

        $output->writeln('<info>[env:seed]</info> ' . $envPath);

        if (!empty($written)) {
            $output->writeln(sprintf('  <info>新增 %d 项：</info>', count($written)));
            foreach ($written as $key) {
                $output->writeln('    + ' . $key);
            }
        }

        $output->writeln(sprintf(
            '  <comment>已存在跳过 %d 项</comment>（幂等：绝不覆盖既有值）',
            count($skipped)
        ));

        if ($skipped !== []) {
            $output->writeln('    ' . implode(', ', $skipped));
        }

        foreach ($warnings as $warning) {
            $output->writeln('  <error>[告警] ' . $warning . '</error>');
        }

        if (empty($written) && empty($warnings)) {
            $output->writeln('<comment>  所有声明的键均已存在，无需变更。</comment>');
        }

        // 告警意味着部分密钥未能生成/落盘，用非 0 退出码让调用方（CI / 部署脚本）能感知
        return $warnings === [] ? 0 : 1;
    }

    /**
     * 预览：复用 EnvSeedService 的发现与收集能力，但只比对 .env 既有键、不生成也不落盘。
     *
     * 之所以在命令层实现而非给 EnvSeedService 加参数：避免改动被安装流程共用的共享包，
     * 保持其行为零回归。预览对 secret 只展示策略名，不展示（也不消耗）随机值。
     */
    protected function preview(Output $output, EnvSeedService $service, string $envPath, string $vendorDir): int
    {
        $warnings  = [];
        $providers = $service->discoverProviderClasses($vendorDir, $warnings);
        $specs     = $service->collectSpecs($providers);
        $existing  = $this->readEnvKeys($envPath);

        $output->writeln('<info>[env:seed]</info> ' . $envPath . ' <comment>(dry-run)</comment>');
        $output->writeln(sprintf(
            '  发现 %d 个 ServiceProvider，汇总 %d 条 env 契约。',
            count($providers),
            count($specs)
        ));

        $pending = [];
        $skipped = [];

        foreach ($specs as $logicalKey => $spec) {
            // secret + keys 映射（如 rsa_2048_pem）时，逻辑键本身不写入，写的是映射后的真实键
            $realKeys = $this->resolveRealKeys((string) $logicalKey, $spec);

            foreach ($realKeys as $realKey) {
                if (isset($existing[$realKey])) {
                    $skipped[] = $realKey;
                    continue;
                }
                $pending[] = [$realKey, $this->describeSpec($spec)];
            }
        }

        if ($pending === []) {
            $output->writeln('<comment>  无待写入项：所有声明的键均已存在。</comment>');
        } else {
            $output->writeln(sprintf('  <info>将新增 %d 项：</info>', count($pending)));
            $width = max(array_map(static fn(array $row): int => strlen($row[0]), $pending));
            foreach ($pending as [$key, $desc]) {
                $output->writeln(sprintf('    + %-' . $width . 's  %s', $key, $desc));
            }
        }

        $output->writeln(sprintf('  <comment>将跳过 %d 项（已存在）</comment>', count($skipped)));

        foreach ($warnings as $warning) {
            $output->writeln('  <error>[告警] ' . $warning . '</error>');
        }

        $output->writeln('<comment>  dry-run 模式：未对 .env 做任何写入。</comment>');

        return $warnings === [] ? 0 : 1;
    }

    /**
     * 解析一条契约最终会写入的真实 env 键名。
     *
     * - 常规项：逻辑键即真实键；
     * - secret + keys（如 rsa_2048_pem 的 ['private'=>..,'public'=>..]）：真实键为映射值。
     *
     * @param array<string,mixed> $spec
     * @return string[]
     */
    protected function resolveRealKeys(string $logicalKey, array $spec): array
    {
        $keys = $spec['keys'] ?? null;

        if (is_array($keys) && $keys !== []) {
            $real = [];
            foreach ($keys as $mapped) {
                if (is_string($mapped) && $mapped !== '') {
                    $real[] = $mapped;
                }
            }
            if ($real !== []) {
                return $real;
            }
        }

        return [$logicalKey];
    }

    /**
     * 生成契约的人类可读描述（用于 dry-run 输出）。
     *
     * @param array<string,mixed> $spec
     */
    protected function describeSpec(array $spec): string
    {
        $type    = (string) ($spec['type'] ?? 'static');
        $comment = (string) ($spec['comment'] ?? '');

        $detail = match ($type) {
            'secret'   => '[secret:' . (string) ($spec['generator'] ?? '?') . '] 将生成随机值',
            'external' => '[external] 留空占位，待手工填写',
            default    => '[static] 默认值 ' . var_export($spec['default'] ?? '', true),
        };

        return $comment !== '' ? $comment . ' ' . $detail : $detail;
    }

    /**
     * 读取 .env 中已存在的键名（与 EnvSeedService::extractKeys 规则保持一致）。
     *
     * 兼容 KEY=value / KEY = value / 引号包裹 / export 前缀，并排除 # 与 ; 注释行。
     *
     * @return array<string,bool>
     */
    protected function readEnvKeys(string $envPath): array
    {
        $content = (string) file_get_contents($envPath);
        $keys    = [];

        foreach (explode("\n", $content) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $trimmed, $m)) {
                $keys[$m[1]] = true;
            }
        }

        return $keys;
    }
}

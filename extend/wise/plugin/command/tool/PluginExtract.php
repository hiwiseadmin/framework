<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\command\concern\HasJsonOutput;
use wise\plugin\extract\ExtractPlanner;
use wise\plugin\extract\ExtractWriter;
use wise\plugin\extract\NamespaceRewriter;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;

/**
 * 模块 → 插件抽取（`plugin:extract`）。
 *
 * 对应方桼�交付商项目里已经写好的通用模块，**多留一份**变成插件。
 * 本命令只做「搬运 + 命名空间改写 + 落位」，不做「凭表名生成代码」——
 * 后者已经由 `wise:crud --plugin=` 覆盖；两者组合的推荐姿势是：
 *
 * ```bash
 * php think plugin:create warehouse --minimal
 * php think plugin:extract -p warehouse \
 *     --from=app/admin/controller/Warehouse.php,app/common/model/Warehouse.php,app/admin/view/warehouse
 * ```
 *
 * ## 为什么 v1 不引入 php-parser
 *
 * 见 {@see NamespaceRewriter} 类注释：只需要改 `namespace` 与顶部 `use` 的前缀，
 * token 级改写足够且零依赖；给全体站点加 composer 依赖只为少数搬运场景不划算。
 * 函数体内的完全限定引用一律**只在输出里提示**，不做猜测性替换。
 *
 * ## 三条硬规则
 *
 * 1. **不猜边界**：必须显式 `--from`；无法推断目标目录就报错（不塞 misc/）；
 * 2. **规划失败不写盘**：有任何 error（源不存在 / 目标冲突 / 无法映射）就整体不执行，
 *    避免"搬了一半"的插件目录 —— 那种状态比不做更糟；
 * 3. **`--dry-run` 走同一条写盘代码路径**（只是不落盘），预览与真实结果不会漂移。
 *
 * 用法：
 * ```bash
 * php think plugin:extract -p warehouse --from=app/admin/controller/Warehouse.php
 * php think plugin:extract -p warehouse --from=app/admin/view/warehouse --strip=admin
 * php think plugin:extract -p warehouse --from=app/common/model/Warehouse.php --to=model/Warehouse.php
 * php think plugin:extract -p warehouse --from=... --map-namespace="app\\common\\repo=plugin\\warehouse\\repo"
 * php think plugin:extract -p warehouse --from=... --dry-run --json
 * ```
 *
 * 退出码：`0` 成功（或预览无问题）；`1` 规划失败（未写盘）；`2` 参数 / 写盘失败。
 */
class PluginExtract extends Command
{
    use HasJsonOutput;

    protected const EXIT_OK = 0;

    protected const EXIT_PLAN_FAILED = 1;

    protected const EXIT_ERROR = 2;

    protected function configure(): void
    {
        $this->setName('plugin:extract')
            ->setDescription('把已有模块（控制器/模型/视图/资源/迁移）抽进插件骨架，并改写命名空间')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '目标插件标识（须先用 plugin:create 建好骨架）')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, '源文件或目录（逗号分隔，相对应用根或绝对路径）')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, '单文件抽取时的目标路径（相对插件根，如 model/Order.php）')
            ->addOption('strip', null, InputOption::VALUE_REQUIRED, '目标路径需剥离的前导段（逗号分隔，如 statics/admin/js → statics/js）')
            ->addOption('map-namespace', null, InputOption::VALUE_REQUIRED, '额外命名空间映射 old=new（逗号分隔，覆盖默认映射）')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '覆盖插件内已存在的同名文件')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只预览（含命名空间替换结果统计），不写盘')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, '输出格式：text / json（与 --json 等价）', 'text')
            ->addJsonOption();
    }

    protected function execute(Input $input, Output $output): int
    {
        $format = strtolower(trim((string) $input->getOption('format')));

        if (!in_array($format, ['text', 'json'], true)) {
            $output->error('未知输出格式：' . $format . '（可选 text / json）');

            return self::EXIT_ERROR;
        }

        $json = $this->isJsonOutput($input);

        $ident = trim((string) $input->getOption('plugin'));

        if ($ident === '' || preg_match('/^[a-z][a-z0-9_]*$/', $ident) !== 1) {
            return $this->fail($output, $json, '请用 --plugin=<标识> 指定目标插件（小写字母开头，仅字母/数字/下划线）');
        }

        $pluginDir = rtrim(str_replace('\\', '/', plugin_path()), '/') . '/' . $ident;

        if (!is_dir($pluginDir) || !is_file($pluginDir . '/manifest.json')) {
            return $this->fail($output, $json, sprintf(
                '插件目录不存在或缺少 manifest.json：plugins/%s —— 先执行 `php think plugin:create %s --minimal`',
                $ident,
                $ident
            ));
        }

        $sources = $this->splitList((string) $input->getOption('from'));

        if ($sources === []) {
            return $this->fail($output, $json, '请用 --from=源文件或目录 指定要抽取的内容（逗号分隔）');
        }

        $appRoot = rtrim(str_replace('\\', '/', $this->app->getRootPath()), '/');
        $to      = trim((string) $input->getOption('to'));
        $to      = $to === '' ? null : $to;

        if ($to !== null && (count($sources) !== 1 || !is_file($this->absolutize($sources[0], $appRoot)))) {
            return $this->fail($output, $json, '--to 只能与「单个 --from 文件」同用（目录请按 controller/model/view/... 放置）');
        }

        $planner = new ExtractPlanner();
        $plan    = $planner->plan(
            $pluginDir,
            $appRoot,
            $sources,
            $to,
            $this->splitList((string) $input->getOption('strip')),
            (bool) $input->getOption('force')
        );

        if ($plan['errors'] !== []) {
            return $this->reportPlanFailure($output, $json, $ident, $plan);
        }

        if ($plan['files'] === []) {
            return $this->reportPlanFailure($output, $json, $ident, [
                'files'    => [],
                'errors'   => ['没有可抽取的受支持文件（检查扩展名白名单，或确认目录里确实有文件）'],
                'warnings' => $plan['warnings'],
            ]);
        }

        $map     = $this->buildNamespaceMap($ident, (string) $input->getOption('map-namespace'));
        $dryRun  = (bool) $input->getOption('dry-run');
        $writer  = new ExtractWriter(new NamespaceRewriter($map));
        $write   = $writer->write($pluginDir, $plan['files'], $dryRun);

        $warnings = array_values(array_unique(array_merge($plan['warnings'], $write['warnings'])));

        if (!$write['ok']) {
            if ($json) {
                $this->writeJson($output, [
                    'ok'      => false,
                    'dry_run' => $dryRun,
                    'plugin'  => $ident,
                    'error'   => 'write_failed',
                    'files'   => $plan['files'],
                    'written' => $write['written'],
                    'stats'   => $write['stats'],
                    'errors'  => $write['errors'],
                    'warnings' => $warnings,
                ]);
            } else {
                $output->error('写盘失败（可能有文件已写入，请按下方清单核对）：');

                foreach ($write['errors'] as $error) {
                    $output->writeln('  <error>-</error> ' . $this->escape($error));
                }
            }

            return self::EXIT_ERROR;
        }

        if (!$dryRun) {
            try {
                $manager = $this->app->get(PluginContainerKeys::MANAGER);
                $manager->refreshScannerCache();
            } catch (\Throwable $e) {
            }
        }

        if ($json) {
            $this->writeJson($output, [
                'ok'            => true,
                'dry_run'       => $dryRun,
                'plugin'        => $ident,
                'plugin_dir'    => $pluginDir,
                'namespace_map' => (object) $map,
                'files'         => $plan['files'],
                'written'       => $write['written'],
                'stats'         => $write['stats'],
                'errors'        => [],
                'warnings'      => $warnings,
            ]);

            return self::EXIT_OK;
        }

        $this->renderResult($output, $ident, $plan['files'], $write, $warnings, $dryRun);

        return self::EXIT_OK;
    }


    /**
     * 默认映射 + `--map-namespace` 覆盖。
     *
     * 只映射「宿主 app\ 下的模块目录 → 插件同名目录」，其余（控制器基类、公共库）
     * 保持原样并进入 unmapped 警告 —— 那些引用往往确实应该继续指向宿主。
     *
     * @return array<string, string>
     */
    protected function buildNamespaceMap(string $ident, string $extra): array
    {
        $target = 'plugin\\' . $ident . '\\';

        $map = [
            'app\\admin\\controller'   => $target . 'controller',
            'app\\api\\controller'     => $target . 'controller',
            'app\\common\\controller'  => $target . 'controller',
            'app\\common\\model'       => $target . 'model',
            'app\\common\\validate'    => $target . 'validate',
            'app\\common\\service'     => $target . 'service',
            'app\\common\\logic'       => $target . 'logic',
            'app\\common\\middleware'  => $target . 'middleware',
            'app\\common\\event'       => $target . 'event',
            'app\\common\\job'         => $target . 'job',
            'app\\common\\command'     => $target . 'command',
        ];

        foreach ($this->splitList($extra) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$from, $to] = explode('=', $pair, 2);

            $from = trim(str_replace('/', '\\', $from), '\\');
            $to   = trim(str_replace('/', '\\', $to), '\\');

            if ($from !== '' && $to !== '') {
                $map[$from] = $to;
            }
        }

        return $map;
    }


    /**
     * @param array{files: array<int, array{source:string, target:string, kind:string, overwrite:bool}>, errors: array<int, string>, warnings: array<int, string>} $plan
     */
    protected function reportPlanFailure(Output $output, bool $json, string $ident, array $plan): int
    {
        if ($json) {
            $this->writeJson($output, [
                'ok'     => false,
                'plugin' => $ident,
                'error'  => 'plan_failed',
                'errors' => $plan['errors'],
                'warnings' => $plan['warnings'],
                'files'  => $plan['files'],
            ]);

            return self::EXIT_PLAN_FAILED;
        }

        $output->writeln('<error>抽取计划未通过（未写盘）</error>');

        foreach ($plan['errors'] as $error) {
            $output->writeln('  <error>-</error> ' . $this->escape($error));
        }

        if ($plan['files'] !== []) {
            $output->writeln('');
            $output->writeln('<comment>可用的映射如下（修完上面的问题后重试）：</comment>');

            foreach ($plan['files'] as $file) {
                $output->writeln(sprintf('  %-46s → %s', $this->escape($this->relative($file['source'])), $this->escape($file['target'])));
            }
        }

        return self::EXIT_PLAN_FAILED;
    }

    /**
     * @param array<int, array{source:string, target:string, kind:string, overwrite:bool}> $files
     * @param array<string, mixed>                                                        $write
     * @param array<int, string>                                                          $warnings
     */
    protected function renderResult(
        Output $output,
        string $ident,
        array $files,
        array $write,
        array $warnings,
        bool $dryRun
    ): void {
        $output->writeln(sprintf(
            '<info>plugin:extract</info>  %s%s',
            $this->escape($ident),
            $dryRun ? '  <comment>（--dry-run：未写盘）</comment>' : ''
        ));
        $output->writeln('');

        foreach ($files as $file) {
            $output->writeln(sprintf(
                '  %s %-46s → %s',
                $file['overwrite'] ? '<comment>~</comment>' : '<info>+</info>',
                $this->escape($this->relative($file['source'])),
                $this->escape($file['target'])
            ));
        }

        $stats = $write['stats'];

        $output->writeln('');
        $output->writeln(sprintf(
            '共 %d 个文件（PHP %d / 资源 %d），命名空间替换 <info>%d</info> 处',
            count($files),
            (int) $stats['php'],
            (int) $stats['asset'],
            (int) $stats['replaced']
        ));

        if ($warnings !== []) {
            $output->writeln('');
            $output->writeln('<comment>警告（需人工确认，未做自动修改）：</comment>');

            foreach ($warnings as $warning) {
                $output->writeln('  <comment>!</comment> ' . $this->escape($warning));
            }
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('预览完成：确认无误后去掉 --dry-run 再执行。');

            return;
        }

        $output->writeln('');
        $output->writeln('下一步:');
        $output->writeln('  1) 核对 manifest.json 的 menus / permissions（可用 <comment>php think plugin:menu-derive -p ' . $ident . ' --print</comment>）');
        $output->writeln('  2) <comment>php think plugin:dev -p ' . $ident . ' --refresh</comment>            同步菜单 / 权限到库');
        $output->writeln('  3) <comment>php think plugin:test -p ' . $ident . '</comment>                    契约检查');
        $output->writeln('  4) <comment>php think plugin:audit -p ' . $ident . '</comment>                   上架前预检');
    }

    protected function fail(Output $output, bool $json, string $message): int
    {
        if ($json) {
            $this->writeJson($output, ['ok' => false, 'error' => 'bad_argument', 'message' => $message]);
        } else {
            $output->error($message);
        }

        return self::EXIT_ERROR;
    }


    /**
     * @return array<int, string>
     */
    protected function splitList(string $raw): array
    {
        $out = [];

        foreach (explode(',', $raw) as $item) {
            $item = trim($item);

            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    protected function absolutize(string $path, string $appRoot): string
    {
        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return rtrim($appRoot, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * 展示用相对路径（去掉应用根前缀）。
     */
    protected function relative(string $path): string
    {
        $appRoot = rtrim(str_replace('\\', '/', $this->app->getRootPath()), '/');
        $path    = str_replace('\\', '/', $path);

        return str_starts_with($path, $appRoot . '/') ? substr($path, strlen($appRoot) + 1) : $path;
    }

    /**
     * 转义 Symfony 输出格式标签，避免路径里的 `<` 被当作样式标签。
     */
    protected function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}

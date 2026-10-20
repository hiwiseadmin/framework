<?php
declare(strict_types=1);

namespace wise\command;

use app\common\service\PackageUninstaller;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:package:cleanup — 库包卸载清理
 *
 * 把「某个 composer 库包」在框架内留下的痕迹清掉：菜单 + 菜单派生权限、
 * 已发布静态资源、配置行、能力市场启停记录、数据表 + 迁移记录，并输出
 * 代码级硬引用扫描报告（只报告，不改代码）。
 *
 * 默认 **dry-run（只预览）**，加 `--apply` 才真正清理 —— 自动场景
 * （composer 的 `post-package-uninstall` 事件、`post-autoload-dump` 对账）
 * 由钩子显式传 `--apply`。
 *
 * 用法：
 *   php think wise:package:cleanup wiseadmin/wise-member            # 预览
 *   php think wise:package:cleanup wiseadmin/wise-member --apply    # 执行
 *   php think wise:package:cleanup --reconcile --apply              # 对账：清理已不在安装集合里的包
 *   php think wise:package:cleanup wiseadmin/wise-member --keep-tables   # 保留数据表
 *
 * @see PackageUninstaller
 * @see \wise\composer\PackageHook composer 事件回调（自动触发本命令）
 */
class PackageCleanup extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:package:cleanup')
            ->setDescription('清理已卸载库包在框架内的痕迹（菜单/权限/静态资源/配置/数据表）')
            ->addArgument('package', Argument::OPTIONAL, 'composer 包名，如 wiseadmin/wise-member（对账模式可省略）')
            ->addOption('apply', null, Option::VALUE_NONE, '真正执行清理（默认只预览）')
            ->addOption('reconcile', null, Option::VALUE_NONE, '对账模式：清理「登记过但已不在安装集合」的包')
            ->addOption('keep-menus', null, Option::VALUE_NONE, '保留菜单与菜单派生权限')
            ->addOption('keep-permissions', null, Option::VALUE_NONE, '保留菜单派生权限与角色绑定')
            ->addOption('keep-assets', null, Option::VALUE_NONE, '保留已发布静态资源')
            ->addOption('keep-config', null, Option::VALUE_NONE, '保留 wise-config 配置行')
            ->addOption('keep-capability', null, Option::VALUE_NONE, '保留能力市场启停记录')
            ->addOption('keep-tables', null, Option::VALUE_NONE, '保留数据表与迁移记录')
            ->addOption('keep-plugins', null, Option::VALUE_NONE, '不处理声明依赖该库包的插件')
            ->addOption('disable-dependent-plugins', null, Option::VALUE_NONE, '一并停用声明依赖该库包的插件（默认只报告）')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出结果（供脚本消费）')
            ->addOption('silent', null, Option::VALUE_NONE, '静默：无输出、异常也不返回失败（供 composer 钩子调用）')
            ->setHelp(<<<EOT
清理已卸载库包在框架内的痕迹。

<info>php think wise:package:cleanup wiseadmin/wise-member</info>            预览将清理什么
<info>php think wise:package:cleanup wiseadmin/wise-member --apply</info>    真正执行
<info>php think wise:package:cleanup --reconcile --apply</info>               对账清理（手删 vendor 的兜底）

清理依据来自 <comment>.wise/package_registry.json</comment>（发布期快照）：
包被删之后 installed.json / composer.lock / 包目录都不再可靠，只有快照能反查
「它的静态资源发布到哪、建了哪些表、菜单与配置用什么标识」。

清理范围：菜单 + 菜单派生权限 + 静态资源 + 配置行 + 能力市场启停记录 + 数据表/迁移记录。
代码级硬引用（如 app/member/middleware.php 里写死的 wise\member\...）<comment>只报告，不自动修改</comment>。
EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $silent    = (bool) $input->getOption('silent');
        $json      = (bool) $input->getOption('json');
        $apply     = (bool) $input->getOption('apply');
        $reconcile = (bool) $input->getOption('reconcile');
        $package   = (string) ($input->getArgument('package') ?? '');

        $options = [
            'apply'           => $apply,
            'keepMenus'       => (bool) $input->getOption('keep-menus'),
            'keepPermissions' => (bool) $input->getOption('keep-permissions'),
            'keepAssets'      => (bool) $input->getOption('keep-assets'),
            'keepConfig'      => (bool) $input->getOption('keep-config'),
            'keepCapability'  => (bool) $input->getOption('keep-capability'),
            'keepTables'      => (bool) $input->getOption('keep-tables'),
            'keepPlugins'     => (bool) $input->getOption('keep-plugins'),
            'disableDependentPlugins' => (bool) $input->getOption('disable-dependent-plugins'),
        ];

        try {
            $installer = $this->app->make(PackageUninstaller::class);
        } catch (\Throwable $e) {
            $this->log('FAIL', '引导 PackageUninstaller 失败：' . $e->getMessage());

            return $silent ? 0 : 1;
        }

        if ($reconcile) {
            try {
                $result = $installer->reconcile($options);
            } catch (\Throwable $e) {
                $this->log('FAIL', '对账失败：' . $e->getMessage());

                if ($silent) {
                    return 0;
                }

                $output->writeln('<error>对账失败：' . $e->getMessage() . '</error>');

                return 1;
            }

            $this->log('RECONCILE', sprintf(
                '%s：缺失 %d 个%s',
                $apply ? 'apply' : 'dry-run',
                count($result['missing']),
                $result['missing'] === [] ? '' : '（' . implode('、', $result['missing']) . '）'
            ));

            if ($silent) {
                return 0;
            }

            if ($json) {
                $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            return $this->renderReconcile($output, $result);
        }

        if ($package === '') {
            if ($silent) {
                return 0;
            }

            $output->writeln('<error>请指定包名（如 wiseadmin/wise-member），或使用 --reconcile。</error>');

            return 1;
        }

        try {
            $report = $installer->uninstall($package, $options);
        } catch (\Throwable $e) {
            $this->log('FAIL', $package . '：' . $e->getMessage());

            if ($silent) {
                return 0;
            }

            $output->writeln('<error>清理失败：' . $e->getMessage() . '</error>');

            return 1;
        }

        $this->log($apply ? 'APPLY' : 'DRY-RUN', $this->logLine($report));

        if ($silent) {
            return 0;
        }

        if ($json) {
            $output->writeln((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->renderReport($output, $report);

        return 0;
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function renderReport(Output $output, array $report): void
    {
        $apply = (bool) $report['apply'];

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>wise:package:cleanup</info> %s <comment>(%s)</comment>',
            $report['package'],
            $apply ? 'APPLY — 已实际清理' : 'DRY-RUN — 仅预览，加 --apply 执行'
        ));
        $output->writeln(sprintf('  标识 ident  ：%s', $report['ident']));
        $output->writeln(sprintf('  清理依据    ：%s', $this->sourceText((string) $report['source'])));
        $output->writeln('');

        foreach ($report['steps'] as $key => $step) {
            $mode = (string) ($step['mode'] ?? '');
            $tag  = match ($mode) {
                'skipped' => '<comment>skip </comment>',
                'report'  => '<comment>report</comment>',
                'summary' => '<comment>none </comment>',
                default   => $apply ? '<info>done </info>' : '<comment>plan </comment>',
            };

            $output->writeln(sprintf(
                '  %s %-14s %s',
                $tag,
                (string) ($step['label'] ?? $key),
                (string) ($step['note'] ?? '')
            ));

            if ($key === 'assets') {
                foreach ((array) ($step['items'] ?? []) as $item) {
                    $output->writeln(sprintf(
                        '        - %s（%s，%d 文件）',
                        (string) ($item['target'] ?? ''),
                        (string) ($item['status'] ?? ''),
                        (int) ($item['files'] ?? 0)
                    ));
                }
            }

            if ($key === 'tables') {
                foreach ((array) ($step['dropped'] ?? []) as $table) {
                    $output->writeln('        - DROP TABLE `' . $table . '`');
                }
                foreach ((array) ($step['absent'] ?? []) as $table) {
                    $output->writeln('        - 不存在，跳过：' . $table);
                }
            }

            if ($key === 'references' && (int) ($step['count'] ?? 0) > 0) {
                $output->writeln('        <comment>以下代码级引用需人工改造（本命令不自动改代码）：</comment>');

                $hits = (array) ($step['hits'] ?? []);
                foreach (array_slice($hits, 0, 30) as $hit) {
                    $output->writeln('        · ' . $hit);
                }

                if (count($hits) > 30 || !empty($step['truncated'])) {
                    $output->writeln(sprintf(
                        '        <comment>（共 %d 条，此处仅列前 30 条；完整清单用 --json）</comment>',
                        count($hits)
                    ));
                }
            }

            if ($key === 'plugins') {
                foreach ((array) ($step['items'] ?? []) as $ident => $item) {
                    $tags = [];

                    if (in_array((string) $ident, (array) ($step['disabled'] ?? []), true)) {
                        $tags[] = '已停用';
                    }

                    if (!empty($step['failed'][$ident])) {
                        $tags[] = '停用失败：' . $step['failed'][$ident];
                    }

                    $output->writeln(sprintf(
                        '        - %-13s %s%s',
                        (string) $ident,
                        !empty($item['required']) ? '<error>硬依赖</error>' : '<comment>软依赖</comment>',
                        $tags === [] ? '' : '（' . implode('，', $tags) . '）'
                    ));
                }
            }

            if (in_array($key, ['permissions', 'config', 'capability'], true)) {
                foreach ((array) ($step['keys'] ?? []) as $k) {
                    $output->writeln('        - ' . $k);
                }
            }
        }

        $registry = $report['steps']['registry'] ?? null;
        if (is_array($registry)) {
            $output->writeln('  <info>done </info> ' . ($registry['label'] ?? '') . ' ' . ($registry['note'] ?? ''));
        }

        $output->writeln('');

        if (!$apply) {
            $output->writeln('<comment>以上为预览。确认后执行：</comment>');
            $output->writeln(sprintf(
                '<comment>  php think wise:package:cleanup %s --apply</comment>',
                $report['package']
            ));
        } else {
            $output->writeln('<info>清理完成。</info>建议随后执行 php think clear（清运行期缓存）。');
        }

        $output->writeln('');
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function renderReconcile(Output $output, array $result): int
    {
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>wise:package:cleanup --reconcile</info> <comment>(%s)</comment>',
            !empty($result['apply']) ? 'APPLY — 已实际清理' : 'DRY-RUN — 仅预览'
        ));
        $output->writeln(sprintf(
            '  登记 %d 个包 / 新登记 %d，仍在安装 %d 个，缺失 %d 个',
            count($this->registryPackages()),
            (int) ($result['registered']['registered'] ?? 0),
            (int) ($result['installed'] ?? 0),
            count((array) ($result['missing'] ?? []))
        ));
        $output->writeln('');

        if (($result['missing'] ?? []) === []) {
            $output->writeln('  <info>done </info> 无缺失包，无需清理。');
        }

        foreach ((array) ($result['results'] ?? []) as $package => $report) {
            $output->writeln('');
            $this->renderReport($output, $report);
        }

        foreach ((array) ($result['unclaimed_assets'] ?? []) as $dir) {
            $output->writeln(sprintf('  <comment>report</comment> 无归属的插件静态目录（需人工确认）：%s', $dir));
        }

        $output->writeln('');

        return 0;
    }

    /**
     * @return string[]
     */
    protected function registryPackages(): array
    {
        try {
            return $this->app->make(\app\common\service\PackageRegistry::class)->packages();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function logLine(array $report): string
    {
        $parts = [sprintf('%s（ident=%s，依据=%s）', $report['package'], $report['ident'], $report['source'])];

        foreach ($report['steps'] as $key => $step) {
            $parts[] = sprintf('%s=%s', $key, (string) ($step['count'] ?? 0));
        }

        return implode(' ', $parts);
    }

    protected function sourceText(string $source): string
    {
        return match ($source) {
            'registry' => '.wise/package_registry.json 快照',
            'live'     => '现场扫描包目录（快照缺失，包目录仍在）',
            default    => '仅包名兜底（无快照、无包目录 → 静态资源按 public/statics/plugins/{ident} 约定，表名无法获知）',
        };
    }

    /**
     * 追加审计日志（runtime/package_cleanup.log，最多保留最近 500 行）
     */
    protected function log(string $action, string $message): void
    {
        try {
            $file = rtrim($this->app->getRuntimePath(), '/\\') . DIRECTORY_SEPARATOR . 'package_cleanup.log';

            $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
            $lines[] = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $action, $message);

            if (count($lines) > 500) {
                $lines = array_slice($lines, -500);
            }

            file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }
}

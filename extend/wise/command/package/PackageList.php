<?php
declare(strict_types=1);

namespace wise\command\package;

use app\common\service\PackageInspector;
use app\common\service\PackageLifecycle;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:package:list — 列出「接入型」Composer 包（只读）
 *
 * 解决「装了但没人知道」的可观测性缺口：后台插件列表只扫 `plugins/`，
 * 而标准 Composer 包在 `vendor/` 里，装没装、接没接入、迁移有没有跑，都无从查看。
 *
 * ## 列含义
 *
 * | 列 | 含义 |
 * |---|---|
 * | PACKAGE   | composer 包名 |
 * | IDENT     | 标识（`extra.think.ident` → 包名 basename），即 `menus.plugin` / 配置命名空间 |
 * | DECLARED  | 接入声明（services / config / publish / menus / migrations / seeds） |
 * | MIG       | 包内迁移文件数 |
 * | SEED      | 包内 Seeder 数 |
 * | PENDING   | 未执行的迁移版本数（`-` = 无迁移，`?` = 数据库不可读，fail-open） |
 * | REG       | 是否已登记卸载快照（`.wise/package_registry.json`，由 `wise:vendor:publish` 自动补齐） |
 *
 * ## 退出码
 *
 * 恒为 0（只读命令，不因「有待执行迁移」而失败，方便在脚本里无条件调用）。
 *
 * 用法：
 *   php think wise:package:list
 *   php think wise:package:list --json
 *   php think wise:package:list wise-member      # 按包名 / ident 过滤
 */
class PackageList extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:package:list')
            ->setDescription('列出接入框架的标准 Composer 包（声明 / 迁移 / 待执行状态）')
            ->addArgument('filter', Argument::OPTIONAL, '按包名或 ident 过滤（子串匹配）', '')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出')
            ->addOption('no-pending', null, Option::VALUE_NONE, '跳过「待执行迁移」查询（完全离线）')
            ->setHelp(<<<EOT
列出所有接入了 WiseAdmin 的标准 Composer 包。

<info>php think wise:package:list</info>            表格输出
<info>php think wise:package:list --json</info>     JSON 输出（供脚本消费）
<info>php think wise:package:list wise</info>       只看 wise* 相关包

判据：包声明了 extra.think.{services,config,publish,menus} 或包内有 database/migrations。
安装入口：<info>php think wise:package:install &lt;pkg&gt;</info>
EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $filter     = trim((string) ($input->getArgument('filter') ?? ''));
        $json       = (bool) $input->getOption('json');
        $noPending  = (bool) $input->getOption('no-pending');

        $inspector = $this->app->make(PackageInspector::class);

        $packages = $inspector->candidates();
        if ($packages === []) {
            if ($json) {
                $output->writeln('[]');

                return 0;
            }

            $output->writeln('<comment>没有找到「接入型」Composer 包（未声明 extra.think 且无 database/migrations）。</comment>');

            return 0;
        }

        $rows        = [];
        $pendingSum  = 0;
        $pendingKnown = false;

        foreach ($packages as $name => $meta) {
            if ($filter !== ''
                && stripos($name, $filter) === false
                && stripos((string) $meta['ident'], $filter) === false
            ) {
                continue;
            }

            $pending = $noPending ? null : $inspector->pendingVersions($meta);
            if (is_array($pending)) {
                $pendingKnown = true;
                $pendingSum  += count($pending);
            }

            $rows[] = [
                'package'   => $name,
                'ident'     => (string) $meta['ident'],
                'version'   => (string) ($meta['version'] ?: '-'),
                'declared'  => $inspector->declarationSummary($meta),
                'migrations' => count((array) $meta['migrations']),
                'seeds'     => count((array) $meta['seeds']),
                'pending'   => $noPending || $meta['migrations'] === []
                    ? '-'
                    : ($pending === null ? '?' : (string) count($pending)),
                'registered' => $inspector->isRegistered($name) ? 'Y' : 'N',
                'pending_versions' => $pending ?? [],
                'menu_containers'  => (int) ($meta['menu_containers'] ?? 0),
                'menu_items'       => (int) ($meta['menu_items'] ?? 0),
                'menu_error'       => (string) ($meta['menu_error'] ?? ''),
                'lifecycle'        => (new PackageLifecycle())->detect($meta) !== [] ? 'Y' : '-',
            ];
        }

        if ($json) {
            $output->writeln((string) json_encode([
                'total'   => count($rows),
                'pending' => $pendingKnown ? $pendingSum : null,
                'packages' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $headers = [
            'PACKAGE'  => 38,
            'IDENT'    => 22,
            'VERSION'  => 12,
            'DECLARED' => 26,
            'MIG'      => 4,
            'SEED'     => 5,
            'PENDING'  => 8,
            'REG'      => 4,
            'LC'       => 3,
        ];

        $line = '';
        foreach ($headers as $title => $width) {
            $line .= str_pad($title, $width);
        }
        $line = rtrim($line);
        $output->writeln('<comment>' . $line . '</comment>');
        $output->writeln('<comment>' . str_repeat('-', strlen($line)) . '</comment>');

        foreach ($rows as $row) {
            $cells = [
                'PACKAGE'  => $row['package'],
                'IDENT'    => $row['ident'],
                'VERSION'  => $row['version'],
                'DECLARED' => $row['declared'],
                'MIG'      => (string) $row['migrations'],
                'SEED'     => (string) $row['seeds'],
                'PENDING'  => $row['pending'],
                'REG'      => $row['registered'],
                'LC'       => $row['lifecycle'],
            ];

            $line = '';
            foreach ($headers as $title => $width) {
                $value = $cells[$title];
                if (mb_strlen($value) > $width - 1) {
                    $value = mb_substr($value, 0, $width - 2) . '…';
                }
                $line .= str_pad($value, $width);
            }

            $output->writeln(rtrim($line));
        }

        foreach ($rows as $row) {
            if ($row['menu_error'] !== '') {
                $output->writeln(sprintf('<error>%s</error>：菜单声明无效：%s', $row['package'], $row['menu_error']));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>共 %d 个接入型包</info>%s',
            count($rows),
            $pendingKnown ? sprintf('，待执行迁移 %d 个版本', $pendingSum) : '（未查询待执行迁移）'
        ));

        if ($pendingKnown && $pendingSum > 0) {
            $output->writeln('<comment>执行：php think wise:package:install --all</comment>');
        }

        return 0;
    }
}

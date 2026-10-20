<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 评论「站点隔离」存量升级（P1-26）
 *
 * 背景：`wisecms_comment` 原无 `site_id` —— 评论归属只能经 `content_id` 关联内容推断，
 * 于是后台评论列表无法按站点过滤（多站点下跨站显示），审核/删除也只看 id 是否存在
 * （站点 A 的编辑者可操作站点 B 的评论 id）。
 *
 * 新结构已在**建表迁移**里直接带上 `site_id` 与 `(site_id, status)` 索引
 * （插件约定：建表迁移即唯一事实源、不保留历史兼容，见 `tests/run.php` 的建表约定），
 * 但**存量环境的建表迁移不会重跑**，而代码已开始 `where('site_id', ...)` ——
 * 缺列会直接抛 SQL 错（后台评论页 500）。故本命令负责一次性、幂等地补齐：
 *
 *   ① 结构：补 `site_id` 列 + `(site_id, status)` 索引；
 *   ② 数据：按「评论所属内容的站点」回填 `site_id`；
 *      所属内容已不存在（内容表查不到，含被物理删除的历史数据）的评论归 `--default-site`（默认 1）。
 *
 * 用法：
 *   php think wisecms:comment:site --dry-run        # 只报告将要做的事
 *   php think wisecms:comment:site                  # 执行
 *   php think wisecms:comment:site --default-site=2 # 指定「孤儿评论」归属站点
 *
 * 幂等：重复执行不会重复加列/加索引；回填只更新 `site_id` 与目标不一致的行。
 * 注意：② 在重复执行时会重新断言「孤儿评论归 default-site」，覆盖此前的手工调整。
 *
 * 与 P2-4 的 `wisecms:tag:site` 同属一类：**建表迁移管新装、本命令管存量**。
 */
class CommentSiteCommand extends Command
{
    /** 逻辑表名 */
    protected const TABLE = 'wisecms_comment';

    /** 内容表逻辑名（站点来源） */
    protected const CONTENT_TABLE = 'wisecms_content';

    /** 与建表迁移保持一致的索引名 */
    protected const INDEX_NAME = 'idx_site_status';

    protected function configure()
    {
        $this->setName('wisecms:comment:site')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅预览，不写库')
            ->addOption('default-site', null, Option::VALUE_REQUIRED, '所属内容已不存在的评论归属站点', '1')
            ->setDescription('评论站点隔离升级：补 site_id 列与索引 / 按所属内容站点回填');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun      = (bool) $input->getOption('dry-run');
        $defaultSite = max(1, (int) $input->getOption('default-site'));

        $output->writeln($dryRun
            ? '<comment>DRY RUN：只统计不写入</comment>'
            : '<info>开始评论站点隔离升级</info>');

        $this->ensureSchema($output, $dryRun);
        $this->backfill($output, $dryRun, $defaultSite);

        if ($dryRun) {
            $output->writeln('<comment>--dry-run：未写库。</comment>');

            return 0;
        }

        $output->writeln('<info>完成。后台评论列表此后按「当前站点」过滤。</info>');

        return 0;
    }

    // ══════════════════════ ① 结构（幂等） ══════════════════════

    protected function ensureSchema(Output $output, bool $dryRun): void
    {
        $table = $this->tableName(self::TABLE);

        if (!$this->hasColumn($table, 'site_id')) {
            $output->writeln('  · ' . self::TABLE . ' 缺 site_id 列 → 追加');
            if (!$dryRun) {
                Db::execute(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `site_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '站点ID' AFTER `content_id`",
                    $table
                ));
            }
        }

        if (!$this->hasIndex($table, ['site_id', 'status'])) {
            $output->writeln('  · 新增索引 ' . self::INDEX_NAME . '(site_id,status)');
            if (!$dryRun) {
                Db::execute(sprintf(
                    'ALTER TABLE `%s` ADD INDEX `%s` (`site_id`, `status`)',
                    $table,
                    self::INDEX_NAME
                ));
            }
        }
    }

    /**
     * 表是否已有某列
     */
    protected function hasColumn(string $realTable, string $column): bool
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $realTable . "` LIKE '" . addslashes($column) . "'");
        } catch (\Throwable $e) {
            return false;
        }

        return $rows !== [];
    }

    /**
     * 是否已存在「列集合正好等于 $columns」的索引（按 SHOW INDEX 归并判断，不依赖索引名）
     *
     * @param string[] $columns
     */
    protected function hasIndex(string $realTable, array $columns): bool
    {
        try {
            $indexes = [];
            foreach (Db::query('SHOW INDEX FROM `' . $realTable . '`') as $row) {
                $key = (string) ($row['Key_name'] ?? '');
                if ($key === '' || $key === 'PRIMARY') {
                    continue;
                }
                $seq = max(0, (int) ($row['Seq_in_index'] ?? 1) - 1);
                $indexes[$key][$seq] = (string) ($row['Column_name'] ?? '');
            }
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($indexes as $indexColumns) {
            ksort($indexColumns);
            if (array_values($indexColumns) === $columns) {
                return true;
            }
        }

        return false;
    }

    // ══════════════════════ ② 数据回填 ══════════════════════

    /**
     * 按所属内容的站点回填 `site_id`；孤儿评论归 `$defaultSite`
     */
    protected function backfill(Output $output, bool $dryRun, int $defaultSite): void
    {
        $comment = $this->tableName(self::TABLE);
        $content = $this->tableName(self::CONTENT_TABLE);

        // ① 能关联到内容的：以内容的站点为准
        //    用 INNER JOIN + 「值不同」条件，重复执行时不会再匹配到行（幂等）
        $matchedSql = sprintf(
            'UPDATE `%s` cm INNER JOIN `%s` ct ON ct.id = cm.content_id'
            . ' SET cm.site_id = ct.site_id WHERE cm.site_id <> ct.site_id',
            $comment,
            $content
        );

        // ② 关联不到内容的（内容已被物理删除等）：归默认站点
        //    LEFT JOIN ... IS NULL 即"孤儿"；同样带「值不同」条件保证幂等
        $orphanSql = sprintf(
            'UPDATE `%s` cm LEFT JOIN `%s` ct ON ct.id = cm.content_id'
            . ' SET cm.site_id = %d WHERE ct.id IS NULL AND cm.site_id <> %d',
            $comment,
            $content,
            $defaultSite,
            $defaultSite
        );

        if ($dryRun) {
            $matched = (int) Db::name(self::TABLE)->alias('cm')
                ->join(self::CONTENT_TABLE . ' ct', 'ct.id = cm.content_id')
                ->whereRaw('cm.site_id <> ct.site_id')
                ->count();
            $orphans = (int) Db::name(self::TABLE)->alias('cm')
                ->leftJoin(self::CONTENT_TABLE . ' ct', 'ct.id = cm.content_id')
                ->whereNull('ct.id')
                ->where('cm.site_id', '<>', $defaultSite)
                ->count();

            $output->writeln("  · 将按内容站点回填 {$matched} 行；孤儿评论（内容已不存在）{$orphans} 行 → 站点 {$defaultSite}");

            return;
        }

        $affectedMatched = (int) Db::execute($matchedSql);
        $affectedOrphans = (int) Db::execute($orphanSql);

        $output->writeln("  · 按内容站点回填 {$affectedMatched} 行；孤儿评论 {$affectedOrphans} 行 → 站点 {$defaultSite}");
    }

    /**
     * 逻辑表名 → 真实表名（含前缀）
     */
    protected function tableName(string $logicalTable): string
    {
        return (string) Db::name($logicalTable)->getTable();
    }
}

<?php
namespace plugin\wisecms\command;

use plugin\wisecms\model\Tag;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\content\TagService;
use plugin\wisecms\service\site\CacheService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 标签词表「站点隔离」升级（P2）
 *
 * 背景：`wisecms_tag` / `wisecms_tag_relation` 原为全站共享词表（无 `site_id`，
 * `name` / `alias` 全局唯一）。新结构的建表迁移已直接带上 `site_id` 与新唯一键，
 * 但**存量环境不会重跑建表迁移**，故本命令负责一次性、幂等地完成升级：
 *
 *   ① 结构：补 `site_id` 列（两表）、把全局唯一键 `name` / `alias` 换成
 *      `(site_id, name)` / `(site_id, alias)`、补 `(site_id, tag_id)` 索引；
 *   ② 数据：按「关联内容的站点」回填 `tag.site_id` 与 `relation.site_id`；
 *      跨站共享的标签按站点**拆分成多行**（目标站点已有同名标签则直接复用，保持去重）；
 *   ③ 计数：按站点口径重算 `tag.count`（本站「已发布 + 未回收」内容的引用数）。
 *
 * 用法：
 *   php think wisecms:tag:site --dry-run        # 只报告将要做的事
 *   php think wisecms:tag:site                  # 执行（split：按内容站点推导并拆分）
 *   php think wisecms:tag:site --strategy=site1 # 不拆分，存量标签全部归站点 1
 *   php think wisecms:tag:site --default-site=2 # 指定孤儿标签（无任何关联）的归属站点
 *
 * 执行顺序建议：本命令 → `php think wisecms:url:rebuild`（标签别名改为按站点落库，需重建）
 * → 可选 `php think wisecms:maintain`（其它数据层维护）。
 *
 * 幂等：重复执行不会重复加列/加索引；已回填过的标签保持原站点不变
 * （`site_id` 已 >0 且与内容站点一致时跳过）。
 */
class TagSiteCommand extends Command
{
    /** 标签表逻辑名 */
    protected const TAG_TABLE = 'wisecms_tag';

    /** 关联表逻辑名 */
    protected const RELATION_TABLE = 'wisecms_tag_relation';

    protected function configure()
    {
        $this->setName('wisecms:tag:site')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅预览，不写库')
            ->addOption('strategy', null, Option::VALUE_REQUIRED, '跨站标签归属策略：split（拆分）/ site1（全部归站点1）', 'split')
            ->addOption('default-site', null, Option::VALUE_REQUIRED, '孤儿标签（无关联内容）归属站点', '1')
            ->setDescription('标签词表站点隔离升级：补 site_id / 唯一键改为站点内唯一 / 回填并拆分跨站标签 / 重算计数');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun      = (bool) $input->getOption('dry-run');
        $strategy    = strtolower((string) $input->getOption('strategy'));
        $defaultSite = max(1, (int) $input->getOption('default-site'));

        if (!in_array($strategy, ['split', 'site1'], true)) {
            $output->writeln('<error>--strategy 只支持 split / site1</error>');

            return 1;
        }

        $output->writeln($dryRun
            ? '<comment>DRY RUN：只统计不写入</comment>'
            : '<info>开始标签词表站点隔离升级</info>');

        $this->ensureSchema($output, $dryRun);

        $stats = $this->backfill($output, $dryRun, $strategy, $defaultSite);

        $output->writeln(sprintf(
            '  ② 数据回填：扫描标签 %d 个（回填站点 %d / 拆分 %d / 复用目标站已存在标签 %d），迁移关联 %d 行，孤儿标签 %d 个',
            $stats['tags'],
            $stats['site_filled'],
            $stats['split'],
            $stats['merged'],
            $stats['relations'],
            $stats['orphans']
        ));

        if (!$dryRun) {
            $tagIds = array_map('intval', Tag::column('id'));
            (new ContentService())->recountTags($tagIds);
            (new CacheService())->flush();
            $output->writeln(sprintf('  ③ 计数重算：%d 个标签（本站口径），插件缓存已整体失效', count($tagIds)));
            $output->writeln('<info>完成。建议接着执行：php think wisecms:url:rebuild（标签别名已改为按站点落库）</info>');
        } else {
            $output->writeln('<comment>--dry-run：未写库。</comment>');
        }

        return 0;
    }

    // ══════════════════════ ① 结构（幂等） ══════════════════════

    /**
     * 补齐两表的 `site_id` 列、新索引，替换旧的全站唯一键
     */
    protected function ensureSchema(Output $output, bool $dryRun): void
    {
        // ── site_id 列 ──
        foreach ([self::TAG_TABLE => 'id', self::RELATION_TABLE => 'content_id'] as $table => $after) {
            if ($this->hasColumn($table, 'site_id')) {
                continue;
            }

            $output->writeln("  · {$table} 缺 site_id 列 → 追加");
            if (!$dryRun) {
                Db::execute(sprintf(
                    'ALTER TABLE `%s` ADD COLUMN `site_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'站点ID\' AFTER `%s`',
                    $this->tableName($table),
                    $after
                ));
            }
        }

        // ── wisecms_tag：唯一键由全局 → 站点内 ──
        $tagTable = $this->tableName(self::TAG_TABLE);
        foreach ([['name' => 'name'], ['alias' => 'alias']] as $single) {
            $this->dropIndexesExactlyOn($output, $tagTable, array_values($single), $dryRun);
        }
        $this->ensureIndex($output, $tagTable, ['site_id', 'name'], true, 'uniq_site_name', $dryRun);
        $this->ensureIndex($output, $tagTable, ['site_id', 'alias'], true, 'uniq_site_alias', $dryRun);
        $this->ensureIndex($output, $tagTable, ['site_id'], false, 'idx_site_id', $dryRun);

        // ── wisecms_tag_relation：补站点维度索引 ──
        $relationTable = $this->tableName(self::RELATION_TABLE);
        $this->ensureIndex($output, $relationTable, ['site_id', 'tag_id'], false, 'idx_site_tag', $dryRun);
    }

    /**
     * 表是否已有某列
     */
    protected function hasColumn(string $logicalTable, string $column): bool
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $this->tableName($logicalTable) . "` LIKE '" . addslashes($column) . "'");
        } catch (\Throwable $e) {
            return false;
        }

        return $rows !== [];
    }

    /**
     * 读取全部索引：Key_name => ['columns' => string[], 'unique' => bool]
     *
     * @return array<string, array{columns: string[], unique: bool}>
     */
    protected function indexes(string $realTable): array
    {
        $indexes = [];

        foreach (Db::query('SHOW INDEX FROM `' . $realTable . '`') as $row) {
            $key = (string) ($row['Key_name'] ?? '');
            if ($key === '') {
                continue;
            }

            if (!isset($indexes[$key])) {
                $indexes[$key] = ['columns' => [], 'unique' => !(bool) ($row['Non_unique'] ?? 1)];
            }

            $indexes[$key]['columns'][max(0, (int) ($row['Seq_in_index'] ?? 1) - 1)] = (string) ($row['Column_name'] ?? '');
        }

        foreach ($indexes as $key => $item) {
            ksort($item['columns']);
            $indexes[$key]['columns'] = array_values($item['columns']);
        }

        return $indexes;
    }

    /**
     * 删除「列集合正好等于 $columns」的索引（跳过 PRIMARY）
     *
     * 用于替换旧的全站唯一键：`name` / `alias` 的单列 UNIQUE。
     */
    protected function dropIndexesExactlyOn(Output $output, string $realTable, array $columns, bool $dryRun): void
    {
        foreach ($this->indexes($realTable) as $key => $index) {
            if ($key === 'PRIMARY' || $index['columns'] !== $columns) {
                continue;
            }

            $output->writeln("  · 删除旧索引 {$key}(" . implode(',', $columns) . ')');
            if (!$dryRun) {
                Db::execute(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $realTable, $key));
            }
        }
    }

    /**
     * 确保索引存在（不存在则创建）
     */
    protected function ensureIndex(Output $output, string $realTable, array $columns, bool $unique, string $name, bool $dryRun): void
    {
        foreach ($this->indexes($realTable) as $index) {
            if ($index['columns'] === $columns) {
                return;
            }
        }

        $output->writeln(sprintf(
            '  · 新增%s索引 %s(%s)',
            $unique ? '唯一' : '',
            $name,
            implode(',', $columns)
        ));

        if ($dryRun) {
            return;
        }

        Db::execute(sprintf(
            'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)',
            $realTable,
            $unique ? 'UNIQUE ' : '',
            $name,
            implode(',', array_map(static fn(string $c): string => '`' . $c . '`', $columns))
        ));
    }

    /**
     * 逻辑表名 → 真实表名（含前缀）
     */
    protected function tableName(string $logicalTable): string
    {
        return (string) Db::name($logicalTable)->getTable();
    }

    // ══════════════════════ ② 数据回填 ══════════════════════

    /**
     * 回填 `site_id`（含跨站标签拆分）
     *
     * @return array{tags:int, site_filled:int, split:int, merged:int, relations:int, orphans:int}
     */
    protected function backfill(Output $output, bool $dryRun, string $strategy, int $defaultSite): array
    {
        $stats = ['tags' => 0, 'site_filled' => 0, 'split' => 0, 'merged' => 0, 'relations' => 0, 'orphans' => 0];

        // 关联 → 内容站点（一次 JOIN 取全量，按 tag 归并）
        $rows = Db::name(self::RELATION_TABLE)->alias('tr')
            ->join('wisecms_content c', 'c.id = tr.content_id')
            ->field('tr.id AS relation_id, tr.tag_id, c.site_id AS content_site')
            ->select()
            ->toArray();

        /** @var array<int, array<int, int[]>> $byTag tag_id => [content_site => relation_id[]] */
        $byTag = [];
        foreach ($rows as $row) {
            $tagId = (int) $row['tag_id'];
            $site  = max(1, (int) $row['content_site']);
            $byTag[$tagId][$site][] = (int) $row['relation_id'];
        }

        $tagService = new TagService();

        Tag::chunk(200, function ($tags) use (&$stats, $byTag, $dryRun, $strategy, $defaultSite, $tagService, $output): void {
            foreach ($tags as $tag) {
                $stats['tags']++;
                $tagId  = (int) $tag->id;
                $sites  = $byTag[$tagId] ?? [];

                // ── 孤儿标签（无任何关联内容）：归默认站点 ──
                if ($sites === []) {
                    $stats['orphans']++;
                    if ((int) $tag->site_id !== $defaultSite && !$dryRun) {
                        Tag::where('id', $tagId)->update(['site_id' => $defaultSite]);
                    }

                    return;
                }

                // ── 归属站点：关联内容最多的站点（并列取最小 site id，保证确定性）──
                $ownerSite = $defaultSite;
                $maxCount  = -1;
                foreach ($sites as $site => $relationIds) {
                    $count = count($relationIds);
                    if ($count > $maxCount || ($count === $maxCount && $site < $ownerSite)) {
                        $ownerSite = $site;
                        $maxCount  = $count;
                    }
                }

                // 策略 site1：不拆分，全部归站点 1（保留历史共享形态）
                if ($strategy === 'site1') {
                    $ownerSite = 1;
                }

                if ((int) $tag->site_id !== $ownerSite) {
                    $stats['site_filled']++;
                    if (!$dryRun) {
                        Tag::where('id', $tagId)->update(['site_id' => $ownerSite]);
                    }
                }

                foreach ($sites as $site => $relationIds) {
                    if ($site === $ownerSite || $strategy === 'site1') {
                        // 站点一致：只修 relation.site_id（冗余列回填）
                        if (!$dryRun) {
                            Db::name(self::RELATION_TABLE)->whereIn('id', $relationIds)->update(['site_id' => $site]);
                        }
                        continue;
                    }

                    // ── 跨站：在目标站点重建词表，再把关联迁过去 ──
                    $stats['split']++;
                    if ($dryRun) {
                        $output->writeln(sprintf('  · 拆分：#%d %s → 站点 %d（%d 条关联）', $tagId, $tag->name, $site, count($relationIds)));
                        continue;
                    }

                    $target = $tagService->findOrCreate($site, (string) $tag->name);
                    if ((int) $target->id === $tagId) {
                        // 理论上不会发生（findOrCreate 按站点查，本站已是 ownerSite）
                        continue;
                    }
                    if ((int) $target->id > 0 && (string) $target->alias !== '') {
                        $stats['merged']++;
                    }

                    Db::name(self::RELATION_TABLE)->whereIn('id', $relationIds)->update([
                        'tag_id'  => (int) $target->id,
                        'site_id' => $site,
                    ]);
                    $stats['relations'] += count($relationIds);
                }
            }
        });

        return $stats;
    }
}

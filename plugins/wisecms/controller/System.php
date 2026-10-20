<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\content\ContentRelationService;
use plugin\wisecms\service\site\CacheService;

/**
 * 系统工具（UIUX-P2-06 / P2-05）
 *
 * 插件级维护操作页，两个区块：
 *   1. 缓存维护 —— 刷新 CMS 缓存（P2-06，`CacheService::flush()`，幂等非破坏 → L2）；
 *   2. 孤儿数据清理（P2-05）—— 先扫描预览、再清理（物理删除不可逆 → L3）。
 *
 * ⚠️ 两者都与具体站点无关（缓存是**全站所有站点**的家族；孤儿行按主键悬空判定，
 * 不分站点），故本页**不挂站点选择器**（避免让人误以为"只作用于当前站点"）。
 */
class System extends BaseController
{
    /**
     * 系统工具页
     */
    public function index()
    {
        // 如实展示：把「将要被清理的缓存家族」列出来（由 CacheService 常量自动推导，非硬编码）
        $this->assign('cacheFamilies', CacheService::families());
        $this->assign('cacheDriver', (string) config('cache.default', 'file'));

        // 同理，把「孤儿数据会扫描哪些表」如实列出（来自 ContentRelationService，非硬编码）
        $this->assign('orphanLabels', ContentRelationService::ORPHAN_LABELS);

        return $this->fetch('admin/system/index');
    }

    /**
     * 刷新 CMS 缓存（清空本插件全部缓存家族）
     *
     * ⚠️ 只清 wisecms 自己的缓存家族（见 `CacheService::flush()`），
     * **绝不整体清空缓存存储**（那会波及其它插件与框架自身，审计 L-14）。
     * 不修改任何数据库数据；刷新后首次访问会重新生成缓存。
     */
    public function flushCache()
    {
        try {
            (new CacheService())->flush();

            return $this->success('缓存已刷新，下次访问将重新生成');
        } catch (WisecmsException $e) {
            return $this->error(WisecmsException::userMessage($e));
        } catch (\Throwable $e) {
            cms_log('error', '刷新 CMS 缓存失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('刷新缓存失败，请稍后重试');
        }
    }

    /**
     * 孤儿数据扫描（dry-run，UIUX-P2-05）
     *
     * 只统计不写入：列出「外键指向已不存在内容」的关联表与行数，
     * 供用户在清理前确认影响面（方案 §4.6 对 L3 的前置要求「先预览将清理项」）。
     *
     * 注意：页面上**不自动扫描** —— 扫描要对每张表做 LEFT JOIN 反查，
     * 大表上有开销，故只在用户点「扫描」时执行。
     */
    public function orphans()
    {
        try {
            $report = (new ContentRelationService())->purgeOrphans(true);

            return $this->success('', $this->orphanReport($report));
        } catch (\Throwable $e) {
            cms_log('error', '孤儿数据扫描失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('孤儿数据扫描失败，请稍后重试');
        }
    }

    /**
     * 孤儿数据清理（UIUX-P2-05，L3 不可逆物理删除）
     *
     * ⚠️ `ContentRelationService::purgeOrphans(false)` 是**物理 DELETE**、不可恢复；
     * 前端必须先「扫描」、再经 `cmsDangerConfirm` 输入确认词才允许提交（方案 §4.6）。
     *
     * 安全性说明：被删的每一行其外键都**指向不存在的内容**（悬空行），
     * 因此不会影响任何可见数据；标签计数也无需重算 ——
     * `ContentService::recountTags()` 的 JOIN 本就排除悬空关联行。
     * 但仍统一失效插件缓存（标签云 / Feed 等聚合结果需重建）。
     */
    public function purgeOrphans()
    {
        try {
            $deleted = (new ContentRelationService())->purgeOrphans(false);

            (new CacheService())->flush();

            $total = array_sum($deleted);

            return $this->success(
                $total > 0 ? "已清理 {$total} 行孤儿数据" : '未发现孤儿数据，无需清理',
                $this->orphanReport($deleted)
            );
        } catch (WisecmsException $e) {
            return $this->error(WisecmsException::userMessage($e));
        } catch (\Throwable $e) {
            cms_log('error', '孤儿数据清理失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('孤儿数据清理失败，请稍后重试');
        }
    }

    /**
     * 统一组装孤儿报告：表名 → 可读说明 + 合计（两个接口共用同一结构）
     *
     * @param array<string, int> $report 表名 => 行数
     * @return array{total: int, tables: array<int, array{table: string, label: string, count: int}>}
     */
    protected function orphanReport(array $report): array
    {
        $tables = [];

        foreach ($report as $table => $count) {
            $tables[] = [
                'table' => (string) $table,
                'label' => ContentRelationService::labelOf((string) $table),
                'count' => (int) $count,
            ];
        }

        return [
            'total'  => array_sum($report),
            'tables' => $tables,
        ];
    }
}

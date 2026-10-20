<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Category;
use plugin\wisedocs\model\Document;
use plugin\wisedocs\model\Feedback as FeedbackModel;
use plugin\wisedocs\model\Stat;

/**
 * 数据统计（P4-2 / P4-3）
 *
 * 修复内容：
 *   - 原实现 join 使用 `documents` / `categories`（缺 wisedocs_ 前缀，SQL 必然报错），
 *     统一改为 `wisedocs_documents` / `wisedocs_categories`；
 *   - 导出不再 `header()+exit` 绕过框架，改用 response()；
 *   - CSV 增加公式注入防护（= + - @ 开头前置单引号）。
 */
class Stats extends Base
{
    /** 文档表（Db 自动补表前缀） */
    protected const DOC_TABLE = 'wisedocs_documents';

    /** 分类表 */
    protected const CAT_TABLE = 'wisedocs_categories';

    /**
     * 统计首页
     */
    public function index()
    {
        $startDate = $this->validDate($this->request->param('start_date', date('Y-m-01')), date('Y-m-01'));
        $endDate   = $this->validDate($this->request->param('end_date', date('Y-m-d')), date('Y-m-d'));

        $this->assign([
            'overallStats'  => $this->getOverallStats($startDate, $endDate),
            'hotDocuments'  => $this->getHotDocuments($startDate, $endDate),
            'categoryStats' => $this->getCategoryStats($startDate, $endDate),
            // 趋势数据改为前端经 stats/trend 接口 AJAX 拉取（接通闲置端点，避免重复计算）
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'title'         => '数据统计',
        ]);

        return $this->fetch();
    }

    /**
     * 单篇文档统计
     */
    public function document()
    {
        $id = (int) $this->request->param('id/d', 0);

        $document = Document::with(['category'])->find($id);
        if (!$document) {
            return $this->error('文档不存在');
        }

        // 把「并列数组」形式整理成行数组，便于模板 volist 渲染
        $trend     = (new Stat())->getTrendData($id, 30);
        $trendRows = [];
        foreach ($trend['dates'] as $index => $date) {
            $trendRows[] = [
                'date'      => $date,
                'views'     => $trend['views'][$index] ?? 0,
                'helpful'   => $trend['helpful'][$index] ?? 0,
                'unhelpful' => $trend['unhelpful'][$index] ?? 0,
            ];
        }

        $this->assign([
            'document'      => $document,
            'trendRows'     => $trendRows,
            'feedbackStats' => (new FeedbackModel())->getFeedbackStats($id),
            'title'         => $document->title . ' - 统计',
        ]);

        return $this->fetch();
    }

    /**
     * 趋势数据（AJAX）
     */
    public function trend()
    {
        $startDate = $this->validDate($this->request->param('start_date', date('Y-m-01')), date('Y-m-01'));
        $endDate   = $this->validDate($this->request->param('end_date', date('Y-m-d')), date('Y-m-d'));

        return $this->success('', [
            'data'       => $this->getTrendRows($startDate, $endDate),
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);
    }

    /**
     * 导出 CSV（含公式注入防护）
     */
    public function export()
    {
        $startDate = $this->validDate($this->request->param('start_date', date('Y-m-01')), date('Y-m-01'));
        $endDate   = $this->validDate($this->request->param('end_date', date('Y-m-d')), date('Y-m-d'));

        $overall = $this->getOverallStats($startDate, $endDate);
        $hot     = $this->getHotDocuments($startDate, $endDate, 50);
        $cats    = $this->getCategoryStats($startDate, $endDate);

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM，避免 Excel 中文乱码

        $csv .= $this->csvLine(['总体统计', '']);
        $csv .= $this->csvLine(['统计项', '数值']);
        $csv .= $this->csvLine(['统计区间', $startDate . ' ~ ' . $endDate]);
        $csv .= $this->csvLine(['总浏览量', $overall['total_views']]);
        $csv .= $this->csvLine(['有帮助', $overall['total_helpful']]);
        $csv .= $this->csvLine(['无帮助', $overall['total_unhelpful']]);
        $csv .= $this->csvLine(['文档数量', $overall['document_count']]);
        $csv .= $this->csvLine(['反馈率(%)', $overall['feedback_rate']]);
        $csv .= $this->csvLine(['有帮助率(%)', $overall['helpful_rate']]);
        $csv .= $this->csvLine([]);

        $csv .= $this->csvLine(['热门文档', '', '', '', '', '', '']);
        $csv .= $this->csvLine(['排名', '文档标题', '分类', '浏览量', '有帮助', '无帮助', '反馈率(%)']);
        $rank = 1;
        foreach ($hot as $doc) {
            $rate = $doc['views'] > 0
                ? round(($doc['helpful'] + $doc['unhelpful']) / $doc['views'] * 100, 2)
                : 0;
            $csv .= $this->csvLine([
                $rank++,
                $doc['title'],
                $doc['category_name'],
                $doc['views'],
                $doc['helpful'],
                $doc['unhelpful'],
                $rate,
            ]);
        }
        $csv .= $this->csvLine([]);

        $csv .= $this->csvLine(['分类统计', '', '', '', '', '']);
        $csv .= $this->csvLine(['分类名称', '文档数', '浏览量', '有帮助', '无帮助', '反馈率(%)']);
        foreach ($cats as $cat) {
            $rate = $cat['total_views'] > 0
                ? round(($cat['total_helpful'] + $cat['total_unhelpful']) / $cat['total_views'] * 100, 2)
                : 0;
            $csv .= $this->csvLine([
                $cat['name'],
                $cat['document_count'],
                $cat['total_views'],
                $cat['total_helpful'],
                $cat['total_unhelpful'],
                $rate,
            ]);
        }

        $filename = 'wisedocs_stats_' . date('YmdHis') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ── 统计计算 ──────────────────────────────────────────

    /**
     * 总体统计
     */
    protected function getOverallStats(string $startDate, string $endDate): array
    {
        $stats = Stat::alias('s')
            ->join(self::DOC_TABLE . ' d', 's.document_id = d.id')
            ->where('d.status', Document::STATUS_PUBLISHED)
            ->whereBetween('s.date', [$startDate, $endDate])
            ->field('
                SUM(s.view_count) as total_views,
                SUM(s.helpful_count) as total_helpful,
                SUM(s.unhelpful_count) as total_unhelpful,
                COUNT(DISTINCT s.document_id) as document_count
            ')
            ->find();

        $views     = (int) ($stats['total_views'] ?? 0);
        $helpful   = (int) ($stats['total_helpful'] ?? 0);
        $unhelpful = (int) ($stats['total_unhelpful'] ?? 0);

        return [
            'total_views'     => $views,
            'total_helpful'   => $helpful,
            'total_unhelpful' => $unhelpful,
            'document_count'  => (int) ($stats['document_count'] ?? 0),
            'feedback_rate'   => $views > 0 ? round(($helpful + $unhelpful) / $views * 100, 2) : 0,
            'helpful_rate'    => ($helpful + $unhelpful) > 0 ? round($helpful / ($helpful + $unhelpful) * 100, 2) : 0,
        ];
    }

    /**
     * 热门文档
     */
    protected function getHotDocuments(string $startDate, string $endDate, int $limit = 10): array
    {
        return Stat::alias('s')
            ->join(self::DOC_TABLE . ' d', 's.document_id = d.id')
            ->join(self::CAT_TABLE . ' c', 'd.category_id = c.id', 'LEFT')
            ->where('d.status', Document::STATUS_PUBLISHED)
            ->whereBetween('s.date', [$startDate, $endDate])
            ->field('
                d.id, d.title, c.name as category_name,
                SUM(s.view_count) as views,
                SUM(s.helpful_count) as helpful,
                SUM(s.unhelpful_count) as unhelpful
            ')
            ->group('s.document_id, d.id, d.title, c.name')
            ->order('views DESC')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 分类统计
     */
    protected function getCategoryStats(string $startDate, string $endDate): array
    {
        return Stat::alias('s')
            ->join(self::DOC_TABLE . ' d', 's.document_id = d.id')
            ->join(self::CAT_TABLE . ' c', 'd.category_id = c.id', 'LEFT')
            ->where('d.status', Document::STATUS_PUBLISHED)
            ->whereBetween('s.date', [$startDate, $endDate])
            ->field('
                c.id, c.name,
                COUNT(DISTINCT d.id) as document_count,
                SUM(s.view_count) as total_views,
                SUM(s.helpful_count) as total_helpful,
                SUM(s.unhelpful_count) as total_unhelpful
            ')
            ->group('c.id, c.name')
            ->order('total_views DESC')
            ->select()
            ->toArray();
    }

    /**
     * 趋势（按天补齐）
     *
     * @return array<int, array{date:string, views:int, helpful:int, unhelpful:int}>
     */
    protected function getTrendRows(string $startDate, string $endDate): array
    {
        $stats = Stat::alias('s')
            ->join(self::DOC_TABLE . ' d', 's.document_id = d.id')
            ->where('d.status', Document::STATUS_PUBLISHED)
            ->whereBetween('s.date', [$startDate, $endDate])
            ->field('
                s.date,
                SUM(s.view_count) as views,
                SUM(s.helpful_count) as helpful,
                SUM(s.unhelpful_count) as unhelpful
            ')
            ->group('s.date')
            ->order('s.date ASC')
            ->select()
            ->toArray();

        $map = [];
        foreach ($stats as $row) {
            $map[(string) $row['date']] = $row;
        }

        $rows      = [];
        $current   = $startDate;
        $maxViews  = 1;
        $guard     = 0;

        while (strtotime($current) <= strtotime($endDate) && $guard < 400) {
            $row = $map[$current] ?? null;

            $rows[] = [
                'date'      => $current,
                'views'     => (int) ($row['views'] ?? 0),
                'helpful'   => (int) ($row['helpful'] ?? 0),
                'unhelpful' => (int) ($row['unhelpful'] ?? 0),
            ];

            $maxViews = max($maxViews, (int) ($row['views'] ?? 0));

            $current = date('Y-m-d', strtotime($current . ' +1 day'));
            $guard++;
        }

        // 附带宽高百分比 + 纯文本条形（无需图表库，避免 CSP 内联样式问题）
        foreach ($rows as &$item) {
            $item['bar'] = $maxViews > 0 ? round($item['views'] / $maxViews * 100) : 0;
            $blocks = (int) round($item['bar'] / 5);
            $item['bar_text'] = $blocks > 0 ? str_repeat('▉', $blocks) : '';
        }
        unset($item);

        return $rows;
    }

    // ── 工具方法 ──────────────────────────────────────────

    /**
     * 校验日期入参（非法则回退默认值）
     */
    protected function validDate($value, string $default): string
    {
        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
    }

    /**
     * 生成一行 CSV
     */
    protected function csvLine(array $cells): string
    {
        $escaped = array_map(function ($cell) {
            return $this->csvCell($cell);
        }, $cells);

        return implode(',', $escaped) . "\r\n";
    }

    /**
     * 单元格转义（防公式注入 + 引号转义）
     */
    protected function csvCell($value): string
    {
        $value = (string) $value;

        // 以 = + - @ 或制表符/回车开头会被 Excel 当作公式执行，前置单引号阻断
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}

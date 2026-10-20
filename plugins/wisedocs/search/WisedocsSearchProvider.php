<?php

declare(strict_types=1);

namespace plugin\wisedocs\search;

use app\admin\contract\SearchProviderInterface;
use plugin\wisedocs\model\Document as DocumentModel;

/**
 * WiseDocs 文档搜索提供者（批次 6）
 *
 * 接入后台全局搜索（`/` 或 Ctrl+K / Cmd+K 唤醒）：
 *   - 按标题 / 摘要匹配已启用文档，命中后跳转到文档列表并自动打开该文档的编辑抽屉
 *     （复用 document 列表已有的 `?edit=ID` 入口）；
 *   - 页面跳转（概览 / 文档 / 分类 / 标签 / 反馈 / 统计 / 空间）由框架的
 *     MenuSearchProvider 覆盖（wisedocs 菜单已同步进后台菜单），无需重复实现。
 *
 * 说明：后台全局搜索面向「管理」，因此不按状态过滤（草稿也可被搜到、便于继续编辑），
 * 与前台 SearchService（仅返回已发布文档）刻意区分。
 */
class WisedocsSearchProvider implements SearchProviderInterface
{
    public function getId(): string
    {
        return 'wisedocs';
    }

    public function getName(): string
    {
        return '文档';
    }

    public function getIcon(): string
    {
        return 'bi bi-journal-text';
    }

    public function search(string $keyword, int $limit = 10): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $docs = DocumentModel::with(['category'])
            ->whereLike('title|excerpt|slug', "%{$keyword}%")
            ->field('id,title,excerpt,category_id,is_top,sort_order')
            ->order('is_top DESC, sort_order DESC, id DESC')
            ->limit($limit)
            ->select();

        $results = [];
        foreach ($docs as $doc) {
            $title    = (string) $doc->title;
            $excerpt  = trim((string) $doc->excerpt);
            $category = $doc->category ? (string) $doc->category->name : '未分类';

            if ($excerpt !== '' && mb_strlen($excerpt) > 50) {
                $excerpt = mb_substr($excerpt, 0, 50) . '…';
            }

            $results[] = [
                'id'         => 'wisedocs:' . $doc->id,
                'title'      => $title,
                'subtitle'   => $excerpt !== '' ? $excerpt : $category,
                'url'        => '/plugin/wisedocs/document/index?edit=' . $doc->id,
                'icon'       => 'bi bi-journal-text',
                'category'   => '文档',
                'provider'   => 'wisedocs',
                'breadcrumb' => 'WiseDocs / ' . $category,
                'score'      => max($this->calcScore($keyword, $title), $this->calcScore($keyword, $excerpt)),
            ];
        }

        return $results;
    }

    /**
     * 相关度评分（与 MenuSearchProvider 同口径）：
     * 100 完全匹配 / 80 前缀匹配 / 60 包含匹配 / 0 不匹配
     */
    private function calcScore(string $keyword, string $text): int
    {
        $text = trim($text);
        $kw   = trim($keyword);

        if ($text === '' || $kw === '') {
            return 0;
        }
        if ($text === $kw) {
            return 100;
        }
        if (mb_stripos($text, $kw) === 0) {
            return 80;
        }
        if (mb_stripos($text, $kw) !== false) {
            return 60;
        }

        return 0;
    }
}

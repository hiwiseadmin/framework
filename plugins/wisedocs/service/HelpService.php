<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

use plugin\wisedocs\model\Category as CategoryModel;
use plugin\wisedocs\model\Document as DocumentModel;

/**
 * 帮助中心数据服务（P3-1 重写）
 *
 * 原实现误用 `Category` 模型查询文档字段（view_count / is_featured / tags 等
 * 在分类表并不存在），导致首页与搜索必然 500。本版本统一改用 `Document`。
 */
class HelpService
{
    public function __construct(protected int $projectId = 0)
    {
    }

    /**
     * 已发布文档查询基座（含空间过滤）
     */
    protected function docQuery()
    {
        $query = DocumentModel::where('status', DocumentModel::STATUS_PUBLISHED);

        if ($this->projectId > 0) {
            $query->where('project_id', $this->projectId);
        }

        return $query;
    }

    /**
     * 分类树（前台导航）
     */
    public function categoryTree(): array
    {
        $query = CategoryModel::where('status', 1)->order('sort_order DESC, id ASC');
        if ($this->projectId > 0) {
            $query->where('project_id', $this->projectId);
        }

        $items = $query->field('id,parent_id,name,slug,icon,sort_order')->select()->toArray();

        return $this->buildTree($items, 0);
    }

    /**
     * 推荐文章
     */
    public function featuredDocuments(int $limit = 5): array
    {
        return $this->docQuery()
            ->where('is_featured', 1)
            ->order('sort_order DESC, id DESC')
            ->limit($limit)
            ->field('id,title,slug,excerpt,view_count,published_at')
            ->select()
            ->toArray();
    }

    /**
     * 热门文档
     */
    public function hotDocuments(int $limit = 8): array
    {
        return $this->docQuery()
            ->order('view_count DESC, id DESC')
            ->limit($limit)
            ->field('id,title,slug,view_count,category_id')
            ->select()
            ->toArray();
    }

    /**
     * 文档列表（可按分类）
     */
    public function documents(int $categoryId = 0, int $limit = 15): array
    {
        $query = $this->docQuery()->order('is_top DESC, sort_order DESC, id DESC');

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        return $query->limit($limit)
            ->field('id,title,slug,excerpt,view_count,is_top,is_featured,category_id,published_at')
            ->select()
            ->toArray();
    }

    /**
     * 相邻文档（同分类内）
     *
     * @return array{prev: DocumentModel|null, next: DocumentModel|null}
     */
    public function neighbors(DocumentModel $document): array
    {
        $base = function () use ($document) {
            $query = DocumentModel::where('status', DocumentModel::STATUS_PUBLISHED)
                ->where('category_id', $document->category_id);

            if ($this->projectId > 0) {
                $query->where('project_id', $this->projectId);
            }

            return $query;
        };

        $prev = $base()->where('id', '<', $document->id)->order('id DESC')->find();
        $next = $base()->where('id', '>', $document->id)->order('id ASC')->find();

        return ['prev' => $prev, 'next' => $next];
    }

    /**
     * 相关文档（同分类、排除自身）
     */
    public function related(DocumentModel $document, int $limit = 6): array
    {
        $query = DocumentModel::where('status', DocumentModel::STATUS_PUBLISHED)
            ->where('id', '<>', $document->id)
            ->where('category_id', $document->category_id);

        if ($this->projectId > 0) {
            $query->where('project_id', $this->projectId);
        }

        return $query->order('is_top DESC, view_count DESC, id DESC')
            ->limit($limit)
            ->field('id,title,slug,view_count')
            ->select()
            ->toArray();
    }

    /**
     * 递归构建树
     */
    protected function buildTree(array $items, int $parentId): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int) $item['parent_id'] === $parentId) {
                // children 恒定存在（叶子节点为 []）。
                // 不能「有子级才写键」：前台模板以 {volist name="cat.children"} 无条件读取，
                // 缺键会触发 Undefined array key "children"，再被错误处理器升级为 500。
                $item['children'] = $this->buildTree($items, (int) $item['id']);
                $tree[] = $item;
            }
        }

        return $tree;
    }
}

<?php
namespace plugin\wisecms\service\content;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\site\CacheService;

use plugin\wisecms\model\Category;
use plugin\wisecms\model\Model;
use plugin\wisecms\model\UrlAlias;
use plugin\wisecms\service\url\UrlService;

class CategoryService
{
    /**
     * 获取栏目树（带缓存）
     */
    public function getTree(int $siteId = 1): array
    {
        $cacheKey = sprintf(CacheService::KEY_CATEGORY_TREE, $siteId);
        return (new CacheService())->remember($cacheKey, function () use ($siteId) {
            return Category::getTree($siteId);
        }, 3600);
    }

    /**
     * 获取栏目选项（下拉框用）
     */
    public function getOptions(int $siteId = 1, int $excludeId = 0): array
    {
        $tree = $this->getTree($siteId);
        return $this->treeToOptions($tree, 0, $excludeId);
    }

    /**
     * 获取扁平化栏目行（供后台列表渲染，含层级 level 与绑定模型名 model_name）
     */
    public function getRows(int $siteId = 1): array
    {
        $rows = $this->flatten($this->getTree($siteId));

        $modelNames = Model::where('status', 1)->column('title', 'id');

        foreach ($rows as &$row) {
            $row['model_name'] = $modelNames[$row['model_id']] ?? '';
        }

        return $rows;
    }

    /**
     * 后台栏目树（**含禁用栏目** + 补 model_name）—— UIUX-P0-47
     *
     * 与 {@see getTree()} 的三点差异（后者是**前台口径**，不能直接当后台树用）：
     *   1. 不过滤 `status` —— `Category::getTree` 硬编码 `where('status', 1)`
     *      （`model/Category.php:57-61`），导致禁用栏目在后台「消失」：既看不到、
     *      也无法在树上重新启用；
     *   2. 不做缓存 —— 前台那份走 `KEY_CATEGORY_TREE`（TTL 3600），而后台任何一次
     *      排序 / 状态 / 绑定模型改动都必须立刻可见；栏目数量有限，直查可接受；
     *   3. 补 `model_name`，且**不按模型 status 过滤** —— 栏目绑定的模型被禁用时
     *      也应如实展示模型名（`getRows()` 过滤了模型 status，会出现「绑了模型却显示 -」）。
     *
     * @param int $siteId 站点 ID
     * @return array{rows: array<int, array<string, mixed>>, tree: array<int, array<string, mixed>>}
     *         `rows` = 扁平行（含 `level` / `model_name`），供 `parentIdField` 型树表直接消费；
     *         `tree` = 嵌套树（含 `children`），供左侧栏目树消费
     */
    public function getAdminTree(int $siteId = 1): array
    {
        $all = Category::where('site_id', $siteId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (empty($all)) {
            return ['rows' => [], 'tree' => []];
        }

        $modelNames = Model::column('title', 'id');
        foreach ($all as &$node) {
            $node['model_name'] = $modelNames[$node['model_id']] ?? '';
        }
        unset($node);

        $treeLib = new \wise\support\tools\Tree([
            'pidname'   => 'parent_id',
            'idname'    => 'id',
            'childKey'  => 'children',
            'sortfield' => 'sort',
            'auto_sort' => false, // 已由数据库排序
        ]);
        $treeLib->init($all);
        $tree = $treeLib->getTreeArray(0);

        return ['rows' => $this->flatten($tree), 'tree' => $tree];
    }

    /**
     * 递归扁平化栏目树
     */
    protected function flatten(array $nodes, int $level = 0): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $node['level'] = $level;
            $rows[]        = $node;
            if (!empty($children)) {
                $rows = array_merge($rows, $this->flatten($children, $level + 1));
            }
        }
        return $rows;
    }

    /**
     * 创建栏目
     */
    public function create(array $data): Category
    {
        // 白名单收口：避免客户端覆盖 id / site_id / delete_time 等非表单列
        $data = array_intersect_key($data, array_flip([
            'site_id', 'parent_id', 'type', 'name', 'alias', 'model_id', 'url',
            'list_rows', 'is_nav', 'sort', 'status', 'description',
        ]));
        // P1-C：别名站点内唯一（空串归一为 NULL，非空先做友好查重）
        $data = $this->normalizeAlias($data, (int) ($data['site_id'] ?? 1));
        // create_time / update_time 由模型 autoWriteTimestamp 维护
        $category = Category::create($data);
        $this->clearCache($data['site_id'] ?? 1);

        // 同步 URL 别名索引
        (new UrlService())->syncCategory($category);

        return $category;
    }

    /**
     * 更新栏目
     */
    public function update(int $id, array $data): Category
    {
        $category = Category::find($id);
        if (!$category) {
            throw new WisecmsException('栏目不存在', ErrorCode::NOT_FOUND);
        }
        // P1-C：别名站点内唯一（同 create）
        $data = $this->normalizeAlias($data, (int) $category->site_id, $id);
        // update_time 由模型 autoWriteTimestamp 维护
        $category->save($data);
        $this->clearCache($category->site_id);

        // 同步 URL 别名索引（alias 变更时旧 URL 转历史，301 到新 URL）
        (new UrlService())->syncCategory($category);

        return $category;
    }

    /**
     * 栏目别名归一 + 站点内唯一性前置校验（P1-C）
     *
     * `(site_id, alias)` 上有唯一索引：空别名写为 NULL（MySQL 唯一索引允许多个 NULL，
     * 因此多个「未填别名」的栏目不会互相冲突），非空别名先做一次友好查重。
     *
     * 查重**包含回收站栏目** —— 软删行同样占用数据库唯一键，只查未删行会在写入时撞 1062。
     */
    protected function normalizeAlias(array $data, int $siteId, int $excludeId = 0): array
    {
        if (!array_key_exists('alias', $data)) {
            return $data;
        }

        $alias = trim((string) $data['alias']);
        if ($alias === '') {
            $data['alias'] = null;

            return $data;
        }

        $query = Category::withTrashed()->where('site_id', $siteId)->where('alias', $alias);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        if ($query->find()) {
            throw new WisecmsException('该别名已被其他栏目使用（含回收站栏目），请更换', ErrorCode::DUPLICATE);
        }

        $data['alias'] = $alias;

        return $data;
    }

    /**
     * 删除栏目
     */
    public function delete(int $id): bool
    {
        $category = Category::find($id);
        if (!$category) {
            throw new WisecmsException('栏目不存在', ErrorCode::NOT_FOUND);
        }
        // 检查是否有子栏目
        if (Category::where('parent_id', $id)->count() > 0) {
            throw new WisecmsException('请先删除子栏目', ErrorCode::VALIDATE_FAILED);
        }

        // P1-B4：还有内容的栏目不允许直接删除 —— 否则内容会残留指向已删栏目的 catid
        // （前台栏目页 404、列表筛选失效、后台按栏目检索不到），与 ModelService::delete 的
        // 「先清空内容再删」策略保持一致
        $contentCount = \plugin\wisecms\model\Content::where('catid', $id)
            ->whereNull('delete_time')
            ->count();
        if ($contentCount > 0) {
            throw new WisecmsException("该栏目下仍有 {$contentCount} 篇内容，请先移动或删除", ErrorCode::VALIDATE_FAILED);
        }

        $siteId = $category->site_id;
        $category->delete();
        $this->clearCache($siteId);

        (new UrlService())->removeAlias(UrlAlias::TYPE_CATEGORY, $id);

        return true;
    }

    /**
     * 清除栏目缓存
     *
     * 设为 public 供控制器在「状态切换」等未走 create/update 的写路径上调用
     * （`Category::toggleStatus` 此前遗漏了这一步，见 UIUX-P0-47 附带修复）。
     */
    public function clearCache(int $siteId): void
    {
        (new CacheService())->delete(sprintf(CacheService::KEY_CATEGORY_TREE, $siteId));
    }

    /**
     * 树形转下拉选项
     */
    protected function treeToOptions(array $tree, int $depth = 0, int $excludeId = 0): array
    {
        $options = [];
        foreach ($tree as $item) {
            if ($item['id'] == $excludeId) continue;
            $prefix = $depth > 0 ? str_repeat('├─', $depth) . ' ' : '';
            $options[] = [
                'id' => $item['id'],
                'name' => $prefix . $item['name'],
            ];
            if (!empty($item['children'])) {
                $options = array_merge($options, $this->treeToOptions($item['children'], $depth + 1, $excludeId));
            }
        }
        return $options;
    }
}

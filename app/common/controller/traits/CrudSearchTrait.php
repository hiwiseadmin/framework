<?php
declare(strict_types=1);

namespace app\common\controller\traits;

use app\common\library\crud\Search;
use app\common\library\crud\SearchRenderer;

/**
 * CrudSearchTrait — 搜索区声明的**独立**接入点。
 *
 * 为什么从 CrudSchemaTrait 里拆出来：
 *   CrudSchemaTrait 要求实现 `defineForm()`（Schema 驱动表单），而存量页面（如
 *   `admin/Admin`）用的是手写表单 + CrudTrait，只需要补一块搜索区。
 *   把搜索相关能力独立成 trait 后，两类页面都能按同一条路径接入：
 *
 *   ```php
 *   class Admin extends BaseController
 *   {
 *       use CrudTrait;          // 查询构建 + RESTful
 *       use CrudSearchTrait;    // 搜索区声明
 *
 *       protected function defineSearch(Search $search): void { ... }
 *
 *       public function index()
 *       {
 *           $this->assign('filterHtml', $this->crudFilterHtml());
 *           return $this->fetch();
 *       }
 *   }
 *   ```
 *
 * 模板侧只需一行（无声明时为 ''，不渲染）：
 *   `{notempty name="filterHtml"}{$filterHtml|raw}{/notempty}`
 *
 * @see \docs\wiseadmin-后台页面统一方案.md §6
 */
trait CrudSearchTrait
{
    /** 一次请求内缓存搜索 Schema，避免多处消费时重复构建 */
    protected ?array $crudSearchCache = null;

    /**
     * 搜索条件声明（**可选**，不实现即无搜索区）。
     *
     * 只生成 AND 条件；OR 分组请用表格高级搜索（AdvancedSearch）。
     *
     * @param Search $search 待填充的搜索容器
     */
    protected function defineSearch(Search $search): void
    {
    }

    /**
     * 构建（并缓存）搜索 Schema 数组。
     *
     * @return array<string, mixed>
     */
    protected function crudSearch(): array
    {
        if ($this->crudSearchCache === null) {
            $search = new Search();
            $this->defineSearch($search);
            $this->crudSearchCache = $search->toArray();
        }

        return $this->crudSearchCache;
    }

    /**
     * 搜索区 HTML（无 defineSearch 声明时为空串）。
     *
     * @param array<string, mixed> $context 透传给 SearchRenderer（id / class / showActions …）
     */
    protected function crudFilterHtml(array $context = []): string
    {
        $schema = $this->crudSearch();

        if (($schema['fields'] ?? []) === []) {
            return '';
        }

        return (new SearchRenderer())->render($schema, $context);
    }
}

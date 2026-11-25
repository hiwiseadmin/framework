<?php
declare(strict_types=1);

namespace app\common\controller\traits;

use think\Model;
use wise\form\schema\Form;
use wise\form\schema\renderer\html\HtmlRenderer;
use wise\form\schema\renderer\html\theme\Bootstrap5WsTheme;

/**
 * CrudSchemaTrait — Schema 驱动的运行时渲染侧。
 *
 * 定位（与 CrudTrait 的分工）：
 *   CrudTrait       —— 查询构建 + 标准 RESTful（save/update/getList/delete/status…）
 *   CrudSchemaTrait —— 把控制器的表单 Schema 声明变成**页面与校验**
 *
 * 设计取舍：**运行时渲染，不生成页面文件**
 *   控制器只声明 `defineForm()`，列表/表单 HTML 在请求时由框架级共享模板 + HtmlRenderer 产出。
 *   改字段只需改一处声明，页面自动跟随 —— 这是与 FastAdmin「生成 form.html 副本」的根本区别。
 *
 * 为什么不在本 trait 里定义 index()/add()/edit()：
 *   动作入口由控制器自己声明（人工与 AI 共用同一入口，未声明即不开放）。
 *   本 trait 只提供**渲染辅助方法**，由控制器写 3 行
 *   `index()/add()/edit()` 调用它们 —— 显式、无魔法、易调试。
 *   `CrudAction` 侧对应的是 protected `_index()/_add()/_edit()`，不会被路由。
 *
 * 自定义 HTML 的逃生通道（4 层）：
 *   L1 字段级 `->renderable(['template' => ...])`  ｜ L2 覆盖本模块 form.html
 *   L3 共享模板的 `{block}` 区块                     ｜ L4 覆盖 add()/edit() 自己 fetch
 *
 * 宿主能力声明（与 CrudAction 同一约定：trait 自身无法继承 BaseController，
 * 因此显式标注由宿主提供的方法与属性，便于 IDE 与静态分析正确解析）：
 *
 * @method string fetch(string $template = '', array $vars = [], array $config = []) 渲染模板（由 BaseController 提供）
 * @method $this assign(string|array $name, mixed $value = null) 模板变量赋值（由 BaseController 提供）
 *
 * @property \think\Request $request 请求对象（由 BaseController 提供）
 *
 * @see \docs\wiseadmin-crud-field-mapping.md
 */
trait CrudSchemaTrait
{
    use CrudSearchTrait;

    /** 一次请求内缓存 Schema，避免多处消费时重复构建 */
    protected ?array $crudSchemaCache = null;

    /**
     * 表单 Schema 声明（由控制器实现，是**唯一声明源**）。
     *
     * @param Form $form 待填充的表单对象
     */
    abstract protected function defineForm(Form $form): void;

    /**
     * 构建（并缓存）Schema 数组。
     *
     * 同一份 Schema 被三处消费：页面渲染 / 校验规则 / 字段过滤。
     *
     * @return array<string, mixed>
     */
    protected function crudSchema(): array
    {
        if ($this->crudSchemaCache === null) {
            $form = new Form($this->crudSchemaName());
            $this->defineForm($form);
            $this->crudSchemaCache = $form->toArray();
        }

        return $this->crudSchemaCache;
    }

    /**
     * 渲染筛选条 HTML（无搜索条件时返回空串 —— 模板据此决定是否渲染）。
     *
     * defineSearch() / crudSearch() 由 CrudSearchTrait 提供；本方法保留为**带上下文的**
     * 渲染入口（id / class / showActions …），并保持旧方法名对存量控制器的兼容。
     *
     * @param array<string, mixed> $context 透传给 SearchRenderer（id / class / submitText …）
     */
    protected function renderCrudSearch(array $context = []): string
    {
        return $this->crudFilterHtml($context);
    }

    /**
     * Schema 名称：默认取当前控制器名的蛇形小写。
     */
    protected function crudSchemaName(): string
    {
        return strtolower((string) $this->request->controller());
    }

    /**
     * 当前控制器的路由基址（module/controller，小写下划线）。
     *
     * 控制器名是驼峰（AttachmentCategory），URL 是下划线（attachment_category）：
     * 只做 strtolower 会生成 /admin/attachmentcategory/schema 这类 404 地址。
     */
    protected function crudRouteBase(): string
    {
        $controller = (string) $this->request->controller();

        if (function_exists('parse_name')) {
            $controller = parse_name($controller);
        } else {
            $controller = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $controller) ?? $controller);
        }

        return trim(strtolower((string) $this->request->module()) . '/' . $controller, '/');
    }

    /**
     * 列表页的标准端点集合（供前端装配与 schema() 消费）。
     *
     * 走 `url()` 生成，故自动遵循 url_html_suffix / 路由重写等配置。
     *
     * @return array<string, string>
     */
    protected function crudUrls(): array
    {
        $base = $this->crudRouteBase();
        $make = static function (string $path): string {
            return function_exists('url') ? (string) url($path) : '/' . $path;
        };

        $urls = [
            'list'   => $make($base . '/getList'),
            'add'    => $make($base . '/add'),
            'edit'   => $make($base . '/edit'),
            'del'    => $make($base . '/del'),
            'schema' => $make($base . '/schema'),
        ];

        if (method_exists($this, 'export')) {
            $urls['export'] = $make($base . '/export');
        }

        if (method_exists($this, 'recycleList')) {
            $urls['recycle'] = $make($base . '/recycleList');

            $optional = [
                'recycleToken'       => 'recycle_token',
                'recycleRestore'     => 'recycle_restore',
                'recycleForceDelete' => 'recycle_force_delete',
            ];

            foreach ($optional as $action => $key) {
                if (method_exists($this, $action)) {
                    $urls[$key] = $make($base . '/' . $action);
                }
            }
        }

        return $urls;
    }

    /**
     * 渲染列表页（框架级共享模板 `public/crud/index`）。
     *
     * 列表数据仍走 CrudTrait::getList()，列配置由页面 JS 提供；
     * 搜索区由 defineSearch() 声明 → SearchRenderer 渲染，模板只负责输出 filterHtml。
     *
     * @param string $title 页面标题
     * @param string $icon  Bootstrap Icons 类名
     * @param string $desc  页面描述（可选，单行）
     * @return string 渲染结果
     */
    protected function renderCrudIndex(string $title, string $icon = 'bi bi-table', string $desc = '')
    {
        $this->assign([
            'crudTitle'     => $title,
            'crudIcon'      => $icon,
            'crudDesc'      => $desc,
            'filterHtml'    => $this->renderCrudSearch(),
            'crudSchemaUrl' => (string) url($this->crudRouteBase() . '/schema'),
            'crudUrls'      => $this->crudUrls(),
        ]);

        return $this->fetch('public/crud/index');
    }

    /**
     * 渲染表单页（由 HtmlRenderer 运行时渲染，无生成物）。
     *
     * @param Model|array<string, mixed>|null $row    编辑态记录；null 表示新增
     * @param string                          $title  页面标题（如「新增文章」）
     * @param string                          $action 表单提交地址
     * @return string 渲染结果
     */
    protected function renderCrudForm(Model|array|null $row, string $title, string $action)
    {
        $schema = $this->crudSchema();

        $renderer = new HtmlRenderer(new Bootstrap5WsTheme());
        $html     = $renderer->render($schema, [
            'values' => $this->crudFormValues($row, $schema),
        ]);

        $this->assign([
            'crudTitle'  => $title,
            'formAction' => $action,
            'isEdit'     => $row !== null,
            'rowId'      => $this->crudRowId($row),
            'schemaHtml' => $html,
        ]);

        return $this->fetch('public/crud/form');
    }

    /**
     * 提取当前记录在 Schema 声明字段上的值（回显用）。
     *
     * 只取声明过的字段，避免把 id / 关联属性等无关数据带进表单。
     *
     * @param Model|array<string, mixed>|null $row
     * @param array<string, mixed>            $schema
     * @return array<string, mixed>
     */
    protected function crudFormValues(Model|array|null $row, array $schema): array
    {
        if ($row === null) {
            return [];
        }

        $data   = $row instanceof Model ? $row->toArray() : $row;
        $fields = array_keys((array) ($schema['fields'] ?? []));

        return array_intersect_key($data, array_flip($fields));
    }

    /**
     * 取记录主键（编辑态隐藏域用）。
     *
     * @param Model|array<string, mixed>|null $row
     */
    protected function crudRowId(Model|array|null $row): mixed
    {
        if ($row === null) {
            return null;
        }

        return $row instanceof Model ? $row->getKey() : ($row['id'] ?? null);
    }

    /**
     * Schema JSON 出口。
     *
     * 一次返回表单字段 + 搜索条件 + 端点集合，供前端（crud-page.js）运行时渲染与
     * AI 工具消费同一份声明。结构：{form, fields, groups, repeaters, columns, search, urls}
     *
     * @return \think\response\Json
     */
    public function schema()
    {
        $schema = $this->crudSchema();
        $schema['search'] = $this->crudSearch();
        $schema['urls']   = $this->crudUrls();

        if (method_exists($this, 'export')) {
            $schema['exportPermission'] = strtolower(
                str_replace('/', '.', trim($this->crudRouteBase() . '/export', '/'))
            );
        }

        return json($schema);
    }
}

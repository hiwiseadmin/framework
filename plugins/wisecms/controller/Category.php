<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\exception\ErrorCode;
use plugin\BaseController;
use plugin\wisecms\service\content\CategoryService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\model\Category as CategoryModel;
use plugin\wisecms\model\Model as ModelModel;
class Category extends BaseController
{
    protected CategoryService $categoryService;

    protected function initPlugin(): void
    {
        $this->categoryService = new CategoryService();
    }

    /**
     * 后台当前站点（P2 站点选择器：会话上下文，见 SiteService::resolveAdminSiteId）
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /**
     * 校验栏目属于后台当前站点（越界按「栏目不存在」拒绝，不暴露存在性）
     */
    protected function assertSiteOwned(int $id): void
    {
        if (!CategoryModel::where('id', $id)->where('site_id', $this->siteId())->find()) {
            throw new WisecmsException('栏目不存在', ErrorCode::NOT_FOUND);
        }
    }

    /**
     * 栏目列表
     */
    public function index()
    {
        // P2：栏目树按「后台当前站点」取数 —— 原实现信任 `?site_id=` 请求参数（P1-B3 已取消该信任），
        // 且默认值硬编码 1，切站后列表不变
        $siteId = $this->siteId();
        $tree = $this->categoryService->getTree($siteId);

        if ($this->request->isAjax()) {
            return $this->success('', $tree);
        }

        $this->assign('tree', $tree);
        // 模板需要扁平化行数据（含 level / model_name）用于表格渲染
        $this->assign('rows', $this->categoryService->getRows($siteId));
        return $this->fetch('admin/category/index');
    }

    /**
     * 后台栏目树（**含禁用栏目** + `model_name`）—— UIUX-P0-47
     *
     * 为什么不直接用 `index` 的 AJAX 分支：它复用的是前台口径的 `getTree()`，
     * 硬过滤 `status = 1`（`model/Category.php:57-61`）→ 禁用栏目在后台树里"消失"，
     * 既看不到也无法在树上恢复；且该分支不含 `model_name`、也没有 `level`。
     *
     * 返回 `data = { rows: 扁平行[], tree: 嵌套树[] }`：
     *   - `rows` 供「栏目管理」树表（`parentIdField` 型）直接消费；
     *   - `tree` 供「内容管理」左侧栏目树消费。
     *
     * 站点归属由 `siteId()`（会话上下文）决定，不接受 `?site_id=` 伪造。
     */
    public function tree()
    {
        return $this->success('', $this->categoryService->getAdminTree($this->siteId()));
    }

    /**
     * 创建栏目
     */
    public function create()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            // 验证
            if (empty($data['name'])) {
                return $this->error('栏目名称不能为空');
            }
            // P2：站点归属由服务端注入（覆盖表单可能伪造的 site_id）
            $data['site_id'] = $this->siteId();
            try {
                $category = $this->categoryService->create($data);
                return $this->success('栏目创建成功', $category);
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }

        $parentId = $this->request->param('parent_id', 0);
        $parentOptions = $this->categoryService->getOptions();
        $this->assign('parentOptions', $parentOptions);
        $this->assign('parentId', $parentId);
        $this->assign('modelOptions', $this->getModelOptions());
        return $this->fetch('admin/category/create');
    }

    /**
     * 编辑栏目
     */
    public function edit()
    {
        $id = $this->request->param('id');
        $category = CategoryModel::find($id);
        if (!$category) {
            return $this->error('栏目不存在');
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            // P2：站点归属不随编辑变更（栏目不能在编辑时被整体迁到别的站点）
            unset($data['site_id']);
            try {
                $category = $this->categoryService->update($id, $data);
                return $this->success('栏目更新成功', $category);
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }

        $parentOptions = $this->categoryService->getOptions(1, $id);
        $this->assign('category', $category);
        $this->assign('parentOptions', $parentOptions);
        $this->assign('modelOptions', $this->getModelOptions());
        return $this->fetch('admin/category/edit');
    }

    /**
     * 可绑定内容模型下拉项
     */
    protected function getModelOptions(): array
    {
        return ModelModel::where('status', 1)->field('id,title')->order('sort', 'asc')->select()->toArray();
    }

    /**
     * 删除栏目
     */
    public function delete()
    {
        $id = $this->request->post('id');
        try {
            // P2：只允许删除当前站点的栏目
            $this->assertSiteOwned((int) $id);
            $this->categoryService->delete((int)$id);
            return $this->success('栏目删除成功');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 切换状态
     */
    public function toggleStatus()
    {
        $id = $this->request->post('id');
        $category = CategoryModel::find($id);
        // P2：只允许切换当前站点栏目的状态
        if (!$category || (int) $category->site_id !== $this->siteId()) {
            return $this->error('栏目不存在');
        }
        // 传入 status 时按**目标值**设置（幂等），未传则维持"取反"的老语义。
        // 背景（UIUX-P0-11）：EnhancedTable 的 toggle formatter 发的就是目标值
        // （`data-params="id=..&status=1"`）；若仍只做取反，重复提交/并发点击会把状态
        // 翻回去 —— 开关看起来随机失灵。旧调用点（category/index.html 只发 id）不受影响。
        $target = $this->request->post('status', null);
        $category->status = ($target === null || $target === '')
            ? ($category->status == 1 ? 0 : 1)
            : ((int) $target === 1 ? 1 : 0);
        $category->save();

        // UIUX-P0-47 附带修复：status 参与前台栏目树口径（`getTree()` 硬过滤 status=1），
        // 而此前只有 create/update 会清 `KEY_CATEGORY_TREE`，toggle 遗漏 —— 结果是
        // 「关闭栏目」后前/后台栏目树最长 1 小时不更新（表现为点了开关像没生效）。
        $this->categoryService->clearCache((int) $category->site_id);

        return $this->success('状态更新成功');
    }

    /**
     * 批量启用 / 禁用栏目（UIUX-P1-19，目标值语义 + 站点过滤）
     */
    public function batchToggle()
    {
        $ids    = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids/a', [])))));
        $status = ((int) $this->request->post('status', 1)) === 1 ? 1 : 0;

        if (empty($ids)) {
            return $this->error('请选择要操作的栏目');
        }

        // 站点过滤：只批量切换本站栏目（越界静默剔除）
        $owned = CategoryModel::whereIn('id', $ids)->where('site_id', $this->siteId())->column('id');
        if (empty($owned)) {
            return $this->error('所选栏目均不属于当前站点');
        }

        CategoryModel::whereIn('id', $owned)->update(['status' => $status]);
        $this->categoryService->clearCache($this->siteId());

        return $this->success('已更新 ' . count($owned) . ' 个栏目');
    }
}

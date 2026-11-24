<?php
namespace app\common\controller\traits;

use app\common\service\ConfirmTokenService;
use app\common\service\ExportJobService;
use app\common\service\ExportService;
use app\common\service\QueueService;
use think\Model;
use think\facade\Db;

/**
 * CRUD 通用操作 Trait（替代 BasicAction）
 *
 * 设计原则：
 * - 通过 abstract getModel() 显式声明模型依赖，替代隐式的 $this->model
 * - 内置 before/after 钩子，消除控制器中的重复事务/校验代码
 * - API 优先：所有方法返回 JsonResponse 统一格式，不强制模板渲染
 * - 子类只需覆盖钩子和扩展点，无需重写整个 CRUD 方法
 * - 可选 Schema 校验：覆盖 validateBySchema() 即可接入 wise-form-schema 校验
 *
 * ## 实现层与路由层分离（重要）
 *
 * 本 Trait 只提供 **protected 实现**（`_index/_getList/_save/...`）：
 *
 * - `_xxx()` 不参与路由，不会因为 `use CrudTrait` 自动暴露给后台或 AI；
 * - 动作是否存在，由控制器自己的 `public xxx()` 唯一决定（人工与 AI 同一入口）；
 * - `public xxx()` 默认一行委托即可：
 *
 * ```php
 * public function save()
 * {
 *     return $this->_save();
 * }
 * ```
 *
 * 需要业务逻辑或操作日志时，在 public 入口上扩展（`#[OperationLog]` 只作用于入口）。
 * 未声明 public 方法的动作 = 该控制器不开放该动作（fail-closed）。
 *
 * ## 回收站（软删记录）
 *
 * 回收站不是独立能力，而是 `_restore()/_forceDelete()` 的**列表视图**，因此同处本 Trait：
 *
 * ```php
 * public function recycleList()        { return $this->_recycleList(); }
 * public function recycleToken()       { return $this->_recycleToken(); }
 * public function recycleRestore()     { return $this->_restore(); }
 * public function recycleForceDelete() { return $this->_forceDelete(true); }
 * ```
 *
 * 控制器写了 `recycleList()`，`CrudSchemaTrait::crudUrls()` 才会把回收站端点下发给前端
 * （与 `export` 同一套按需下发约定），前端据此决定是否渲染「回收站」按钮。
 * 模型未启用软删除时 `supportsRecycle()` 为 false，相关动作返回明确错误而非空列表。
 *
 * 前置条件：
 * - 使用本 Trait 的类必须同时使用 JsonResponse Trait
 * - 使用本 Trait 的类必须拥有 $request 属性（继承 BaseController 即可）
 *
 * @method string fetch(string $template = '', array $vars = [], array $config = []) 渲染模板（由 BaseController 提供）
 * @method $this assign(string|array $name, mixed $value = null) 模板变量赋值（由 BaseController 提供）
 * @method \think\response\Json success(string $msg = '', mixed $data = [], int $code = 0) 成功响应（由 JsonResponse Trait 提供）
 * @method \think\response\Json error(string $msg = '', mixed $data = [], int $code = 1) 错误响应（由 JsonResponse Trait 提供）
 * @method \think\Model applyQueryToModel(\think\Model $model, array $options = []) 应用查询参数（由 RequestQueryBuild Trait 提供）
 * @method array buildQueryParams(array $keys) 构建查询参数（由 RequestQueryBuild Trait 提供）
 *
 * @property \think\Request $request 请求对象（由 BaseController 提供）
 *
 * 使用示例：
 * ```php
 * class Article extends BaseController
 * {
 *     use CrudTrait; // 或单独 use CrudAction + RequestQueryBuild
 *
 *     protected function getModel(): Model { return new ArticleModel(); }
 *
 *     // 可选：在保存前处理数据
 *     protected function beforeSave(array &$data, bool $isNew): void {
 *         if ($isNew) $data['admin_id'] = CurrentAdmin::getId();
 *     }
 * }
 * ```
 */
trait CrudAction
{
    /**
     * 获取模型实例（子类必须实现）
     * @return Model
     */
    abstract protected function getModel(): Model;

    /**
     * 首页
     * @return string
     */
    protected function _index()
    {
        return $this->fetch();
    }

    /**
     * 新增页面
     *
     * 模板变量：
     * - $isEdit (bool)     false
     * - $row (null)        无记录
     * - $formAction (string) url('save')
     * - $pageTitle (string) '添加'
     *
     * 子类可覆盖此方法添加自定义变量赋值。
     *
     * @return string
     */
    protected function _add()
    {
        $this->assign('isEdit', false);
        $this->assign('row', null);
        $this->assign('formAction', url('save'));
        $this->assign('pageTitle', '添加');

        return $this->fetch('form');
    }

    /**
     * 编辑页面
     *
     * 通过 URL 参数 ?id= 加载记录。
     *
     * 模板变量：
     * - $isEdit (bool)     true
     * - $row (Model)       加载的记录
     * - $formAction (string) url('update')
     * - $pageTitle (string) '编辑'
     *
     * 子类可覆盖此方法添加自定义变量赋值。
     *
     * @return string|\think\response\Json
     */
    protected function _edit()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('ID不能为空');
        }

        $row = $this->getModel()->find($id);
        if (!$row) {
            return $this->error('记录不存在');
        }

        $this->assign('isEdit', true);
        $this->assign('row', $row);
        $this->assign('formAction', url('update'));
        $this->assign('pageTitle', '编辑');

        return $this->fetch('form');
    }


    /**
     * 构建列表查询（唯一入口，`_getList()` 与 `_export()` 共用）
     *
     * 抽出的动机：导出必须与列表使用**完全一致**的筛选、排序与业务钩子
     * （`buildSearchQuery()` 由控制器覆盖），否则「导出结果与页面不一致」
     * 会成为长期缺陷。禁止在两处各写一套查询构建。
     *
     * @return Model
     */
    protected function buildListQuery()
    {
        $model = $this->getModel();

        $query = $this->applyQueryToModel($model, ['withoutPage' => true]);

        return $this->buildSearchQuery($query);
    }

    /**
     * 通用分页列表
     * @return \think\response\Json
     */
    protected function _getList()
    {
        try {
            $query          = $this->buildListQuery();
            [$page, $limit] = $this->buildQueryParams(['page', 'limit']);

            $data = $query->paginate(['list_rows' => $limit, 'page' => $page])->toArray();

            return $this->success('操作成功', $this->formatPaginate($data));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 通用单条预览
     * @return string|\think\response\Json
     */
    protected function _preview()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('ID不能为空');
        }
        try {
            $model = $this->getModel();
            $item  = $model->find($id);
            if (!$item) {
                return $this->error('记录不存在');
            }
            return $this->assign('item', $item)->fetch();
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }


    /**
     * 通用导出（服务端 CSV）
     *
     * 与 `_getList()` 共用 `buildListQuery()`，保证筛选/排序/业务钩子完全一致。
     * 导出对象由 `getModel()` 决定，**不接受前端传模型名**（防跨模块越权取数）。
     *
     * 参数：
     *   - scope    page | filter | selected（默认 filter）
     *   - ids[]    scope=selected 时的主键集合（支持跨页勾选）
     *   - format   csv（默认；xlsx 待可选驱动接入）
     *   - columns  JSON 数组 [{field,title}]，后端按表字段白名单 + 敏感字段黑名单校验
     *   - filename 自定义文件名（不含扩展名）
     *
     * 权限：由 `/admin/{controller}/export` 独立节点在 RBAC 中间件校验，
     * 本方法内不重复判断（与项目既有约定一致）。
     *
     * @return \think\Response|\think\response\Json
     */
    protected function _export()
    {
        $service = new ExportService();
        $path    = '';

        try {
            $model  = $this->getModel();
            $pk     = $model->getPk();
            $scope  = (string) $this->request->param('scope', 'filter');
            $format = strtolower((string) $this->request->param('format', 'csv'));
            $ids    = $this->resolveExportIds();

            if (!in_array($scope, ['page', 'filter', 'selected'], true)) {
                return $this->error('导出范围不合法');
            }

            if ($format !== 'csv') {
                return $this->error('当前环境仅支持 CSV 导出');
            }

            if ($scope === 'page') {
                [$page, $limit] = $this->buildQueryParams(['page', 'limit']);
                $ids = array_map('intval', (array) $this->buildListQuery()->page($page, $limit)->column($pk));

                if (empty($ids)) {
                    return $this->error('当前页没有可导出的数据');
                }
            }

            if ($scope === 'selected' && empty($ids)) {
                return $this->error('请先勾选要导出的记录');
            }

            $columns = $service->resolveColumns($model->getTableFields(), $this->parseExportColumns());

            $countQuery = $this->buildListQuery();
            if (!empty($ids)) {
                $countQuery->whereIn($pk, $ids);
            }

            $total  = (int) $countQuery->count();
            $maxRow = (int) config('crud.export.max_rows', 50000);

            if ($total === 0) {
                return $this->error('没有符合条件的可导出数据');
            }
            if ($total > $maxRow) {
                return $this->error(sprintf('本次导出 %d 行，超过单次上限 %d 行，请缩小筛选范围', $total, $maxRow));
            }

            $baseName = trim((string) $this->request->param('filename', ''));
            if ($baseName === '') {
                $baseName = (string) $model->getName();
            }

            $asyncThreshold = (int) config('crud.export.async_threshold', 5000);
            if ($asyncThreshold > 0 && $total > $asyncThreshold) {
                return $this->dispatchExportTask($model, $columns, $ids, $total, $baseName);
            }

            $target = $service->makeTarget($baseName, $this->exportAdminId(), 'csv');
            $path   = $target['path'];

            $rows = $service->csv(
                $path,
                function () {
                    return $this->buildListQuery();
                },
                $columns,
                ['ids' => $ids, 'pk' => $pk]
            );

            $this->recordExportAudit((string) $model->getName(), $scope, $rows, $target['filename']);

            return response('', 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . rawurlencode($target['filename']) . '"',
                'Content-Length'      => (string) filesize($path),
                'Cache-Control'       => 'no-store, no-cache, must-revalidate',
                'Pragma'              => 'no-cache',
            ])->sendfile($path);
        } catch (\Throwable $e) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }

            return $this->error($e->getMessage());
        }
    }

    /**
     * 提交异步导出任务（行列超过阈值时）
     *
     * 关键取舍：**主键快照**而不是在 Worker 里重建查询。
     *   - `buildSearchQuery()` 等控制器钩子的结果无法在 CLI 中复现（没有 Request 上下文）；
     *   - 快照同时保管了列表的排序，导出文件顺序与页面完全一致；
     *   - 50 万行以内的 id 快照（约 3–4 MB）远小于把全量数据塞进 payload。
     *
     * @param Model                $model    导出模型（提供表字段与主键）
     * @param array<string, string> $columns  field => title
     * @param array<int, int>       $ids      scope=page/selected 时的主键集合
     * @param int                   $total    行数（已校验 ≤ max_rows）
     * @param string                $baseName 文件名（不含扩展名）
     * @return \think\response\Json
     */
    protected function dispatchExportTask(Model $model, array $columns, array $ids, int $total, string $baseName)
    {
        $pk      = (string) $model->getPk();
        $adminId = $this->exportAdminId();

        $snapshotQuery = $this->buildListQuery();
        if (!empty($ids)) {
            $snapshotQuery->whereIn($pk, $ids);
        }

        $allIds = array_map('intval', (array) $snapshotQuery->column($pk));
        if (empty($allIds)) {
            return $this->error('没有符合条件的可导出数据');
        }

        $dir = runtime_path() . 'export' . DIRECTORY_SEPARATOR . ($adminId > 0 ? $adminId : 0) . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->error('无法创建导出目录，请检查 runtime 权限');
        }

        $snapshotFile = $dir . 'task_' . uniqid('', true) . '.ids.json';
        if (file_put_contents($snapshotFile, (string) json_encode($allIds)) === false) {
            return $this->error('导出快照写入失败，请稍后重试');
        }

        $task = class_exists(ExportJobService::class)
            ? QueueService::instance()->dispatch(
                ExportJobService::HANDLER,
                [
                    'model'    => get_class($model),
                    'ids_file' => $snapshotFile,
                    'columns'  => $columns,
                    'pk'       => $pk,
                    'filename' => $baseName,
                    'admin_id' => $adminId,
                    'total'    => count($allIds),
                ],
                [
                    'name'       => $baseName . ' 导出',
                    'created_by' => $adminId,
                ]
            )
            : null;

        if ($task === null) {
            if (is_file($snapshotFile)) {
                @unlink($snapshotFile);
            }

            return $this->error('队列不可用，导出任务提交失败；请稍后重试或缩小筛选范围');
        }

        $this->recordExportAudit((string) $model->getName(), 'async', count($allIds), $baseName);

        return $this->success('已提交导出任务，完成后可在「下载中心」下载', [
            'async'        => true,
            'task_id'      => (int) $task->getAttr('id'),
            'task_no'      => (string) $task->getAttr('task_no'),
            'total'        => count($allIds),
            'download_url' => function_exists('url') ? (string) url('admin/download/index') : '/admin/download/index',
        ]);
    }

    /**
     * 解析导出主键集合（兼容 ids[] 与单 id）
     *
     * @return array<int, int>
     */
    protected function resolveExportIds(): array
    {
        $ids = $this->request->param('ids/a', []);

        if (!is_array($ids) || empty($ids)) {
            $single = (int) $this->request->param('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        $ids = array_map('intval', (array) $ids);

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * 解析前端声明的导出列
     *
     * 前端只提供「字段 + 标题」，安全边界由 ExportService 的白名单/黑名单兜底。
     *
     * @return array<int, array{field?: string, title?: string}>
     */
    protected function parseExportColumns(): array
    {
        $raw = $this->request->param('columns', '');

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * 当前操作人 ID（非 admin 上下文返回 0）
     */
    protected function exportAdminId(): int
    {
        if (function_exists('auth_id')) {
            return (int) (auth_id('admin') ?: 0);
        }

        return 0;
    }

    /**
     * 写入导出审计上下文（供 `#[OperationLog]` 模板消费）
     *
     * 模板示例：
     *   #[OperationLog(template: '导出 {context.export_model}（{context.export_scope}），共 {context.export_rows} 行')]
     *
     * 只记录「模型 / 范围 / 行数 / 文件名」，**不含任何数据行**（DoD 硬性）。
     */
    protected function recordExportAudit(string $model, string $scope, int $rows, string $filename): void
    {
        if (!class_exists(\wise\operationlog\context\LogContext::class)) {
            return;
        }

        \wise\operationlog\context\LogContext::set('export_model', $model);
        \wise\operationlog\context\LogContext::set('export_scope', $scope);
        \wise\operationlog\context\LogContext::set('export_rows', (string) $rows);
        \wise\operationlog\context\LogContext::set('export_file', $filename);
    }


    /**
     * 通用新增保存
     * @return \think\response\Json
     */
    protected function _save()
    {
        $data = $this->request->param();
        unset($data['id']);

        $model = $this->getModel();

        try {
            $data = $this->filterDataGeneric($model, $data);
            $data = $this->validateBySchema($data, true);
            $this->beforeSave($data, true);

            Db::startTrans();
            $result = $model->save($data);
            if ($result === false) {
                Db::rollback();
                return $this->error('新增失败');
            }

            $this->afterSave($model, true);
            Db::commit();

            return $this->success('新增成功', ['id' => $model->{$model->getPk()}]);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 通用更新（单条 / 批量）
     *
     * 支持 id 或 ids 参数，单条更新时触发 beforeSave/afterSave 钩子。
     * @return \think\response\Json
     */
    protected function _update()
    {
        $id   = $this->request->param('id/d', 0);
        $ids  = $this->request->param('ids/a', []);
        $data = $this->request->param();
        unset($data['id'], $data['ids']);

        if (!$id && empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        $model = $this->getModel();
        $data  = $this->filterDataGeneric($model, $data);

        $isSingle = count($ids) === 1;
        try {
            if ($isSingle) {
                $record = $model->find($ids[0]);
                if (!$record) {
                    return $this->error('记录不存在');
                }
                $data = $this->validateBySchema($data, false);
                $this->beforeSave($data, false);

                Db::startTrans();
                $record->save($data);
                $this->afterSave($record, false);
                Db::commit();
            } else {
                $model->whereIn($model->getPk(), $ids)->update($data);
            }

            return $this->success('操作成功');
        } catch (\Exception $e) {
            if ($isSingle) {
                Db::rollback();
            }
            return $this->error($e->getMessage());
        }
    }


    /**
     * 通用删除（软删除 / 硬删除取决于模型是否开启 SoftDelete）
     * @return \think\response\Json
     */
    protected function _delete()
    {
        $id  = $this->request->param('id/d', 0);
        $ids = $this->request->param('ids/a', []);

        if (!$id && empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        $model = $this->getModel();

        try {
            $this->beforeDelete($ids);

            Db::startTrans();
            $model->whereIn($model->getPk(), $ids)->select()->delete();
            $this->afterDelete($ids);
            Db::commit();

            return $this->success('操作成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 通用恢复（仅对启用软删除的模型生效）
     *
     * 实现要点：
     *   - 只处理**已在回收站**的记录（`onlyTrashed()`），避免把在线记录当成待恢复条目；
     *   - 逐条 `restore()` 而非批量 SQL：走模型 `BeforeRestore/AfterRestore` 事件，
     *     且不依赖可选的 `app\common\model\traits\SoftDeleteTrait::restoreByIds()`
     *     （多数模型只引了 `think\model\concern\SoftDelete`，没有该方法）；
     *   - 模型未启用软删除 → 明确报错，而不是静默返回「未找到」。
     *
     * @return \think\response\Json
     */
    protected function _restore()
    {
        $id  = $this->request->param('id/d', 0);
        $ids = $this->request->param('ids/a', []);

        if (!$id && empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        if (!$this->supportsRecycle()) {
            return $this->error('当前模型未启用软删除，不支持恢复操作');
        }

        $model = $this->getModel();
        $pk    = (string) $model->getPk();

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
        if (empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }

        try {
            $records = $model->onlyTrashed()->whereIn($pk, $ids)->select();

            if ($records->isEmpty()) {
                return $this->success('未找到可恢复的记录', ['restored' => 0]);
            }

            $restoredIds = [];
            $restored    = 0;

            Db::startTrans();
            foreach ($records as $record) {
                $record->restore();
                $restoredIds[] = (int) $record->getData($pk);
                $restored++;
            }
            Db::commit();

            $this->afterRestore($restoredIds);

            return $this->success('恢复成功', ['restored' => $restored]);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 通用强制删除（绕过软删除）
     *
     * 与 `_delete()` 保持同一套钩子语义：`beforeDelete()` 做保护性检查
     * （如禁止删除超管、清理关联权限），`afterDelete()` 做关联清理。
     * 物理删除不可恢复，因此钩子与事务缺一不可。
     *
     * @param bool $fromRecycle 回收站入口：只允许删除**已软删**记录，且强制校验一次性确认令牌
     *                          （不传时保持原有语义：按主键物理删除，不做令牌校验）
     * @return \think\response\Json
     */
    protected function _forceDelete(bool $fromRecycle = false)
    {
        $id  = $this->request->param('id/d', 0);
        $ids = $this->request->param('ids/a', []);

        if (!$id && empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        $model = $this->getModel();
        $pk    = (string) $model->getPk();

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
        if (empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }

        if ($fromRecycle) {
            if (!$this->supportsRecycle()) {
                return $this->error('当前模型未启用软删除，不支持彻底删除');
            }

            $token = trim((string) $this->request->param('confirm_token', ''));
            if (!(new ConfirmTokenService())->verify($this->recycleTokenScene(), $token, $this->recycleTokenContext())) {
                return $this->error('确认已失效，请重新确认后操作');
            }

            $ids = array_values(array_map('intval', (array) $model->onlyTrashed()->whereIn($pk, $ids)->column($pk)));
            if (empty($ids)) {
                return $this->success('未找到可彻底删除的记录', ['deleted' => 0]);
            }
        }

        try {
            $this->beforeDelete($ids);

            Db::startTrans();

            $query   = $this->supportsRecycle() ? $model->withTrashed() : $model;
            $records = $query->whereIn($pk, $ids)->select();

            $deleted = 0;
            foreach ($records as $record) {
                $record->force()->delete();
                $deleted++;
            }

            $this->afterDelete($ids);
            Db::commit();

            return $this->success('操作成功', ['deleted' => $deleted]);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }


    /**
     * 通用回收站列表（仅已软删记录）
     *
     * 与 `_getList()` 同构：同一套分页参数、同一套 `formatPaginate()` 响应结构，
     * 前端把表格 url 换成本端点即可复用列 / 工具栏配置。
     *
     * 与 `_getList()` 的关键差别是**能力前置校验**：模型未启用软删除时返回明确错误，
     * 而不是空列表 —— 空列表会让「回收站没数据」与「该模块不支持回收站」无法区分。
     *
     * 模块可在子类覆写 `buildRecycleQuery()` 追加业务过滤（如 site_id / parent_id）。
     *
     * @return \think\response\Json
     */
    protected function _recycleList()
    {
        if (!$this->supportsRecycle()) {
            return $this->error('当前模型未启用软删除，不支持回收站');
        }

        $model = $this->getModel();

        try {
            [$page, $limit] = $this->buildQueryParams(['page', 'limit']);

            $query = $this->buildRecycleQuery($model->onlyTrashed());
            $query->order($this->recycleDeleteTimeField($model), 'desc');

            $data = $query->paginate(['list_rows' => $limit, 'page' => $page])->toArray();

            return $this->success('操作成功', $this->formatPaginate($data));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 签发「彻底删除」的一次性确认令牌（回收站入口用）
     *
     * 令牌场景固定、上下文绑定当前控制器类，不能跨模块复用。
     *
     * @return \think\response\Json
     */
    protected function _recycleToken()
    {
        if (!$this->supportsRecycle()) {
            return $this->error('当前模型未启用软删除，不支持回收站');
        }

        $token = (new ConfirmTokenService())->issue($this->recycleTokenScene(), $this->recycleTokenContext());

        return $this->success('操作成功', ['confirm_token' => $token]);
    }

    /**
     * 当前模型是否支持回收站（是否启用软删除）
     *
     * 判定依据必须是 SoftDelete concern 的**真实方法**：`onlyTrashed()` 是 Query 的
     * 魔术作用域（`@method` 注解 + `__call` 派发），`method_exists()` 探测不到。
     */
    protected function supportsRecycle(): bool
    {
        $model = $this->getModel();

        if (!method_exists($model, 'trashed') || !method_exists($model, 'restore')) {
            return false;
        }

        $field = $model->getDeleteTimeField();

        return is_string($field) && $field !== '';
    }

    /**
     * 回收站列表查询钩子（子类覆写：追加业务过滤、关联预载）
     *
     * @param \think\db\BaseQuery $query 已应用 `onlyTrashed()` 的查询
     * @return \think\db\BaseQuery
     */
    protected function buildRecycleQuery($query)
    {
        return $query;
    }

    /** 回收站破坏性动作的确认场景（令牌按场景隔离） */
    protected function recycleTokenScene(): string
    {
        return 'crud.force_delete';
    }

    /** 确认令牌上下文：绑定控制器，防止 A 模块签发的令牌被用到 B 模块 */
    protected function recycleTokenContext(): array
    {
        return ['controller' => static::class];
    }

    /** 软删除时间字段（兼容模型自定义 deleteTime） */
    protected function recycleDeleteTimeField(Model $model): string
    {
        if (method_exists($model, 'getDeleteTimeField')) {
            $field = $model->getDeleteTimeField();

            if (is_string($field) && $field !== '') {
                return $field;
            }
        }

        return 'delete_time';
    }


    /**
     * 通用状态更新
     * @return \think\response\Json
     */
    protected function _status()
    {
        $id     = $this->request->param('id/d', 0);
        $ids    = $this->request->param('ids/a', []);
        $status = $this->request->param('status/d', -1);

        if (!$id && empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }
        if ($status < 0) {
            return $this->error('状态值不能为空或负数');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        $model = $this->getModel();

        try {
            $model->whereIn($model->getPk(), $ids)->update(['status' => $status]);
            return $this->success('操作成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 通用排序
     *
     * 支持单条或批量更新排序值。
     * 参数：
     *   - id / ids   目标记录
     *   - sort       排序值（整数）
     *   - sort_field 排序字段名（默认 sort）
     *
     * @return \think\response\Json
     */
    protected function _sort()
    {
        $id        = $this->request->param('id/d', 0);
        $ids       = $this->request->param('ids/a', []);
        $sort      = $this->request->param('sort/d', -1);
        $sortField = $this->request->param('sort_field', 'sort');

        if (!in_array($sortField, ['sort', 'weigh', 'order_weight'], true)) {
            return $this->error('非法的排序字段');
        }

        if (!$id && empty($ids)) {
            return $this->error('id或ids必须至少提供一个');
        }
        if ($sort < 0) {
            return $this->error('排序值不能为空或负数');
        }
        if ($id) {
            $ids = array_merge($ids, [$id]);
        }

        $model = $this->getModel();

        try {
            $model->whereIn($model->getPk(), $ids)->update([$sortField => $sort]);
            return $this->success('排序成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }


    /**
     * 格式化分页数据
     * @param array $paginator paginate()->toArray() 结果
     * @return array
     */
    public function formatPaginate(array $paginator): array
    {
        return [
            'total' => $paginator['total'] ?? 0,
            'list'  => $paginator['data'] ?? [],
            'page'  => $paginator['current_page'] ?? 1,
            'limit' => $paginator['per_page'] ?? 20,
        ];
    }

    /**
     * 过滤非数据表字段和只读字段
     *
     * 若模型声明了可写字段白名单（BaseModel::$allowWriteField），
     * 则仅保留白名单内的字段，杜绝批量赋值（Mass Assignment）。
     *
     * @param Model $model
     * @param array $data
     * @return array
     */
    protected function filterDataGeneric(Model $model, array $data): array
    {
        $tableFields = $model->getTableFields();

        $allowField = method_exists($model, 'getAllowWriteFields')
            ? $model->getAllowWriteFields()
            : null;

        foreach ($data as $k => $v) {
            if (!in_array($k, $tableFields)) {
                unset($data[$k]);
                continue;
            }
            if (is_array($allowField) && !in_array($k, $allowField, true)) {
                unset($data[$k]);
            }
        }

        if (property_exists($model, 'readonly') && is_array($model->readonly)) {
            foreach ($model->readonly as $field) {
                unset($data[$field]);
            }
        }

        return $data;
    }


    /**
     * 构建搜索条件（在 applyQueryToModel 之后、分页之前调用）
     * @param Model $query 已应用标准查询的模型实例
     * @return Model
     */
    protected function buildSearchQuery($query)
    {
        return $query;
    }

    /**
     * Schema 校验钩子（可选，默认不校验，保持向后兼容）。
     *
     * 调用时机：在 filterDataGeneric() 字段白名单过滤之后、beforeSave() 之前。
     * - save()   新增时调用（$isNew = true）
     * - update() 单条更新（提交 id）时调用（$isNew = false）
     * - 批量更新（提交 ids）不调用：批量操作通常是 status/sort 这类局部字段更新，
     *   用完整 Schema 校验会因缺失字段而误报
     *
     * 子类可覆盖本方法，接入 wise-form-schema 做规则校验与类型清洗。
     * 校验失败请抛出 \think\exception\ValidateException，会被 save()/update()
     * 统一转为 error 响应。
     *
     * 使用示例：
     * ```php
     * use wise\form\schema\DataFilter;
     * use wise\form\schema\Form;
     * use wise\form\schema\field\Text;
     * use wise\form\schema\ValidationGenerator;
     *
     * protected function validateBySchema(array $data, bool $isNew): array
     * {
     *     $form = new Form('article');
     *     $form->add(Text::make('title', '标题')->required());
     *     $form->add(Text::make('company_name', '企业名称')->requiredIf('type', '=', 'company'));
     *
     *     $schema = $form->toArray();
     *
     *     // generateFor() 会依据 $data 求值 conditional_rules（条件必填）
     *     $validate = new \think\Validate(ValidationGenerator::generateFor($schema, $data));
     *     if (!$validate->check($data)) {
     *         throw new \think\exception\ValidateException($validate->getError());
     *     }
     *
     *     // 白名单过滤 + 类型转换
     *     return (new DataFilter())->filter($schema, $data);
     * }
     * ```
     *
     * @param array $data  已通过白名单过滤的待写入数据
     * @param bool  $isNew 是否新增
     * @return array 校验/清洗后的数据
     * @throws \think\exception\ValidateException 校验失败时抛出
     */
    protected function validateBySchema(array $data, bool $isNew): array
    {
        return $data;
    }

    /**
     * 保存前钩子（校验、数据预处理等）
     * @param array $data 引用传递，可在钩子中修改
     * @param bool  $isNew 是否新增
     */
    protected function beforeSave(array &$data, bool $isNew): void
    {
    }

    /**
     * 保存后钩子（关联操作、日志等）
     * @param Model $model 已保存的模型实例
     * @param bool  $isNew 是否新增
     */
    protected function afterSave(Model $model, bool $isNew): void
    {
    }

    /**
     * 删除前钩子（校验、前置检查等）
     * @param array $ids 待删除的主键 ID 列表
     */
    protected function beforeDelete(array $ids): void
    {
    }

    /**
     * 删除后钩子（清理关联、日志等）
     * @param array $ids 已删除的主键 ID 列表
     */
    protected function afterDelete(array $ids): void
    {
    }

    /**
     * 恢复后钩子（重建关联、日志等）
     *
     * 与 `afterDelete()` 对称：删除时清理掉的关联数据，恢复时需要重建
     * （典型场景：菜单删除会清理权限节点，还原后必须重新同步）。
     *
     * @param array $ids 已恢复的主键 ID 列表
     */
    protected function afterRestore(array $ids): void
    {
    }
}

<?php
namespace app\common\controller\traits;

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

    // ==================== 读取 ====================

    /**
     * 通用分页列表
     * @return \think\response\Json
     */
    protected function _getList()
    {
        try {
            $model      = $this->getModel();
            // 修复 P1-3: 传入 withoutPage 避免 applyQueryToModel 内部的 page()
            // 与下方的 paginate() 冲突（分页参数应用两次）
            $query      = $this->applyQueryToModel($model, ['withoutPage' => true]);
            $query      = $this->buildSearchQuery($query);
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

    // ==================== 写入 ====================

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
            // 单条更新时触发钩子
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

    // ==================== 删除 ====================

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
     * 通用恢复（仅对启用 SoftDelete 的模型生效）
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

        $model = $this->getModel();

        // 检查模型是否启用了 SoftDeleteTrait
        if (!method_exists($model, 'restoreByIds')) {
            return $this->error('当前模型不支持恢复操作');
        }

        try {
            $affected = $model->restoreByIds($ids);
            return $this->success($affected > 0 ? '恢复成功' : '未找到可恢复的记录');
        } catch (\Exception $e) {
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
     * @return \think\response\Json
     */
    protected function _forceDelete()
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
            $model->whereIn($model->getPk(), $ids)->select()->force()->delete();
            $this->afterDelete($ids);
            Db::commit();

            return $this->success('操作成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    // ==================== 状态 ====================

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

        // 排序字段白名单，防止任意字段名注入
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

    // ==================== 辅助方法 ====================

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

        // 优先应用模型声明的可写字段白名单（如有）
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

    // ==================== 钩子（子类按需覆盖） ====================

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
}

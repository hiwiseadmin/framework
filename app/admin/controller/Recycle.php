<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\ConfirmTokenService;
use app\common\service\RecycleRegistry;
use wise\operationlog\attribute\OperationLog;

/**
 * 全局回收站＼�
 *
 * 统一入口：跨模型查看已软删记录，按模型还原 / 彻底删除 / 清空。
 * 纳入范围由 `ExtPoints::RECYCLE_PROVIDER` 显式注册（宿主 config / 包 / 插件），
 * 未注册模型一律不可见（fail-closed，见任务卡验收 #6）。
 *
 * 安全要点：
 *   - 模型白名单：`resolveModel()` 只认 RecycleRegistry 内的模型（防 IDOR #10）；
 *   - 彻底删除 / 清空：后端校验一次性确认 token（DoD 硬性），独立权限；
 *   - 还原只还原记录本身，不做级联（第一版明确范围）。
 */
class Recycle extends BaseController
{
    /** 单页最大条数 */
    private const MAX_PAGE_SIZE = 100;

    /**
     * 回收站页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 已注册模型清单（前端下拉）
     */
    public function getModels()
    {
        $registry = new RecycleRegistry();
        $models   = [];

        foreach ($registry->all() as $class => $options) {
            $models[] = [
                'model' => $this->shortName($class),
                'group' => $options['group'],
                'title' => $options['title'],
            ];
        }

        return $this->success('ok', $models);
    }

    /**
     * 指定模型的已软删记录列表
     */
    public function getList()
    {
        $model = $this->resolveModel();
        if ($model === null) {
            return $this->error('模型未注册或不存在', [], 403);
        }

        $modelClass = $model['class'];
        $options    = $model['options'];
        $instance   = new $modelClass();
        $pk         = (string) $instance->getPk();
        $deleteTime = $this->deleteTimeField($instance);

        $page    = max(1, (int) $this->request->param('page', 1));
        $limit   = min(self::MAX_PAGE_SIZE, max(1, (int) $this->request->param('limit', 20)));
        $keyword = trim((string) $this->request->param('keyword', ''));

        $build = function () use ($modelClass, $deleteTime, $options, $keyword) {
            $query = $modelClass::onlyTrashed()->order($deleteTime, 'desc');

            if ($keyword !== '' && !empty($options['title'])) {
                $query->whereLike((string) $options['title'], '%' . addcslashes($keyword, '%_\\') . '%');
            }

            return $query;
        };

        $total = (int) $build()->count();
        $list  = $build()->page($page, $limit)->select();

        $rows = [];
        foreach ($list as $row) {
            $data = $row->toArray();
            $rows[] = [
                'id'          => $data[$pk] ?? null,
                'title'       => $data[$options['title'] ?? $pk] ?? ($data[$pk] ?? '-'),
                'delete_time' => $data[$deleteTime] ?? '',
                'fields'      => $this->pickFields($data, $options['fields']),
            ];
        }

        return $this->success('ok', [
            'total' => $total,
            'list'  => $rows,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 签发破坏性动作的确认令牌（force_delete / clear 用）
     */
    public function issueToken()
    {
        $scene = trim((string) $this->request->param('scene', ''));
        if (!in_array($scene, ['recycle.force_delete', 'recycle.clear'], true)) {
            return $this->error('场景不合法');
        }

        $model = $this->resolveModel();
        if ($model === null) {
            return $this->error('模型未注册或不存在', [], 403);
        }

        $token = (new ConfirmTokenService())->issue($scene, ['model' => $model['class']]);

        return $this->success('ok', ['confirm_token' => $token]);
    }

    /**
     * 还原（单个 / 批量）
     *
     * 只还原记录本身（清空软删时间），不做级联还原（第一版范围）。
     */
    #[OperationLog(template: '还原回收站记录 {context.recycle_model}，共 {context.recycle_rows} 条')]
    public function restore()
    {
        $model = $this->resolveModel();
        if ($model === null) {
            return $this->error('模型未注册或不存在', [], 403);
        }

        $modelClass = $model['class'];
        $ids        = $this->resolveIds();
        if (empty($ids)) {
            return $this->error('请选择要还原的记录');
        }

        $instance = new $modelClass();
        $pk       = (string) $instance->getPk();

        $list = $modelClass::onlyTrashed()->whereIn($pk, $ids)->select();
        if ($list->isEmpty()) {
            return $this->error('未找到可还原的记录');
        }

        $restored = 0;
        foreach ($list as $row) {
            $row->restore();
            $restored++;
        }

        $this->setRecycleContext($model['class'], (string) $restored);

        return $this->success("已还原 {$restored} 条记录", ['restored' => $restored]);
    }

    /**
     * 彻底删除（批量，物理删除，独立权限 + 二次确认）
     */
    #[OperationLog(template: '彻底删除回收站记录 {context.recycle_model}，共 {context.recycle_rows} 条', level: 'WARNING')]
    public function forceDelete()
    {
        $model = $this->resolveModel();
        if ($model === null) {
            return $this->error('模型未注册或不存在', [], 403);
        }

        $ids = $this->resolveIds();
        if (empty($ids)) {
            return $this->error('请选择要彻底删除的记录');
        }

        $token = trim((string) $this->request->param('confirm_token', ''));
        if (!(new ConfirmTokenService())->verify('recycle.force_delete', $token, ['model' => $model['class']])) {
            return $this->error('确认已失效，请重新确认后操作');
        }

        $modelClass = $model['class'];
        $instance   = new $modelClass();
        $pk         = (string) $instance->getPk();

        $list  = $modelClass::onlyTrashed()->whereIn($pk, $ids)->select();
        $count = 0;
        foreach ($list as $row) {
            $row->force()->delete();
            $count++;
        }

        $this->setRecycleContext($model['class'], (string) $count);

        return $this->success("已彻底删除 {$count} 条记录", ['deleted' => $count]);
    }

    /**
     * 清空指定模型的所有软删记录（最高权限 + 二次确认）
     */
    #[OperationLog(template: '清空回收站 {context.recycle_model}', level: 'WARNING')]
    public function clear()
    {
        $model = $this->resolveModel();
        if ($model === null) {
            return $this->error('模型未注册或不存在', [], 403);
        }

        $token = trim((string) $this->request->param('confirm_token', ''));
        if (!(new ConfirmTokenService())->verify('recycle.clear', $token, ['model' => $model['class']])) {
            return $this->error('确认已失效，请重新确认后操作');
        }

        $modelClass = $model['class'];
        $list       = $modelClass::onlyTrashed()->select();

        $count = 0;
        foreach ($list as $row) {
            $row->force()->delete();
            $count++;
        }

        $this->setRecycleContext($model['class'], (string) $count);

        return $this->success("已清空 {$count} 条记录", ['deleted' => $count]);
    }


    /**
     * 从 RecycleRegistry 白名单解析模型（未注册返回 null → 403）
     *
     * @return array{class: string, options: array}|null
     */
    protected function resolveModel(): ?array
    {
        $key = trim((string) $this->request->param('model', ''));

        if ($key === '') {
            return null;
        }

        return (new RecycleRegistry())->find($key);
    }

    /** 解析批量 ID（ids[] / id） */
    protected function resolveIds(): array
    {
        $ids = $this->request->param('ids/a', []);
        if (!is_array($ids) || empty($ids)) {
            $single = (int) $this->request->param('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /** 取模型的软删字段（默认 delete_time，兼容 deleted_at） */
    protected function deleteTimeField(\think\Model $model): string
    {
        if (method_exists($model, 'getDeleteTimeField')) {
            $field = $model->getDeleteTimeField();

            return is_string($field) && $field !== '' ? $field : 'delete_time';
        }

        return 'delete_time';
    }

    /** 模型类短名 */
    protected function shortName(string $class): string
    {
        try {
            return (new \ReflectionClass($class))->getShortName();
        } catch (\Throwable $e) {
            return $class;
        }
    }

    /** 挑出展示字段 */
    protected function pickFields(array $data, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $data[$field] ?? null;
        }

        return $out;
    }

    /** 写入操作日志变量池（供 #[OperationLog] 模板渲染） */
    protected function setRecycleContext(string $modelClass, string $rows): void
    {
        if (!class_exists(\wise\operationlog\context\LogContext::class)) {
            return;
        }

        \wise\operationlog\context\LogContext::set('recycle_model', $this->shortName($modelClass));
        \wise\operationlog\context\LogContext::set('recycle_rows', $rows);
    }
}

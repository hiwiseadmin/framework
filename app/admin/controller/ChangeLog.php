<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\ChangeLogService;
use app\common\service\ConfirmTokenService;
use wise\operationlog\attribute\OperationLog;

/**
 * 变更记录＼�
 *
 * 数据来源：`operation_log_changes`（采集侧 = 模型内 `use ChangeLoggable`）。
 *
 * 安全要点：
 *   - 回滚必须过独立权限节点 `admin/change_log/rollback`（菜单迁移补种）；
 *   - 回滚必须携带一次性确认令牌（DoD 硬性），令牌绑定 change_set；
 *   - 回滚模型白名单由 ChangeLogService 判定（只有显式接入的模型可回滚）；
 *   - 回滚动作本身写入审计（operation_log + 一条 action=rollback 的变更记录）。
 *
 * 路由：/admin/change_log/{action}，走 ThinkPHP 默认控制器路由。
 */
class ChangeLog extends BaseController
{
    /** 单页最大条数 */
    private const MAX_PAGE_SIZE = 100;

    /**
     * 页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 已采集模型清单（下拉）
     */
    public function getModels()
    {
        return $this->success('ok', (new ChangeLogService())->models());
    }

    /**
     * 变更组列表（按 change_set 聚合）
     */
    public function getList()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(self::MAX_PAGE_SIZE, max(1, (int) $this->request->param('limit', 20)));

        $filters = [
            'model'    => trim((string) $this->request->param('model', '')),
            'pk_value' => trim((string) $this->request->param('pk_value', '')),
            'field'    => trim((string) $this->request->param('field', '')),
            'operator' => trim((string) $this->request->param('operator', '')),
            'start'    => trim((string) $this->request->param('start', '')),
            'end'      => trim((string) $this->request->param('end', '')),
        ];

        return $this->success('ok', (new ChangeLogService())->queryList($filters, $page, $limit));
    }

    /**
     * 单条记录的变更时间线（含字段级 diff HTML）
     */
    public function timeline()
    {
        $model   = trim((string) $this->request->param('model', ''));
        $pkValue = trim((string) $this->request->param('pk_value', ''));

        if ($model === '' || $pkValue === '') {
            return $this->error('请指定模型与记录ID');
        }

        return $this->success('ok', (new ChangeLogService())->timeline($model, $pkValue));
    }

    /**
     * 签发回滚确认令牌
     */
    public function issueToken()
    {
        $changeSet = trim((string) $this->request->param('change_set', ''));
        if ($changeSet === '') {
            return $this->error('请指定要回滚的变更组');
        }

        $token = (new ConfirmTokenService())->issue('change_log.rollback', ['change_set' => $changeSet]);

        return $this->success('ok', ['confirm_token' => $token]);
    }

    /**
     * 回滚到该组变更之前
     */
    #[OperationLog(template: '回滚变更记录 {context.change_log_model}#{context.change_log_pk}，字段：{context.change_log_fields}', level: 'WARNING')]
    public function rollback()
    {
        $changeSet = trim((string) $this->request->param('change_set', ''));
        if ($changeSet === '') {
            return $this->error('请指定要回滚的变更组');
        }

        $token = trim((string) $this->request->param('confirm_token', ''));
        if (!(new ConfirmTokenService())->verify('change_log.rollback', $token, ['change_set' => $changeSet])) {
            return $this->error('确认已失效，请重新确认后操作');
        }

        try {
            $result = (new ChangeLogService())->rollback($changeSet);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('回滚失败：' . $e->getMessage());
        }

        $this->setRollbackContext($result);

        return $this->success('已回滚 ' . count($result['fields']) . ' 个字段', $result);
    }


    /**
     * 写入操作日志变量池（供 #[OperationLog] 模板渲染）
     *
     * @param array{model: string, pk_value: string, fields: array<int,string>} $result
     */
    protected function setRollbackContext(array $result): void
    {
        if (!class_exists(\wise\operationlog\context\LogContext::class)) {
            return;
        }

        $short = $result['model'];
        try {
            $short = (new \ReflectionClass($result['model']))->getShortName();
        } catch (\Throwable) {
        }

        \wise\operationlog\context\LogContext::set('change_log_model', $short);
        \wise\operationlog\context\LogContext::set('change_log_pk', (string) $result['pk_value']);
        \wise\operationlog\context\LogContext::set('change_log_fields', implode('、', $result['fields']));
    }
}

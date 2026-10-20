<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\admin\model\LoginLog as LoginLogModel;
use think\Request;
use think\response\Json;
use wise\api\model\ApiLog as ApiLogModel;
use wise\api\response\ErrorCode;
use wise\operationlog\model\OperationLogModel;

/**
 * 日志查询（后台 App API，全部只读）
 *
 * GET /api/admin/logs/operation  操作日志   ident: admin.operation_log.get_list
 * GET /api/admin/logs/login      登录日志   ident: admin.login_log.index
 * GET /api/admin/logs/api        接口日志   ident: admin.api_log.get_list
 *
 * 为什么日志适合做成 App 接口：
 *   纯只读、字段可白名单、无副作用，移动端「随时看异常」场景最实用。
 *   写操作（清理/导出）一律不做，避免误操作把审计证据删掉。
 *
 * 分页统一：page（默认 1）/ limit（默认 20，上限 100）。
 */
class Log extends Base
{
    protected const MAX_LIMIT = 100;

    /**
     * 列表输出字段白名单
     *
     * 刻意不含 params / extra / user_agent（体积大且可能含敏感入参），
     * 需要详情时走 Web 侧预览页。
     */
    protected const OPERATION_FIELDS = [
        'id', 'channel', 'level', 'content', 'duration_ms',
        'operator_id', 'operator_name', 'request_url', 'ip', 'tags', 'create_time',
    ];

    protected const LOGIN_FIELDS = [
        'id', 'admin_id', 'username', 'login_ip', 'location',
        'browser', 'os', 'status', 'message', 'login_time',
    ];

    protected const API_FIELDS = [
        'log_id as id', 'request_id', 'request_method', 'request_url', 'request_route',
        'response_status', 'duration_ms', 'request_ip', 'trace_id', 'channel', 'create_time',
    ];

    /**
     * 操作日志
     *
     * 参数：operator_name / content / channel / level / date_range（"2026-10-01 - 2026-10-20"）
     */
    public function operation(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $operatorName = trim((string) $request->param('operator_name', ''));
        $content      = trim((string) $request->param('content', ''));
        $channel      = trim((string) $request->param('channel', ''));
        $level        = strtoupper(trim((string) $request->param('level', '')));

        // 转义 LIKE 通配符（参数绑定防注入，但 % / _ 会被当成模式字符）
        $escapeLike = static fn (string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        $build = function () use ($operatorName, $content, $channel, $level, $escapeLike, $request) {
            $query = OperationLogModel::field(self::OPERATION_FIELDS);

            if ($operatorName !== '') {
                $query->where('operator_name', 'like', '%' . $escapeLike($operatorName) . '%');
            }

            if ($content !== '') {
                $query->where('content', 'like', '%' . $escapeLike($content) . '%');
            }

            if ($channel !== '') {
                $query->where('channel', $channel);
            }

            if ($level !== '') {
                $query->where('level', $level);
            }

            $this->applyDateRange($query, $request);

            return $query;
        };

        return $this->paginateList($build, 'id desc', $request);
    }

    /**
     * 登录日志
     *
     * 参数：username（模糊）/ status（1 成功 / 0 失败）/ date_range
     */
    public function login(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $username = trim((string) $request->param('username', ''));
        $status   = $request->param('status', '');

        $build = function () use ($username, $status, $request) {
            $query = LoginLogModel::field(self::LOGIN_FIELDS);

            if ($username !== '') {
                $query->where('username', 'like', '%' . $username . '%');
            }

            if ($status !== '' && $status !== null && (int) $status !== -1) {
                $query->where('status', (int) $status);
            }

            $this->applyDateRange($query, $request, 'login_time');

            return $query;
        };

        return $this->paginateList($build, 'id desc', $request);
    }

    /**
     * 接口日志
     *
     * 参数：request_method / response_status / request_route（模糊）/ request_ip（模糊）/ date_range
     */
    public function api(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $method = strtoupper(trim((string) $request->param('request_method', '')));
        $status = $request->param('response_status', '');
        $route  = trim((string) $request->param('request_route', ''));
        $ip     = trim((string) $request->param('request_ip', ''));

        $build = function () use ($method, $status, $route, $ip, $request) {
            $query = ApiLogModel::field(self::API_FIELDS);

            if ($method !== '') {
                $query->where('request_method', $method);
            }

            if ($status !== '' && $status !== null) {
                $query->where('response_status', (int) $status);
            }

            if ($route !== '') {
                $query->where('request_route', 'like', '%' . $route . '%');
            }

            if ($ip !== '') {
                $query->where('request_ip', 'like', '%' . $ip . '%');
            }

            $this->applyDateRange($query, $request);

            return $query;
        };

        return $this->paginateList($build, 'log_id desc', $request);
    }

    /**
     * 统一分页输出
     *
     * 用「条件闭包」而非 clone 查询对象：count 与 select 各建一次查询，
     * 避免聚合查询与字段白名单 / 排序互相污染。
     *
     * @param callable $build 返回未执行的条件查询
     * @param string   $order 排序
     */
    protected function paginateList(callable $build, string $order, Request $request): Json
    {
        [$page, $limit] = $this->pageParams($request);

        $total = (int) $build()->count();
        $list  = $total > 0
            ? $build()->order($order)->page($page, $limit)->select()->toArray()
            : [];

        return $this->success('ok', [
            'total' => $total,
            'list'  => $list,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 分页参数
     *
     * @return array{0: int, 1: int}
     */
    protected function pageParams(Request $request): array
    {
        $page  = max(1, (int) $request->param('page', 1));
        $limit = (int) $request->param('limit', 20);
        $limit = $limit < 1 ? 20 : min(self::MAX_LIMIT, $limit);

        return [$page, $limit];
    }

    /**
     * 时间范围过滤（"2026-10-01 - 2026-10-20"）
     *
     * @param mixed  $query
     * @param string $field
     */
    protected function applyDateRange($query, Request $request, string $field = 'create_time'): void
    {
        $range = (string) $request->param('date_range', '');
        if ($range === '') {
            return;
        }

        // 限定 2 段，避免缺少分隔符时解构产生告警
        $parts = explode(' - ', $range, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return;
        }

        $query->whereBetweenTime($field, $parts[0], $parts[1]);
    }
}

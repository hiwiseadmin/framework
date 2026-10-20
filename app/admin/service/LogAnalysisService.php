<?php
namespace app\admin\service;

use app\admin\model\LoginLog;
use wise\api\model\ApiLog;

class LogAnalysisService
{
    /**
     * 获取登录统计
     */
    public function getLoginStats($days = 7)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $stats = LoginLog::where('login_time', '>=', $startDate)
            ->field("DATE(login_time) as date,
                    COUNT(*) as total,
                    SUM(status = 1) as success,
                    SUM(status = 0) as failed")
            ->group('DATE(login_time)')
            ->order('date', 'desc')
            ->select();

        return $stats;
    }

    /**
     * 获取操作统计
     */
    public function getOperationStats($days = 7)
    {
        // 操作统计已迁移至 wise-operation-log 包
        return [];
    }

    /**
     * 获取 API 性能分析（对接 wise-api 的 api_log 表）
     */
    public function getApiPerformance($days = 7)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $performance = ApiLog::where('create_time', '>=', $startDate)
            ->field("request_route,
                    COUNT(*) as request_count,
                    AVG(duration_ms) as avg_response_time,
                    MAX(duration_ms) as max_response_time,
                    MIN(duration_ms) as min_response_time,
                    SUM(CASE WHEN response_status >= 400 THEN 1 ELSE 0 END) as error_count")
            ->group('request_route')
            ->having('request_count', '>', 0)
            ->order('avg_response_time', 'desc')
            ->select();

        return $performance;
    }

    /**
     * 安全风险检测
     */
    public function securityRiskDetection()
    {
        $risks = [];

        // 检测频繁失败登录
        $failedLogins = LoginLog::where('status', 0)
            ->where('login_time', '>=', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->field('login_ip, COUNT(*) as failed_count')
            ->group('login_ip')
            ->having('failed_count', '>=', 5)
            ->select();

        if (!$failedLogins->isEmpty()) {
            $risks[] = [
                'type' => '暴力破解',
                'level' => 'high',
                'message' => '检测到疑似暴力破解攻击',
                'details' => $failedLogins,
            ];
        }

        // 检测 API 异常（wise-api 表 - 高频错误接口）
        $abnormalApis = ApiLog::where('create_time', '>=', date('Y-m-d H:i:s', strtotime('-30 minutes')))
            ->where('response_status', '>=', 500)
            ->field('request_route, request_method, COUNT(*) as error_count')
            ->group('request_route, request_method')
            ->having('error_count', '>=', 3)
            ->select();

        if (!$abnormalApis->isEmpty()) {
            $risks[] = [
                'type' => 'API 异常',
                'level' => 'high',
                'message' => '检测到 API 高频 5xx 错误',
                'details' => $abnormalApis,
            ];
        }

        // 检测慢请求异常
        $slowApis = ApiLog::where('create_time', '>=', date('Y-m-d H:i:s', strtotime('-30 minutes')))
            ->where('duration_ms', '>', 3000)
            ->field('request_route, request_method, duration_ms, COUNT(*) as slow_count')
            ->group('request_route, request_method')
            ->having('slow_count', '>=', 3)
            ->select();

        if (!$slowApis->isEmpty()) {
            $risks[] = [
                'type' => '慢请求',
                'level' => 'medium',
                'message' => '检测到 API 高频慢请求（>3s）',
                'details' => $slowApis,
            ];
        }

        return $risks;
    }

    /**
     * 获取系统健康度
     */
    public function getSystemHealth()
    {
        $today = date('Y-m-d');

        $health = [
            'login' => [
                'today_total' => LoginLog::where('login_time', '>=', $today)->count(),
                'today_success' => LoginLog::where('login_time', '>=', $today)->where('status', 1)->count(),
                'today_failed' => LoginLog::where('login_time', '>=', $today)->where('status', 0)->count(),
            ],
            'operation' => [
                // 操作统计已迁移至 wise-operation-log 包
            ],
            'api' => [
                'today_total' => ApiLog::where('create_time', '>=', $today)->count(),
                'error_count' => ApiLog::where('create_time', '>=', $today)->where('response_status', '>=', 400)->count(),
                'avg_duration' => round((float) ApiLog::where('create_time', '>=', $today)->avg('duration_ms'), 2),
                'slow_count' => ApiLog::where('create_time', '>=', $today)->where('duration_ms', '>', 1000)->count(),
            ],
        ];

        return $health;
    }
}

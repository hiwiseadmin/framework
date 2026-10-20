<?php
namespace plugin\wisecms\service\integration;

use wise\webhook\facade\Webhook;

/**
 * Webhook 系统
 *
 * 职责分层（D1 决策落地）：
 *   - wisecms_webhook 表 = 业务「订阅注册表」（events / url / secret / status），后台管理照旧；
 *   - 投递 / 重试 / 退避 / 签名 / 投递日志（webhook_sends 表）统一交由 wise-webhook。
 *
 * 说明：
 *   - wise-webhook 内置完整 SSRF 防护（DNS 固定 IP 防重绑定 + 逐跳重定向校验 + 协议白名单），
 *     因此本类不再自行拼接 curl。
 *   - 投递模式（P1-B7）由插件配置 `webhook_mode` 决定：
 *       auto  （默认）跟随 wise-webhook 的 `server.dispatch.default_mode`；
 *       queue 强制入队（发布等写路径立即返回，重试/退避由 worker 承担）；
 *       sync  强制同步。
 *     ⚠️ 选 queue 必须部署队列 worker（`php think queue:work`），否则任务只入队不投递。
 *   - 同步模式下单个订阅的超时由 `webhook_timeout` 控制：N 个订阅串行投递，
 *     最坏阻塞 = N × timeout，故默认收紧到 5s（原 10s）。
 */
class WebhookService
{
    public function trigger(string $event, array $data): void
    {
        // P1-B7：wise-webhook 未安装时**先短路**，避免每次发布都白跑一次订阅表查询
        if (!class_exists(Webhook::class)) {
            return;
        }

        $hooks = \plugin\wisecms\model\Webhook::where('status', 1)->select();
        foreach ($hooks as $hook) {
            // 事件声明允许 "a, b" 这类带空格的写法，需 trim 后再比对
            $events = array_filter(array_map('trim', explode(',', (string) $hook->events)));
            if (!in_array($event, $events, true)) continue;
            $this->send($hook, $event, $data);
        }
    }

    protected function send($hook, string $event, array $data): void
    {
        if (!class_exists(Webhook::class)) {
            return;
        }

        try {
            $call = Webhook::serverCreate()
                ->url($hook->url)
                ->payload(['event' => $event, 'timestamp' => time(), 'data' => $data])
                ->method('POST')
                ->timeout(max(1, (int) config('wisecms.webhook_timeout', 5)))
                ->connectTimeout(3)
                ->maxRetries(3)
                // 签名交给 wise-webhook 默认签名器（HMAC-SHA256，头名 X-Signature）
                ->useSecret((string) $hook->secret)
                // 事件路由头（业务语义，非签名），供下游按事件分流
                ->withHeaders(['X-WISECMS-Event' => $event]);

            $mode = (string) config('wisecms.webhook_mode', 'auto');

            if ($mode === 'queue') {
                // 异步：立即返回；投递结果与重试明细见 wise-webhook 的 webhook_sends 表
                $call->dispatchToQueue();
                $hook->last_status = null;   // 尚未知结果，不写死状态码
                $hook->retry_count = 0;
            } elseif ($mode === 'sync') {
                $result = $call->dispatchSync();
                $hook->last_status = (int) ($result['code'] ?? 0);
                $hook->retry_count = 0;
            } else {
                // auto：跟随 wise-webhook 全局配置
                $result = $call->dispatch();
                $hook->last_status = is_array($result) ? (int) ($result['code'] ?? 0) : null;
                $hook->retry_count = 0;
            }
        } catch (\Throwable $e) {
            $hook->last_status = 0;
            $hook->retry_count++;
            cms_log('warning', 'Webhook 投递失败: ' . $e->getMessage(), ['url' => $hook->url, 'event' => $event]);
        } finally {
            try {
                $hook->save();
            } catch (\Throwable $e) {
                // 订阅表状态回写失败不应阻断投递主流程，也不应中断后续订阅的投递
            }
        }
    }
}

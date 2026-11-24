<?php
declare (strict_types = 1);

namespace app\common\service;

use wise\support\context\ContextualLogger;
use wise\support\tools\HttpClient;

/**
 * 通知服务
 *
 * 支持渠道：邮件、钉钉机器人、企业微信机器人
 */
class Notifier
{
    protected $config;

    public function __construct()
    {
        $this->config = config('update.notification', []);
    }

    /**
     * 发送通知
     * @param string $subject 通知主题
     * @param string $message 通知内容
     * @param string $level 通知级别，默认info
     */
    public function send($subject, $message, $level = 'info')
    {
        $channels = $this->config['channels'] ?? [];
        foreach ($channels as $channel) {
            $method = 'sendVia' . ucfirst($channel);
            if (method_exists($this, $method)) {
                $this->$method($subject, $message, $level);
            } else {
                ContextualLogger::warning("未知的通知渠道: {$channel}", ['update']);
            }
        }
    }

    /**
     * 发送邮件通知
     * @param string $subject 通知主题
     * @param string $message 通知内容
     * @param string $level 通知级别，默认info
     */
    protected function sendViaMail($subject, $message, $level)
    {
        $mailConfig = $this->config['mail'] ?? [];
        if (empty($mailConfig['to'])) {
            return;
        }
        try {
            \think\facade\Mail::to($mailConfig['to'])
                ->subject($subject)
                ->html(nl2br($message))
                ->send();
        } catch (\Exception $e) {
            ContextualLogger::error("邮件通知发送失败: " . $e->getMessage(), ['update']);
        }
    }

    /**
     * 发送钉钉通知
     * @param string $subject 通知主题
     * @param string $message 通知内容
     * @param string $level 通知级别，默认info
     */
    protected function sendViaDingtalk($subject, $message, $level)
    {
        $webhook = $this->config['dingtalk']['webhook'] ?? null;
        if (!$webhook) {
            return;
        }
        $data = [
            'msgtype' => 'text',
            'text'    => [
                'content' => "【{$subject}】\n{$message}",
            ],
        ];
        $this->sendWebhook($webhook, $data, '钉钉');
    }

    /**
     * 发送微信通知
     * @param string $subject 通知主题
     * @param string $message 通知内容
     * @param string $level 通知级别，默认info
     */
    protected function sendViaWechat($subject, $message, $level)
    {
        $webhook = $this->config['wechat']['webhook'] ?? null;
        if (!$webhook) {
            return;
        }
        $data = [
            'msgtype' => 'text',
            'text'    => [
                'content' => "【{$subject}】\n{$message}",
            ],
        ];
        $this->sendWebhook($webhook, $data, '微信');
    }

    /**
     * 通用 Webhook 发送方法（钉钉/企业微信共用）
     *
     * 通过 HttpClient 发送 JSON POST 请求，
     * 相比原生 curl 自动获得：SSL验证、超时控制、重试机制、日志中间件。
     *
     * @param string $webhook Webhook URL
     * @param array  $data 请求数据（会被 JSON 编码发送）
     * @param string $channelName 渠道名称（用于日志标识）
     */
    protected function sendWebhook(string $webhook, array $data, string $channelName): void
    {
        try {
            $client = new HttpClient([
                'timeout'       => 5,
                'connect_timeout' => 3,
                'verify'        => true,
                'http_errors'   => false,
            ]);

            $response = $client->json('POST', $webhook, $data);

            if (!$response->isSuccess()) {
                ContextualLogger::warning(
                    "{$channelName} Webhook 返回非成功状态: HTTP {$response->getStatusCode()}",
                    ['update', 'notifier']
                );
            }
        } catch (\think\exception\HttpException $e) {
            ContextualLogger::error("{$channelName} 请求异常: {$e->getMessage()}", ['update', 'notifier']);
        } catch (\Exception $e) {
            ContextualLogger::error("{$channelName} 未知错误: {$e->getMessage()}", ['update', 'notifier']);
        }
    }
}

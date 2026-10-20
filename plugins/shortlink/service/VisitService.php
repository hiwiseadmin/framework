<?php
namespace plugin\shortlink\service;

use plugin\shortlink\model\Link;
use plugin\shortlink\model\Log;
use think\facade\Db;
use think\facade\Event;
use think\facade\Request;
use wise\support\rate\RateLimiter;

class VisitService
{
    protected $shortLinkService;

    public function __construct()
    {
        $this->shortLinkService = new ShortLinkService();
    }

    public function visit(string $code, ?string $password = null): array
    {
        $link = Link::where('code', $code)->find();

        if (!$link) {
            return ['success' => false, 'message' => '短链接不存在'];
        }

        if ($link->status == Link::STATUS_DISABLED) {
            return ['success' => false, 'message' => '该短链接已被禁用'];
        }

        if ($link->is_expired) {
            // 自动同步过期状态
            if ($link->status == Link::STATUS_ACTIVE) {
                $link->status = Link::STATUS_EXPIRED;
                $link->save();
            }
            Event::trigger('shortlink.expired', $link);
            return ['success' => false, 'message' => '该短链接已过期'];
        }

        if ($link->max_visits > 0 && $link->total_visits >= $link->max_visits) {
            Event::trigger('shortlink.expired', $link);
            return ['success' => false, 'message' => '该短链接已达到最大访问次数'];
        }

        // 提前获取 IP，避免多次调用
        $ip = $this->getRealIp();

        if (!empty($link->password) && !password_verify((string)$password, $link->password)) {
            if ($password === null) {
                return ['success' => false, 'message' => '需要访问密码', 'need_password' => true];
            }
            // 密码暴力破解防护：15分钟内最多5次
            $failLimiter = RateLimiter::perMinutes(5, 15)->by("shortlink_pwd_fail:{$code}:{$ip}");
            if ($failLimiter->tooManyAttempts()) {
                return ['success' => false, 'message' => '密码错误次数过多，请15分钟后再试'];
            }
            $failLimiter->hit();
            return ['success' => false, 'message' => '访问密码错误'];
        }

        // 成功输入正确密码后，清除失败记录
        if (!empty($link->password)) {
            RateLimiter::perMinutes(5, 15)->by("shortlink_pwd_fail:{$code}:{$ip}")->clear();
        }

        $rateLimitResult = $this->checkRateLimit($code);
        if (!$rateLimitResult['success']) {
            return $rateLimitResult;
        }

        $isNewVisitor = Log::where('link_id', $link->id)->where('ip', $ip)->count() === 0;

        // 使用原子更新避免高并发下的竞态条件
        $updateData = ['total_visits' => Db::raw('total_visits + 1')];
        if ($isNewVisitor) {
            $updateData['unique_visits'] = Db::raw('unique_visits + 1');
        }
        Link::where('id', $link->id)->update($updateData);
        // 同步模型最新值
        $link->refresh();

        $log = new Log();
        $log->save([
            'link_id' => $link->id,
            'code'    => $code,
            'ip'      => $ip,
            'ua'      => substr(Request::header('user-agent', ''), 0, 500),
            'referer' => substr(Request::header('referer', ''), 0, 500),
        ]);

        Event::trigger('shortlink.visit', ['link' => $link, 'log' => $log]);

        // 二次校验 URL 安全性，防止已入库的恶意 URL 被用于开放重定向
        if (!$this->shortLinkService->isValidUrl($link->long_url)) {
            return ['success' => false, 'message' => '目标链接不安全'];
        }

        return ['success' => true, 'url' => $link->long_url];
    }

    protected function getRealIp(): string
    {
        if (PHP_SAPI === 'cli') return '127.0.0.1';
        try {
            return Request::ip();
        } catch (\Exception $e) {
            return '127.0.0.1';
        }
    }

    protected function checkRateLimit(string $code): array
    {
        $limit = (int) shortlink_config('rate_limit', 60);
        if ($limit <= 0) {
            return ['success' => true];
        }

        $ip = $this->getRealIp();
        $limiter = RateLimiter::perMinute($limit)->by("shortlink:{$code}:{$ip}");

        if ($limiter->tooManyAttempts()) {
            return ['success' => false, 'message' => '访问过于频繁，请稍后再试'];
        }

        $limiter->hit();
        return ['success' => true];
    }
}

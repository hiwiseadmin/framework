<?php
namespace plugin\shortlink\service;

use plugin\shortlink\model\Link;
use plugin\shortlink\model\Group;
use think\facade\Db;
use think\facade\Event;

class ShortLinkService
{
    protected $codeGenerator;

    public function __construct()
    {
        $this->codeGenerator = new CodeGenerator();
    }

    public function create($data, int $adminId = 0): Link
    {
        if (is_string($data)) {
            $data = ['long_url' => $data];
        }
        $data = $this->prepareData($data, $adminId);
        $link = new Link();
        $link->save($data);
        Event::trigger('shortlink.create', $link);
        return $link;
    }

    public function batchCreate(array $urls, array $options = [], int $adminId = 0): array
    {
        return Db::transaction(function () use ($urls, $options, $adminId) {
            $links = [];
            foreach ($urls as $item) {
                $data = is_array($item) ? $item : ['long_url' => $item];
                $data = array_merge($options, $data);
                $links[] = $this->create($data, $adminId);
            }
            return $links;
        });
    }

    public function update(int $id, array $data): Link
    {
        $link = Link::find($id);
        if (!$link) {
            throw new \RuntimeException('短链接不存在');
        }
        $data = $this->prepareData($data, $link->admin_id, true);
        $link->save($data);
        return $link;
    }

    public function parse(string $code): ?Link
    {
        return Link::where('code', $code)->find();
    }

    public function delete($ids): bool
    {
        Link::destroy($ids);
        return true;
    }

    public function setStatus(int $id, int $status): Link
    {
        $link = Link::find($id);
        if (!$link) {
            throw new \RuntimeException('短链接不存在');
        }
        $link->status = $status;
        $link->save();
        if ($status == Link::STATUS_DISABLED) {
            Event::trigger('shortlink.disabled', $link);
        }
        return $link;
    }

    public function getUrl(string $code): string
    {
        return shortlink_url($code);
    }

    public function isAvailable(Link $link): bool
    {
        if ($link->status != Link::STATUS_ACTIVE) return false;
        if ($link->is_expired) return false;
        if ($link->max_visits > 0 && $link->total_visits >= $link->max_visits) return false;
        return true;
    }

    /**
     * 验证 URL 有效性（协议白名单 + 域名黑名单 + 控制字符检测 + 凭据检测）
     */
    public function isValidUrl(string $url): bool
    {
        // 禁止换行符等控制字符，防止 CRLF 注入
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return false;
        }

        // 基础 URL 格式校验
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $allowedSchemes = ['http', 'https'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        // 检测 URL 凭据注入 (http://evil.com@trusted.com)
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // 域名黑名单校验
        if (!$this->isHostAllowed($host)) {
            return false;
        }

        return true;
    }

    /**
     * 检查域名是否不在黑名单中
     * 支持：精确匹配(example.com)、子域名匹配(*.example.com)、无通配符的子域名匹配
     */
    protected function isHostAllowed(string $host): bool
    {
        $blacklist = shortlink_config('blacklist_domains', []);
        if (empty($blacklist)) {
            return true;
        }

        foreach ($blacklist as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }

            // 精确匹配
            if ($host === $pattern) {
                return false;
            }

            // 子域名匹配 (如 sub.example.com 命中 example.com)
            $dotPattern = '.' . $pattern;
            if (substr($host, -strlen($dotPattern)) === $dotPattern) {
                return false;
            }

            // 通配符匹配 (*.example.com)
            if (strpos($pattern, '*.') === 0) {
                $suffix = substr($pattern, 2);
                if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function prepareData(array $data, int $adminId = 0, bool $isUpdate = false): array
    {
        $longUrl = $data['long_url'] ?? '';
        if (!$isUpdate && empty($longUrl)) {
            throw new \RuntimeException('原始链接不能为空');
        }
        if (!$isUpdate && !$this->isValidUrl($longUrl)) {
            throw new \RuntimeException('原始链接格式不正确');
        }
        if (!empty($data['custom_code'])) {
            $data['code'] = $this->codeGenerator->generate(
                shortlink_config('code_length', 6),
                $data['custom_code']
            );
        } elseif (!$isUpdate) {
            $data['code'] = $this->codeGenerator->generate(shortlink_config('code_length', 6));
        }
        if (isset($data['expire_time']) && is_string($data['expire_time'])) {
            $data['expire_time'] = $this->parseExpireTime($data['expire_time']);
        } elseif (!$isUpdate && empty($data['expire_time'])) {
            $defaultDays = (int) shortlink_config('default_expire_days', 0);
            $data['expire_time'] = $defaultDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$defaultDays} days")) : null;
        }
        if (isset($data['password']) && $data['password'] === '') {
            $data['password'] = null;
        } elseif (!empty($data['password'])) {
            // 密码哈希存储，防止明文泄露
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }
        if (!$isUpdate) {
            $data['admin_id'] = $adminId;
            $data['total_visits'] = 0;
            $data['unique_visits'] = 0;
            $data['status'] = $data['status'] ?? Link::STATUS_ACTIVE;
        }
        unset($data['custom_code']);
        return $data;
    }

    protected function parseExpireTime(string $time): ?string
    {
        if (empty($time) || $time === '0000-00-00 00:00:00') return null;
        if (is_numeric($time)) return date('Y-m-d H:i:s', (int) $time);
        $timestamp = strtotime($time);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    public function getDefaultGroupId(): int
    {
        $group = Group::where('ident', 'default')->find();
        return $group ? $group->id : 0;
    }
}

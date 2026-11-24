<?php

declare(strict_types=1);

namespace wise\plugin\publish;

use wise\support\tools\HttpClient;

/**
 * 市场投稿客户端（`plugin:publish` 专用）。
 *
 * ## 服务端契约（`wise-market` 包）
 *
 * | 动作 | 方法与路径 | 身份 |
 * |:--|:--|:--|
 * | 首版投稿 | `POST {market}/api/market/member/submit` | 会员 JWT（`Authorization: Bearer`） |
 * | 新版本 | `POST {market}/api/market/member/version` | 同上 |
 * | 解析 plugin_id | `GET {market}/api/market/member/myPlugins?keyword=` | 同上 |
 *
 * 三者统一响应信封 `{code, msg, data}`，`code === 0` 为成功。
 *
 * ## 为什么不用 admin 端点
 *
 * `POST /admin/market/plugin/save` 走后台登录态 + RBAC + CSRF，CLI 既拿不到 CSRF，
 * 也不该为一次发版去伪装管理员会话。会员 JWT 是服务端**明确设计给外部调用方**的身份。
 *
 * ## 失败分类
 *
 * 把服务端的「人话」保留下来（驳回文案必须能指导修改），同时给出机器可判的 `error`：
 * `auth` / `forbidden` / `conflict` / `rejected` / `server` / `network` / `protocol`。
 * 命令侧据 `error` 决定退出码与是否提示「先到后台确认」。
 */
final class PublishClient
{
    /** 会员端 API 前缀（相对市场根） */
    protected const MEMBER_PATH = 'api/market/member';

    protected string $marketBase;

    protected HttpClient $http;

    public function __construct(
        string $marketBase,
        protected string $token,
        ?HttpClient $http = null,
        int $timeout = 60
    ) {
        $this->marketBase = rtrim(trim($marketBase), '/');
        $this->http       = $http ?? new HttpClient([
            'timeout'         => $timeout,
            'connect_timeout' => min(10, max(3, $timeout)),
        ]);
    }

    /**
     * 会员端某个动作的完整 URL（输出给用户看时**不含** token）。
     */
    public function endpoint(string $action): string
    {
        return $this->marketBase . '/' . self::MEMBER_PATH . '/' . ltrim($action, '/');
    }

    /**
     * 按标识解析「市场里的插件 ID」。
     *
     * 返回 `id === null` 表示**确定不存在**（可以走首版投稿）；`ok === false` 表示无法判定
     * （网络/鉴权失败），调用方应据场景决定是否继续 —— 命令侧把「无法判定」当成警告，
     * 让真正的上传去给出权威结论。
     *
     * @return array{ok: bool, id: int|null, message: string, error: string}
     */
    public function lookup(string $identifier): array
    {
        try {
            $response = $this->http->get($this->endpoint('myPlugins'), [
                'query'   => ['keyword' => $identifier, 'limit' => 50, 'page' => 1],
                'headers' => $this->authHeaders(),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'message' => '查询我的插件失败：' . $e->getMessage(), 'error' => 'network'];
        }

        $json = $response->getJson();

        if (!is_array($json)) {
            return [
                'ok'      => false,
                'id'      => null,
                'message' => sprintf('服务端返回非 JSON 响应（HTTP %d）', $response->getStatusCode()),
                'error'   => 'protocol',
            ];
        }

        if ((int) ($json['code'] ?? -1) !== 0) {
            return [
                'ok'      => false,
                'id'      => null,
                'message' => (string) ($json['msg'] ?? '查询我的插件失败'),
                'error'   => $this->classify((int) $response->getStatusCode(), $json),
            ];
        }

        $list = (array) ($json['data']['list'] ?? []);

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string) ($item['identifier'] ?? '') === $identifier) {
                return ['ok' => true, 'id' => (int) ($item['id'] ?? 0), 'message' => '', 'error' => ''];
            }
        }

        return ['ok' => true, 'id' => null, 'message' => '', 'error' => ''];
    }

    /**
     * 首版投稿（新建插件记录）。
     *
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, message: string, error: string, data: array<string, mixed>}
     */
    public function submit(array $fields, string $zipPath): array
    {
        return $this->post('submit', $fields, $zipPath);
    }

    /**
     * 已有插件发新版本（追加版本记录）。
     *
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, message: string, error: string, data: array<string, mixed>}
     */
    public function submitVersion(array $fields, string $zipPath): array
    {
        return $this->post('version', $fields, $zipPath);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, message: string, error: string, data: array<string, mixed>}
     */
    protected function post(string $action, array $fields, string $zipPath): array
    {
        try {
            $response = $this->http->upload(
                $this->endpoint($action),
                ['plugin_file' => $zipPath],
                $fields,
                ['headers' => $this->authHeaders()]
            );
        } catch (\Throwable $e) {
            return $this->failure('network', '网络请求失败：' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $json   = $response->getJson();

        if (!is_array($json)) {
            return $this->failure('protocol', sprintf('服务端返回非 JSON 响应（HTTP %d）', $status));
        }

        $code    = (int) ($json['code'] ?? -1);
        $message = trim((string) ($json['msg'] ?? ''));

        if ($code === 0) {
            return [
                'ok'      => true,
                'message' => $message !== '' ? $message : '提交成功',
                'error'   => '',
                'data'    => (array) ($json['data'] ?? []),
            ];
        }

        return $this->failure(
            $this->classify($status, $json),
            $message !== '' ? $message : '服务端拒绝请求（code=' . $code . '）'
        );
    }

    /**
     * 把服务端响应归类成机器可判的错误类型。
     *
     * @param array<string, mixed> $json
     */
    protected function classify(int $status, array $json): string
    {
        $message = (string) ($json['msg'] ?? '');
        $code    = (int) ($json['code'] ?? 0);

        if ($status === 401 || $code === 401) {
            return 'auth';
        }

        if (str_contains($message, '无权操作') || str_contains($message, '无权')) {
            return 'forbidden';
        }

        if (str_contains($message, '已存在') || str_contains($message, '版本号')) {
            return 'conflict';
        }

        if ($status >= 500 || $code >= 500) {
            return 'server';
        }

        return 'rejected';
    }

    /**
     * @return array{ok: bool, message: string, error: string, data: array<string, mixed>}
     */
    protected function failure(string $error, string $message): array
    {
        return ['ok' => false, 'message' => $message, 'error' => $error, 'data' => []];
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }
}

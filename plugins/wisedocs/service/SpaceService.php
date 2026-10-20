<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

use plugin\wisedocs\model\Project as ProjectModel;
use think\facade\Cache;
use think\Request;

/**
 * 空间识别服务（P3-3 · 设计 D6）
 *
 * 识别优先级：绑定域名 > URL 参数 project（id 或 code）> 默认空间（排序第一个）。
 * 无任何启用空间时返回空数组，前台不按空间过滤。
 */
class SpaceService
{
    protected const CACHE_KEY = 'wisedocs:spaces';
    protected const CACHE_TTL = 300;

    protected Request $request;

    /** @var array|null 当前空间缓存（单请求内） */
    protected ?array $current = null;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 全部启用空间（带缓存）
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            try {
                return ProjectModel::where('status', 1)
                    ->order('sort_order DESC, id ASC')
                    ->select()
                    ->toArray();
            } catch (\Throwable $e) {
                // 表未创建等异常：降级为空，前台不按空间过滤
                return [];
            }
        }, self::CACHE_TTL);
    }

    /**
     * 当前空间（未命中返回空数组）
     */
    public function current(): array
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $spaces = $this->all();
        if (empty($spaces)) {
            return $this->current = [];
        }

        // 1) 绑定域名
        $host = strtolower((string) $this->request->host(true));
        foreach ($spaces as $space) {
            $domain = strtolower(trim((string) ($space['domain'] ?? ''), '/ '));
            if ($domain !== '' && $domain === $host) {
                return $this->current = $space;
            }
        }

        // 2) URL 参数（id 或 code）；命中后写入会话，供后续页面沿用（多空间切换）
        $param = trim((string) $this->request->param('project', ''));
        if ($param !== '') {
            foreach ($spaces as $space) {
                if ((string) $space['id'] === $param || (string) $space['code'] === $param) {
                    try {
                        session('wisedocs_project', (int) $space['id']);
                    } catch (\Throwable $e) {
                        // 会话不可用（无状态请求）时忽略
                    }

                    return $this->current = $space;
                }
            }
        }

        // 3) 会话记忆
        try {
            $remembered = (int) session('wisedocs_project', 0);
        } catch (\Throwable $e) {
            $remembered = 0;
        }
        if ($remembered > 0) {
            foreach ($spaces as $space) {
                if ((int) $space['id'] === $remembered) {
                    return $this->current = $space;
                }
            }
        }

        // 4) 默认空间
        return $this->current = $spaces[0];
    }

    /**
     * 当前空间 ID（0 表示不按空间过滤）
     */
    public function currentId(): int
    {
        return (int) ($this->current()['id'] ?? 0);
    }

    /**
     * 清空空间缓存（空间变更后调用）
     */
    public function clearCache(): void
    {
        try {
            Cache::delete(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // 忽略缓存异常
        }
    }

    /**
     * 应用当前空间的主题（P5-3 多主题）
     *
     * 主题来源：`wisedocs_projects.theme`。
     * 若 `view/theme/{theme}/index/layout.html` 存在，则把视图根路径切到该主题目录
     * （主题可整体覆盖模板）；否则仅返回主题名，由 layout 加载对应的 CSS 覆盖。
     *
     * @return string 生效主题名
     */
    public function applyTheme(): string
    {
        $theme = (string) ($this->current()['theme'] ?? 'default');
        $theme = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $theme);
        if ($theme === '' || $theme === 'default') {
            return 'default';
        }

        $themeView = plugin_path() . 'wisedocs' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR
            . 'theme' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR;

        if (is_dir($themeView) && is_file($themeView . 'index' . DIRECTORY_SEPARATOR . 'layout.html')) {
            \think\facade\View::config(['view_path' => $themeView]);
        }

        return $theme;
    }
}

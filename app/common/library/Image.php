<?php

declare(strict_types=1);

namespace app\common\library;

use think\facade\Config;
use wise\image\ImageManager;

/**
 * 框架图片处理工具类（继承 wise\image\ImageManager）
 *
 * 定位：`wise\image\ImageManager` 的「框架适配层」。
 *
 * 与直接使用 ImageManager 的差别：
 *   1. 免传配置 —— 默认自动读取宿主配置 `config/wise-image.php`
 *      （服务提供者已把包内默认配置合并进该配置项）；
 *   2. 自动注入框架 Request —— 图片安全检查（IP / Referer / 签名）
 *      直接走 `think\Request`，不再读取超全局变量；
 *   3. 提供 {@see Image::getInstance()} 工厂，业务与插件可一行取用；
 *   4. 框架特有的强耦合方法统一在此扩展，避免污染与框架无关的 wise-image 包。
 *
 * Swoole / 常驻内存：本类不使用任何静态单例，每个实例自带源图、操作链、
 * 驱动资源与 Request，天然满足「一次处理一个实例」，协程并发下互不串扰；
 * 请勿把实例缓存到全局/静态变量中以跨越请求复用。
 *
 * 图片处理能力（open / make / resize / crop / watermark / textWatermark /
 * format / quality / rotate / blur / brightness / contrast / style /
 * parseUrlParams / save / output / response / getInfo / signUrl …）
 * 全部继承自 ImageManager，用法与官方文档完全一致。
 *
 * 用法示例：
 * ```php
 * // 打开本地图片 → 居中裁剪为 200x200 → 转 WebP 保存
 * Image::getInstance()
 *     ->open('/path/to/a.jpg')
 *     ->resize(200, 200, 'fill')
 *     ->format('webp')
 *     ->save('/path/to/a.webp');
 *
 * // 生成等比缩略图并直接响应浏览器（命中 HTTP 协商缓存时返回 304）
 * Image::getInstance()->open('/path/to/a.jpg')->width(200)->response();
 *
 * // 使用预定义样式
 * Image::getInstance()->open('/path/to/a.jpg')->style('thumb')->save('/path/to/t.jpg');
 *
 * // 显式传入配置（忽略宿主配置），例如改用 imagick 驱动
 * Image::getInstance(['driver' => 'imagick'])->open('/path/to/a.jpg')->save('/path/to/a.png', 'png');
 *
 * // 容器解析：每次返回新实例，同样自动装配配置与 Request
 * app(Image::class)->open('/path/to/a.jpg')->save('/path/to/a.jpg');
 * ```
 *
 * @see \wise\image\ImageManager
 */
class Image extends ImageManager
{
    /**
     * @param array<string, mixed> $config 显式配置；为空时自动读取宿主 `wise-image` 配置
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config === [] ? self::resolveConfig() : $config);

        $this->bindFrameworkRequest();
    }

    /**
     * 获取图片处理实例
     *
     * 每次调用都返回**全新实例**，类内不持有任何静态单例
     * （与 {@see \wise\support\tools\RedisHelper::getInstance()} 的约定一致）。
     *
     * 这样可保证 Swoole / Workerman 等常驻内存（含协程并发）环境下的
     * 请求级隔离：源图路径、操作链、驱动资源、注入的 Request 都不会
     * 跨请求 / 跨协程残留或被并发串改。
     *
     * @param array<string, mixed>|null $config 显式配置；null 时读取宿主 `wise-image` 配置
     * @return static
     */
    public static function getInstance(?array $config = null): static
    {
        return new static($config ?? []);
    }

    // -----------------------------------------------------------------
    // 框架耦合方法扩展区
    //
    // 后续框架特有、与 ThinkPHP / 本项目强绑定的图片能力（如上传文件接入、
    // 写入 public 目录并回传访问 URL 等）统一在此实现，保持 wise-image 包纯净。
    // -----------------------------------------------------------------

    /**
     * 解析框架配置
     *
     * 优先读取宿主 `wise-image` 配置（服务提供者已合并包内默认值）；
     * 脱离框架上下文（CLI / 单元测试）时返回空数组，交由 ImageManager 使用内置默认配置。
     *
     * @return array<string, mixed>
     */
    protected static function resolveConfig(): array
    {
        try {
            if (class_exists(Config::class)) {
                $config = Config::get('wise-image', []);
                if (is_array($config)) {
                    return $config;
                }
            }
        } catch (\Throwable) {
            // 配置系统不可用时静默降级为 ImageManager 内置默认配置
        }

        return [];
    }

    /**
     * 注入框架请求实例
     *
     * 使 ImageManager 的安全检查与协商缓存直接使用 `think\Request`；
     * 无请求上下文（CLI / 单测）时跳过，ImageManager 会自动回退超全局变量。
     */
    protected function bindFrameworkRequest(): void
    {
        if (!function_exists('app')) {
            return;
        }

        try {
            $app     = app();
            $request = $app->request ?? app('request');
        } catch (\Throwable) {
            return;
        }

        if ($request instanceof \think\Request) {
            $this->setRequest($request);
        }
    }
}

<?php
declare(strict_types=1);

namespace plugin\contract;

/**
 * Dashboard 数据面板契约
 *
 * 面向插件开发者的面板提供者契约（位于插件 SDK 契约层）。
 * 实现方：框架内置面板 / 插件面板（如 plugin\smartstock\widget\StockAlertWidget）。
 * 注册方：调用 app\common\dashboard\DashboardRegistry::register()。
 *
 * key 命名约定：
 *  - 内置（框架保留段）：system.xxx，如 system.server_status
 *  - 插件：{plugin_ident}.{name}，如 smartstock.stock_alert
 *
 * 面板 UI 由 dashboard.js 依据 category 通用渲染，实现方无需编写前端；
 * 需要自定义前端组件的场景走既有 Slot 通道（SlotManager / manifest slots）。
 *
 * @see \app\common\dashboard\DashboardRegistry
 */
interface DashboardWidgetInterface
{
    /**
     * 全局唯一 key，如 system.server_status / smartstock.stock_alert
     *
     * @return string
     */
    public function getKey(): string;

    /**
     * 面板标题
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * 面板说明（展示在“添加面板”抽屉）
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 图标（与插件 manifest 顶层 icon 同规范）
     *
     * @return array{type:string, value:string, color:string}
     */
    public function getIcon(): array;

    /**
     * 内容类别，决定前端通用渲染器
     * stat | list | entries | chart | html
     *
     * @return string
     */
    public function getCategory(): string;

    /**
     * 建议尺寸（GridStack 单位格）
     *
     * @return array{w:int, h:int}
     */
    public function getDefaultSize(): array;

    /**
     * 最小尺寸
     *
     * @return array{w:int, h:int}
     */
    public function getMinSize(): array;

    /**
     * 建议主题色 accent：brand|success|warning|danger|info|''（'' = 跟随全局 scheme）
     *
     * @return string
     */
    public function getSuggestedAccent(): string;

    /**
     * 默认 props
     *
     * @return array
     */
    public function getDefaultConfig(): array;

    /**
     * props 表单 schema（字段风格对齐插件 manifest config：type/title/options/...）
     *
     * @return array
     */
    public function getConfigSchema(): array;

    /**
     * 拉取面板数据；$config 为用户已保存的 props
     *
     * @param array $config
     * @return array
     */
    public function getData(array $config = []): array;

    /**
     * 缓存 key（相同 props 复用缓存）；返回空串表示不缓存
     *
     * @param array $config
     * @return string
     */
    public function getCacheKey(array $config = []): string;

    /**
     * 缓存秒数
     *
     * @return int
     */
    public function getCacheTTL(): int;

    /**
     * 当前请求上下文下是否对当前用户可见（catalog 过滤 / 数据接口鉴权）
     *
     * @return bool
     */
    public function isAccessible(): bool;
}

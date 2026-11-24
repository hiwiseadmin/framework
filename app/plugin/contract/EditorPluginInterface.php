<?php
declare(strict_types=1);

namespace app\plugin\contract;

use app\common\library\EditorManager;

/**
 * 编辑器插件接口
 *
 * 插件在引导时通过此接口向框架注册编辑器驱动。
 *
 * 实现类命名约定：{PluginNamespace}\EditorProvider
 * 例如：plugin\wiseeditor\EditorProvider
 *
 * 注册时机：BootServiceProvider::boot() 中调用
 */
interface EditorPluginInterface
{
    /**
     * 向 EditorManager 注册本插件提供的编辑器驱动
     *
     * 典型实现：
     * ```
     * public function registerEditors(EditorManager $manager): void
     * {
     *     $manager->registerDriver('richtext', TipTapDriver::class);
     *     $manager->registerDriver('markdown', MarkdownDriver::class);
     * }
     * ```
     *
     * @param EditorManager $manager 全局编辑器管理器
     * @return void
     */
    public function registerEditors(EditorManager $manager): void;

    /**
     * 获取本插件提供的编辑器类型列表
     *
     * @return string[] 如 ['richtext', 'markdown']
     */
    public function getEditorTypes(): array;

    /**
     * 获取本插件的前端 ESM 适配器模块路径
     *
     * 页面加载时，框架通过 import(modulePath) 动态加载。
     *
     * @param  string $type 编辑器类型
     * @return string|null  ESM 模块路径（相对于 public/statics/），null 表示不需要前端模块
     */
    public function getEditorModulePath(string $type): ?string;

    /**
     * 获取编辑器图标（用于系统设置页面）
     *
     * @param  string $type 编辑器类型
     * @return string|null Bootstrap Icons class 如 'bi bi-pencil-square'
     */
    public function getEditorIcon(string $type): ?string;
}

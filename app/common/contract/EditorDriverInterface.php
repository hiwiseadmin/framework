<?php
declare(strict_types=1);

namespace app\common\contract;

/**
 * 编辑器驱动接口
 *
 * 所有编辑器驱动（TipTap、Markdown、CKEditor、Quill 等）必须实现此接口。
 * 实现类在对应插件的配置中注册到 config('wise-editor.drivers.{type}')。
 *
 * 设计参考：wise\oauth\contract\OAuthDriverInterface
 *
 * 典型接入流程：
 * 1. 编写实现类 MyEditorDriver implements EditorDriverInterface
 * 2. 在插件的 EditorProvider 中调用 EditorManager::registerDriver('my-type', MyEditorDriver::class)
 * 3. 前端通过 WiseEditor.init({type: 'my-type'}) 使用
 */
interface EditorDriverInterface
{
    /**
     * 获取编辑器类型标识
     *
     * 类型标识用于区分不同的编辑模式：
     * - 'richtext'  富文本编辑器（对应多媒体编辑）
     * - 'markdown'  Markdown 编辑器
     * - 'code'      代码编辑器
     * - 'simple'    简易编辑器（仅加粗/斜体/链接等基础功能）
     *
     * 一个驱动可以声明多个能力，例如 TipTap 可以声明 ['richtext', 'simple']。
     *
     * @return string[] 编辑器类型标识数组
     */
    public function getTypes(): array;

    /**
     * 获取编辑器显示名称
     *
     * @return string 如 'TipTap'、'Markdown'、'CKEditor 5'
     */
    public function getName(): string;

    /**
     * 获取编辑器驱动版本
     *
     * @return string 如 '2.11.0'
     */
    public function getVersion(): string;

    /**
     * 生成编辑器容器的后端 HTML
     *
     * 这包括:
     * - 编辑器挂载的 DOM 容器（<div> 或 <textarea>）
     * - 必要的 data-* 属性，供前端适配器识别
     * - 隐藏域或 textarea 用于承载实际表单数据
     *
     * JS 初始化代码不在此方法生成，前端适配层负责。
     *
     * @param  string $name    表单字段名
     * @param  mixed  $value   初始值（HTML 字符串或 Markdown 文本）
     * @param  array  $options 渲染选项
     *         - 'mode'           string  编辑模式（richtext|markdown|simple|code）
     *         - 'height'         int     编辑器高度(px)，默认 400
     *         - 'placeholder'    string  占位文本
     *         - 'readonly'       bool    是否只读
     *         - 'toolbar'        string  工具栏预设（full|basic|minimal）
     *         - 'upload_url'     string  上传地址（覆盖默认）
     *         - 'id'             string  容器 ID（不提供则自动生成）
     *         - 'class'          string  额外 CSS 类名
     *         - 'attrs'          array   额外 HTML 属性
     * @return string HTML 代码
     */
    public function renderHtml(string $name, $value, array $options = []): string;

    /**
     * 获取前端 JS 初始化配置数组
     *
     * 该数组被 JSON 序列化后注入到模板，供前端适配层使用。
     *
     * @param  string $elementId DOM 元素 ID
     * @param  array  $options   渲染选项（与 renderHtml 相同）
     * @return array
     *         - 'type'          string  编辑器类型
     *         - 'module'        string  前端 ESM 模块路径（相对于 public/statics/）
     *         - 'config'        array   传递给编辑器构造函数的配置
     */
    public function getJsConfig(string $elementId, array $options = []): array;

    /**
     * 获取该编辑器所需的 CSS 依赖列表
     *
     * @param  string $mode 编辑模式
     * @return string[] CSS 文件路径列表（相对于 public/statics/）
     */
    public function getCssDependencies(string $mode = 'richtext'): array;

    /**
     * 获取该编辑器所需的 JS 依赖列表
     *
     * @param  string $mode 编辑模式
     * @return string[] JS 文件路径列表（相对于 public/statics/）
     */
    public function getJsDependencies(string $mode = 'richtext'): array;

    /**
     * 获取编辑器默认配置
     *
     * @return array
     */
    public function getDefaultConfig(): array;

    /**
     * 获取 HTML 输出的 XSS 白名单配置
     *
     * 用于 HTMLPurifier 配置，告知系统该编辑器可能输出的 HTML 标签和属性。
     *
     * @return array
     *         - 'allowed_tags'        string[]  HTML 标签白名单
     *         - 'allowed_attributes'   array    标签→属性映射
     */
    public function getHtmlWhitelist(): array;

    /**
     * 编辑器是否直接输出 Markdown 格式
     *
     * Markdown 编辑器的原始数据是纯文本而非 HTML，
     * 框架据此决定存储和过滤策略。
     *
     * @return bool
     */
    public function isMarkdownNative(): bool;
}

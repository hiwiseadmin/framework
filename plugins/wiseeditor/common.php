<?php
/**
 * wiseeditor 公共辅助函数
 */

if (!function_exists('wiseeditor_render')) {
    /**
     * 便捷函数：渲染编辑器
     *
     * @param string $name    表单字段名
     * @param mixed  $value   初始值
     * @param string $type    编辑器类型 (richtext|markdown|simple)
     * @param array  $options 额外选项
     * @return string
     */
    function wiseeditor_render(string $name, $value = null, string $type = 'richtext', array $options = []): string
    {
        try {
            $manager = app('editor.manager');
            if ($manager instanceof \app\common\library\EditorManager && $manager->has($type)) {
                $options['type'] = $type;
                return $manager->render($name, $value, $options);
            }
        } catch (\Throwable $e) {
            // fall through
        }

        // 退化为 textarea
        $id          = $options['id'] ?? ('editor_' . $name);
        $height      = ($options['height'] ?? 400) . 'px';
        $placeholder = $options['placeholder'] ?? '';
        $value       = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<textarea name="%s" id="%s" class="form-control" style="height:%s" placeholder="%s">%s</textarea>',
            $name, $id, $height, htmlspecialchars($placeholder, ENT_QUOTES), $value
        );
    }
}

if (!function_exists('wiseeditor_assets')) {
    /**
     * 便捷函数：获取编辑器资源列表
     *
     * @param string|null $type 编辑器类型
     * @return array ['css' => [], 'js' => []]
     */
    function wiseeditor_assets(?string $type = null): array
    {
        // P0-3：原实现依赖不存在的 \app\common\library\Form 类，调用即致命错误。
        // 改为直接调用 EditorManager::collectAssets()。
        try {
            $manager = app('editor.manager');
            if ($manager instanceof \app\common\library\EditorManager) {
                return $manager->collectAssets($type);
            }
        } catch (\Throwable $e) {
            // 编辑器未安装 / 容器未就绪，静默降级
        }

        return ['css' => [], 'js' => []];
    }
}

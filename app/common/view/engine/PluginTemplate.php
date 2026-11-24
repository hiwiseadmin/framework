<?php
declare(strict_types=1);

namespace app\common\view\engine;

use think\Template;

/**
 * 插件视图模板引擎（扩展 think-template）
 *
 * 解决的问题：
 *   think-template 原生在 compiler() 中先执行 parse()（此时 {extend}/{include}
 *   的 name/file 属性已被解析为模板路径），之后才应用 tpl_replace_string。
 *   因此 {extend name="__PLUGIN_VIEW__/base"} 这类"路径式"替换符写法默认不生效。
 *
 * 本引擎在 parse() 之前：
 *   1. 提前应用 tpl_replace_string，使编译期标签属性中的替换符可用；
 *   2. 为 extend/include 中无扩展名的绝对路径引用补全 .html 后缀，
 *      避免 parseTemplateFile 把绝对路径当作 view_path 相对路径处理而破坏路径。
 */
class PluginTemplate extends Template
{
    /**
     * 模板解析入口
     * @access public
     * @param string $content 模板内容
     */
    public function parse(string &$content): void
    {
        $replace = $this->config['tpl_replace_string'] ?? [];
        if (!empty($replace)) {
            $content = str_replace(array_keys($replace), array_values($replace), $content);

            $content = preg_replace_callback(
                '/\{(extend\s+name|include\s+file)\s*=\s*(["\'])([^"\']+?)\2/is',
                function (array $match): string {
                    $path = $match[3];
                    if ('' == pathinfo($path, PATHINFO_EXTENSION) && $this->isAbsolutePath($path)) {
                        $path .= '.' . ltrim($this->config['view_suffix'], '.');
                    }
                    return '{' . $match[1] . '=' . $match[2] . $path . $match[2];
                },
                $content
            );
        }

        parent::parse($content);
    }

    /**
     * 判断是否为绝对路径（Windows 盘符或 / 开头）
     */
    protected function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path);
    }
}

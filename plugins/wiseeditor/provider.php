<?php
/**
 * wiseeditor 插件对外暴露的服务标识
 *
 * 其他插件可通过 app('editor.tiptap') 或 app('editor.milkdown') 获取驱动实例。
 */
return [
    'editor.tiptap'   => \plugin\wiseeditor\src\TipTapDriver::class,
    'editor.milkdown' => \plugin\wiseeditor\src\MilkdownDriver::class,
];

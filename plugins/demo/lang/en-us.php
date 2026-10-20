<?php
/**
 * demo 插件 · English language pack
 *
 * 与 zh-cn.php 同目录、同键集合；缺键会在运行期回退为「键名本身」，
 * 可用 `php think lang:check` 审计缺失项。
 */
return [
    '__locale_name' => 'English',

    'demo.title'      => 'Demo Plugin',
    'demo.intro'      => 'A plugin development template. Start building your features here.',
    'demo.version'    => 'Version',
    'demo.author'     => 'Author',
    'demo.created_at' => 'Created at',
    'demo.locale'     => 'Current language',
];

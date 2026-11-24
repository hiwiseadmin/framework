<?php

/**
 * 回收站 —— 宿主侧注册清单（P0-2A）
 *
 * 软删除模型横跨宿主 / wise-* 包 / 插件，因此本文件**只声明宿主 app/ 下的模型**；
 * 包 / 插件在各自 ServiceProvider 里注册（见 `WiseBootServiceProvider::registerRecycle()`），
 * 宿主不替它们声明（否则卸载后会出现指向已不存在模型的悬空项）。
 *
 * 注册项结构：
 *   title  —— 列表标题字段（缺省回退主键）
 *   group  —— 分组名（缺省模型短名）
 *   fields —— 列表额外展示字段（可选，仅展示用，不参与还原）
 *
 * 不注册 = 不出现在回收站（fail-closed）。
 */

return [
    'models' => [
        \app\admin\model\Menu::class => [
            'title'  => 'name',
            'group'  => '系统',
            'fields' => ['path', 'type', 'plugin'],
        ],
    ],
];

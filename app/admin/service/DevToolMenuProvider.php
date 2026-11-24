<?php
declare(strict_types=1);

namespace app\admin\service;

use wise\support\contracts\MenuItemProvider;

/**
 * 宿主侧「开发工具」菜单声明
 *
 * 后台内部工具页不属于任何一个 wise-* 包，但又必须走**同一条**菜单落库链路
 * （`app\admin\service\MenuItemSync`）才能获得幂等同步与权限派生。
 * 因此用宿主自己的 `MenuItemProvider` 实现，注册到 `ExtPoints::MENU_ITEM`。
 *
 * 为什么不写迁移直接 INSERT：菜单是「声明」，数据库是「运行时」。
 * 声明改了再跑 `php think menu:sync` 即可，避免两份真相源。
 *
 * @package app\admin\service
 */
final class DevToolMenuProvider implements MenuItemProvider
{
    public function ident(): string
    {
        return 'devtools';
    }

    public function containers(): array
    {
        return [
            [
                'key'    => 'devtools',
                'name'   => '开发工具',
                'icon'   => 'bi bi-magic',
                'anchor' => 'console',
                'sort'   => 650,
            ],
        ];
    }

    public function items(): array
    {
        return [
            [
                'container'   => 'devtools',
                'name'        => 'CRUD 构建器',
                'icon'        => 'bi bi-magic',
                'path'        => '/admin/crud_builder/index',
                'type'        => 'menu',
                'sort'        => 0,
                'status'      => 1,
                'description' => '从数据表生成后台模块：先看判定依据，再改映射，最后预览落盘',
            ],
        ];
    }
}

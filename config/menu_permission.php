<?php
/**
 * 菜单到权限的映射规则配置
 *
 * MenuPermissionBridge 读取此配置，将菜单数据转换为权限数据。
 * 插件可在 manifest.json 中通过 menu_permission 字段覆盖。
 *
 * 默认配置完全兼容当前硬编码行为，不修改任何配置即可无缝迁移。
 */

return [
    // -----------------------------------------------------------------------
    // 字段映射
    // -----------------------------------------------------------------------
    // key: 菜单字段名（Menu 模型属性）
    // value: 权限字段名（auth_permissions 表字段）
    'field_map' => [
        'name'        => 'name',
        'description' => 'description',
        'sort'        => 'sort',
        'status'      => 'status',
    ],

    // -----------------------------------------------------------------------
    // 默认值（固定填写，不依赖菜单字段）
    // -----------------------------------------------------------------------
    'defaults' => [
        'type'   => 'route',
        'status' => 1,
    ],

    // -----------------------------------------------------------------------
    // 权限标识生成策略
    // -----------------------------------------------------------------------
    'ident' => [
        // 策略:
        //   path_to_dot: /admin/user/list → admin.user.list（snake_case，当前默认）
        //   path_lower:  /admin/User/List → admin.user.list（仅小写）
        //   hash:        /admin/User/List → md5 前 16 位
        //   callback:    使用下面定义的回调
        'strategy' => 'path_to_dot',

        // 自定义回调 function(string $path): string
        'callback' => null,
    ],

    // -----------------------------------------------------------------------
    // HTTP 方法推断策略
    // -----------------------------------------------------------------------
    'method' => [
        // 策略:
        //   path_suffix: 根据路径最后一段匹配 pattern（当前默认）
        //   always_get:  始终返回 GET
        //   always_post: 始终返回 POST
        //   always_any:  始终返回 *
        //   callback:    自定义回调
        'strategy' => 'path_suffix',

        // path_suffix 模式下的后缀→方法映射
        //
        // 注意 `edit`：后台的 edit 动作用来**渲染编辑表单**（GET，见 CrudAction::_edit），
        // 提交走 save/update（POST）。历史配置把 edit 推断成 POST，导致菜单/角色/用户
        // 的「编辑」按钮打开 iframe 时被 RBAC 判为方法不匹配 → 403。
        'pattern' => [
            'save'   => 'POST',
            'update' => 'POST',
            'delete' => 'POST',
            'remove' => 'POST',
            'store'  => 'POST',
            'create' => 'GET',
            'edit'   => 'GET',

            // ── 人工侧为 POST 的动作 ──────────────────────────────────
            // 缺省 fallback 是 GET；下列动作在后台 JS 里都是 POST，
            // 不显式列出会让新建权限行 method=GET，前端 POST 时 403。
            'forcedelete'    => 'POST',
            'restore'        => 'POST',
            'status'         => 'POST',
            'sort'           => 'POST',
            'multi'          => 'POST',
            'dragsort'       => 'POST',
            'cancel'         => 'POST',
            'retry'          => 'POST',
            'clear'          => 'POST',
            'sync'           => 'POST',
            'toggle'         => 'POST',
            'quickedit'      => 'POST',
            'togglestatus'   => 'POST',
            'inheritstatus'  => 'POST',
            'resetinherit'   => 'POST',
            'tagadd'         => 'POST',
            'resetpassword'  => 'POST',
            'assignrole'     => 'POST',
        ],

        // path_suffix 未匹配时的默认方法
        'default_method' => 'GET',

        // 自定义回调 function(string $path): string
        'callback' => null,
    ],

    // -----------------------------------------------------------------------
    // 父权限解析策略
    // -----------------------------------------------------------------------
    'parent' => [
        // 策略:
        //   parent_menu: 从父菜单的 permission_id 字段获取（当前默认）
        //   none:        始终为 0（根权限，无父级）
        //   callback:    自定义回调
        'strategy' => 'parent_menu',

        // 自定义回调 function(?Menu $parentMenu): int
        'callback' => null,
    ],

    // -----------------------------------------------------------------------
    // 双向绑定
    // -----------------------------------------------------------------------
    // 是否在创建/同步权限后自动更新 menu.permission_id
    'auto_bind' => true,

    // -----------------------------------------------------------------------
    // 插件级别覆盖
    // -----------------------------------------------------------------------
    // 是否允许 manifest.json 中的 menu_permission 覆盖全局配置
    'plugin_override' => true,
];

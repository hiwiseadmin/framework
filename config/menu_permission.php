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

        // path_suffix 模式下的后缀→方法映射。
        //
        // 值可以是单方法，也可以是**逗号分隔的方法集合**：RbacManager::methodMatch()
        // 已支持 `GET,POST` / `*`，所以歧义后缀用集合表达，避免开发者按习惯选了另一种方法
        // 就被 RBAC 判 403（例如 POST 提交到 edit 页、DELETE 删除）。
        //
        // 推荐命名仍然是：`edit` = GET 表单页，`update` = POST 保存；集合只是容错，不是放宽约定。
        'pattern' => [
            'save'   => 'POST',
            'update' => 'POST',
            // 删除：POST（全仓多数包）与 DELETE（REST 习惯）都允许
            'delete' => 'POST,DELETE',
            'remove' => 'POST,DELETE',
            'store'  => 'POST',
            // 表单页 GET / 提交 POST 都可能命中同一路径
            'create' => 'GET,POST',
            'edit'   => 'GET,POST',

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
            // 「立即执行 / 签发一次性确认令牌」：语义上是写入与副作用动作，
            // 必须 POST（GET 会被预取/爬虫触发，且无法带确认令牌）。
            'run'            => 'POST',
            'issuetoken'     => 'POST',

            // ── 控制器内回收站（CrudAction::_recycleList 及其配套动作）──────
            // 动作名固定为 recycleXxx，后缀不在上面的通用表里：
            // 不显式登记会让派生出来的权限行 method=GET，前端 POST 时 403。
            'recyclelist'        => 'GET',
            'recycletoken'       => 'POST',
            'recyclerestore'     => 'POST',
            'recycleforcedelete' => 'POST',
        ],

        // 精确路径覆盖（优先级高于后缀 pattern）：
        // 键为菜单 path（带不带前导 / 都可），值为单方法或方法集合。
        // 用于个别不守后缀约定的路由，避免为了权限去改公开路由路径。
        'overrides' => [
            // '/admin/market/plugin/edit' => 'GET',
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

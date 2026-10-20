<?php

/**
 * CRUD 生成器 —— 字段类型推导规则表。
 *
 * 规范见 docs/wiseadmin-crud-field-mapping.md。
 *
 * 设计要点：
 *   1. **五级优先级 P0~P5**，高命中即停（不靠代码顺序覆盖）
 *   2. 规则**数据化**：改规则 = 改本配置，不动生成器代码
 *   3. 每条规则必须能回答「命中层 + 命中规则 + 备选」——由 --dry-run 输出
 *
 * 匹配语义（matcherToken）：字段名满足以下**任一**即命中
 *   $name === $token          （完整相等）
 *   str_ends_with($name, '_' . $token)   （下划线边界后缀）
 * 因此 token 一律**不带前导下划线**：`time` 命中 `create_time`，但**不**命中 `runtime`。
 */

return [
    // ────────────────────────────────────────────────
    // P0 · 显式覆盖（最高优先级，压倒一切）
    //   '表名.字段名' => ['type' => 'editor', 'props' => [...]]
    //   '表名.*'      => 该表所有字段的默认覆盖
    //   命令行 --field=status:radio:启用=1,禁用=0 亦写入此层（运行时合并）
    // ────────────────────────────────────────────────
    'overrides' => [],

    // ────────────────────────────────────────────────
    // P1 · 字段名精确匹配
    // ────────────────────────────────────────────────

    // 主键（跳过表单；列表列由生成器单独处理）
    'primary_key' => ['id'],

    // 系统字段：**同时覆盖两派命名**（本项目 *_time 与 FastAdmin 无分隔命名）
    // role: create_time / update_time / delete_time
    'system_fields' => [
        'create_time' => 'create_time',
        'created_at'  => 'create_time',
        'add_time'    => 'create_time',
        'createtime'  => 'create_time',
        'update_time' => 'update_time',
        'updated_at'  => 'update_time',
        'updatetime'  => 'update_time',
        'modify_time' => 'update_time',
        'delete_time' => 'delete_time',
        'deleted_at'  => 'delete_time',
        'deletetime'  => 'delete_time',
        'admin_id'    => 'reserved',      // 由业务注入，不进表单
    ],

    // 语义字段：注释能解析出枚举时按 radio/select，否则按 DB 类型
    'exact' => [
        'status' => ['type' => 'enum_choice', 'css' => 2],
        'state'  => ['type' => 'enum_choice', 'css' => 2],
        'type'   => ['type' => 'enum_choice', 'css' => 3],
        'sort'   => ['type' => 'number', 'props' => ['min' => 0]],
        'weigh'  => ['type' => 'number', 'props' => ['min' => 0]],
    ],

    // ────────────────────────────────────────────────
    // P2 · 前缀匹配 → switch
    // ────────────────────────────────────────────────
    'prefix_switch' => ['is_', 'has_', 'can_', 'enable', 'disable'],

    // ────────────────────────────────────────────────
    // P3 · 后缀匹配
    //
    // **顺序即优先级**：先命中者胜。以下顺序即规范 §4.3 的「冲突消解顺序」：
    //   头像 > 图片 > 文件 > 关联 > 时间 > 富文本 > URL/颜色/邮箱/密码 > 长文本 > JSON
    //
    // 字段说明：
    //   semantics  语义标识（用于 --dry-run 输出与去重）
    //   match      匹配 token 列表（不带前导下划线）
    //   type       映射的表单字段类型
    //   multiple   是否多值
    //   db_type    可选的 DB 类型白名单（不满足则跳过本条，继续下一条）
    //   note       备注（写进 --dry-run 与生成物注释）
    // ────────────────────────────────────────────────
    'suffix_rules' => [
        // ① 头像（必须先于图片，否则 avatars 会被图片规则抢走）
        [
            'semantics' => 'avatar',
            'match'     => ['avatar'],
            'type'      => 'avatar',
            'db_type'   => ['varchar', 'char', 'string'],
            'note'      => '头像：存 URL 字符串，用头像选择器',
        ],

        // ② 图片（必须先于 file 与关联，才能实现 img_id = 图片）
        [
            'semantics' => 'image_multi',
            'match'     => [
                'imgs', 'images', 'img_ids', 'image_ids', 'img_list', 'image_list',
                'pics', 'photos', 'thumbs', 'covers', 'banners', 'logos', 'gallery',
            ],
            'type'     => 'image',
            'multiple' => true,
            'note'     => '多图：存附件 ID 的 JSON 数组 [1,23,45]',
        ],
        [
            'semantics' => 'image_single',
            'match'     => ['img', 'image', 'img_id', 'image_id', 'pic', 'photo', 'thumb', 'cover', 'banner', 'logo'],
            'type'      => 'image',
            'note'      => '单图：存附件 ID',
        ],

        // ③ 文件
        [
            'semantics' => 'file_multi',
            'match'     => ['files', 'attachs', 'attachments', 'file_ids', 'attach_ids', 'file_list'],
            'type'      => 'file',
            'multiple'  => true,
            'note'      => '多文件：存附件 ID 的 JSON 数组',
        ],
        [
            'semantics' => 'file_single',
            'match'     => ['file', 'attach', 'attachment'],
            'type'      => 'file',
            'note'      => '单文件：存附件 ID',
        ],

        // ④ 关联（**必须加 DB 类型约束**，否则 session_id(varchar) 会被误判）
        [
            'semantics' => 'relation_multi',
            'match'     => ['ids'],
            'type'      => 'select',
            'multiple'  => true,
            'db_type'   => ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'],
            'note'      => '多选关联（不猜关联表，生成 TODO）',
        ],
        [
            'semantics' => 'relation_single',
            'match'     => ['id'],
            'type'      => 'select',
            'db_type'   => ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'],
            'note'      => '单选关联（不猜关联表，生成 TODO）',
        ],

        // ⑤ 时间
        [
            'semantics' => 'datetime',
            'match'     => ['time', 'at'],
            'type'      => 'datetime',
            'note'      => '时间（下划线边界，不误伤 runtime/lifetime）',
        ],
        [
            'semantics' => 'date',
            'match'     => ['date'],
            'type'      => 'date',
        ],

        // ⑥ 富文本（**仅 text 系列**；varchar 不加富文本）
        [
            'semantics' => 'editor',
            'match'     => ['content', 'detail', 'rich', 'html'],
            'type'      => 'editor',
            'db_type'   => ['text', 'mediumtext', 'longtext', 'smalltext', 'tinytext'],
            'note'      => '富文本（仅 text 系列列类型）',
        ],

        // ⑦ 标量语义
        ['semantics' => 'url',      'match' => ['url', 'link'],                'type' => 'text',     'props' => ['rule' => 'url']],
        ['semantics' => 'color',    'match' => ['color', 'colour'],            'type' => 'color'],
        ['semantics' => 'email',    'match' => ['email', 'mail'],              'type' => 'email'],
        ['semantics' => 'password', 'match' => ['password', 'pwd', 'secret'],  'type' => 'password'],
        ['semantics' => 'ip',       'match' => ['ip'],                         'type' => 'text',     'props' => ['rule' => 'ip']],

        // ⑧ 长文本
        ['semantics' => 'longtext', 'match' => ['desc', 'description', 'remark', 'memo', 'note', 'summary'], 'type' => 'textarea'],

        // ⑨ JSON
        ['semantics' => 'json',     'match' => ['json', 'config', 'extra', 'meta'], 'type' => 'textarea'],
    ],

    // ────────────────────────────────────────────────
    // P4 · DB 列类型兜底（= 提案 §4 的映射表）
    // ────────────────────────────────────────────────
    'db_type_map' => [
        'tinyint'  => 'number', 'smallint' => 'number', 'mediumint' => 'number',
        'int'      => 'number', 'bigint'   => 'number',
        'decimal'  => 'number', 'float'    => 'number', 'double'    => 'number',
        'char'     => 'text',   'varchar'  => 'text',
        'tinytext' => 'textarea', 'text' => 'textarea', 'mediumtext' => 'textarea', 'longtext' => 'textarea',
        'date'     => 'date',   'time' => 'time', 'datetime' => 'datetime', 'timestamp' => 'datetime', 'year' => 'date',
        'json'     => 'textarea',
    ],

    // tinyint(1) 视为布尔（DB 类型兜底层内的细化）
    'bool_column_types' => ['tinyint(1)', 'bit(1)', 'bool', 'boolean'],

    // ────────────────────────────────────────────────
    // P5 · 默认
    // ────────────────────────────────────────────────
    'default_type' => 'text',

    // ────────────────────────────────────────────────
    // 其他生成器行为
    // ────────────────────────────────────────────────
    'generator' => [
        // 表前缀自动剥离（留空则读 config/database.php 的 prefix）
        'table_prefix'  => '',
        // 菜单落库使用的 plugin 标识（宿主层固定为 system）
        'menu_plugin'   => 'system',
        // 生成物根路径（相对项目根）
        'controller_dir' => 'app/admin/controller',
        'model_dir'      => 'app/admin/model',
        'migration_dir'  => 'database/migrations',
        'js_dir'         => 'public/statics/admin',
    ],
];

<?php

declare(strict_types=1);

/**
 * CRUD 字段映射声明（按表归档）
 *
 * 作用：把「这张表这个字段该用什么组件」的人工判定**固化下来**，可 review、可 diff、可回滚。
 * 与命令行 `--field=` 的区别：后者是一次性的，前者是团队共享的声明。
 *
 * 优先级（高者胜）：
 *   config/crud.php 的 overrides  <  table_defaults  <  fields  <  命令行/Web 的 --field
 *
 * 结构示例：
 * ```php
 * return [
 *     '__enabled' => true,                       // 一键回滚：false 时完全忽略本文件
 *     'ws_article' => [
 *         'class'          => 'Article',                 // 可选：覆盖类名推导
 *         'title'          => '文章',                    // 可选：覆盖菜单标题
 *         'table_defaults' => ['type' => 'text'],        // 可选：表级兜底（写入 `表名.*`）
 *         'fields'         => [                          // 字段级声明
 *             'status' => ['type' => 'radio', 'options' => ['0' => '禁用', '1' => '启用']],
 *             'cover'  => ['type' => 'image'],
 *         ],
 *         'relations'      => [                          // 关联字段：字段 => 目标表/值列/显示列
 *             'dept_id' => ['table' => 'ws_dept', 'value' => 'id', 'label' => 'name'],
 *         ],
 *         'ignore'         => ['remark'],                 // 不进表单
 *     ],
 * ];
 * ```
 *
 * 用法：
 *   php think wise:crud ws_article --dry-run           # 先看判定（会标注哪些来自本文件）
 *   php think wise:crud ws_article --no-map            # 临时忽略本文件，对照纯启发式结果
 *   php think wise:crud ws_article --field=... --save-map   # 把显式声明合并回本文件
 *
 * 注意：文件名用下划线（crud_map）而不是 `crud.map` —— ThinkPHP 以文件名作配置键，
 * 含点的键会与 `config('a.b')` 的点号路径语法冲突。
 */

return [
    // '__enabled' => true,
];

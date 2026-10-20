<?php
/**
 * 后台安全入口配置
 *
 * 作用：隐藏后台真实地址。启用后，访问 /admin 下的任意地址（含 GET/POST）
 * 前，必须先访问 ADMIN_ENTRY 配置的入口地址，否则统一返回 404。
 *
 * 启用步骤：
 *  1. 确认 .env 中已生成 ADMIN_ENTRY（CLI/Web 安装时自动写入 24 位随机串，
 *     已安装站点也可手动在 .env 中追加 ADMIN_ENTRY=<你的随机入口>）；
 *  2. 将下方 'enabled' 改为 true。
 *
 * 安全提示：入口地址属于机密信息，切勿在聊天/截图/文档中随意扩散；
 * 若怀疑泄露，修改 .env 中的 ADMIN_ENTRY 即可立即生效。
 */
return [
    // 启用状态（true=开启，false=关闭；默认关闭）
    'enabled' => false,

    // 未通过入口时的 HTTP 状态码：404=伪装页面不存在（推荐），403=禁止访问
    'code' => 404,

    // 入口有效期（秒）：通过入口后允许进入后台的时长，默认 2 小时；
    // 已登录管理员不受影响，会在访问后台时自动续期。
    'ttl' => 7200,

    // 同一 IP 在探测窗口内直接访问 /admin（未带入口）达到次数后，临时封禁该 IP
    'probe_limit'  => 10,    // 触发封禁的探测次数
    'probe_window' => 300,   // 探测计数窗口（秒）
    'probe_ban'    => 900,   // 临时封禁时长（秒）

    // 可选：追加无需入口校验的公共应用（追加到内置豁免集 api/plugin/member/index/install；
    // admin 永远不可豁免，即使写在此处也会被强制剔除）。示例：
    // 'except_list' => ['openapi'],

    // 后台安全入口列表：只读 .env 的 ADMIN_ENTRY（由安装器自动生成唯一随机值）。
    'entry_list' => array_values(array_filter([
        env('ADMIN_ENTRY'),
    ])),
];

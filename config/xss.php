<?php
/**
 * XSS 全局过滤配置
 *
 * XssFilterMiddleware 提供三层纵深防护：
 *   1. 输入清洗 — strip_input 对 GET/POST/COOKIE 做过滤
 *   2. 安全响应头 — CSP / X-Content-Type-Options 等
 *   3. 响应体过滤 — strip_response 对 HTML 输出做兜底转义（默认关闭）
 */
return [
    // 总开关
    'enabled' => true,

    // ============================================================
    // 第一层：输入清洗
    // ============================================================

    // 是否清洗 GET/POST/COOKIE 中的恶意内容
    'strip_input' => false,

    // 是否使用 strip_tags 移除 HTML 标签
    // true:  主动 strip_tags（适用于纯文本输入场景）
    // false: 仅做关键字符转义，保留原始内容（适用于富文本场景）
    'allow_strip_tags' => false,

    // ============================================================
    // 第二层：安全响应头
    // ============================================================

    // 是否添加安全响应头（CSP / X-Content-Type-Options 等）
    'security_headers' => true,

    // CSP 策略 — 留空使用默认（同源 + unsafe-inline + nonce）
    // 默认 script-src: 'self' 'unsafe-inline' 'nonce-{随机}'
    //   unsafe-inline 用于兼容 onclick 等内联事件处理器（nonce 无法覆盖）
    //   nonce 为未来收紧策略预留（移除 unsafe-inline 后仅允许 nonce 白名单脚本）
    // 如需接入 CDN / 第三方脚本，按需覆盖具体指令：
    // 'csp' => [
    //     'script-src' => "'self' 'unsafe-inline' https://cdn.example.com",
    //     'style-src'  => "'self' 'unsafe-inline' https://cdn.example.com",
    // ],
    'csp' => [],

    // ============================================================
    // 第三层：响应体过滤（默认关闭 — 性能敏感）
    // ============================================================

    // 是否清洗 text/html 响应体
    // 仅在排查反射型 XSS 或对第三方插件安全性存疑时开启
    'strip_response' => false,

    // ============================================================
    // 白名单 — 排除的路由（不经过 XSS 过滤）
    // 支持通配符 *（如 'api/*'）
    // ============================================================
    'except' => [
        // API 路由通常不是 HTML 场景，跳过以免破坏 JSON 响应
        'api/*',
    ],
];

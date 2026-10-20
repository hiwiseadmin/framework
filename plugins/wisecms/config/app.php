<?php
/**
 * CMS 插件配置
 */
return [
    'name'          => 'wisecms',
    'title'         => 'CMS内容管理',
    'description'   => '企业级内容管理系统',
    'author'        => 'WiseAdmin',
    'version'       => '2.0.0',
    'status'        => 1,
    
    // 站点设置
    'site_name'             => '我的网站',
    'site_url'              => '',
    
    // 内容设置
    'content_check'         => 1,           // 0不审 1先审后发 2先发后审
    'auto_description'      => true,        // 自动摘要
    'auto_thumb'            => true,        // 自动缩略图
    'max_title_length'      => 200,
    
    // URL 规则（详见 docs/wisecms-url-rule-design.md）
    // url_rewrite=0 时统一走查询串（/cms/content/detail?id=N），与旧站完全一致；
    // 置 1 后按规则表（wisecms_url_rule）生成伪静态，表为空时用下列内置模板兜底。
    'url_rewrite'           => 0,
    'url_suffix'            => '.html',
    'url_rule'              => '/{catdir}/{slug}.html',        // 内容详情默认规则
    'category_url_rule'     => '/{catdir}/',                   // 栏目列表默认规则
    'archive_url_rule'      => '/{catdir}/list_{page}.html',   // 栏目分页默认规则
    'tag_url_rule'          => '/tag/{alias}/',                // 标签页默认规则
    'page_url_rule'         => '/{alias}.html',                // 单页默认规则
    
    // 分页
    'list_rows'             => 15,
    
    // 上传
    'image_max_size'        => 2048,        // KB
    'image_ext'             => 'jpg,jpeg,png,gif,webp',
    'file_max_size'         => 10240,
    'file_ext'              => 'doc,docx,xls,xlsx,pdf,zip,rar',
    
    // 缓存
    'cache_time'            => 3600,
    'content_cache'         => true,

    // Feed 订阅（RSS 2.0 / Atom 1.0，详见 docs/wisecms-feed-design.md）
    'feed_enable'           => true,        // 总开关（关闭后 feed 端点 404）
    'feed_format'           => 'rss',       // 默认格式：rss / atom
    'feed_limit'            => 20,          // 条目数（内部收敛到 1..100）
    'feed_scope'            => 'full',      // full（含 content:encoded 全文）/ summary（仅摘要）
    'feed_ttl'              => 900,         // 缓存秒数
    'feed_title'            => '',          // 留空回退 site_name
    'feed_description'      => '',          // 留空回退 feed_title + ' 内容订阅'
    'feed_copyright'        => '',          // 可选
    
    // 推荐位（相关文章 / 热门 / 编辑推荐，详见 docs/wisecms-recommend-design.md）
    'recommend_enable'        => true,      // 总开关
    'recommend_related_limit' => 8,         // 相关文章条数（内部收敛 1..20）
    'recommend_hot_limit'     => 8,         // 热门条数
    'recommend_picked_limit'  => 8,         // 编辑推荐条数
    'recommend_hot_days'      => 0,         // 热门时间窗口（天），0 = 不限
    'recommend_cache_ttl'     => 1800,      // 缓存秒数
    'recommend_cache_limit'   => 20,        // 缓存/扫描上限（内部收敛 1..100）
    'recommend_fill'          => false,     // 结果不足时用同栏目热门补足
    
    // 模板标签库（{cms:arclist} 等，C-3，详见 docs/wisecms-template-taglib-design.md §13）
    'tag_enable'              => true,      // 标签库总开关（关闭后所有 {cms:*} 输出空）
    'tag_cache_ttl'           => 900,       // 标签数据缓存秒数
    'tag_arclist_limit_max'   => 50,        // 列表标签单次最大条数（内部收敛 1..50）
    'tag_default_limit'       => 10,        // 未指定 limit 时的默认条数
    // {cms:sql} 开关（P0-3：已从 manifest 配置面板移除，仅可在此部署级开关处启用；
    // 运行时另有「表名显式白名单 + select/from 等关键词黑名单」双闸门，不要在前端暴露本项）
    'tag_sql_enable'          => false,
    'tag_debug'               => false,     // 输出标签执行信息 HTML 注释（调试用）
    
    // 草稿预览（Preview Token，B-3，详见 docs/wisecms-preview-design.md）
    'preview_enable'        => true,        // 总开关（关闭后预览端点 404、后台签发报错）
    'preview_ttl'           => 1800,        // 令牌有效期秒数（内部收敛到 60..86400）
    'preview_secret'        => '',          // 签名密钥；留空回退 APP_KEY；两者皆空则功能不可用
    // P1-A7：默认开启「绑定签发用户」。关闭后仅校验 id+site+exp，
    // 链接一旦泄露，TTL（最长 24h）内任何人可打开他人草稿。
    'preview_bind_user'     => true,
    // P1-A7：一次性令牌（同一链接只能打开一次；开启后刷新页面即失效，按需启用）
    'preview_once'          => false,

    // Webhook 投递（P1-B7）
    // auto=跟随 wise-webhook 的 server.dispatch.default_mode；queue=强制入队（需部署 worker）；sync=强制同步
    'webhook_mode'          => 'auto',
    // 同步模式下单个订阅的超时（秒）：N 个订阅串行投递，最坏阻塞 = N × 本值
    'webhook_timeout'       => 5,

    // 友情链接（B-1，详见 docs/wisecms-friendlink-design.md）
    'friendlink_enable'     => true,        // 总开关（关闭后前台不输出，后台仍可管理）
    'friendlink_ttl'        => 1800,        // 前台列表缓存秒数
    'friendlink_max'        => 50,          // 页脚直出条数上限（0=不限）
    'friendlink_nofollow'   => false,       // 全局默认是否 nofollow（单条可覆盖）
    'friendlink_show_desc'  => false,       // 独立友链页是否输出描述
    
    // 评论
    'comment_check'         => 1,
    'comment_limit'         => 10,
    'comment_interval'      => 60,
    
    // SEO
    'seo_title_format'      => '{title} - {site_name}',
    'sitemap_auto'          => true,
    'sitemap_limit'         => 1000,
    'default_og_image'      => '',
    'auto_push_baidu'       => false,
    'auto_push_google'      => false,
    'auto_push_bing'        => false,
    
    // SEO 秘钥（建议通过 .env 配置）
    'seo' => [
        'baidu_token'       => env('wisecms.baidu_token', ''),
        'bing_api_key'      => env('wisecms.bing_api_key', ''),
    ],
    
    // 质量评分
    'quality_publish_threshold' => 60,  // 低于此分拒绝发布
    'quality_warn_threshold'    => 80,  // 低于此分提示优化
    
    // 编辑器
    'editor_mode'           => 'html',      // html|markdown
    'editor_toolbar'        => 'full',
    'editor_height'         => 500,
    
    // 调试
    'debug'                 => env('wisecms.debug', false),
];

<?php
declare(strict_types=1);

/**
 * wise-rag 默认配置
 *
 * 宿主可在 config/wise-rag.php 中覆盖任意键（ServiceTrait::mergeConfig 递归合并，宿主优先）。
 *
 * 说明：本文件的 class 常量指向 P1+ 才会实现的驱动/解析器；PHP 的 `::class` 为编译期
 * 常量解析，不触发自动加载，因此在此声明「尚不存在的实现」是安全的。
 */
return [
    // =========================================================================
    // 总开关
    // =========================================================================
    'enable' => true,

    // =========================================================================
    // Embedding
    // =========================================================================
    // source 决定鉴权 / 端点来源：
    //   auto  = 与 wise-agent.providers 同名则复用其 api_key/base_url，否则用下方独立配置（默认）
    //   agent = 强制复用 wise-agent.providers
    //   own   = 只用本包独立配置
    // 说明：chat 与 embedding 往往不是同一家最优，故 default 与各 provider 的 model/dimension 独立可配。
    'embedding' => [
        'source'    => env('RAG_EMBEDDING_SOURCE', 'auto'),
        'default'   => env('RAG_EMBEDDING', 'openai'),
        'providers' => [
            'openai' => [
                'class'      => \wise\rag\client\OpenAiEmbeddingClient::class,
                'api_key'    => env('OPENAI_API_KEY', ''),
                'base_url'   => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model'      => 'text-embedding-3-small',
                'dimension'  => 1536,
                'batch_size' => 64,
                'timeout'    => 30,
            ],
            // 独立 provider（chat 与 embedding 可不同源，按成本 / 模型强项取舍）：
            // 'bge'    => ['class' => \wise\rag\client\OpenAiEmbeddingClient::class, 'model' => 'bge-m3', 'dimension' => 1024],
            // 'zhipu'  => ['class' => \wise\rag\client\OpenAiEmbeddingClient::class, 'model' => 'embedding-3', 'dimension' => 2048],
            // 'ollama' => ['class' => \wise\rag\client\OpenAiEmbeddingClient::class, 'base_url' => 'http://127.0.0.1:11434/v1', 'dimension' => 1024],
        ],
    ],

    // =========================================================================
    // 向量存储
    // =========================================================================
    'vector' => [
        // 默认 database（PHP 暴力余弦，零外部依赖）；规模增大后再切 pgvector / redis 等
        'default' => env('RAG_VECTOR_STORE', 'database'),
        'stores'  => [
            'database' => [
                'class'          => \wise\rag\vector\DatabaseVectorStore::class,
                // 扫描分页大小：检索按主键游标分批取向量打分，
                // 峰值内存 ≈ 页大小 × 维度 × 4B（1000 × 1536 × 4B ≈ 6MB）
                'scan_page_size' => (int) env('RAG_SCAN_PAGE_SIZE', 1000),
            ],
            // 'pgvector' => ['class' => \wise\rag\vector\PgVectorStore::class, 'dsn' => env('RAG_PG_DSN', '')],
            // 'redis'    => ['class' => \wise\rag\vector\RedisVectorStore::class, 'prefix' => 'wise:rag:'],
            // 'qdrant'   => ['class' => \wise\rag\vector\QdrantVectorStore::class, 'endpoint' => '', 'api_key' => ''],
            // 'milvus'   => ['class' => \wise\rag\vector\MilvusVectorStore::class, 'endpoint' => '', 'token' => ''],
        ],

        // 暴力检索规模告警阈值（分块总数；0 = 关闭提示）。
        // 超过该值时 wise:rag:stats / wise:rag:prune 会提示切换到带 ANN 索引的驱动。
        'brute_warn_threshold' => (int) env('RAG_BRUTE_WARN_THRESHOLD', 50000),
    ],

    // =========================================================================
    // 关键词检索
    // =========================================================================
    'keyword' => [
        // mysql_fulltext（走全文索引，中文用 ngram）| like（全表扫描，回退用）| redis
        'driver'     => env('RAG_KEYWORD_DRIVER', 'mysql_fulltext'),
        'parser'     => 'ngram',   // MySQL FULLTEXT 分词器（中文必须 ngram）
        'boolean'    => true,      // 使用 BOOLEAN MODE
        'min_length' => 2,         // 查询短于该长度 → 回退 like（ngram 单字无法命中）
        'fallback'   => 'like',    // FULLTEXT 不可用时自动回退的驱动
        'max_terms'  => 8,         // like 回退时拼接的最大词数（防慢查询）
        'cache_ttl'  => 3600,      // FULLTEXT 可用性探测结果缓存（秒）
        // 可替换 / 追加驱动（插件亦可 mergeConfig）
        'drivers'    => [
            'mysql_fulltext' => ['class' => \wise\rag\keyword\FulltextDriver::class],
            'like'           => ['class' => \wise\rag\keyword\LikeDriver::class],
        ],
    ],

    // =========================================================================
    // 分块
    // =========================================================================
    'chunk' => [
        'size'      => 500,
        'overlap'   => 80,
        'splitter'  => 'structure',   // structure | fixed | recursive
        'min_chars' => 20,            // 过滤噪声块
    ],

    // =========================================================================
    // 检索
    // =========================================================================
    'retrieval' => [
        'top_k'             => 8,
        'min_score'         => 0.25,
        'hybrid'            => true,
        'fusion'            => 'rrf',   // rrf | weighted
        'rrf_k'             => 60,
        'vector_weight'     => 0.7,
        'keyword_weight'    => 0.3,
        'max_context_chars' => 6000,
        'dedup'             => true,    // 已实现：同一文本只保留得分最高的一条
        'query_rewrite'     => false,   // 预留：未实现（P5+ 走 wise-agent LLM 做多查询 / HyDE）
        'log_enabled'       => false,   // 是否写入 ai_kb_retrieval_logs（观测，默认关）
        'rerank'            => [
            'enabled'  => false,
            'provider' => '',
            'top_n'    => 4,
        ],
    ],

    // =========================================================================
    // 重排（rerank）
    // =========================================================================
    'rerank' => [
        'default'   => '',   // 留空 = 不重排；等价于 retrieval.rerank.enabled=false
        'providers' => [
            // 例（BGE-reranker 服务 / Cohere / Jina 等）：
            // 'bge' => [
            //     'class'    => \wise\rag\rerank\HttpReranker::class,
            //     'endpoint' => 'http://127.0.0.1:8080/rerank',
            //     'api_key'  => '',
            //     'model'    => 'bge-reranker-v2-m3',
            //     'timeout'  => 30,
            // ],
        ],
    ],

    // =========================================================================
    // 解析器（按 mime / 扩展名路由）
    // =========================================================================
    'parser' => [
        'max_file_size' => 20 * 1024 * 1024,
        'mapping'       => [
            'text/plain'    => \wise\rag\parser\TextParser::class,
            'text/markdown' => \wise\rag\parser\MarkdownParser::class,
            'text/html'     => \wise\rag\parser\HtmlParser::class,
            'application/pdf' => \wise\rag\parser\PdfParser::class,
            // 需安装 phpoffice/phpword 与 phpoffice/phpspreadsheet 后再启用
            // （未安装时 ParserRegistry 会静默跳过，但保留声明会让配置看起来支持而实际不可用）
            // 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => \wise\rag\parser\DocxParser::class,
            // 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => \wise\rag\parser\XlsxParser::class,
        ],
    ],

    // =========================================================================
    // 摄取
    // =========================================================================
    'ingestion' => [
        'async'      => true,   // 走 think-queue
        'connection' => 'database',
        'queue'      => 'default',
        'batch_size' => 500,
        'max_retry'  => 3,
    ],

    // =========================================================================
    // 知识源驱动
    // =========================================================================
    'sources' => [
        'providers' => [
            'manual'     => ['class' => \wise\rag\source\ManualSource::class],
            'table'      => ['class' => \wise\rag\source\TableSource::class],
            'attachment' => ['class' => \wise\rag\source\AttachmentSource::class],
        ],
    ],

    // =========================================================================
    // 插件知识源发现
    // =========================================================================
    'discovery' => [
        // 预留：未实现（discover 后是否自动索引；默认否，命令用 --index 显式触发）
        'auto_index' => false,
    ],

    // =========================================================================
    // 语义缓存：缓存 embedding 结果，避免重复调用（省成本）
    // =========================================================================
    'cache' => [
        'enabled'   => env('RAG_CACHE_ENABLED', false),
        'driver'    => env('RAG_CACHE_DRIVER', 'database'),
        'keep_days' => 30,   // prune 清理多少天未使用的缓存（0 = 不清理）
        'drivers'   => [
            'database' => [
                'class' => \wise\rag\cache\DatabaseEmbeddingCache::class,
                // 进程内 memo 条目上限：常驻进程（Swoole / 队列 worker）下驱动实例会被
                // 容器长期持有，不设上限会随处理过的文本数无限增长
                'memo_limit' => (int) env('RAG_CACHE_MEMO_LIMIT', 500),
            ],
        ],
    ],

    // =========================================================================
    // MCP 外溢：把知识库暴露给外部 MCP 客户端
    // =========================================================================
    'mcp' => [
        'expose_tools'      => true,   // 暴露 kb.search 为 MCP Tool
        'expose_resources'  => true,   // 暴露 kb://index、kb://{kb_key}/docs 等资源
        'max_doc_resources' => 100,    // 每个知识库暴露的文档级资源上限（0 = 不暴露文档级）
    ],

    // =========================================================================
    // 与 wise-agent 集成
    // =========================================================================
    'agent' => [
        'auto_tool'      => true,   // 启动时自动向 wise-agent 注册检索工具
        'global_tool'    => true,   // 是否注册全库检索工具 kb.search
        'default_top_k'  => 5,      // 工具默认返回条数
        'max_results'    => 20,     // 单次检索返回上限（防 LLM 传大值）
        'tool_cache_ttl' => 60,     // 知识库清单缓存秒数（0 = 不缓存，每请求查库）
        // 说明：原 `tool_namespace` 键已移除 —— 本包刻意注册在默认命名空间，
        // 使 LLM 函数名等同于注册键（详见 KnowledgeToolRegistrar 类注释），
        // 保留该键会让人误以为命名空间可配置。
        'inject_context' => false,  // 预留：未实现（检索结果上下文注入）
    ],

    // =========================================================================
    // 统计概览（后台首页）
    // =========================================================================
    'stats' => [
        // 概览结果缓存秒数（0 = 不缓存）。
        // vectors 取自 ai_kb_knowledge_bases.vector_count 求和，不再扫描 vector 大列；
        // 计数变更（摄取 / 删除文档 / 增删知识库）会主动失效该缓存。
        'cache_ttl' => (int) env('RAG_STATS_CACHE_TTL', 60),
    ],

    // =========================================================================
    // 检索评测
    // =========================================================================
    'eval' => [
        'top_k' => 5,
    ],

    // =========================================================================
    // Web 路由（配置驱动，由 RouteRegistrar 消费）
    // =========================================================================
    'web' => [
        'enable'     => true,
        // 归属应用：admin / api / member / plugin；留空则按 prefix 首段嗅探
        'app'        => 'admin',
        'prefix'     => 'admin/kb',
        'middleware' => [],
        'groups'     => [
            'admin' => [
                'enable' => true,
                // RBAC 中间件（/admin/kb/* 涉及知识库、文档与上传，必须鉴权）。
                //
                // 说明：
                //   1. 宿主 app/admin/middleware.php 已在应用级挂载 RbacMiddleware，此处再挂一次
                //      属**重复执行**；因该中间件幂等（仅多一次登录态与权限查询），代价可接受，
                //      换来的是「web.app 被改成其它应用 / prefix 被改动」时仍受保护，
                //      不会静默把管理端点暴露成公开接口。
                //   2. wise-auth 未安装时自动降级为空数组，不影响路由注册。
                //   3. 如需自行控制，把本键改为自己的中间件列表（同键的**列表为覆盖语义**），
                //      置 [] 即关闭。
                'middleware' => class_exists(\wise\auth\middleware\RbacMiddleware::class)
                    ? [\wise\auth\middleware\RbacMiddleware::class]
                    : [],
            ],
        ],
    ],
];

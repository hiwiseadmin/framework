<?php
namespace plugin\wisecms\service\url;

use plugin\wisecms\model\UrlRule;

/**
 * URL 规则服务
 *
 * 职责：
 *   1. 维护「类型 + 作用域」的 URL 模式模板（pattern）；
 *   2. 按优先级解析实体应采用的有效模板（entity > category > model > global，站点精确 > 全站）；
 *   3. 渲染模板（token 替换）与编译模板为正则（供伪静态反向解析）。
 *
 * 模式模板 token：
 *   {id} {catid} {model} {slug} {alias} {tag} {catdir} {year} {month} {day} {page}
 *
 * 落地约定：
 *   - 规则表为空 / 未迁移时自动降级为 config('wisecms.*_url_rule') 的内置模板；
 *   - 模板解析与渲染为纯逻辑，不依赖请求上下文，便于单元测试。
 */
class UrlRuleService
{
    /** 支持的实体类型 */
    public const TYPES = ['content', 'category', 'tag', 'page'];

    /** 作用域（与 model\UrlRule 的常量取值保持一致；此处独立定义以便纯逻辑测试） */
    public const SCOPE_GLOBAL   = 'global';
    public const SCOPE_MODEL    = 'model';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_ENTITY   = 'entity';

    /** 各类型的内置预设（后台下拉可直接复用） */
    public const PRESETS = [
        'content' => [
            '/{catdir}/{slug}.html'  => '栏目路径/别名.html',
            '/{catdir}/{id}.html'    => '栏目路径/ID.html',
            '/{slug}.html'           => '别名.html（扁平）',
            '/article/{id}.html'     => 'article/ID.html',
        ],
        'category' => [
            '/{catdir}/'                   => '栏目路径/',
            '/{catdir}/list_{page}.html'   => '栏目路径/list_页码.html',
            '/{alias}/'                    => '别名/（扁平）',
        ],
        'tag' => [
            '/tag/{alias}/'                => 'tag/别名/',
            '/tag/{alias}/list_{page}.html' => 'tag/别名/list_页码.html',
        ],
        'page' => [
            '/{alias}.html'          => '别名.html',
        ],
    ];

    /** token → 正则（用于把模板编译为反向解析正则；catdir 允许多级） */
    protected const TOKEN_RULE = [
        'id'     => '\d+',
        'catid'  => '\d+',
        'model'  => '\d+',
        'page'   => '\d+',
        'year'   => '\d{4}',
        'month'  => '\d{2}',
        'day'    => '\d{2}',
        'catdir' => '[^/]+(?:/[^/]+)*',
        'slug'   => '[^/]+',
        'alias'  => '[^/]+',
        'tag'    => '[^/]+',
    ];

    /** 作用域权重：决定同类型规则的优先次序 */
    protected const SCOPE_WEIGHT = [
        self::SCOPE_ENTITY   => 300,
        self::SCOPE_CATEGORY => 200,
        self::SCOPE_MODEL    => 100,
        self::SCOPE_GLOBAL   => 0,
    ];

    /** 规则缓存（进程内） */
    protected ?array $ruleCache = null;

    // ══════════════════════════ 规则解析 ══════════════════════════

    /**
     * 解析实体应采用的有效模式模板；无规则时回退内置模板
     *
     * @param string $type    content|category|tag|page
     * @param array  $context site_id / id / catid / model_id
     */
    public function resolvePattern(string $type, array $context = []): string
    {
        $rules = $this->matchRules($type, $context);
        foreach ($rules as $rule) {
            if (($rule['pattern'] ?? '') !== '') {
                return (string) $rule['pattern'];
            }
        }
        return $this->fallbackPattern($type);
    }

    /**
     * 匹配规则并按有效优先级降序返回
     */
    public function matchRules(string $type, array $context = []): array
    {
        $siteId   = (int) ($context['site_id'] ?? 0);
        $entityId = (int) ($context['id'] ?? 0);
        $catid    = (int) ($context['catid'] ?? 0);
        $modelId  = (int) ($context['model_id'] ?? 0);

        $matched = [];
        foreach ($this->allRules() as $rule) {
            if (($rule['type'] ?? '') !== $type) {
                continue;
            }

            $ruleSite = (int) ($rule['site_id'] ?? 0);
            if ($ruleSite !== 0 && $ruleSite !== $siteId) {
                continue;
            }

            $scopeId = (int) ($rule['scope_id'] ?? 0);
            $ok = match ($rule['scope_type'] ?? self::SCOPE_GLOBAL) {
                self::SCOPE_ENTITY   => $entityId > 0 && $scopeId === $entityId,
                self::SCOPE_CATEGORY => $catid > 0 && $scopeId === $catid,
                self::SCOPE_MODEL    => $modelId > 0 && $scopeId === $modelId,
                default              => true,
            };

            if ($ok) {
                $matched[] = $rule;
            }
        }

        usort($matched, fn ($a, $b) => $this->effectivePriority($b) <=> $this->effectivePriority($a));

        return $matched;
    }

    /**
     * 有效优先级 = 自定义优先级 + 作用域权重 + 站点精确加成
     */
    protected function effectivePriority(array $rule): int
    {
        $base  = (int) ($rule['priority'] ?? 0);
        $scope = self::SCOPE_WEIGHT[$rule['scope_type'] ?? self::SCOPE_GLOBAL] ?? 0;
        $site  = (int) ($rule['site_id'] ?? 0) > 0 ? 500 : 0;

        return $base + $scope + $site;
    }

    /**
     * 内置兜底模板（读取 config('wisecms.*_url_rule')）
     */
    public function fallbackPattern(string $type): string
    {
        $map = [
            'content'  => 'url_rule',
            'category' => 'category_url_rule',
            'tag'      => 'tag_url_rule',
            'page'     => 'page_url_rule',
        ];
        $key = $map[$type] ?? '';

        return $key !== '' ? (string) config('wisecms.' . $key, '') : '';
    }

    /**
     * 全站/指定站点的全部启用规则（进程内缓存）
     */
    public function allRules(): array
    {
        if ($this->ruleCache !== null) {
            return $this->ruleCache;
        }

        try {
            $this->ruleCache = UrlRule::where('status', 1)
                ->order('priority', 'desc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            // 规则表尚未迁移时静默降级为内置模板
            $this->ruleCache = [];
        }

        return $this->ruleCache;
    }

    /**
     * 清除规则缓存（后台保存规则后调用）
     */
    public function clearCache(): void
    {
        $this->ruleCache = null;
    }

    // ══════════════════════════ 渲染 / 编译 ══════════════════════════

    /**
     * 渲染模板为路径
     *
     * @param string $pattern 模式模板
     * @param array  $tokens  token 值（缺失以空串代入）
     * @param int    $page    分页页码，1 时不带页码段
     */
    public function render(string $pattern, array $tokens, int $page = 1): string
    {
        if (str_contains($pattern, '{page}')) {
            if ($page > 1) {
                $pattern = str_replace('{page}', (string) $page, $pattern);
            } else {
                // 第 1 页去掉页码 token 及其紧邻分隔符（/ _ - .）
                $pattern = (string) preg_replace('#[/_.-]?\{page\}#', '', $pattern);
            }
        }

        if (str_contains($pattern, '{suffix}')) {
            $pattern = str_replace('{suffix}', (string) config('wisecms.url_suffix', '.html'), $pattern);
        }

        $path = (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn ($m) => (string) ($tokens[$m[1]] ?? ''),
            $pattern
        );

        return self::normalizePath($path);
    }

    /**
     * 模板中出现的 token 列表
     */
    public function tokensIn(string $pattern): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * 模板中无法识别的 token（后台保存时校验）
     */
    public function unknownTokens(string $pattern): array
    {
        return array_values(array_filter(
            $this->tokensIn($pattern),
            fn ($t) => !isset(self::TOKEN_RULE[$t]) && !in_array($t, ['suffix'], true)
        ));
    }

    /**
     * 把模式模板编译为反向解析正则（含命名捕获组）
     */
    public function toRegex(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        // 统一去掉尾部斜杠（解析时路径亦已规范化）
        $quoted = preg_quote(rtrim($pattern, '/'), '#');

        // preg_quote 会把 {token} 转义为 \{token\}，还原为命名捕获组
        $quoted = (string) preg_replace_callback(
            '/\\\\\{([a-z_]+)\\\\\}/',
            function ($m) {
                $token = $m[1];
                if ($token === 'suffix') {
                    return preg_quote((string) config('wisecms.url_suffix', '.html'), '#');
                }
                $rule = self::TOKEN_RULE[$token] ?? '[^/]+';
                return '(?P<' . $token . '>' . $rule . ')';
            },
            $quoted
        );

        $body = ltrim($quoted, '/');

        return '#^/?' . $body . '/?$#u';
    }

    /**
     * 路径规范化：去查询串、去首尾斜杠、折叠重复斜杠
     */
    public static function normalizePath(string $path): string
    {
        $path = (string) preg_replace('#\?.*$#', '', $path);
        $path = (string) preg_replace('#/+#', '/', $path);

        return trim($path, '/');
    }

    // ══════════════════════════ 规则 CRUD（后台） ══════════════════════════

    /**
     * 规则列表
     *
     * `site_id` 支持**数组**（UIUX-P0-19）：`0` 表示「全站通用」，而
     * {@see matchRules()} 对 `site_id=0` 的规则在任何站点都放行 ——
     * 因此后台列表必须能同时展示「全站」与「本站」规则。
     * 原实现只做精确匹配（`where('site_id', N)`）→ **全站通用规则在列表里根本看不见**。
     *
     * @param array{type?:string, site_id?:int|int[]} $filter
     */
    public function getRules(array $filter = []): array
    {
        $query = UrlRule::order('priority', 'desc')->order('id', 'asc');
        if (!empty($filter['type'])) {
            $query->where('type', $filter['type']);
        }
        if (isset($filter['site_id'])) {
            $siteIds = array_values(array_unique(array_map('intval', (array) $filter['site_id'])));
            if ($siteIds !== []) {
                $query->whereIn('site_id', $siteIds);
            }
        }

        return $query->select()->toArray();
    }

    /**
     * 新建/更新规则
     *
     * @param array $data type/pattern/scope_type/scope_id/site_id/priority/status[/id]
     */
    public function saveRule(array $data): UrlRule
    {
        $pattern = trim((string) ($data['pattern'] ?? ''));

        if (!in_array($data['type'] ?? 'content', self::TYPES, true)) {
            throw new \InvalidArgumentException('非法规则类型');
        }
        if ($pattern === '') {
            throw new \InvalidArgumentException('URL 模式模板不能为空');
        }
        $unknown = $this->unknownTokens($pattern);
        if ($unknown) {
            throw new \InvalidArgumentException('存在无法识别的占位符：{' . implode('}, {', $unknown) . '}');
        }

        $data['site_id']    = (int) ($data['site_id'] ?? 0);
        $data['scope_type'] = $data['scope_type'] ?? self::SCOPE_GLOBAL;
        $data['scope_id']   = (int) ($data['scope_id'] ?? 0);
        $data['priority']   = (int) ($data['priority'] ?? 0);
        $data['status']     = (int) ($data['status'] ?? 1);

        if (!empty($data['id'])) {
            $rule = UrlRule::find((int) $data['id']);
            if (!$rule) {
                throw new \InvalidArgumentException('规则不存在');
            }
            $rule->save($data);
        } else {
            $rule = UrlRule::create($data);
        }

        $this->clearCache();

        return $rule;
    }

    /**
     * 删除规则
     */
    public function deleteRule(int $id): bool
    {
        $rule = UrlRule::find($id);
        if ($rule) {
            $rule->delete();
            $this->clearCache();
        }

        return true;
    }
}

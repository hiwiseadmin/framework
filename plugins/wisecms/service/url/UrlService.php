<?php
namespace plugin\wisecms\service\url;

use plugin\wisecms\model\Category;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\Page;
use plugin\wisecms\model\Redirect;
use plugin\wisecms\model\Tag;
use plugin\wisecms\model\UrlAlias;
use think\facade\Db;

/**
 * CMS 前台 URL 服务 —— 全站唯一的 URL 事实源
 *
 * 任何模块都不再自行拼接 URL 字符串，统一经由本服务生成 / 解析：
 *   · 生成：content()/category()/tag()/page()/search()/home()，依据 URL 规则表渲染；
 *   · 索引：sync*() 在保存时写入 wisecms_url_alias（当前 URL + 历史 URL）；
 *   · 解析：resolvePath() 把伪静态路径还原为实体，历史 URL / 手工规则返回 301。
 *
 * 开关：config('wisecms.url_rewrite')
 *   0（默认）= 查询串形态（/cms/content/detail?id=N），与旧站完全一致，零风险；
 *   1        = 伪静态形态（按规则模板生成，如 /news/tech/hello.html）。
 *
 * 详见 docs/wisecms-url-rule-design.md
 */
class UrlService
{
    protected UrlRuleService $rules;

    /** 栏目路径缓存 id => alias 链 */
    protected array $catdirCache = [];

    /** 栏目映射缓存（一次查询） */
    protected ?array $categoryMap = null;

    public function __construct(?UrlRuleService $rules = null)
    {
        $this->rules = $rules ?? new UrlRuleService();
    }

    public function rules(): UrlRuleService
    {
        return $this->rules;
    }

    // ══════════════════════════ 基础 ══════════════════════════

    /** 是否启用伪静态 URL 规则 */
    public function rewriteEnabled(): bool
    {
        return (int) config('wisecms.url_rewrite', 0) === 1;
    }

    /** 站点根（无尾部斜杠） */
    public function siteBase(): string
    {
        $base = (string) config('wisecms.site_url', '');
        if ($base === '') {
            $base = (string) request()->domain();
        }

        return rtrim($base, '/');
    }

    /** 相对路径 → 绝对 URL（保留查询串） */
    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = ltrim($path, '/');

        return $this->siteBase() . ($path === '' ? '/' : '/' . $path);
    }

    // ══════════════════════════ 生成 ══════════════════════════

    public function home(): string
    {
        return $this->absolute('cms');
    }

    public function content($content): string
    {
        return $this->absolute($this->contentPath($content));
    }

    public function category($category, int $page = 1): string
    {
        return $this->absolute($this->categoryPath($category, $page));
    }

    public function tag($tag, int $page = 1): string
    {
        return $this->absolute($this->tagPath($tag, $page));
    }

    public function page($page): string
    {
        return $this->absolute($this->pagePath($page));
    }

    public function search(string $keyword, int $page = 1): string
    {
        $params = ['keyword' => $keyword];
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->absolute($this->queryPath('cms/search/index', $params));
    }

    /** 内容详情路径（无前导斜杠，规范化） */
    public function contentPath($content): string
    {
        return $this->buildPath(UrlAlias::TYPE_CONTENT, $content);
    }

    /** 栏目列表路径 */
    public function categoryPath($category, int $page = 1): string
    {
        return $this->buildPath(UrlAlias::TYPE_CATEGORY, $category, $page);
    }

    /** 标签页路径 */
    public function tagPath($tag, int $page = 1): string
    {
        return $this->buildPath(UrlAlias::TYPE_TAG, $tag, $page);
    }

    /** 单页路径 */
    public function pagePath($page): string
    {
        return $this->buildPath(UrlAlias::TYPE_PAGE, $page);
    }

    /**
     * 统一路径构建
     *
     * @param string $type    content|category|tag|page
     * @param mixed  $entity  模型对象或数组
     * @param int    $page    分页页码
     * @param bool|null $pretty null=按 url_rewrite 开关；true=强制伪静态（重建索引用）
     * @return string 规范化路径（无前导斜杠；rewrite 关闭时为 /cms/... 查询串路径）
     */
    public function buildPath(string $type, $entity, int $page = 1, ?bool $pretty = null): string
    {
        $pretty = $pretty ?? $this->rewriteEnabled();
        $e      = self::toArray($entity);

        if (!$pretty) {
            return $this->queryFallback($type, $e, $page);
        }

        $pattern = $this->rules->resolvePattern($type, $this->ruleContext($type, $e));
        if ($pattern === '') {
            return $this->queryFallback($type, $e, $page);
        }

        $tokens = $this->tokens($type, $e);

        // 栏目分页：模板未含 {page} 时改用归档模板
        $used = $pattern;
        if ($type === UrlAlias::TYPE_CATEGORY && $page > 1 && !str_contains($used, '{page}')) {
            $archive = (string) config('wisecms.archive_url_rule', '');
            if ($archive !== '' && str_contains($archive, '{page}')) {
                $used = $archive;
            }
        }

        $path = $this->rules->render($used, $tokens, $page);

        if (!$this->usable($path, str_replace('{page}', '', $used), $tokens)) {
            return $this->queryFallback($type, $e, $page);
        }

        // 模板无 {page} 但确需分页 → 追加查询串
        if ($page > 1 && !str_contains($used, '{page}')) {
            return $path . '?page=' . $page;
        }

        return $path;
    }

    /**
     * 分页 URL 模板（__PAGE__ 为页码占位符，供模板 str_replace）
     *
     * 模板含 {page}（如 list_{page}.html）时输出伪静态分页，
     * 否则回落「规范 URL + ?page=」形态；两者都能被伪静态入口解析。
     */
    public function paginationTemplate(string $type, $entity): string
    {
        $e = self::toArray($entity);

        $append = static function (string $path): string {
            return $path . (str_contains($path, '?') ? '&' : '?') . 'page=__PAGE__';
        };

        // 兜底：查询串分页
        $fallback = $this->absolute($append($this->queryFallback($type, $e, 1)));

        if (!$this->rewriteEnabled()) {
            return $fallback;
        }

        $pattern = $this->rules->resolvePattern($type, $this->ruleContext($type, $e));
        if ($type === UrlAlias::TYPE_CATEGORY && !str_contains($pattern, '{page}')) {
            $archive = (string) config('wisecms.archive_url_rule', '');
            if ($archive !== '' && str_contains($archive, '{page}')) {
                $pattern = $archive;
            }
        }

        if ($pattern === '' || !str_contains($pattern, '{page}')) {
            return $this->absolute($append($this->buildPath($type, $e, 1)));
        }

        $path = $this->rules->render(str_replace('{page}', '__PAGE__', $pattern), $this->tokens($type, $e), 1);
        if ($path === '' || str_contains($path, '{')) {
            return $fallback;
        }

        return $this->absolute($path);
    }

    /**
     * 查询串兜底路径（保持与旧站一致的 URL 形态）
     */
    protected function queryFallback(string $type, array $e, int $page = 1): string
    {
        switch ($type) {
            case UrlAlias::TYPE_CONTENT:
                $slug = (string) ($e['slug'] ?? '');
                return $this->queryPath('cms/content/detail', $slug !== '' ? ['slug' => $slug] : ['id' => (int) ($e['id'] ?? 0)]);

            case UrlAlias::TYPE_CATEGORY:
                $params = ['catid' => (int) ($e['id'] ?? 0)];
                if ($page > 1) {
                    $params['page'] = $page;
                }
                return $this->queryPath('cms/category/index', $params);

            case UrlAlias::TYPE_TAG:
                $params = ['id' => (int) ($e['id'] ?? 0)];
                if ($page > 1) {
                    $params['page'] = $page;
                }
                return $this->queryPath('cms/tag/index', $params);

            case UrlAlias::TYPE_PAGE:
                return $this->queryPath('cms/page/detail', ['id' => (int) ($e['id'] ?? 0)]);
        }

        return '';
    }

    // ══════════════════════════ 别名索引同步 ══════════════════════════

    public function syncContent($content): void
    {
        $c = self::toArray($content);
        $this->syncEntity(UrlAlias::TYPE_CONTENT, (int) ($c['id'] ?? 0), $this->contentPath($c), (int) ($c['site_id'] ?? 0));
    }

    public function syncCategory($category): void
    {
        $c = self::toArray($category);
        $this->catdirCache = [];
        $this->categoryMap = null;
        $this->syncEntity(UrlAlias::TYPE_CATEGORY, (int) ($c['id'] ?? 0), $this->categoryPath($c), (int) ($c['site_id'] ?? 0));
    }

    public function syncTag($tag): void
    {
        $t = self::toArray($tag);
        // P2：标签别名按所属站点落库（原实现固定 site_id=0 全局）——
        // url_alias 唯一键为 (site_id, url_path)，若沿用 0，两站的同名标签别名会互相顶掉，
        // 且前台按 `?site` 解析时无法区分站点。
        $this->syncEntity(UrlAlias::TYPE_TAG, (int) ($t['id'] ?? 0), $this->tagPath($t), (int) ($t['site_id'] ?? 0));
    }

    public function syncPage($page): void
    {
        $p = self::toArray($page);
        $this->syncEntity(UrlAlias::TYPE_PAGE, (int) ($p['id'] ?? 0), $this->pagePath($p), (int) ($p['site_id'] ?? 0));
    }

    /**
     * 写入/更新实体的别名索引
     *
     * 旧「当前 URL」会被标记为 is_current=0（历史），新 URL 置为当前，
     * 因此「改别名 → 旧链 301」自动成立。
     *
     * @param bool $force 忽略 url_rewrite 开关强制写入（重建索引用）
     */
    public function syncEntity(string $type, int $id, string $path, int $siteId = 0, bool $force = false): void
    {
        if ($id <= 0 || $path === '' || str_contains($path, '?')) {
            return;
        }
        if (!$force && !$this->rewriteEnabled()) {
            return;
        }

        $path = UrlRuleService::normalizePath($path);
        if ($path === '') {
            return;
        }

        try {
            Db::transaction(function () use ($type, $id, $path, $siteId) {
                $current = UrlAlias::where('entity_type', $type)
                    ->where('entity_id', $id)
                    ->where('is_current', 1)
                    ->find();

                if ($current && $current->url_path === $path && (int) $current->site_id === $siteId) {
                    return;
                }
                if ($current) {
                    $current->save(['is_current' => 0]);
                }

                $existing = UrlAlias::where('site_id', $siteId)->where('url_path', $path)->find();
                if ($existing) {
                    $existing->save(['entity_type' => $type, 'entity_id' => $id, 'is_current' => 1]);
                } else {
                    UrlAlias::create([
                        'site_id'     => $siteId,
                        'entity_type' => $type,
                        'entity_id'   => $id,
                        'url_path'    => $path,
                        'is_current'  => 1,
                        // create_time / update_time 由模型 autoWriteTimestamp 维护
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // 表未迁移 / 路径冲突时静默，不影响主流程
        }
    }

    /**
     * 删除实体的全部别名
     */
    public function removeAlias(string $type, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            UrlAlias::where('entity_type', $type)->where('entity_id', $id)->delete();
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 重建全部别名索引（清空后按规则重新生成）
     *
     * @return array<string,int> 各类型重建条数
     */
    public function rebuildAliases(): array
    {
        $stats = ['content' => 0, 'category' => 0, 'tag' => 0, 'page' => 0];

        $this->clearAliases();
        $this->catdirCache = [];
        $this->categoryMap = null;

        try {
            foreach (Category::select() as $row) {
                $this->syncEntity(UrlAlias::TYPE_CATEGORY, (int) $row->id, $this->buildPath(UrlAlias::TYPE_CATEGORY, $row, 1, true), (int) $row->site_id, true);
                $stats['category']++;
            }

            foreach (Content::whereNull('delete_time')->select() as $row) {
                $this->syncEntity(UrlAlias::TYPE_CONTENT, (int) $row->id, $this->buildPath(UrlAlias::TYPE_CONTENT, $row, 1, true), (int) $row->site_id, true);
                $stats['content']++;
            }

            foreach (Tag::where('site_id', '>', 0)->select() as $row) {
                // P2：标签别名按所属站点落库（原为固定 0，跨站同名别名会互相顶掉）
                $this->syncEntity(UrlAlias::TYPE_TAG, (int) $row->id, $this->buildPath(UrlAlias::TYPE_TAG, $row, 1, true), (int) $row->site_id, true);
                $stats['tag']++;
            }

            foreach (Page::select() as $row) {
                $this->syncEntity(UrlAlias::TYPE_PAGE, (int) $row->id, $this->buildPath(UrlAlias::TYPE_PAGE, $row, 1, true), (int) ($row->site_id ?? 0), true);
                $stats['page']++;
            }
        } catch (\Throwable $e) {
            // 单条失败不阻断整体
        }

        return $stats;
    }

    /** 清空别名索引 */
    public function clearAliases(): void
    {
        try {
            UrlAlias::where('id', '>', 0)->delete();
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    // ══════════════════════════ 解析（伪静态 → 实体 / 301） ══════════════════════════

    /**
     * 解析伪静态路径
     *
     * @return array|null
     *   ['entity_type'=>.., 'entity_id'=>.., 'page'=>1, 'redirect'=>null]  → 直接渲染
     *   ['redirect'=>'news/hello.html', ...]                                → 301
     *   null                                                                → 404
     */
    public function resolvePath(string $path, int $siteId = 0): ?array
    {
        $norm = UrlRuleService::normalizePath($path);
        if ($norm === '') {
            return null;
        }

        // 1) 别名索引精确匹配（当前 URL / 历史 URL）
        $alias = $this->findAlias($siteId, $norm);
        if ($alias) {
            $row = [
                'entity_type' => (string) $alias['entity_type'],
                'entity_id'   => (int) $alias['entity_id'],
                'page'        => 1,
                'redirect'    => null,
            ];

            if ((int) $alias['is_current'] === 1) {
                return $row;
            }

            // 历史 URL → 301 到当前 URL
            $current = UrlAlias::where('entity_type', $row['entity_type'])
                ->where('entity_id', $row['entity_id'])
                ->where('is_current', 1)
                ->find();

            return $current ? array_merge($row, ['redirect' => (string) $current->url_path]) : null;
        }

        // 2) 规则正则反查（覆盖分页等未入索引的派生 URL）
        $hit = $this->resolveByPattern($norm, $siteId);
        if ($hit !== null) {
            return $hit;
        }

        // 3) 后台手工配置的 301
        try {
            // P1-C：重定向按站点隔离（原实现无 site 条件 → 其它站点配置的规则会在本站生效）
            $redirect = Redirect::where('site_id', $siteId)
                ->where('source_url', $norm)
                ->where('status', 1)
                ->find();
            if ($redirect) {
                return ['redirect' => (string) $redirect->target_url];
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        return null;
    }

    /**
     * 按规则模板反查实体
     */
    protected function resolveByPattern(string $norm, int $siteId): ?array
    {
        foreach ($this->rules->allRules() as $rule) {
            $ruleSite = (int) ($rule['site_id'] ?? 0);
            if ($ruleSite !== 0 && $ruleSite !== $siteId) {
                continue;
            }

            $regex = $this->rules->toRegex((string) ($rule['pattern'] ?? ''));
            if ($regex === null || !preg_match($regex, $norm, $m)) {
                continue;
            }

            $tokens   = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $resolved = $this->resolveTokens((string) ($rule['type'] ?? ''), $tokens, $siteId);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * token → 实体
     */
    protected function resolveTokens(string $type, array $tokens, int $siteId): ?array
    {
        $page  = max(1, (int) ($tokens['page'] ?? 1));
        $id    = (int) ($tokens['id'] ?? 0);
        $slug  = (string) ($tokens['slug'] ?? '');
        $alias = (string) ($tokens['alias'] ?? $tokens['tag'] ?? '');
        $catid = (int) ($tokens['catid'] ?? 0);
        $catdir = (string) ($tokens['catdir'] ?? '');

        try {
            switch ($type) {
                case UrlAlias::TYPE_CONTENT:
                    if ($id > 0) {
                        $entity = Content::find($id);
                    } elseif ($slug !== '') {
                        $entity = Content::where('slug', $slug)->whereNull('delete_time')->find();
                    } else {
                        return null;
                    }
                    if (!$entity || ($siteId > 0 && (int) $entity->site_id !== $siteId)) {
                        return null;
                    }
                    return ['entity_type' => UrlAlias::TYPE_CONTENT, 'entity_id' => (int) $entity->id, 'page' => 1, 'redirect' => null];

                case UrlAlias::TYPE_CATEGORY:
                    if ($catid > 0) {
                        $entity = Category::find($catid);
                    } else {
                        $name = $alias !== '' ? $alias : self::lastSegment($catdir);
                        if ($name === '') {
                            return null;
                        }
                        $entity = Category::where('alias', $name)->where('status', 1)->find();
                    }
                    if (!$entity || ($siteId > 0 && (int) $entity->site_id !== $siteId)) {
                        return null;
                    }
                    return ['entity_type' => UrlAlias::TYPE_CATEGORY, 'entity_id' => (int) $entity->id, 'page' => $page, 'redirect' => null];

                case UrlAlias::TYPE_TAG:
                    if ($id > 0) {
                        $entity = Tag::find($id);
                    } else {
                        $name = $alias !== '' ? $alias : $catdir;
                        if ($name === '') {
                            return null;
                        }
                        // P2：别名/名称的唯一性已收敛到站点内 → 查询也按站点收敛。
                        // 否则跨站同名标签时可能先取到别的站点那一行，再被下方站点校验拒绝，
                        // 导致「本站确实有该标签」却解析失败（404）。
                        $lookup = static fn(string $field) => $siteId > 0
                            ? Tag::where($field, $name)->where('site_id', $siteId)
                            : Tag::where($field, $name);

                        $entity = $lookup('alias')->find() ?: $lookup('name')->find();
                    }

                    // P2：标签解析必须校验站点（与 CATEGORY / PAGE 分支对齐）——
                    // 原实现缺此校验，他站的 /tag/{alias} 会在本站被解析（跨站标签页泄露）
                    if (!$entity || ($siteId > 0 && (int) $entity->site_id !== $siteId)) {
                        return null;
                    }

                    return ['entity_type' => UrlAlias::TYPE_TAG, 'entity_id' => (int) $entity->id, 'page' => $page, 'redirect' => null];

                case UrlAlias::TYPE_PAGE:
                    if ($id > 0) {
                        $entity = Page::find($id);
                    } elseif ($alias !== '') {
                        $entity = Page::where('alias', $alias)->where('status', 1)->find();
                    } else {
                        return null;
                    }

                    // P1-D：单页别名解析必须校验站点（与 CATEGORY 分支对齐）——
                    // 原实现缺此校验，他站的 /page/{alias} 会在本站被解析出来（跨站内容泄露）
                    if (!$entity || ($siteId > 0 && (int) $entity->site_id !== $siteId)) {
                        return null;
                    }

                    return ['entity_type' => UrlAlias::TYPE_PAGE, 'entity_id' => (int) $entity->id, 'page' => 1, 'redirect' => null];
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * 按路径查别名（优先精确站点，其次全站 site_id=0）
     */
    protected function findAlias(int $siteId, string $norm): ?array
    {
        try {
            $query = UrlAlias::where('url_path', $norm);
            if ($siteId > 0) {
                $query->whereIn('site_id', [0, $siteId])->order('site_id', 'desc');
            }
            $row = $query->find();

            return $row ? $row->toArray() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 规则解析上下文
     */
    protected function ruleContext(string $type, array $e): array
    {
        return [
            'site_id'  => (int) ($e['site_id'] ?? 0),
            'id'       => (int) ($e['id'] ?? 0),
            'catid'    => (int) ($e['catid'] ?? 0),
            'model_id' => (int) ($e['model_id'] ?? $e['modelid'] ?? 0),
        ];
    }

    /**
     * 各类型 token 取值
     */
    protected function tokens(string $type, array $e): array
    {
        switch ($type) {
            case UrlAlias::TYPE_CONTENT:
                $name = (string) ($e['slug'] ?? '');
                if ($name === '') {
                    $name = (string) ($e['alias'] ?? '');
                }
                // create_time 为 DATETIME，URL 年月日 token 需先归一为时间戳
                $time = cms_timestamp($e['create_time'] ?? null) ?: time();

                return [
                    'id'     => (string) ($e['id'] ?? ''),
                    'catid'  => (string) ($e['catid'] ?? ''),
                    'model'  => (string) ($e['model_id'] ?? $e['modelid'] ?? ''),
                    'slug'   => $name,
                    'alias'  => $name,
                    'catdir' => $this->catdir((int) ($e['catid'] ?? 0)),
                    'year'   => date('Y', $time),
                    'month'  => date('m', $time),
                    'day'    => date('d', $time),
                ];

            case UrlAlias::TYPE_CATEGORY:
                return [
                    'id'     => (string) ($e['id'] ?? ''),
                    'catid'  => (string) ($e['id'] ?? ''),
                    'alias'  => (string) ($e['alias'] ?? ''),
                    'catdir' => $this->catdir((int) ($e['id'] ?? 0)),
                ];

            case UrlAlias::TYPE_TAG:
                $alias = (string) ($e['alias'] ?? '');
                if ($alias === '') {
                    $alias = (string) ($e['name'] ?? '');
                }

                return [
                    'id'    => (string) ($e['id'] ?? ''),
                    'alias' => $alias,
                    'tag'   => $alias,
                ];

            case UrlAlias::TYPE_PAGE:
                return [
                    'id'     => (string) ($e['id'] ?? ''),
                    'alias'  => (string) ($e['alias'] ?? ''),
                    'catdir' => $this->catdir((int) ($e['catid'] ?? 0)),
                ];
        }

        return [];
    }

    /**
     * 渲染结果是否可用（模板引用的 token 均已具备）
     */
    protected function usable(string $path, string $pattern, array $tokens): bool
    {
        if ($path === '' || str_contains($path, '{')) {
            return false;
        }
        foreach ($this->rules->tokensIn($pattern) as $token) {
            if (in_array($token, ['catdir', 'page', 'suffix'], true)) {
                continue; // catdir 允许为空（根级栏目）
            }
            if ((string) ($tokens[$token] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 栏目别名路径（父链 alias 拼接，如 news/tech）
     */
    public function catdir(int $catid): string
    {
        if ($catid <= 0) {
            return '';
        }
        if (isset($this->catdirCache[$catid])) {
            return $this->catdirCache[$catid];
        }

        $map      = $this->categoryMap();
        $segments = [];
        $current  = $catid;
        $guard    = 0;

        while ($current > 0 && $guard++ < 30) {
            $node = $map[$current] ?? null;
            if ($node === null) {
                break;
            }
            array_unshift($segments, $node['alias'] !== '' ? $node['alias'] : (string) $current);
            $current = (int) $node['parent_id'];
        }

        return $this->catdirCache[$catid] = implode('/', $segments);
    }

    /**
     * 一次性加载栏目映射（id => parent_id/alias）
     */
    protected function categoryMap(): array
    {
        if ($this->categoryMap !== null) {
            return $this->categoryMap;
        }

        $this->categoryMap = [];
        try {
            foreach (Category::field('id,parent_id,alias')->select() as $row) {
                $this->categoryMap[(int) $row->id] = [
                    'parent_id' => (int) $row->parent_id,
                    'alias'     => (string) $row->alias,
                ];
            }
        } catch (\Throwable $e) {
            $this->categoryMap = [];
        }

        return $this->categoryMap;
    }

    protected function queryPath(string $base, array $params = []): string
    {
        return $base . ($params ? '?' . http_build_query($params) : '');
    }

    protected static function lastSegment(string $path): string
    {
        $parts = explode('/', trim($path, '/'));

        return (string) end($parts);
    }

    /**
     * 实体统一转数组（支持模型对象 / 数组）
     */
    protected static function toArray($entity): array
    {
        if (is_array($entity)) {
            return $entity;
        }
        if ($entity instanceof \think\Model) {
            return $entity->toArray();
        }
        if (is_object($entity)) {
            return get_object_vars($entity);
        }

        return [];
    }
}

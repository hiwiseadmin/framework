<?php
declare(strict_types=1);

namespace wise\plugin\derive;

use think\helper\Str;
use wise\plugin\manifest\MenuPathNormalizer;
use wise\support\menu\MenuSchema;

/**
 * MenuDeriver — **纯静态**扫描插件控制器，推导 `manifest.json` 的菜单候选节点，
 * 并与既有声明比对漂移。
 *
 * ## 定位（对齐方桼�
 *
 * - **不落库、不碰数据库**：manifest → DB 是插件安装期的动作（`MenuRegistrar`），
 *   本类只读文件系统与 manifest 文件；
 * - **不写文件**：本类只产出结构化结果，落盘由调用方（CLI）决定；
 * - 职责是「**一致性**」：回答「控制器集合 ⇄ manifest 声明是否对得上」。
 *
 * ## 推导规则（对齐方桼�
 *
 * | 来源 | 产出 |
 * |:---|:---|
 * | 方法名 `index` | 菜单节点，`type = menu`，path 按该 manifest 既定风格（短式 / 长式） |
 * | 方法 docblock `@menu name=.. group=.. icon=.. sort=..` | **强制产出**（逃生舱：`dashboard` 这类非 index 页面入口） |
 * | 方法 docblock `@menu type="button" name=.. icon=.. sort=..` | 按钮节点（`type = button`），path 固定为长式 |
 * | 其余 public 方法（`save` / `delete` / `getList`…） | **不产出** —— 插件侧「未声明 = 放行」，多推导会让合法用户 403 |
 *
 * 按钮**必须显式声明** `type="button"`：非标准方法无法从方法名推断中文名与图标，
 * 未注解时一律不产出（与「未声明 = 放行」同一取向）。`index` 恒为 menu，不会因为
 * 注解被降级为 button。
 *
 * ## 扫描范围为什么是「顶层 `controller/*.php`」
 *
 * 插件后台控制器位于 `controller/` 顶层（`plugin\{ident}\controller\{Class}`），
 * URL 段 = `Str::snake(类名)`（URL 段经 `Str::studly()` 反查类名，因此
 * `group_battle` 与 `groupBattle` 指向同一个类，见 {@see MenuPathNormalizer::identity()}）。
 *
 * `controller/api/*`（JSON API）与 `controller/index/*`（前台站点页）**不是后台菜单**，
 * 其 URL 段还是点号形式（`api.product`），与菜单语义无关，故不递归 —— 11 个存量插件
 * 均为该布局。
 *
 * ## 安全设计（目标：防误伤，方桼�
 *
 * | 机制 | 规则 |
 * |:---|:---|
 * | 默认白名单动词 | 仅 `index` 与显式 `@menu` 注解的方法产出 |
 * | 敏感词硬拦截 | 控制器 / 方法名含 {@see self::SENSITIVE_WORDS} → 一律不产出（不可配置放开） |
 * | 注解豁免 | `@menu-ignore` 无条件豁免该方法的产出 |
 * | 排除清单 | `--only` / `--exclude` |
 */
final class MenuDeriver
{
    /**
     * 敏感词硬拦截清单（不区分大小写，不可通过配置放开）。
     *
     * 出现在控制器名或方法名里时一律不产出候选 —— 它们通常是调试 / 运维 / 测试入口，
     * 不该被自动登记成菜单与权限节点。
     *
     * @var array<int, string>
     */
    public const SENSITIVE_WORDS = [
        'debug', 'test', 'exec', 'shell', 'sql', 'query', 'tool', 'dump', 'reset',
    ];

    /**
     * 不产出节点的框架钩子与魔术方法。
     *
     * @var array<int, string>
     */
    public const EXCLUDED_METHODS = [
        '__construct', '__destruct', '__call', '__callStatic', '__get', '__set',
        '__isset', '__unset', '__toString', '__invoke', '__clone', '__debugInfo',
        'initialize', '_empty', '_initialize',
    ];

    /**
     * 约定基类名：不作为页面入口。
     *
     * @var array<int, string>
     */
    public const BASE_CLASS_NAMES = ['Base', 'BaseController'];

    /** 插件根目录（`plugins/`，无尾分隔符） */
    private string $pluginsRoot;

    private MenuPathNormalizer $normalizer;

    public function __construct(?string $pluginsRoot = null, ?MenuPathNormalizer $normalizer = null)
    {
        $this->pluginsRoot = rtrim(
            $pluginsRoot ?? (function_exists('plugin_path') ? (string) plugin_path() : ''),
            '/\\'
        );
        $this->normalizer  = $normalizer ?? MenuPathNormalizer::fromConfig();
    }

    public function normalizer(): MenuPathNormalizer
    {
        return $this->normalizer;
    }

    public function pluginsRoot(): string
    {
        return $this->pluginsRoot;
    }

    /**
     * 列出「有 manifest.json」的插件标识（目录名，已排序）。
     *
     * @return array<int, string>
     */
    public function plugins(): array
    {
        if ($this->pluginsRoot === '' || !is_dir($this->pluginsRoot)) {
            return [];
        }

        $identList = [];

        foreach ((array) glob($this->pluginsRoot . '/*', GLOB_ONLYDIR) as $dir) {
            $ident = basename((string) $dir);

            if ($this->isValidIdent($ident) && is_file($dir . DIRECTORY_SEPARATOR . 'manifest.json')) {
                $identList[] = $ident;
            }
        }

        sort($identList);

        return $identList;
    }

    /**
     * 推导单个插件的候选菜单，并与 manifest 声明比对。
     *
     * 选项：
     * ```
     * only    array<int, string>  只扫描这些控制器（类名，不区分大小写，支持逗号串）
     * exclude array<int, string>  跳过这些控制器
     * group   string|null         未标注分组的节点统一归入该分组
     * ```
     *
     * 返回结构：
     * ```
     * ident      插件标识
     * exists     插件目录与 manifest 是否都存在
     * manifest   manifest.json 绝对路径
     * error      致命错误（标识非法 / 目录缺失 / manifest 非法 JSON）；非 null 时其余字段无意义
     * form       该 manifest 既定产出风格（short / long）
     * scanned    扫到的顶层控制器数
     * blocked    因安全策略（敏感词）被忽略的控制器数
     * candidates 候选节点（推导产出）
     * declared   manifest 已声明的菜单节点（递归，含分组子节点）
     * missing    候选 - 已声明（按归一化身份）—— **漂移的主判据**
     * extra      已声明 - 候选（仅提示：动作级页面 / 未加注解的入口 / @menu-ignore 过的）
     * duplicates 同一身份被非 button 节点声明多次
     * drift      是否存在漂移（missing 或 duplicates 非空）
     * ```
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function derive(string $ident, array $options = []): array
    {
        $manifest = $this->pluginsRoot . DIRECTORY_SEPARATOR . $ident . DIRECTORY_SEPARATOR . 'manifest.json';

        $result = [
            'ident'      => $ident,
            'exists'     => false,
            'manifest'   => $manifest,
            'error'      => null,
            'form'       => MenuPathNormalizer::FORM_LONG,
            'scanned'    => 0,
            'blocked'    => 0,
            'candidates' => [],
            'declared'   => [],
            'missing'    => [],
            'extra'      => [],
            'duplicates' => [],
            'drift'      => false,
        ];

        if (!$this->isValidIdent($ident)) {
            $result['error'] = sprintf('插件标识非法：%s（应匹配 [a-zA-Z0-9][a-zA-Z0-9_-]*）', $ident);

            return $result;
        }

        $pluginDir = $this->pluginsRoot . DIRECTORY_SEPARATOR . $ident;

        if (!is_dir($pluginDir)) {
            $result['error'] = '插件目录不存在：' . $pluginDir;

            return $result;
        }

        if (!is_file($manifest)) {
            $result['error'] = '未找到 manifest.json：' . $manifest;

            return $result;
        }

        $raw = file_get_contents($manifest);

        if ($raw === false) {
            $result['error'] = 'manifest.json 不可读：' . $manifest;

            return $result;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $result['error'] = 'manifest.json 不是合法 JSON（请先修好它）：' . $manifest;

            return $result;
        }

        $result['exists'] = true;

        $declared           = $this->collectDeclared((array) ($data['menus'] ?? []));
        $result['declared'] = $declared;
        $result['form']     = $this->normalizer->preferredForm(array_column($declared, 'path'));

        $scan                 = $this->scanControllers($pluginDir, $ident, $result['form'], $options);
        $result['scanned']    = $scan['scanned'];
        $result['blocked']    = $scan['blocked'];
        $result['candidates'] = $scan['candidates'];

        $this->diff($result);

        return $result;
    }

    /**
     * 候选与声明双向比对（按归一化身份）。
     *
     * @param array<string, mixed> $result 已填充 declared / candidates（引用修改）
     */
    private function diff(array &$result): void
    {
        $declaredByIdentity = [];

        foreach ($result['declared'] as $node) {
            $declaredByIdentity[$this->normalizer->identity((string) $node['path'])][] = $node;
        }

        $candidateIdentities = [];

        foreach ($result['candidates'] as $candidate) {
            $identity                       = $this->normalizer->identity((string) $candidate['path']);
            $candidateIdentities[$identity] = true;

            if (!isset($declaredByIdentity[$identity])) {
                $result['missing'][] = $candidate;
            }
        }

        foreach ($result['declared'] as $node) {
            if ((string) ($node['type'] ?? '') === 'button') {
                continue;
            }

            if (!isset($candidateIdentities[$this->normalizer->identity((string) $node['path'])])) {
                $result['extra'][] = $node;
            }
        }

        foreach ($declaredByIdentity as $identity => $nodes) {
            $pages = array_filter(
                $nodes,
                static fn (array $node): bool => (string) ($node['type'] ?? '') !== 'button'
            );

            if (count($pages) > 1) {
                $result['duplicates'][] = [
                    'identity' => $identity,
                    'paths'    => array_column($pages, 'path'),
                    'names'    => array_column($pages, 'name'),
                ];
            }
        }

        $result['drift'] = $result['missing'] !== [] || $result['duplicates'] !== [];
    }

    /**
     * 递归读取 manifest 的菜单声明（含 `children` / `child` / `childlist` 别名）。
     *
     * @param array<int|string, mixed> $menus
     * @return array<int, array{path: string, name: string, type: string}>
     */
    private function collectDeclared(array $menus): array
    {
        $declared = [];

        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }

            $path = trim((string) ($menu['path'] ?? ''));

            if ($path !== '') {
                $declared[] = [
                    'path' => $path,
                    'name' => (string) ($menu['name'] ?? ''),
                    'type' => (string) ($menu['type'] ?? ''),
                ];
            }

            foreach (MenuSchema::childrenOf($menu) as $child) {
                if (is_array($child)) {
                    foreach ($this->collectDeclared([$child]) as $node) {
                        $declared[] = $node;
                    }
                }
            }
        }

        return $declared;
    }

    /**
     * 扫描 `controller/` 顶层，产出候选节点。
     *
     * @param array<string, mixed> $options
     * @return array{scanned: int, blocked: int, candidates: array<int, array<string, mixed>>}
     */
    private function scanControllers(string $pluginDir, string $ident, string $form, array $options): array
    {
        $controllerDir = $pluginDir . DIRECTORY_SEPARATOR . 'controller';

        if (!is_dir($controllerDir)) {
            return ['scanned' => 0, 'blocked' => 0, 'candidates' => []];
        }

        $only    = $this->normalizeFilterList((array) ($options['only'] ?? []));
        $exclude = $this->normalizeFilterList((array) ($options['exclude'] ?? []));
        $group   = isset($options['group']) && trim((string) $options['group']) !== ''
            ? trim((string) $options['group'])
            : null;

        $candidates = [];
        $scanned    = 0;
        $blocked    = 0;

        foreach ((array) glob($controllerDir . '/*.php') as $file) {
            $class = basename((string) $file, '.php');

            if (in_array($class, self::BASE_CLASS_NAMES, true)) {
                continue;
            }

            if ($this->hasSensitiveWord($class)) {
                $blocked++;
                continue;
            }

            if ($only !== [] && !in_array(strtolower($class), $only, true)) {
                continue;
            }

            if (in_array(strtolower($class), $exclude, true)) {
                continue;
            }

            $parsed = $this->parseControllerFile((string) $file);

            if ($parsed === null) {
                continue;
            }

            $scanned++;

            $segment    = Str::snake($class);
            $classGroup = $this->annotationValue($parsed['docblock'], 'group');

            foreach ($parsed['methods'] as $method) {
                $name       = $method['name'];
                $annotation = $method['annotation'];

                if (in_array($name, self::EXCLUDED_METHODS, true) || $this->hasSensitiveWord($name)) {
                    continue;
                }

                if ($method['ignored']) {
                    continue;
                }

                $isIndex = strtolower($name) === $this->normalizer->defaultAction();

                if (!$isIndex && $annotation === null) {
                    continue;
                }

                $type = 'menu';
                if (!$isIndex) {
                    $declared = strtolower((string) ($this->annotationValue($annotation, 'type') ?? 'menu'));
                    $type     = in_array($declared, ['menu', 'button'], true) ? $declared : 'menu';
                }

                $path = ($isIndex && $type === 'menu' && $form === MenuPathNormalizer::FORM_SHORT)
                    ? sprintf('/plugin/%s/%s', $ident, $segment)
                    : sprintf('/plugin/%s/%s/%s', $ident, $segment, $name);

                $candidates[] = [
                    'controller' => $class,
                    'method'     => $name,
                    'name'       => $this->annotationValue($annotation, 'name')
                        ?? ($isIndex ? $class : $class . '::' . $name),
                    'path'       => $path,
                    'type'       => $type,
                    'group'      => $this->annotationValue($annotation, 'group') ?? $classGroup ?? $group,
                    'icon'       => $this->annotationValue($annotation, 'icon'),
                    'sort'       => $this->annotationInt($annotation, 'sort'),
                    'source'     => $isIndex ? 'index' : 'annotation',
                    'file'       => sprintf('plugins/%s/controller/%s', $ident, basename((string) $file)),
                    'line'       => $method['line'],
                ];
            }
        }

        return ['scanned' => $scanned, 'blocked' => $blocked, 'candidates' => $candidates];
    }

    /**
     * 解析控制器文件：类级 docblock + 自己声明的 public 方法（含方法级 docblock）。
     *
     * 用 token 解析而非 `ReflectionClass`：本工具是**纯静态开发期工具**，不应为了
     * 枚举菜单而加载（乃至实例化）插件代码；且插件类在未启用时未必可自动加载。
     *
     * @return array{docblock: string|null, methods: array<int, array{name: string, line: int, ignored: bool, annotation: string|null}>}|null
     *         null = 该文件不构成页面入口（抽象类 / 接口 / trait / 解析不出类）
     */
    private function parseControllerFile(string $file): ?array
    {
        $code = file_get_contents($file);

        if ($code === false || $code === '') {
            return null;
        }

        $tokens    = token_get_all($code);
        $total     = count($tokens);
        $line      = 1;
        $depth     = 0;
        $pending   = null;
        $classDoc  = null;
        $classSeen = false;
        $classDepth = null;
        $methods   = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;

                    if ($classSeen && $classDepth === null) {
                        $classDepth = $depth;
                        $classDoc   = $pending;
                    }
                } elseif ($token === '}') {
                    if ($classDepth !== null && $depth === $classDepth) {
                        $classDepth = null;
                    }

                    $depth--;
                } elseif ($token === ';') {
                    $pending = null;
                }

                continue;
            }

            [$type, $text] = [$token[0], $token[1]];
            $line += substr_count($text, "\n");

            if ($type === T_DOC_COMMENT) {
                $pending = $text;
                continue;
            }

            if ($type === T_CLASS) {
                if (!$classSeen) {
                    $prev = $this->prevSignificantType($tokens, $i);

                    if ($prev === T_NEW || $prev === T_DOUBLE_COLON) {
                        continue;
                    }

                    if ($this->isAbstractDeclaration($tokens, $i)) {
                        return null;
                    }

                    $classSeen = true;
                }

                continue;
            }

            if ($type === T_INTERFACE || $type === T_TRAIT || $type === T_ENUM) {
                if ($classDepth === null && !$classSeen) {
                    return null;
                }
            }

            if ($type === T_FUNCTION) {
                if ($classSeen && $classDepth !== null && $depth === $classDepth) {
                    $name = $this->nextString($tokens, $i);

                    if ($name !== null && $this->isPublic($tokens, $i)) {
                        $methods[] = [
                            'name'       => $name,
                            'line'       => $line,
                            'ignored'    => $pending !== null && str_contains($pending, '@menu-ignore'),
                            'annotation' => $this->extractMenuAnnotation($pending),
                        ];
                    }
                }

                $pending = null;
                continue;
            }

            if (!in_array($type, [T_WHITESPACE, T_COMMENT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_ATTRIBUTE], true)) {
                $pending = null;
            }
        }

        if (!$classSeen) {
            return null;
        }

        return ['docblock' => $classDoc, 'methods' => $methods];
    }

    /**
     * 取 `T_FUNCTION` 之后的方法名。
     *
     * @param array<int, mixed> $tokens
     */
    private function nextString(array $tokens, int $index): ?string
    {
        for ($i = $index + 1, $max = count($tokens); $i < $max; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_STRING) {
                    return $token[1];
                }

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
            }

            if ($token === '&') {
                continue;
            }

            break;
        }

        return null;
    }

    /**
     * 方法是否 public（未显式声明可见性即 public）。
     *
     * @param array<int, mixed> $tokens
     */
    private function isPublic(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                    continue;
                }

                if ($token[0] === T_PUBLIC) {
                    return true;
                }

                if ($token[0] === T_PROTECTED || $token[0] === T_PRIVATE) {
                    return false;
                }
            }

            break;
        }

        return true;
    }

    /**
     * 类声明是否带 `abstract`（抽象类不可被路由，不是页面入口）。
     *
     * @param array<int, mixed> $tokens
     */
    private function isAbstractDeclaration(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_READONLY], true)) {
                    continue;
                }

                return $token[0] === T_ABSTRACT;
            }

            return false;
        }

        return false;
    }

    /**
     * 前一个「有意义的」 token 类型（跳过空白与注释）。
     *
     * @param array<int, mixed> $tokens
     */
    private function prevSignificantType(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $token[0];
            }

            return null;
        }

        return null;
    }

    /**
     * 从 docblock 提取 `@menu` 注解的属性串（无则 null）。
     */
    private function extractMenuAnnotation(?string $docblock): ?string
    {
        if ($docblock === null || !str_contains($docblock, '@menu')) {
            return null;
        }

        foreach (preg_split('/\R/', $docblock) ?: [] as $line) {
            if (preg_match('/@menu(?!-ignore)\b(.*)$/', trim($line), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * 读取注解属性：`name="文章"` / `group='内容'`。
     */
    private function annotationValue(?string $annotation, string $key): ?string
    {
        if ($annotation === null || $annotation === '') {
            return null;
        }

        $pattern = sprintf('/\b%s\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/u', preg_quote($key, '/'));

        if (preg_match($pattern, $annotation, $matches) !== 1) {
            return null;
        }

        $value = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');

        return $value === '' ? null : $value;
    }

    private function annotationInt(?string $annotation, string $key): ?int
    {
        if ($annotation === null || $annotation === '') {
            return null;
        }

        $pattern = sprintf('/\b%s\s*=\s*(-?\d+)/', preg_quote($key, '/'));

        return preg_match($pattern, $annotation, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * 敏感词判定（控制器名 / 方法名，不区分大小写）。
     */
    private function hasSensitiveWord(string $name): bool
    {
        $lower = strtolower($name);

        foreach (self::SENSITIVE_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 归一过滤清单（支持数组或逗号串）。
     *
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeFilterList(array $values): array
    {
        $list = [];

        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $item) {
                $item = strtolower(trim($item));

                if ($item !== '') {
                    $list[] = $item;
                }
            }
        }

        return $list;
    }

    private function isValidIdent(string $ident): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $ident) === 1;
    }
}

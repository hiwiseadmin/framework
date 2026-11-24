<?php
declare(strict_types=1);

namespace app\common\view;

/**
 * 视图 scope 注册表 —— 包 / 插件内视图的统一解析协议
 *
 * 解决的问题
 * ----------
 * 「视图进包」后宿主无法覆盖、「视图进宿主」后包升级视图不跟随。
 * 本类把两者变成**双层 fallback**，而不是二选一：
 *
 *   L1  app/{app}/view/{path}.html              ← 宿主覆盖层（think-view 原生逻辑，优先）
 *   L2  {pkgRoot}/view/{scope}/{path}.html      ← 包内兜底层（本类）
 *
 * L1 命中用宿主的（第三方定制 / 主题覆盖）；L1 未命中用包内的（包升级视图跟着走）；
 * 两层都没有则由 think-view 抛 TemplateNotFoundException（保持原有行为）。
 *
 * 三层解析顺序（固定）
 * -------------------
 *   L1  应用层   app/{app}/view/{path}.html        ← think-view 原生逻辑，优先
 *   L2  显式 scope   fetch('wise-crontab@admin/index')
 *   L3  当前 scope   fetch() / fetch('admin/index')  ← 自动落到「当前插件 / 当前包」
 *
 * L2、L3 的落地形态与区别：
 *
 *   locate()        显式写法，调用方写明 scope（跨包引用、宿主覆盖层）
 *   locateInScope() 隐式写法，调用方只给相对名，scope 由**当前上下文**决定。
 *                   `plugin\BaseController` / `common\FrontController` 在 initialize()
 *                   时把 scope 写入 `$request->view_scope`，故插件/包内的
 *                   `fetch()` 无需任何改动即可命中自身视图 —— 与 TP 原生
 *                   「空参数 = 当前控制器/当前方法」的直觉一致。
 *
 * 与原生 `@`（跨应用语义）的共存
 * -----------------------------
 * think-view 原生把 `admin@wise_dict/index` 的 `admin` 当**应用名**。
 * 本类只在「前段已注册为 scope」时接管，否则返回 null 交回原生语义：
 *
 *   admin@wise_dict/index    → admin 非 scope → null → 原生跨应用解析（行为不变）
 *   member@admin/page        → member 已注册 → L2 解析
 *
 * 约定优于配置
 * ------------
 * 未显式注册的 scope 会按以下目录自动发现（结果缓存，命中即止）：
 *
 *   {root}/packages/{scope}/view/
 *   {root}/packages/wiseadmin/{scope}/view/
 *   {root}/plugins/{scope}/view/
 *   {root}/vendor/{vendor}/{scope}/view/       ← composer 安装（非 packages/ 布局）时
 *
 * 因此 `packages/wiseadmin/wise-crontab/view/`、`plugins/member/view/` 这类标准布局零注册即可用；
 * 非标准布局（视图不在 `view/` 下）再显式注册。
 *
 * 宿主覆盖层
 * ----------
 * 宿主若要改包内某一个页面，**不改包代码**，在宿主侧放同名文件即可生效 ——
 * 位置由 `app\common\view\driver\Think` 的 L1b 负责探测：
 *
 *   app/{app}/view/{scope}/{path}.html     ← 如 app/admin/view/wise-crontab/admin/task/index.html
 *
 * 该层优先于本类的包内兜底，且仅对显式 `scope@path` 生效（见驱动注释）。
 *
 * Swoole 兼容
 * ----------
 * scope → 路径映射是**应用级不变量**，不随请求变化，故用静态属性承载，
 * 不会产生跨请求污染（与「请求级数据不得用静态缓存」的场景不同）。
 */
class ViewScope
{
    /**
     * 显式注册的 scope 表。
     *
     * @var array<string, string> scope => 视图根目录（含尾分隔符）
     */
    protected static array $scopes = [];

    /**
     * scope 解析结果缓存（含自动发现结果）。
     *
     * 值为 null 表示「已确认该 scope 无对应目录」，避免重复 IO 探测。
     *
     * @var array<string, string|null>
     */
    protected static array $resolved = [];

    /**
     * 是否启用按约定目录自动发现。
     */
    protected static bool $autoDiscover = true;


    /**
     * 注册一个 scope 到视图根目录。
     *
     * @param string $scope     scope 名（同时作为 `@` 前缀），如 wise-crontab
     * @param string $viewRoot 视图根目录，模板名 `admin/page` 会解析为 {viewRoot}/admin/page.html
     * @return void
     */
    public static function register(string $scope, string $viewRoot): void
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $viewRoot), DIRECTORY_SEPARATOR);

        self::$scopes[$scope] = $normalized . DIRECTORY_SEPARATOR;
        unset(self::$resolved[$scope]);
    }

    /**
     * 按「包根 + view/」约定注册 scope。
     *
     * 等价于 register($scope, $packageRoot . '/view')，
     * 但会先把包根转成绝对路径（相对路径按根目录解析）。
     *
     * @param string $scope       scope 名
     * @param string $packageRoot 包根目录
     * @return void
     */
    public static function registerPackage(string $scope, string $packageRoot): void
    {
        self::register($scope, rtrim($packageRoot, '/\\') . DIRECTORY_SEPARATOR . 'view');
    }

    /**
     * 声明「当前请求」的视图 scope。
     *
     * 供**包控制器**等不经插件派发链的场景使用，一行即可让后续的
     * 空参数 `fetch()` 落到本包视图：
     *
     *     protected function initialize()
     *     {
     *         ViewScope::activate('wise-crontab');
     *         parent::initialize();
     *     }
     *
     *     // 控制器内：
     *     View::fetch();              → {包内 view}/{controller}/{action}.html
     *     View::fetch('admin/index'); → {包内 view}/admin/index.html
     *
     * 插件后台控制器**无需**调用 —— PluginDispatch / IndexDispatch 已自动注入。
     *
     * 实现上写入 Request 的「中间件传递数据」（think\Request::__set），
     * 请求级载体，Swoole 协程下天然隔离。
     *
     * @param string $scope scope 名，须能被 register() / 自动发现解析出视图根
     * @return void
     */
    public static function activate(string $scope): void
    {
        try {
            app()->request->view_scope = $scope;
        } catch (\Throwable $e) {
        }
    }

    /**
     * 注销一个 scope。
     */
    public static function forget(string $scope): void
    {
        unset(self::$scopes[$scope], self::$resolved[$scope]);
    }

    /**
     * 清空全部注册与缓存（测试 / 运行时重载用）。
     */
    public static function flush(): void
    {
        self::$scopes   = [];
        self::$resolved = [];
    }

    /**
     * 开关自动发现。
     */
    public static function setAutoDiscover(bool $enabled): void
    {
        self::$autoDiscover = $enabled;
        self::$resolved     = [];
    }


    /**
     * 是否已显式注册（不含自动发现）。
     */
    public static function has(string $scope): bool
    {
        return isset(self::$scopes[$scope]);
    }

    /**
     * 全部显式注册项。
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$scopes;
    }

    /**
     * 解析 scope 对应的视图根目录。
     *
     * 顺序：显式注册 → 自动发现 → null。
     * 结果（含 null）会被缓存，避免重复 is_dir 探测。
     *
     * @param string $scope scope 名
     * @return string|null 视图根目录（含尾分隔符），无则 null
     */
    public static function resolveRoot(string $scope): ?string
    {
        if (array_key_exists($scope, self::$resolved)) {
            return self::$resolved[$scope];
        }

        $root = self::$scopes[$scope] ?? null;

        if ($root === null && self::$autoDiscover) {
            $root = self::discover($scope);
        }

        return self::$resolved[$scope] = $root;
    }

    /**
     * L2：解析「显式 scope」模板名 —— 形如 `scope@path`。
     *
     * 仅处理「前段可解析为 scope」的模板名；其余（空模板名、绝对路径、
     * 未注册的 `@` 前缀）一律返回 null，由调用方交回 think-view 原生逻辑，
     * 从而保证 `admin@wise_dict/index` 这类原生「应用名@模板」语义不受影响。
     *
     * @param string $template 模板名，如 member@admin/page（不含后缀）
     * @param string $suffix   模板后缀，如 html
     * @return array{file: string, root: string}|null 命中的文件与视图根
     */
    public static function locate(string $template, string $suffix = 'html'): ?array
    {
        $parsed = self::parseScope($template);

        if ($parsed === null) {
            return null;
        }

        [$scope, $path] = $parsed;

        $root = self::resolveRoot($scope);

        if ($root === null) {
            return null;
        }

        $relative = str_replace(['/', ':'], DIRECTORY_SEPARATOR, $path)
            . '.' . ltrim($suffix, '.');

        return self::makeHit($root, $relative);
    }

    /**
     * L3：在指定 scope 下解析「相对模板名」。
     *
     * 供「空参数 / 相对模板名」场景使用：调用方（视图驱动）已由
     * think-view 算出相对名，只需补上「当前是谁」这一信息。
     *
     * @param string $scope    scope 名（当前插件 / 当前包）
     * @param string $relative 相对模板名，**须含后缀**，如 alert/log_index.html
     * @return array{file: string, root: string}|null 命中的文件与视图根
     */
    public static function locateInScope(string $scope, string $relative): ?array
    {
        $root = self::resolveRoot($scope);

        if ($root === null) {
            return null;
        }

        $relative = str_replace(['/', '\\', ':'], DIRECTORY_SEPARATOR, $relative);
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);

        return $relative === '' ? null : self::makeHit($root, $relative);
    }


    /**
     * 组装命中结果 —— 文件必须真实存在，否则视为未命中。
     *
     * 返回 root 是为了让调用方能把模板引擎的 view_path 同步到**视图根**：
     * 模板内的 `{include file="product/form"}` 等相对引用由
     * `Template::parseTemplateFile()` 按 view_path 拼接，若指向文件所在
     * 子目录（如 `.../view/product/`）会拼成 `.../view/product/product/form.html`。
     *
     * @param string $root     视图根目录（含尾分隔符）
     * @param string $relative 相对模板名（含后缀）
     * @return array{file: string, root: string}|null
     */
    protected static function makeHit(string $root, string $relative): ?array
    {
        $file = $root . $relative;

        return is_file($file) ? ['file' => $file, 'root' => $root] : null;
    }

    /**
     * 拆解 `scope@path` 形式的模板名。
     *
     * 以下情况一律返回 null（不参与 scope 解析）：
     *   - 空模板名
     *   - 绝对路径（`/` `\` 开头，或 Windows 盘符）
     *   - 不含 `@`、`@` 位于首/尾
     *   - scope 段不是合法标识符（纯字母数字、-_）
     *
     * @return array{0: string, 1: string}|null [scope, path]
     */
    protected static function parseScope(string $template): ?array
    {
        if ($template === '') {
            return null;
        }

        $first = $template[0];
        if ($first === '/' || $first === '\\') {
            return null;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $template)) {
            return null;
        }

        $pos = strpos($template, '@');

        if ($pos === false || $pos === 0 || $pos === strlen($template) - 1) {
            return null;
        }

        $scope = substr($template, 0, $pos);
        $path  = substr($template, $pos + 1);

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $scope)) {
            return null;
        }

        $path = trim($path, "/\\ \t\n\r\0\x0B");

        if ($path === '') {
            return null;
        }

        return [$scope, $path];
    }

    /**
     * 按约定目录自动发现 scope 的视图根。
     *
     * @param string $scope scope 名
     * @return string|null 命中的视图根目录（含尾分隔符）
     */
    protected static function discover(string $scope): ?string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $scope)) {
            return null;
        }

        foreach (self::searchRoots() as $base) {
            $candidate = $base . $scope . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;

            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 自动发现的搜索根列表。
     *
     * 顺序即优先级（命中即止）：本地 packages/ 布局 → 插件 → composer vendor 布局。
     * 本地 packages/ 先于 vendor/ 是有意的：path 仓库 + symlink 安装时两者指向同一份
     * 代码（本地优先可少一次 realpath 探测）；非 symlink 安装时 packages/ 为空，
     * 自动落到 vendor/ —— 这正是「单独 composer require 一个包也能开箱有界面」的保证。
     *
     * @return array<int, string>
     */
    protected static function searchRoots(): array
    {
        $root = root_path();
        $sep  = DIRECTORY_SEPARATOR;

        $roots = [
            $root . 'packages' . $sep,
            $root . 'packages' . $sep . 'wiseadmin' . $sep,
            $root . 'plugins' . $sep,
        ];

        foreach (glob($root . 'vendor' . $sep . '*', GLOB_ONLYDIR) ?: [] as $vendorDir) {
            $roots[] = rtrim($vendorDir, '/\\') . $sep;
        }

        return $roots;
    }
}

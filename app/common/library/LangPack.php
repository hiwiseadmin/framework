<?php
declare(strict_types=1);

namespace app\common\library;

use think\facade\Config;

/**
 * 语言包 scope 注册表 —— 包 / 插件 / 宿主语言包的统一发现与注册协议
 *
 * 为什么需要本类
 * --------------
 * ThinkPHP 的 `Lang::switchLangSet()`（vendor/topthink/framework/src/think/Lang.php:128-151）
 * 只自动加载两处语言包：
 *
 *   1. 框架系统包      vendor/topthink/framework/src/lang/{locale}.php
 *   2. 应用语言包      {getAppPath()}/lang/{locale}.*
 *
 * 第三处是官方留给扩展的钩子 `config('lang.extend_list')`：
 *
 *   'extend_list' => [
 *       'zh-cn' => ['/abs/path/plugins/foo/lang/zh-cn.php', ...],
 *   ]
 *
 * 且 `Lang::load()` 只做 `is_file()` 判断（Lang.php:170）—— **目录会被静默忽略**，
 * 因此插件/包的语言包必须展开成「文件路径」显式注册，本类即负责这件事。
 *
 * 约定优于配置
 * ------------
 * 零注册即可生效（按目录自动发现）：
 *
 *   {root}/packages/{vendor}/{pkg}/lang/{locale}.php    ← 包层
 *   {root}/packages/{pkg}/lang/{locale}.php             ← 包层（无 vendor 的布局）
 *   {root}/plugins/{ident}/lang/{locale}.php            ← 插件层
 *   {root}/app/lang/{locale}.php                        ← 宿主层（框架级 UI 文案）
 *
 * 宿主的 `app/{app}/lang/{locale}.php` 由 ThinkPHP 原生加载，无需登记；
 * `{root}/app/lang/` 是「跨应用共用」的宿主层，供 admin / api / plugin / member
 * 四个应用共享一份框架级文案，避免同一句话在四个应用各放一份。
 *
 * 优先级（同名键，后者胜）
 * ----------------------
 *   框架系统包  <  应用 app/{app}/lang  <  包  <  插件  <  宿主 app/lang
 *
 * `Lang::load()` 的合并是「数组左侧优先」（Lang.php:172-177），而本类按
 * 包 → 插件 → 宿主 的顺序输出文件列表，故与上表一致：**插件可覆盖包，宿主可覆盖插件**
 * —— 与视图的 L1 宿主覆盖 → L2 包内兜底（{@see \app\common\view\ViewScope}）同源。
 *
 * ⚠️ 例外：`app/{app}/lang/` 无法被宿主层覆盖 —— ThinkPHP 原生已把它排在本类
 * 之前加载。若需要覆盖 `app/{app}/lang/` 的文案，请改为放到 `{root}/app/lang/`。
 *
 * 语言包内声明显示名（可选）
 * -------------------------
 *   return ['__locale_name' => '简体中文', 'xxx' => '...'];
 * 供 {@see locales()} 输出语言切换器所需的 `locale => 显示名` 映射。
 *
 * 已知边界（有意为之）
 * ------------------
 *  - 发现只依赖文件系统，**不查插件启用状态**（与 ViewScope 一致），以免在
 *    安装期 / 表未迁移时把启动流程绑死在数据库上；被停用插件的语言包即使登记，
 *    只要没有代码调用其键就无副作用。
 *  - 运行期新增插件后需调用 {@see flush()}（或重启 worker）才能被重新发现，
 *    与 {@see \wise\plugin\middleware\PluginLoader::resetBootState()} 同构。
 *
 * Swoole 兼容
 * ----------
 * 发现结果是**应用级不变量**（与请求无关），故进程内只注册一次（静态标记），
 * 不产生跨请求污染。
 */
final class LangPack
{
    /** 语言包内声明「本语言显示名」的保留键 */
    public const LOCALE_NAME_KEY = '__locale_name';

    /**
     * 支持的扩展名
     *
     * 与 `Lang::parse()` 一致（php / json / yml / yaml）；逐个 glob 而非用
     * GLOB_BRACE，后者在部分平台（如 musl 的 Alpine）不可用。
     */
    protected const EXTENSIONS = ['php', 'json', 'yml', 'yaml'];

    /** 是否已完成注册（进程内幂等） */
    protected static bool $registered = false;

    // ==================== 注册 ====================

    /**
     * 发现全部语言包并注册到 `lang.extend_list`
     *
     * 幂等：同一进程内只执行一次。
     *
     * 🔴 时机要求：必须在**第一次 `switchLangSet()` 之前**执行。
     * `Lang::get()` 按 range 缓存已加载的语言包（Lang.php:233），一旦某个 range
     * 加载过就永不重载；而 `App::loadLangPack()` 会在服务 `boot()` 之前就以
     * 默认语言调用一次 `switchLangSet()`（App.php:479-480）。
     * 因此调用点只能是**服务 `register()` 阶段**，不能是 `boot()`。
     *
     * @see \app\common\service\LangPackService
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        Config::set(['extend_list' => self::mergeWithDeclared(self::extendList())], 'lang');
    }

    /**
     * 重置注册标记（运行期新增插件 / 测试用）
     */
    public static function flush(): void
    {
        self::$registered = false;
    }

    // ==================== 查询 ====================

    /**
     * 自动发现的语言包清单
     *
     * @return array<string, array<int, string>> locale => 语言包文件
     *         ⚠️ **数组顺序即优先级：越靠后加载优先级越高**
     */
    public static function extendList(): array
    {
        $list = [];

        foreach (self::scan() as $entry) {
            $list[$entry['locale']][] = $entry['file'];
        }

        return $list;
    }

    /**
     * 指定语言命中的语言包文件
     *
     * @param string $locale 语言标识，如 zh-cn
     * @return array<int, string>
     */
    public static function files(string $locale): array
    {
        return self::extendList()[$locale] ?? [];
    }

    /**
     * 是否存在该语言的语言包
     */
    public static function has(string $locale): bool
    {
        return self::files($locale) !== [];
    }

    /**
     * 已发现的语言及其显示名（供语言切换器 / 后台展示）
     *
     * 显示名来源优先级：`config('lang.locale_labels')` > 语言包内的
     * {@see LOCALE_NAME_KEY} > 语言标识本身。
     *
     * @return array<string, string> locale => 显示名
     */
    public static function locales(): array
    {
        $labels = Config::get('lang.locale_labels', []);
        $labels = is_array($labels) ? $labels : [];

        $locales = array_unique(array_merge(array_keys(self::extendList()), array_keys($labels)));
        sort($locales);

        $result = [];
        foreach ($locales as $locale) {
            $locale = (string) $locale;
            $result[$locale] = (string) ($labels[$locale] ?? self::declaredName($locale) ?? $locale);
        }

        return $result;
    }

    /**
     * 全量扫描结果（含来源分层，供 `php think lang:check` 展示）
     *
     * 返回顺序即加载顺序（优先级升序）。
     *
     * @return array<int, array{layer: string, owner: string, locale: string, file: string}>
     */
    public static function scan(): array
    {
        $entries = [];

        foreach (self::roots() as $root) {
            $dirs = glob($root['pattern'], GLOB_ONLYDIR);
            if (!is_array($dirs)) {
                continue;
            }

            sort($dirs);

            foreach ($dirs as $dir) {
                $owner = basename(dirname($dir));

                foreach (self::langFiles($dir) as $file) {
                    $locale = self::localeOf($file);
                    if ($locale === null) {
                        continue;
                    }

                    $entries[] = [
                        'layer'  => $root['layer'],
                        'owner'  => $owner,
                        'locale' => $locale,
                        'file'   => $file,
                    ];
                }
            }
        }

        return $entries;
    }

    // ==================== 内部 ====================

    /**
     * 合并自动发现与「显式声明」的语言包
     *
     * 显式声明（`config/lang.php` 的 `extend_list`）排在自动发现之后，
     * 即**显式优先于约定**。
     *
     * @param array<string, array<int, string>> $discovered
     * @return array<string, array<int, string>>
     */
    protected static function mergeWithDeclared(array $discovered): array
    {
        $declared = Config::get('lang.extend_list', []);
        $declared = is_array($declared) ? $declared : [];

        $locales = array_unique(array_merge(array_keys($discovered), array_keys($declared)));
        sort($locales);

        $merged = [];
        foreach ($locales as $locale) {
            $files = array_merge(
                array_values((array) ($discovered[$locale] ?? [])),
                array_values((array) ($declared[$locale] ?? []))
            );

            $merged[(string) $locale] = array_values(array_unique(array_map(
                static fn(string $file): string => self::absolute($file),
                array_filter($files, 'is_string')
            )));
        }

        return $merged;
    }

    /**
     * 约定搜索根（按层优先级升序）
     *
     * @return array<int, array{layer: string, pattern: string}>
     */
    protected static function roots(): array
    {
        $sep  = DIRECTORY_SEPARATOR;
        $root = rtrim(root_path(), '/\\') . $sep;

        return [
            // 包层：packages/{vendor}/{pkg}/lang
            ['layer' => 'package', 'pattern' => $root . 'packages' . $sep . '*' . $sep . '*' . $sep . 'lang'],
            // 包层：packages/{pkg}/lang（无 vendor 的布局）
            ['layer' => 'package', 'pattern' => $root . 'packages' . $sep . '*' . $sep . 'lang'],
            // 插件层：plugins/{ident}/lang
            ['layer' => 'plugin', 'pattern' => $root . 'plugins' . $sep . '*' . $sep . 'lang'],
            // 宿主层：app/lang（跨应用共用，最后加载故优先级最高）
            ['layer' => 'host', 'pattern' => $root . 'app' . $sep . 'lang'],
        ];
    }

    /**
     * 列出某语言包目录下的语言文件
     *
     * @return array<int, string>
     */
    protected static function langFiles(string $dir): array
    {
        $dir   = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        $files = [];

        foreach (self::EXTENSIONS as $ext) {
            foreach ((array) glob($dir . '*.' . $ext) as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 从文件名解析语言标识，非法/占位文件名返回 null
     *
     * `zh-cn.php` → `zh-cn`；`_template.php`、`.gitkeep` → null
     */
    protected static function localeOf(string $file): ?string
    {
        $locale = pathinfo($file, PATHINFO_FILENAME);

        if ($locale === '' || $locale[0] === '_' || $locale[0] === '.') {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9_\-]*$/i', $locale) === 1 ? $locale : null;
    }

    /**
     * 读取语言包内声明的显示名（{@see LOCALE_NAME_KEY}）
     *
     * 仅解析 php 语言包；解析失败（语法错误等）时静默跳过，交由 `lang:check` 报告。
     */
    protected static function declaredName(string $locale): ?string
    {
        foreach (self::files($locale) as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            try {
                $data = include $file;
            } catch (\Throwable $e) {
                continue;
            }

            if (is_array($data) && isset($data[self::LOCALE_NAME_KEY]) && is_string($data[self::LOCALE_NAME_KEY])) {
                return $data[self::LOCALE_NAME_KEY];
            }
        }

        return null;
    }

    /**
     * 相对路径按项目根补全为绝对路径
     */
    protected static function absolute(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if ($path !== ''
            && ($path[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:#', $path) === 1)
        ) {
            return $path;
        }

        return rtrim(root_path(), '/\\') . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

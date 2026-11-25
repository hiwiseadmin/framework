<?php
declare(strict_types=1);

namespace wise\plugin\manifest;

use think\helper\Str;

/**
 * 插件菜单 `path` 的「身份归一」—— **只产出比较键，不产出落盘值**。
 *
 * ## 为什么需要它
 *
 * `/plugin/{ident}/{controller}` 与 `/plugin/{ident}/{controller}/index` 在运行时是
 * **同一个资源**：`PluginDispatch::init()` 的动作段缺省取 `config('route.default_action')`、
 * 控制器段缺省取 `config('route.default_controller')`（`config/route.php:36-38`）。
 *
 * 既然语义等价，「去重 / 漂移检测 / 落库识别」就该按归一后的**身份**比对，而不是按字符串原样比对，
 * 否则同一个控制器会因为 `/index` 写法不同被判成两个节点：
 *   - 生成器追加出重复菜单（`CrudWriter::mergeManifestMenu()`，已接入）；
 *   - `--check` 对「手工写短式、生成器写长式」的插件永远报漂移；
 *   - 落库原语新建**第二条菜单 + 第二份权限**，角色授权留在旧权限行上 → 页面悄悄丢权
 *     （`MenuPermissionBridge::findOrCreateMenu()`，已接入；清理侧 `MenuItemSync::prune()`
 *     必须用同一口径，否则复用到的旧写法行会被当成「已删除」清掉）。
 *
 * 而鉴权匹配方向相反，需要的是**全部等价写法**（{@see self::variants()} /
 * {@see self::expandCandidates()}）：权限表存的是菜单 `path` 原样，请求侧若只投一种形态，
 * 另一种写法就匹配不到 —— 插件侧「未匹配到权限 → 放行」，等于放行了一条
 * 「在 URL 末尾多写个 `/index`」的绕过路径（`PluginAdminAuth`，已接入）。
 *
 * 除了「默认段写不写」，还有第三个维度：**控制器段拼写**。URL 的控制器段经
 * `Str::studly()` 映射到类名，故 `groupBattle` 与 `group_battle` 是同一个控制器
 * （`identity()` 负责折叠、`spellingVariants()` 负责展开）。
 *
 * 最后，{@see self::formOf()} / {@see self::preferredForm()} 提供**风格识别**，
 * 供生成器「风格继承」——新节点沿用该插件既有条目的多数派写法，不强制统一。
 *
 * ## 三条硬约定
 *
 * 1. **不强制统一，不改写存量**：菜单 `path` 同时是权限 `route`
 *    （`MenuPermissionBridge::transform()`），改写 path 会连带改权限 `ident`
 *    （`config/menu_permission.php` 的 `path_to_dot`）并留下孤立权限行。
 *    因此落盘 / 落库一律**存原样**，归一化只用于「是不是同一个节点」。
 * 2. **只认插件前缀**：宿主 `/admin/**` 不收敛 —— 那边 route 是历史约定，且
 *    `RbacManager::routeMatch()` 的通配 `*` 需要至少一段（`^admin/user/.*$`
 *    匹配不到 `admin/user`），削段会造成真实回归。
 * 3. **按位置收敛，不用尾正则**：插件路径结构固定为
 *    `plugin / {ident} / {controller} / {action} / key,value…`，只在「动作位」
 *    与「控制器位」收敛。无脑 `preg_replace('#/index$#')` 会把参数**值**削掉
 *    （`/plugin/x/y/detail/type/index` 的尾段是值不是动作）。
 *
 * ## 大小写
 *
 * 只有被比较的那一段做大小写折叠（`Index` ≡ `index`：`Str::studly('index')` 与
 * `Str::studly('Index')` 解析到同一个类）。**其余段保留原样** —— camelCase 控制器名
 * 大小写敏感（`Str::studly('urlRule')` = `UrlRule` ≠ `Str::studly('urlrule')` = `Urlrule`），
 * 整体小写会制造错误的等价。
 */
final class MenuPathNormalizer
{
    /** 插件路由前缀（`app/plugin/route/plugin.php`） */
    public const PLUGIN_PREFIX = 'plugin';

    /** `config/route.php` 的缺省值兜底（配置不可读时使用） */
    public const FALLBACK_ACTION     = 'index';
    public const FALLBACK_CONTROLLER = 'Index';

    /** 产出风格：显式带上可省略的默认段（`/plugin/x/y/index`） */
    public const FORM_LONG = 'long';

    /** 产出风格：省略默认段（`/plugin/x/y`） */
    public const FORM_SHORT = 'short';

    /** 不表达风格偏好（动作级 path、带参数的 path、空 path） */
    public const FORM_NEUTRAL = 'neutral';

    /** @var string 默认动作段（已小写） */
    private string $defaultAction;

    /** @var string 默认控制器段（已小写） */
    private string $defaultController;

    public function __construct(
        string $defaultAction = self::FALLBACK_ACTION,
        string $defaultController = self::FALLBACK_CONTROLLER
    ) {
        $this->defaultAction     = $this->fold($defaultAction, self::FALLBACK_ACTION);
        $this->defaultController = $this->fold($defaultController, self::FALLBACK_CONTROLLER);
    }

    /**
     * 按宿主配置构造（`config/route.php` 的 `default_action` / `default_controller`）。
     *
     * 配置不可读时静默回落常量：归一化是「比较」的辅助手段，绝不能因为读配置失败
     * 让生成器 / 校验命令直接炸掉。
     */
    public static function fromConfig(): self
    {
        return new self(
            self::configValue('route.default_action', self::FALLBACK_ACTION),
            self::configValue('route.default_controller', self::FALLBACK_CONTROLLER)
        );
    }

    /** 默认动作段（小写，取宿主 `route.default_action`） */
    public function defaultAction(): string
    {
        return $this->defaultAction;
    }

    /** 默认控制器段（小写，取宿主 `route.default_controller`） */
    public function defaultController(): string
    {
        return $this->defaultController;
    }

    /**
     * 归一为「身份键」（无前导斜杠）。
     *
     * ```
     * /plugin/x/y/index              → plugin/x/y
     * /plugin/x/y/index/index        → plugin/x/y          （动作位 + 控制器位依次收敛）
     * /plugin/x/index                → plugin/x
     * /plugin/x/y/detail             → plugin/x/y/detail   （非默认动作，不收敛）
     * /plugin/x/y/detail/type/index  → 原样                （尾段是参数值）
     * /admin/menu/index              → admin/menu/index    （非插件路径，不收敛）
     * ```
     */
    public function normalize(string $path): string
    {
        $forms = $this->stripSteps($path);

        return $forms === [] ? '' : (string) end($forms);
    }

    /**
     * 展开一个 path 的**全部等价写法**（原样写法在最前；均无前导斜杠）。
     *
     * **两个方向都要有** —— 存量的长式 / 短式不由我们决定，只投一种就会漏匹配：
     *
     * | 方向 | 例子 | 漏掉的后果 |
     * |:---|:---|:---|
     * | 削（长 → 短） | `/plugin/x/y/index` → `/plugin/x/y` | 鉴权侧：短式声明匹配不到长式请求 → 未匹配即放行＼� |
     * | 补（短 → 长） | `/plugin/x/y` → `/plugin/x/y/index` | 鉴权侧同上；**落库侧**（`MenuPermissionBridge::findOrCreateMenu()`）会多建一条菜单 + 一份权限，角色授权留在旧权限行上＼� |
     *
     * 只补「默认控制器段 / 默认动作段」，且补出来的写法必须仍归一到同一身份键
     * （`…/index/index/index` 这类不存在的形态会被 identity 过滤掉）；
     * 动作级 / 带参数的 path（≥4 段）**不补不削** —— 尾段是参数**值**。
     *
     * ```
     * /plugin/x/y/index          → ['plugin/x/y/index', 'plugin/x/y']
     * /plugin/x/y                → ['plugin/x/y', 'plugin/x/y/index']
     * /plugin/x                  → ['plugin/x', 'plugin/x/index', 'plugin/x/index/index']
     * /plugin/x/y/detail         → ['plugin/x/y/detail']             （动作级：不补不削）
     * /plugin/x/y/detail/type/index → ['plugin/x/y/detail/type/index']（尾段是参数值）
     * /admin/menu/index          → ['admin/menu/index']              （非插件路径，不收敛）
     * ''                          → []
     * ```
     *
     * @return array<int, string>
     */
    public function variants(string $path): array
    {
        $identity = $this->identity($path);

        if ($identity === '') {
            return [];
        }

        $forms = [];

        foreach ($this->stripSteps($path) as $form) {
            $forms[$form] = true;
        }

        $segments = $this->segments($path);

        if (strtolower($segments[0] ?? '') === self::PLUGIN_PREFIX && count($segments) <= 3) {
            for ($i = 0; $i < 2; $i++) {
                $segments[] = count($segments) === 2 ? $this->defaultController : $this->defaultAction;

                $forms[implode('/', $segments)] = true;
            }
        }

        $variants = [];
        foreach (array_keys($forms) as $form) {
            if ($this->identity($form) === $identity) {
                $variants[] = $form;
            }
        }

        return $variants;
    }

    /**
     * 把一组候选 route 展开为鉴权匹配用的**候选集合**。
     *
     * = 每个等价写法 × 控制器段拼写变体 × 「带前导斜杠 / 不带前导斜杠」，去重保序。
     * 三个维度都对应过真实缺口：
     *   - 前导斜杠（权限表存带斜杠的菜单 path，请求侧曾只投无斜杠形态）；
     *   - 等价写法（短式声明被「URL 末尾加 `/index`」绕过）；
     *   - 控制器段拼写：`group_battle` 与 `groupBattle` 是同一个控制器，见
     *     {@see self::spellingVariants()}。
     *
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    public function expandCandidates(array $paths): array
    {
        $routes = [];

        foreach ($paths as $path) {
            foreach ($this->variants((string) $path) as $variant) {
                foreach ($this->spellingVariants($variant) as $form) {
                    $routes[$form]       = true;
                    $routes['/' . $form] = true;
                }
            }
        }

        return array_keys($routes);
    }

    /**
     * 逐级省略「默认动作段 / 默认控制器段」，返回每一步的写法。
     *
     * 单独抽出来是因为**调用方需要中间形态**：`normalize()` 只要终点，
     * `variants()` / `expandCandidates()` 要整条链。
     *
     * @return array<int, string> 空数组表示空 path / 无有效段
     */
    private function stripSteps(string $path): array
    {
        $segments = $this->segments($path);

        if ($segments === []) {
            return [];
        }

        $forms = [implode('/', $segments)];

        if (strtolower($segments[0]) !== self::PLUGIN_PREFIX) {
            return $forms;
        }

        if (count($segments) === 4 && $this->isDefaultAction($segments[3])) {
            array_pop($segments);
            $forms[] = implode('/', $segments);
        }

        if (count($segments) === 3 && $this->isDefaultController($segments[2])) {
            array_pop($segments);
            $forms[] = implode('/', $segments);
        }

        return $forms;
    }

    /**
     * 完整身份键 = 形态归一（{@see self::normalize()}）+ **控制器段拼写折叠**。
     *
     * 为什么还要折叠拼写：URL 的控制器段经 `Str::studly()` 映射到类名
     * （`PluginDispatch::init()`），因此 `groupBattle` / `group_battle` 指向**同一个**
     * `GroupBattle` 类；而存量 manifest 两种拼写并存（wisecms 用 camelCase：
     * `urlRule` / `friendLink`；match3game / smartstock 用 snake_case：
     * `group_battle` / `product_category`）。不折叠就会把同一个页面判成两个节点。
     *
     * 折叠到 snake 形态；**动作段不折叠**（动作按方法名原样调用，大小写敏感，
     * `Str::studly` 不参与）。
     *
     * ```
     * /plugin/x/groupBattle/index  → plugin/x/group_battle
     * /plugin/x/group_battle       → plugin/x/group_battle      （等价）
     * /plugin/x/urlRule/detail     → plugin/x/url_rule/detail
     * /plugin/x/urlrule            → plugin/x/urlrule           （❌ 不等价：类名不同）
     * ```
     */
    public function identity(string $path): string
    {
        $segments = $this->segments($this->normalize($path));

        if ($segments === []) {
            return '';
        }

        if (count($segments) >= 3 && strtolower($segments[0]) === self::PLUGIN_PREFIX) {
            $segments[2] = $this->foldControllerSegment($segments[2]);
        }

        return implode('/', $segments);
    }

    /**
     * 该 path 的**产出风格**（长式 / 短式 / 不表达偏好）。
     *
     * 用于「风格继承」：新节点沿用该 manifest 既有条目的多数派写法，
     * 避免同一个插件里长短混排（见 {@see self::preferredForm()}）。
     */
    public function formOf(string $path): string
    {
        $segments = $this->segments($path);

        if ($segments === []) {
            return self::FORM_NEUTRAL;
        }

        if ($this->stripSteps($path) !== [implode('/', $segments)]) {
            return self::FORM_LONG;
        }

        if (strtolower($segments[0]) !== self::PLUGIN_PREFIX) {
            return self::FORM_NEUTRAL;
        }

        return in_array(count($segments), [2, 3], true) ? self::FORM_SHORT : self::FORM_NEUTRAL;
    }

    /**
     * 从一组既有 path 推断「该 manifest 的产出风格」（多数派）。
     *
     * 中性写法（动作级 / 带参数）不计票 —— 它们并不表达「长式还是短式」的偏好。
     * 无数据或平票 → 长式（生成器的既有默认，保持零惊喜）。
     *
     * @param array<int, string> $paths
     */
    public function preferredForm(array $paths): string
    {
        $long  = 0;
        $short = 0;

        foreach ($paths as $path) {
            $form = $this->formOf((string) $path);

            if ($form === self::FORM_LONG) {
                $long++;
            } elseif ($form === self::FORM_SHORT) {
                $short++;
            }
        }

        return $short > $long ? self::FORM_SHORT : self::FORM_LONG;
    }

    /**
     * 控制器段的**拼写变体**（原样 + snake_case，仅插件路径、仅控制器位）。
     *
     * 供「多形态投递」使用：权限表的 `route` 存的是菜单 path 原样，而同一个控制器
     * 既可能被写成 `group_battle` 也可能被写成 `groupBattle`，只投一种会导致另一种
     * 匹配不到（插件侧未匹配即放行）。单段控制器（`article`）两种写法相同，不会膨胀。
     *
     * @return array<int, string>
     */
    public function spellingVariants(string $path): array
    {
        $segments = $this->segments($path);

        if ($segments === []) {
            return [];
        }

        if (count($segments) < 3 || strtolower($segments[0]) !== self::PLUGIN_PREFIX) {
            return [implode('/', $segments)];
        }

        $forms = [];

        foreach (array_unique([$segments[2], $this->foldControllerSegment($segments[2])]) as $segment) {
            $variant    = $segments;
            $variant[2] = $segment;
            $forms[implode('/', $variant)] = true;
        }

        return array_keys($forms);
    }

    /**
     * 两个 path 是否指向同一个资源（形态 + 拼写都归一）。
     *
     * 空 path（`dir` 分组节点、未填 path 的节点）**永远不与他者等价** —— 它们不是
     * 「同一个页面」，把它们归成一组会让去重误判（分组节点互认、甚至与空串相撞）。
     */
    public function isSame(string $pathA, string $pathB): bool
    {
        $left = $this->identity($pathA);

        return $left !== '' && $left === $this->identity($pathB);
    }

    /**
     * 控制器段归一为 snake_case（`groupBattle` → `group_battle`），
     * 因为 `Str::studly()` 会把两种写法映射到同一个类。
     */
    private function foldControllerSegment(string $segment): string
    {
        return Str::snake($segment);
    }

    /**
     * 拆段：剥离查询串 / 锚点，忽略首尾斜杠与空段（`//`）。
     *
     * @return array<int, string>
     */
    private function segments(string $path): array
    {
        $path = trim($path);

        if ($path === '') {
            return [];
        }

        $path = (string) preg_split('/[?#]/', $path, 2)[0];
        $path = trim($path, "/ \t\n\r\0\x0B");

        if ($path === '') {
            return [];
        }

        return array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== ''
        ));
    }

    private function isDefaultAction(string $segment): bool
    {
        return strtolower($segment) === $this->defaultAction;
    }

    private function isDefaultController(string $segment): bool
    {
        return strtolower($segment) === $this->defaultController;
    }

    private function fold(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));

        return $value === '' ? strtolower($fallback) : $value;
    }

    /**
     * 读取配置项（`config()` 不存在 / 抛错 / 非字符串 → 回落兜底值）。
     */
    private static function configValue(string $key, string $fallback): string
    {
        if (!function_exists('config')) {
            return $fallback;
        }

        try {
            $value = config($key);
        } catch (\Throwable $e) {
            return $fallback;
        }

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }
}

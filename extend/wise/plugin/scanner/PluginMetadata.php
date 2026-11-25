<?php

declare(strict_types=1);

namespace wise\plugin\scanner;

/**
 * 表示从 plugin.json 或 manifest.json 清单文件中解析出的插件元数据。
 *
 * 此值对象包含插件的所有信息，包括
 * 名称、版本、依赖关系以及服务提供者类。
 *
 * 兼容两种清单格式：
 * - plugin.json（新格式）：使用 name 作为标识，dependencies 声明依赖
 * - manifest.json（旧格式）：使用 ident 作为标识，require 声明依赖
 */
class PluginMetadata
{
    /**
     * 插件名称/标识。
     *
     * @var string
     */
    protected string $name = '';

    /**
     * 插件标识（用于 manifest.json 兼容，通常与 name 相同）。
     *
     * @var string
     */
    protected string $ident = '';

    /**
     * 插件显示名称（manifest.json 中的 name 字段，中文名）。
     *
     * @var string
     */
    protected string $displayName = '';

    /**
     * 插件版本。
     *
     * @var string
     */
    protected string $version = '0.0.0';

    /**
     * 插件描述。
     *
     * @var string
     */
    protected string $description = '';

    /**
     * 插件作者。
     *
     * @var string
     */
    protected string $author = '';

    /**
     * 插件依赖关系（名称 => 版本约束）。
     *
     * @var array<string, string>
     */
    protected array $dependencies = [];

    /**
     * 库包依赖声明（manifest.json 的 packages 字段）。
     *
     * 声明插件依赖某个 **composer 库包**（如 `wiseadmin/wise-member`），
     * 与 `dependencies`（插件间依赖）语义区分：库包是可选安装的
     * `src/packages/wiseadmin/wise-*`，卸载后不会有"同名插件"可查。
     *
     * 两种写法（{@see PluginMetadata::normalizePackages()} 归一）：
     *
     * ```json
     * "packages": {
     *     "wiseadmin/wise-member": "*",                                        // 简写 = 硬依赖
     *     "wiseadmin/wise-oauth": { "constraint": "^1.0", "required": false }  // 软依赖
     * }
     * ```
     *
     * 硬依赖缺失 → **阻止加载与启用**（{@see \wise\plugin\middleware\PluginLoader}、
     * {@see \wise\plugin\dependency\DependencyChecker}）；
     * 软依赖缺失 → 仅诊断提示（{@see \wise\plugin\dependency\PackageDependencyChecker}）。
     *
     * @var array<string, array{constraint:string, required:bool}>
     */
    protected array $packages = [];

    /**
     * 插件服务提供者类名。
     *
     * @var string
     */
    protected string $serviceProvider = '';

    /**
     * 插件是否已启用。
     *
     * @var bool
     */
    protected bool $enabled = false;

    /**
     * 菜单定义（manifest.json 兼容字段）。
     *
     * @var array
     */
    protected array $menus = [];

    /**
     * 插件根容器声明（manifest.json 的 menu 字段）。
     *
     * 用于把插件作为一个顶层容器展示，可选字段：
     *   - title  插件入口标题（默认取 title/name）
     *   - icon   插件入口图标
     *   - mount  挂载模式（module/sidebar_group/sidebar_fixed/launcher）
     *   - group  固定分组名（sidebar_fixed 时生效）
     *   - pinned 是否钉选到顶部导航
     *   - sort   排序
     *   - path   入口路径（可选）
     *
     * @var array
     */
    protected array $menuRoot = [];

    /**
     * 权限定义（manifest.json 兼容字段）。
     *
     * @var array
     */
    protected array $permissions = [];

    /**
     * 是否开发模式。
     *
     * @var bool
     */
    protected bool $develop = false;

    /**
     * 数据填充策略（manifest.json 的 seed 字段）。
     *
     * `required` 基线数据（默认）/ `demo` 演示数据 / `none` 无。判定见
     * {@see \wise\plugin\lifecycle\SeedPolicy}。
     *
     * 未声明时为 null（对外 `getSeedMode()` 返回默认策略）——保留 null 是为了
     * `toManifest()` 写回清单时不凭空多出一个字段。
     *
     * ⚠️ 与 publish / capabilities 同理：必须出现在 toArray() 中，
     * 否则扫描缓存（plugin.cache_enabled 默认 true）往返后策略被静默重置。
     *
     * @var string|null
     */
    protected ?string $seed = null;

    /**
     * 框架兼容范围声明（manifest.json 的 compatible_with 字段）。
     *
     * 作者声明的支持范围（如 `["^1.0"]`），由
     * {@see \wise\plugin\compat\FrameworkCompatibility} 归一与判定。
     * 空数组 = 未声明（不拦截、不标红）。
     *
     * ⚠️ 必须出现在 toArray() / toManifest() 中，否则缓存往返/写回会丢声明。
     *
     * @var array<int, string>
     */
    protected array $compatibleWith = [];

    /**
     * 启动台/品牌图标声明（manifest.json 顶层 icon 字段）。
     *
     * 完全来自 manifest，与菜单图标（menus[].icon）解耦。轻量结构：
     *   - type  bi（Bootstrap Icons 类名）| image（图片 URL）| emoji
     *   - value 类名 / URL / Emoji
     *   - color 圆形容器底色/主题色（可选，bi / emoji 形态生效）
     *
     * @var array{type:string,value:string,color:string}
     */
    protected array $icon = [
        'type'  => '',
        'value' => '',
        'color' => '',
    ];

    /**
     * 前端插槽声明（manifest.json 的 slots 字段）。
     *
     * @var array
     */
    protected array $slots = [];

    /**
     * 第三方命名空间（manifest.json 的 vendor 字段）。
     *
     * 声明插件额外需要注册到 Composer ClassLoader 的 PSR-4 命名空间。
     *
     * @var array
     */
    protected array $vendor = [];

    /**
     * 配置项定义（manifest.json 的 config 字段，配置 schema）。
     *
     * @var array
     */
    protected array $configSchema = [];

    /**
     * 配置展示元数据：标签页（manifest.json 顶层 config_tabs）。
     *
     * 结构（值支持数组或字符串简写）：
     * ```json
     * { "basic": { "title": "站点设置", "sort": 10 }, "api": "接口输出" }
     * ```
     * 供插件配置页渲染 Nav tabs 的中文标题与顺序；未声明时回退内置表
     * {@see \wise\plugin\config\PluginConfigForm::TAB_TITLES}。
     *
     * ⚠️ 与 slots / config 同理：本字段必须同时出现在 toArray() 中，否则扫描缓存
     * （plugin.cache_enabled 默认 true）往返后 `config_tabs` 会被静默丢弃。
     *
     * @var array<string, array{title?:string,sort?:int}|string>
     */
    protected array $configTabs = [];

    /**
     * 配置展示元数据：分区（manifest.json 顶层 config_groups）。
     *
     * 结构与消费方式同 {@see self::$configTabs}：对应配置页每个标签页内的
     * `.config-group-title` 分区标题；未声明时回退内置表
     * {@see \wise\plugin\config\PluginConfigForm::GROUP_TITLES}。
     *
     * @var array<string, array{title?:string,sort?:int}|string>
     */
    protected array $configGroups = [];

    /**
     * API 版本策略声明（manifest.json 的 api 字段）。
     *
     * 由插件自行声明其 API 主版本集合与生命周期：
     *   - versions        可服务的 API 主版本集合（如 ['v1','v2']）
     *   - default_version 未带版本段时的默认版本
     *   - require_version 是否强制请求携带版本段
     *   - deprecated      废弃版本与下线时间 { v1: { sunset, message } }
     *
     * @var array
     */
    protected array $api = [];

    /**
     * 额外静态资源发布映射（manifest.json 的 publish 字段）。
     *
     * `{源相对插件根: 目标相对应用根}`（目标以 `public/` 开头），用于
     * `statics/` 之外、但需要公开访问的资源，如会员主题
     * `theme/{theme}/assets` → `public/statics/member/themes/{theme}`。
     *
     * 消费点：{@see \wise\plugin\adapter\AssetManager} 在安装/启用时复制、
     * 卸载时清理。保留相对路径原样，绝对路径解析与越界过滤由 AssetManager 负责。
     *
     * ⚠️ 与 slots / config_schema / config_tabs 同理：本字段必须同时出现在
     * toArray() / toManifest() 中，否则扫描缓存（plugin.cache_enabled 默认 true）
     * 往返后 `publish` 会被静默丢弃。
     *
     * @var array<string, string>
     */
    protected array $publish = [];

    /**
     * 能力协商声明（manifest.json 的 capabilities 字段）。
     *
     * 结构（{@see \wise\plugin\capability\CapabilityResolver} 在安装/启用时消费）：
     *
     * ```json
     * "capabilities": {
     *     "requires": {                                  // 本插件需要的外部能力
     *         "editor.rich": {                           // 能力名 = ExtPoint 扩展点名
     *             "required": true,                      // false = 可选（缺失仅告警）
     *             "mode": "any",                         // any | all | active
     *             "preferred": "tiptap",                 // 首选实现名（mode=any 时）
     *             "description": "富文本编辑能力"
     *         },
     *         "storage.oss": true                        // 布尔/字符串简写
     *     },
     *     "provides": {                                  // 本插件可提供的能力（缺失建议用）
     *         "services": { "editor.rich": "TipTap 编辑器" }
     *     }
     * }
     * ```
     *
     * 提供方通过 `ExtPoint::register(能力名, 实现名, 实现)` 注册；本字段声明
     * `provides` 只用于缺失诊断时的建议，不自动注册。
     *
     * ⚠️ 与 slots / config_schema / publish 同理：本字段必须同时出现在
     * toArray() / toManifest() 中，否则扫描缓存（plugin.cache_enabled 默认 true）
     * 往返后 `capabilities` 会被静默丢弃。
     *
     * @var array
     */
    protected array $capabilities = [];

    /**
     * 从 JSON 字符串创建 PluginMetadata 实例。
     *
     * @param string $json 来自清单文件的 JSON 字符串
     * @return static
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = [];
        }

        return static::fromArray($data);
    }

    /**
     * 从数组创建 PluginMetadata 实例。
     *
     * 解析 plugin.json v2 格式字段。
     *
     * @param array<string, mixed> $data 插件元数据数组
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $metadata = new static();

        $pluginIdent = $data['name'] ?? '';
        $metadata->setName($pluginIdent);
        $metadata->setIdent($pluginIdent);

        $displayName = $data['display_name'] ?? $data['title'] ?? $pluginIdent;
        $metadata->setDisplayName($displayName);

        $metadata->setVersion($data['version'] ?? '0.0.0');
        $metadata->setDescription($data['description'] ?? '');
        $metadata->setAuthor($data['author'] ?? '');

        $metadata->setDependencies($data['dependencies'] ?? []);

        $metadata->setPackages($data['packages'] ?? []);

        $serviceProvider = $data['service_provider'] ?? '';
        if ($serviceProvider === '' && $pluginIdent !== '') {
            $serviceProvider = 'plugin\\' . $pluginIdent . '\\BootServiceProvider';
        }
        $metadata->setServiceProvider($serviceProvider);

        $metadata->setEnabled($data['enabled'] ?? false);

        $metadata->setMenuRoot($data['menu'] ?? []);
        $metadata->setMenus($data['menus'] ?? []);
        $metadata->setPermissions($data['permissions'] ?? []);
        $metadata->setDevelop($data['develop'] ?? false);
        $metadata->setIcon($data['icon'] ?? '');
        $metadata->setSlots($data['slots'] ?? []);

        $metadata->setVendor($data['vendor'] ?? []);

        $metadata->setConfigSchema($data['config_schema'] ?? $data['config'] ?? []);

        $metadata->setConfigTabs((array) ($data['config_tabs'] ?? []));
        $metadata->setConfigGroups((array) ($data['config_groups'] ?? []));

        $metadata->setApi($data['api'] ?? []);

        $metadata->setPublish(is_array($data['publish'] ?? null) ? $data['publish'] : []);

        $metadata->setCapabilities(is_array($data['capabilities'] ?? null) ? $data['capabilities'] : []);

        $metadata->setSeedMode((string) ($data['seed'] ?? ''));

        $metadata->setCompatibleWith($data['compatible_with'] ?? []);

        return $metadata;
    }

    /**
     * 将元数据转换为数组（包含运行时状态）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name'             => $this->name,
            'ident'            => $this->ident ?: $this->name,
            'display_name'     => $this->displayName,
            'version'          => $this->version,
            'description'      => $this->description,
            'author'           => $this->author,
            'dependencies'     => $this->dependencies,
            'packages'         => $this->packages,
            'service_provider' => $this->serviceProvider,
            'enabled'          => $this->enabled,
            'menu'             => $this->menuRoot,
            'menus'            => $this->menus,
            'icon'             => $this->icon,
            'permissions'      => $this->permissions,
            'develop'          => $this->develop,
            'slots'            => $this->slots,
            'vendor'           => $this->vendor,
            'api'              => $this->api,
            'config_schema'    => $this->configSchema,
            'config_tabs'      => $this->configTabs,
            'config_groups'    => $this->configGroups,
            'publish'          => $this->publish,
            'capabilities'     => $this->capabilities,
        ];

        if ($this->seed !== null) {
            $data['seed'] = $this->seed;
        }

        if ($this->compatibleWith !== []) {
            $data['compatible_with'] = $this->compatibleWith;
        }

        return $data;
    }

    /**
     * 将清单字段转换为数组（不含运行时状态 enabled）。
     *
     * 用于写回清单文件时仅包含清单声明字段，
     * enabled 等运行时状态由 PluginConfig 独立管理。
     *
     * @return array<string, mixed>
     */
    public function toManifest(): array
    {
        $manifest = [
            'name'             => $this->name,
            'version'          => $this->version,
            'description'      => $this->description,
            'author'           => $this->author,
            'dependencies'     => $this->dependencies,
            'packages'         => $this->packages,
            'service_provider' => $this->serviceProvider,
            'menu'             => $this->menuRoot,
            'menus'            => $this->menus,
            'icon'             => $this->icon,
            'permissions'      => $this->permissions,
            'develop'          => $this->develop,
            'slots'            => $this->slots,
            'vendor'           => $this->vendor,
            'api'              => $this->api,
            'publish'          => $this->publish,
            'capabilities'     => $this->capabilities,
        ];

        if ($this->seed !== null) {
            $manifest['seed'] = $this->seed;
        }

        if ($this->compatibleWith !== []) {
            $manifest['compatible_with'] = $this->compatibleWith;
        }

        return $manifest;
    }


    /**
     * 获取插件名称。
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 设置插件名称。
     *
     * @param string $name
     * @return void
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * 获取插件标识。
     *
     * @return string
     */
    public function getIdent(): string
    {
        return $this->ident ?: $this->name;
    }

    /**
     * 设置插件标识。
     *
     * @param string $ident
     * @return void
     */
    public function setIdent(string $ident): void
    {
        $this->ident = $ident;
    }

    /**
     * 获取插件显示名称。
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->displayName ?: $this->name;
    }

    /**
     * 设置插件显示名称。
     *
     * @param string $displayName
     * @return void
     */
    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    /**
     * 获取插件版本。
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * 设置插件版本。
     *
     * @param string $version
     * @return void
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * 获取插件描述。
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 设置插件描述。
     *
     * @param string $description
     * @return void
     */
    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    /**
     * 获取插件作者。
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return $this->author;
    }

    /**
     * 设置插件作者。
     *
     * @param string $author
     * @return void
     */
    public function setAuthor(string $author): void
    {
        $this->author = $author;
    }

    /**
     * 获取插件依赖关系。
     *
     * @return array<string, string>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * 设置插件依赖关系。
     *
     * @param array<string, string> $dependencies
     * @return void
     */
    public function setDependencies(array $dependencies): void
    {
        $this->dependencies = $dependencies;
    }

    /**
     * 获取库包依赖声明（已归一为 `{包名: {constraint, required}}`）
     *
     * @return array<string, array{constraint:string, required:bool}>
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    /**
     * 设置库包依赖声明（兼容 manifest 原始写法与归一写法）
     *
     * @param mixed $packages
     * @return void
     */
    public function setPackages($packages): void
    {
        $this->packages = self::normalizePackages($packages);
    }

    /**
     * 是否声明了库包依赖
     */
    public function hasPackages(): bool
    {
        return $this->packages !== [];
    }

    /**
     * 归一化库包依赖声明
     *
     * 兼容三种输入：
     *   1. `{"pkg": "*"}`（简写 → 硬依赖）；
     *   2. `{"pkg": {"constraint": "*", "required": false}}`（完整写法）；
     *   3. `["pkg"]`（列表写法）；
     * 其它形态（非数组 / 空 / 非法项）一律忽略。
     *
     * 归一后的结构也是 toArray() 的输出形态，因此扫描缓存往返（toArray → fromArray）
     * 不会丢失声明 —— 与 `config_schema` / `config_tabs` / `publish` 同样的坑。
     *
     * @param  mixed $packages
     * @return array<string, array{constraint:string, required:bool}>
     */
    public static function normalizePackages($packages): array
    {
        if (!is_array($packages) || $packages === []) {
            return [];
        }

        $result = [];

        foreach ($packages as $name => $spec) {
            if (is_int($name)) {
                if (is_string($spec) && $spec !== '') {
                    $result[$spec] = ['constraint' => '*', 'required' => true];
                }

                continue;
            }

            if (!is_string($name) || $name === '') {
                continue;
            }

            if (is_string($spec)) {
                $result[$name] = ['constraint' => $spec !== '' ? $spec : '*', 'required' => true];
                continue;
            }

            if (!is_array($spec)) {
                continue;
            }

            $constraint = (string) ($spec['constraint'] ?? $spec['version'] ?? '*');

            $result[$name] = [
                'constraint' => $constraint !== '' ? $constraint : '*',
                'required'   => (bool) ($spec['required'] ?? true),
            ];
        }

        return $result;
    }

    /**
     * 获取插件服务提供者类名。
     *
     * @return string
     */
    public function getServiceProvider(): string
    {
        return $this->serviceProvider;
    }

    /**
     * 设置插件服务提供者类名。
     *
     * @param string $serviceProvider
     * @return void
     */
    public function setServiceProvider(string $serviceProvider): void
    {
        $this->serviceProvider = $serviceProvider;
    }

    /**
     * 检查插件是否已启用。
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 设置插件是否已启用。
     *
     * @param bool $enabled
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * 返回 enabled 状态变更后的新副本（不可变模式）。
     *
     * 原始实例不会被修改，适用于缓存共享场景。
     *
     * @param bool $enabled 新的启用状态
     * @return static
     */
    public function withEnabled(bool $enabled): static
    {
        $new = clone $this;
        $new->enabled = $enabled;
        return $new;
    }


    /**
     * 获取启动台/品牌图标声明（已归一化）。
     *
     * @return array{type:string,value:string,color:string}
     */
    public function getIcon(): array
    {
        return $this->icon;
    }

    /**
     * 设置启动台/品牌图标声明。
     *
     * 接受字符串（如 "bi bi-box-seam" / 图片 URL / Emoji）或对象
     * {type, value, color}；内部做轻量归一化（不做存储落库）。
     *
     * @param mixed $icon
     * @return void
     */
    public function setIcon(mixed $icon): void
    {
        $this->icon = self::normalizeIcon($icon);
    }

    /**
     * 轻量归一化启动台图标（manifest 顶层 icon 字段）。
     *
     * @param mixed $icon string|array{type?:string,value?:string,color?:string}|null
     * @return array{type:string,value:string,color:string}
     */
    protected static function normalizeIcon(mixed $icon): array
    {
        $type  = '';
        $value = '';
        $color = '';

        if (is_string($icon)) {
            $value = trim($icon);
            if ($value !== '') {
                $type = self::guessIconType($value);
            }
        } elseif (is_array($icon)) {
            $type  = strtolower((string)($icon['type'] ?? ''));
            $value = trim((string)($icon['value'] ?? ''));
            $color = trim((string)($icon['color'] ?? ''));
            if ($type === '' && $value !== '') {
                $type = self::guessIconType($value);
            }
            if (!in_array($type, ['bi', 'image', 'emoji'], true)) {
                $type = $value !== '' ? self::guessIconType($value) : 'bi';
            }
        }

        return ['type' => $type, 'value' => $value, 'color' => substr($color, 0, 50)];
    }

    /**
     * 依据 value 推断图标类型。
     *
     * @param string $value
     * @return string bi | image | emoji
     */
    protected static function guessIconType(string $value): string
    {
        if (preg_match('/^bi\s/i', $value)) {
            return 'bi';
        }
        if (preg_match('#^(https?://|/|\./|\.\./)#i', $value)) {
            return 'image';
        }
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $value)) {
            return 'emoji';
        }
        return 'bi';
    }

    /**
     * 获取插件根容器声明。
     *
     * @return array
     */
    public function getMenuRoot(): array
    {
        return $this->menuRoot;
    }

    /**
     * 设置插件根容器声明。
     *
     * @param array $menuRoot
     * @return void
     */
    public function setMenuRoot(array $menuRoot): void
    {
        $this->menuRoot = $menuRoot;
    }

    /**
     * 获取菜单定义。
     *
     * @return array
     */
    public function getMenus(): array
    {
        return $this->menus;
    }

    /**
     * 设置菜单定义。
     *
     * @param array $menus
     * @return void
     */
    public function setMenus(array $menus): void
    {
        $this->menus = $menus;
    }

    /**
     * 获取权限定义。
     *
     * @return array
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * 设置权限定义。
     *
     * @param array $permissions
     * @return void
     */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
    }

    /**
     * 是否为开发模式。
     *
     * @return bool
     */
    public function isDevelop(): bool
    {
        return $this->develop;
    }

    /**
     * 设置开发模式。
     *
     * @param bool $develop
     * @return void
     */
    public function setDevelop(bool $develop): void
    {
        $this->develop = $develop;
    }

    /**
     * 获取数据填充策略：required（默认）/ demo / none。
     *
     * 调用方（PluginLifecycle）据此决定是否执行 `database/seeds/`：
     * 语义与判定见 {@see \wise\plugin\lifecycle\SeedPolicy}。
     */
    public function getSeedMode(): string
    {
        return $this->seed ?? 'required';
    }

    /**
     * 设置数据填充策略（manifest.json 的 seed 字段）。
     *
     * 无法识别的值按「未声明」处理（回落默认 required），不在这里抛异常 ——
     * 拼写错误由 `plugin:audit` 的清单检查提示，元数据层只保证不把安装流程打崩。
     */
    public function setSeedMode(?string $mode): void
    {
        $mode = strtolower(trim((string) $mode));

        $this->seed = in_array($mode, ['required', 'demo', 'none'], true) ? $mode : null;
    }

    /**
     * 清单是否显式声明了数据填充策略。
     */
    public function hasSeedDeclaration(): bool
    {
        return $this->seed !== null;
    }

    /**
     * 框架兼容范围声明（未声明返回空数组）。
     *
     * @return array<int, string>
     */
    public function getCompatibleWith(): array
    {
        return $this->compatibleWith;
    }

    /**
     * 设置框架兼容范围（manifest.json 的 compatible_with 字段）。
     *
     * 归一规则见 {@see \wise\plugin\compat\FrameworkCompatibility::normalize()}；
     * 语法合法性由 `plugin:audit` 负责提示，这里只做形态归一。
     */
    public function setCompatibleWith(mixed $ranges): void
    {
        $this->compatibleWith = \wise\plugin\compat\FrameworkCompatibility::normalize($ranges);
    }

    /**
     * 是否声明了框架兼容范围。
     */
    public function hasCompatibilityDeclaration(): bool
    {
        return $this->compatibleWith !== [];
    }

    /**
     * 获取前端插槽声明。
     *
     * @return array
     */
    public function getSlots(): array
    {
        return $this->slots;
    }

    /**
     * 设置前端插槽声明。
     *
     * @param array $slots
     * @return void
     */
    public function setSlots(array $slots): void
    {
        $this->slots = $slots;
    }

    /**
     * 获取第三方命名空间（vendor）。
     *
     * @return array
     */
    public function getVendor(): array
    {
        return $this->vendor;
    }

    /**
     * 设置第三方命名空间（vendor）。
     *
     * @param array $vendor
     * @return void
     */
    public function setVendor(array $vendor): void
    {
        $this->vendor = $vendor;
    }

    /**
     * 获取配置项定义。
     *
     * @return array
     */
    public function getConfigSchema(): array
    {
        return $this->configSchema;
    }

    /**
     * 设置配置项定义。
     *
     * @param array $configSchema
     * @return void
     */
    public function setConfigSchema(array $configSchema): void
    {
        $this->configSchema = $configSchema;
    }

    /**
     * 获取配置页标签页展示元数据（manifest.json 顶层 config_tabs）
     *
     * @return array<string, array{title?:string,sort?:int}|string>
     */
    public function getConfigTabs(): array
    {
        return $this->configTabs;
    }

    /**
     * 设置配置页标签页展示元数据
     *
     * @param array<string, array{title?:string,sort?:int}|string> $configTabs
     */
    public function setConfigTabs(array $configTabs): void
    {
        $this->configTabs = $configTabs;
    }

    /**
     * 获取配置页分区展示元数据（manifest.json 顶层 config_groups）
     *
     * @return array<string, array{title?:string,sort?:int}|string>
     */
    public function getConfigGroups(): array
    {
        return $this->configGroups;
    }

    /**
     * 设置配置页分区展示元数据
     *
     * @param array<string, array{title?:string,sort?:int}|string> $configGroups
     */
    public function setConfigGroups(array $configGroups): void
    {
        $this->configGroups = $configGroups;
    }

    /**
     * 获取 API 版本策略声明。
     *
     * @return array
     */
    public function getApi(): array
    {
        return $this->api;
    }

    /**
     * 设置 API 版本策略声明。
     *
     * @param array $api
     * @return void
     */
    public function setApi(array $api): void
    {
        $this->api = $api;
    }

    /**
     * 获取额外静态资源发布映射（相对路径原样，未做绝对路径解析）。
     *
     * @return array<string, string>
     */
    public function getPublish(): array
    {
        return $this->publish;
    }

    /**
     * 设置额外静态资源发布映射（manifest.json 的 publish 字段）。
     *
     * 仅保留 `{字符串键: 字符串值}` 的合法项，其余形态忽略。
     *
     * @param array<string, string> $publish
     * @return void
     */
    public function setPublish(array $publish): void
    {
        $this->publish = [];

        foreach ($publish as $source => $target) {
            if (!is_string($source) || !is_string($target) || $source === '' || $target === '') {
                continue;
            }

            $this->publish[$source] = $target;
        }
    }

    /**
     * 获取能力协商声明（manifest.json 的 capabilities 字段）。
     *
     * @return array{requires?:array, provides?:array}
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * 设置能力协商声明（manifest.json 的 capabilities 字段）。
     *
     * 仅接受 `requires` / `provides` 两个已知键，其余键忽略；
     * 键值非法（非数组）时忽略该键。
     *
     * @param array $capabilities
     * @return void
     */
    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = [];

        foreach (['requires', 'provides'] as $key) {
            if (isset($capabilities[$key]) && is_array($capabilities[$key])) {
                $this->capabilities[$key] = $capabilities[$key];
            }
        }
    }

    /**
     * 获取归一化的插件 API 版本策略。
     *
     * 统一输出结构，供网关版本闸门（ApiDispatch）与插件基类直接消费：
     *   [
     *     'versions'        => string[],   // 可服务的 API 主版本集合
     *     'default_version' => string,     // 未带版本段时的默认版本
     *     'require_version' => bool,       // 是否强制请求携带版本段
     *     'deprecated'      => array,      // { v1: { sunset, message } }
     *   ]
     *
     * @return array{versions:string[],default_version:string,require_version:bool,deprecated:array}
     */
    public function getApiVersionPolicy(): array
    {
        $versions = $this->api['versions'] ?? [];
        if (!is_array($versions)) {
            $versions = [];
        }
        $versions = array_values(array_map('strval', $versions));

        if (empty($versions)) {
            $versions = ['v1'];
        }

        $default = $this->api['default_version'] ?? ($versions[0] ?? 'v1');

        return [
            'versions'        => $versions,
            'default_version' => (string) $default,
            'require_version' => (bool) ($this->api['require_version'] ?? false),
            'deprecated'      => is_array($this->api['deprecated'] ?? null) ? $this->api['deprecated'] : [],
        ];
    }
}

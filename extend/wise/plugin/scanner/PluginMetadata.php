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
     * 会员中心声明（manifest.json 的 member 字段）。
     *
     * 结构（三段全部可选）：
     *   features_override  {功能键: bool}  插件对会员功能开关的**初始建议**（P2-2）
     *   features_require   {功能键: bool}  插件**必需**的官方功能（安装前置校验，P2-6）
     *   scene_preset       {code,name,theme,features{...}}  安装时生成的会员场景（P2-2/P2-4）
     *
     * 语义边界：这是**声明式预设** —— 仅在安装时写入初始值，且**不覆盖运营已修改的值**；
     * 插件不得在运行时改写全局开关（见 docs/wise-member-插件接入会员中心指南.md §五）。
     *
     * ⚠️ 与 slots / config 同理：本字段必须同时出现在 toArray() 中，否则扫描缓存
     * （plugin.cache_enabled 默认 true）往返后 `member` 段会被静默丢弃。
     *
     * @var array<string, mixed>
     */
    protected array $memberProfile = [];

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

        // 标识符
        $pluginIdent = $data['name'] ?? '';
        $metadata->setName($pluginIdent);
        $metadata->setIdent($pluginIdent);

        // 显示名称：优先 display_name（toArray() 的序列化键，用于扫描缓存往返），
        // 回退 title（manifest.json 原始键，供直接由 manifest 数据构造时使用）。
        // 二者不可只认其一 —— 历史代码仅读 'title'，而 toArray() 输出 'display_name'，
        // 导致缓存往返（plugin.cache_enabled 默认 true）后显示名退化为目录名。
        $displayName = $data['display_name'] ?? $data['title'] ?? $pluginIdent;
        $metadata->setDisplayName($displayName);

        $metadata->setVersion($data['version'] ?? '0.0.0');
        $metadata->setDescription($data['description'] ?? '');
        $metadata->setAuthor($data['author'] ?? '');

        // 依赖关系
        $metadata->setDependencies($data['dependencies'] ?? []);

        // 库包依赖（manifest.json 的 packages 字段；同时承接 toArray() 的归一输出）
        $metadata->setPackages($data['packages'] ?? []);

        // 服务提供者：显式声明 或 按约定构造
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

        // 第三方命名空间：优先新键 vendor（manifest.json 规范键名），
        $metadata->setVendor($data['vendor'] ?? []);

        // 配置 schema：优先读 config_schema（toArray() 的序列化键，用于扫描缓存往返），
        // 回退 config（manifest.json 原始键，供直接由 manifest 数据构造时使用）。
        // 二者不可只认其一 —— 历史代码仅读 'config'，导致 toArray()/fromArray()
        // 缓存往返后 config schema 全部丢失（plugin.cache_enabled 默认为 true）。
        $metadata->setConfigSchema($data['config_schema'] ?? $data['config'] ?? []);

        // 配置展示元数据（tab / group 的中文标题与顺序）：manifest 顶层键与 toArray()
        // 序列化键同名，扫描缓存往返不会丢。
        $metadata->setConfigTabs((array) ($data['config_tabs'] ?? []));
        $metadata->setConfigGroups((array) ($data['config_groups'] ?? []));

        // API 版本策略（manifest.json 的 api 字段）
        $metadata->setApi($data['api'] ?? []);

        // 会员中心声明（manifest.json 的 member 字段，P2-1）
        $metadata->setMemberProfile($data['member'] ?? []);

        return $metadata;
    }

    /**
     * 将元数据转换为数组（包含运行时状态）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
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
            // 必须与 fromArray() 的读取键对齐：扫描缓存往返（toArray → fromArray）依赖本键
            'member'           => $this->memberProfile,
            // ⚠️ 同样必须对齐：fromArray() 读的是 config_schema ?? config，
            // 而本方法此前两者都不输出 —— 于是 plugin.cache_enabled（默认 true）下
            // 缓存往返一次就把 config schema 丢光，表现为「后台插件列表没有『配置』按钮」
            // 且 setPluginConfig() 直接抛「未声明任何配置项」。实测对照：
            //   直接读 manifest → 7 条；fromArray(toArray()) 往返 → 0 条。
            'config_schema'    => $this->configSchema,
            // 配置展示元数据：同样必须输出，否则 plugin.cache_enabled（默认 true）下
            // 缓存往返一次就把 tab/group 的中文标题与顺序丢光
            'config_tabs'      => $this->configTabs,
            'config_groups'    => $this->configGroups,
        ];
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
        return [
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
            'member'           => $this->memberProfile,
        ];
    }

    // ==================== 基础字段 getter/setter ====================

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
     * 不会丢失声明 —— 与 `dependencies` / `config_schema` / `member` 同样的坑。
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
            // 列表写法：[0 => 'wiseadmin/wise-member']
            if (is_int($name)) {
                if (is_string($spec) && $spec !== '') {
                    $result[$spec] = ['constraint' => '*', 'required' => true];
                }

                continue;
            }

            if (!is_string($name) || $name === '') {
                continue;
            }

            // 简写：值即版本约束字符串
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

    // ==================== manifest.json 兼容字段 getter/setter ====================

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
            // 仅保留允许的 type（bi/image/emoji），其它一律丢弃
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
        // Bootstrap Icons 类名
        if (preg_match('/^bi\s/i', $value)) {
            return 'bi';
        }
        // URL / 相对路径 → 图片
        if (preg_match('#^(https?://|/|\./|\.\./)#i', $value)) {
            return 'image';
        }
        // Emoji（常见 emoji Unicode 区间）
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $value)) {
            return 'emoji';
        }
        // 兜底：无法识别的内容视为未配置（交给渲染层用默认 bi bi-plugin 兜底）
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
     * 获取会员中心声明（manifest.json 的 member 字段，P2-1）。
     *
     * 未声明时返回 `[]` —— 语义为「本插件不介入会员功能的显隐」，
     * 消费方（安装流程 / 场景预设服务）应据此短路，不做任何写入。
     *
     * @return array<string, mixed>
     */
    public function getMemberProfile(): array
    {
        return $this->memberProfile;
    }

    /**
     * 设置会员中心声明。
     *
     * @param array<string, mixed> $profile
     * @return void
     */
    public function setMemberProfile(array $profile): void
    {
        $this->memberProfile = $profile;
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

        // 未显式声明 versions 时，隐式支持 v1（向后兼容 /api/{plugin}/v1/... 与无版本调用）
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

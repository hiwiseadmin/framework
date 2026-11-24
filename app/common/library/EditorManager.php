<?php
declare(strict_types=1);

namespace app\common\library;

use app\common\contract\EditorDriverInterface;
use think\facade\Config;
use think\facade\Event;

/**
 * 编辑器管理器
 *
 * 全局单例，负责：
 * 1. 维护所有已注册的编辑器驱动（type → driver 映射）
 * 2. 提供驱动实例获取（惰性加载）
 * 3. 为 FormBuilder 提供统一的 render() 入口
 * 4. 管理默认编辑器策略
 *
 * 设计参考：wise\oauth\OAuthManager
 *
 * 驱动注册方式：
 * A. 插件通过 registerDriver() 主动注册（推荐）
 * B. 通过 config('wise-editor.drivers') 静态配置（兜底）
 */
class EditorManager
{
    /**
     * @var array<string, EditorDriverInterface> 已实例化的驱动缓存
     */
    protected array $driverInstances = [];

    /**
     * @var array<string, string> 驱动类注册表 (type → className)
     */
    protected array $driverRegistry = [];

    /**
     * @var string|null 默认编辑器类型
     */
    protected ?string $defaultType = null;

    /**
     * @var bool 是否已执行驱动发现扫描
     */
    protected bool $discovered = false;

    /**
     * 获取指定类型的编辑器驱动实例
     *
     * @param  string|null $type 编辑器类型，null 使用默认
     * @return EditorDriverInterface
     * @throws \RuntimeException 无可用编辑器时抛出
     */
    public function driver(?string $type = null): EditorDriverInterface
    {
        $type = $type ?? $this->getDefaultType();

        if (isset($this->driverInstances[$type])) {
            return $this->driverInstances[$type];
        }

        if (!$this->discovered) {
            $this->discoverDrivers();
        }

        $className = $this->driverRegistry[$type] ?? null;

        if (!$className) {
            throw new \RuntimeException(
                "未找到类型为 '{$type}' 的编辑器驱动。请安装编辑器插件。"
            );
        }

        if (!class_exists($className)) {
            throw new \RuntimeException(
                "编辑器驱动类 '{$className}' 不存在，请检查插件是否正确安装。"
            );
        }

        $instance = new $className();

        if (!($instance instanceof EditorDriverInterface)) {
            throw new \RuntimeException(
                "编辑器驱动类 '{$className}' 必须实现 EditorDriverInterface。"
            );
        }

        $this->driverInstances[$type] = $instance;
        return $instance;
    }

    /**
     * 手动注册一个编辑器驱动
     *
     * @param string $type      编辑器类型标识
     * @param string $className 驱动类名（FQCN）
     * @return void
     */
    public function registerDriver(string $type, string $className): void
    {
        if (isset($this->driverRegistry[$type])) {
            return;
        }
        $this->driverRegistry[$type] = $className;
    }

    /**
     * 取消注册编辑器驱动
     *
     * @param string $type 编辑器类型标识
     * @return void
     */
    public function unregisterDriver(string $type): void
    {
        unset($this->driverRegistry[$type], $this->driverInstances[$type]);
    }

    /**
     * 获取所有可用的编辑器类型
     *
     * @return array<array{type: string, name: string, version: string}>
     */
    public function getAvailableTypes(): array
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }

        $result = [];
        foreach ($this->driverRegistry as $type => $className) {
            if (!class_exists($className)) {
                continue;
            }
            $driver = $this->driver($type);
            $result[] = [
                'type'    => $type,
                'name'    => $driver->getName(),
                'version' => $driver->getVersion(),
            ];
        }
        return $result;
    }

    /**
     * 检查指定类型的编辑器是否可用
     *
     * @param  string $type
     * @return bool
     */
    public function has(string $type): bool
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }
        return isset($this->driverRegistry[$type])
            && class_exists($this->driverRegistry[$type]);
    }

    /**
     * 检查是否有任何可用的编辑器
     *
     * @return bool
     */
    public function hasAny(): bool
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }
        return !empty($this->driverRegistry);
    }

    /**
     * 渲染编辑器 HTML
     *
     * FormBuilder 调用此方法生成编辑器 HTML。
     *
     * @param  string $name    表单字段名
     * @param  mixed  $value   初始值
     * @param  array  $options 渲染选项
     * @return string HTML 代码
     */
    public function render(string $name, $value, array $options = []): string
    {
        $type = $options['type'] ?? null;

        if (!$type) {
            $mode = $options['mode'] ?? 'richtext';
            $type = $mode;
        }

        if (!$this->has($type)) {
            return $this->renderFallbackTextarea($name, $value, $options);
        }

        $driver = $this->driver($type);

        Event::trigger('EditorBeforeRender', [
            'type'    => $type,
            'name'    => $name,
            'value'   => $value,
            'options' => $options,
            'driver'  => $driver,
        ]);

        return $driver->renderHtml($name, $value, $options);
    }

    /**
     * 渲染 Markdown 编辑器
     */
    public function renderMd(string $name, $value, array $options = []): string
    {
        $options['mode'] = 'markdown';
        $options['type'] = 'markdown';
        return $this->render($name, $value, $options);
    }

    /**
     * 渲染简易编辑器
     */
    public function renderSimple(string $name, $value, array $options = []): string
    {
        $options['mode'] = 'simple';
        $options['type'] = 'simple';
        return $this->render($name, $value, $options);
    }

    /**
     * 收集页面所需的所有 JS/CSS 依赖
     *
     * 控制器在渲染视图前调用，将结果注入模板。
     *
     * @param  string|null $type 编辑器类型，null 收集所有已注册编辑器
     * @return array
     */
    public function collectAssets(?string $type = null): array
    {
        $css = [];
        $js  = [];

        $types = $type ? [$type] : array_keys($this->driverRegistry);
        foreach ($types as $t) {
            if (!$this->has($t)) {
                continue;
            }
            $driver = $this->driver($t);
            $css = array_merge($css, $driver->getCssDependencies($t));
            $js  = array_merge($js, $driver->getJsDependencies($t));
        }

        return [
            'css' => array_unique($css),
            'js'  => array_unique($js),
        ];
    }

    /**
     * 获取默认编辑器类型
     */
    public function getDefaultType(): string
    {
        if ($this->defaultType) {
            return $this->defaultType;
        }
        return Config::get('wise-editor.default_editor', 'richtext');
    }

    /**
     * 设置默认编辑器类型
     */
    public function setDefaultType(string $type): void
    {
        $this->defaultType = $type;
    }

    /**
     * 获取所有已注册编辑器的合并 HTML 白名单
     *
     * 返回的 `allowed_tags` 已规范化为 HTMLPurifier 兼容写法
     * （标签内属性用 `|` 分隔，如 `a[href|title]`），可直接用于
     * `HTMLPurifier_Config::set('HTML.Allowed', ...)`。
     *
     * @return array{allowed_tags: string[], allowed_attributes: array<string, string[]>}
     */
    public function getCombinedHtmlWhitelist(): array
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }

        $map = $this->buildWhitelistMap();

        $rawAttrs = [];
        foreach ($this->driverRegistry as $type => $className) {
            if (!class_exists($className)) {
                continue;
            }
            $whitelist = $this->driver($type)->getHtmlWhitelist();
            if (!empty($whitelist['allowed_attributes']) && is_array($whitelist['allowed_attributes'])) {
                foreach ($whitelist['allowed_attributes'] as $tag => $attrs) {
                    $tag = strtolower((string) $tag);
                    $rawAttrs[$tag] = array_values(array_unique(array_merge(
                        $rawAttrs[$tag] ?? [],
                        is_array($attrs) ? $attrs : [$attrs]
                    )));
                }
            }
        }

        return [
            'allowed_tags'       => self::fragmentsFromMap($map),
            'allowed_attributes' => $rawAttrs,
        ];
    }

    /**
     * 获取合并后的纯标签名列表（供 strip_tags() 等使用）
     *
     * @return string[]
     */
    public function getAllowedTags(): array
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }
        return array_keys($this->buildWhitelistMap());
    }

    /**
     * 获取合并后的属性名列表（全局并集，供属性级过滤使用）
     *
     * @return string[]
     */
    public function getAllowedAttributeNames(): array
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }

        $names = [];
        foreach ($this->buildWhitelistMap() as $attrs) {
            $names = array_merge($names, $attrs);
        }
        return array_values(array_unique($names));
    }

    /**
     * 获取 HTMLPurifier 的 `HTML.Allowed` 配置串
     *
     * @return string 如 "p,a[href|title],img[src|alt]"
     */
    public function getHtmlPurifierAllowed(): string
    {
        if (!$this->discovered) {
            $this->discoverDrivers();
        }
        return implode(',', self::fragmentsFromMap($this->buildWhitelistMap()));
    }

    /**
     * 构建 tag => [attr, ...] 的规范化白名单映射
     *
     * 兼容两种驱动声明写法：
     *  - allowed_tags: ['p', 'a[href,title]']  （属性分隔符 | 或 , 均可）
     *  - allowed_attributes: ['a' => ['href', 'title']]
     *
     * @return array<string, string[]>
     */
    protected function buildWhitelistMap(): array
    {
        $map = [];

        foreach ($this->driverRegistry as $type => $className) {
            if (!class_exists($className)) {
                continue;
            }

            $whitelist = $this->driver($type)->getHtmlWhitelist();

            foreach (($whitelist['allowed_tags'] ?? []) as $fragment) {
                [$tag, $attrs] = self::splitAllowedTag((string) $fragment);
                if ($tag === '') {
                    continue;
                }
                $map[$tag] = array_values(array_unique(array_merge($map[$tag] ?? [], $attrs)));
            }

            foreach (($whitelist['allowed_attributes'] ?? []) as $tag => $attrs) {
                $tag = strtolower((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $attrs = is_array($attrs) ? $attrs : [$attrs];
                $map[$tag] = array_values(array_unique(array_merge($map[$tag] ?? [], $attrs)));
            }
        }

        return $map;
    }

    /**
     * 把白名单映射还原为 HTMLPurifier 片段列表
     *
     * @param array<string, string[]> $map
     * @return string[]
     */
    protected static function fragmentsFromMap(array $map): array
    {
        $fragments = [];
        foreach ($map as $tag => $attrs) {
            $attrs = array_values(array_filter(array_map('strval', $attrs)));
            $fragments[] = $attrs ? $tag . '[' . implode('|', $attrs) . ']' : $tag;
        }
        return array_values(array_unique($fragments));
    }

    /**
     * 解析单条白名单片段为 [标签, 属性数组]
     *
     * @param string $fragment 如 'a[href,title]' 或 'img'
     * @return array{0: string, 1: string[]}
     */
    protected static function splitAllowedTag(string $fragment): array
    {
        $fragment = trim($fragment);
        if ($fragment === '') {
            return ['', []];
        }

        if (preg_match('/^([a-zA-Z0-9_*]+)\s*(?:\[([^\]]*)\])?$/', $fragment, $m)) {
            $attrs = [];
            if (isset($m[2]) && $m[2] !== '') {
                $attrs = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $m[2]))));
            }
            return [strtolower($m[1]), $attrs];
        }

        return ['', []];
    }


    /**
     * 发现所有已安装的编辑器驱动
     *
     * 优先级：
     * 1. config('wise-editor.drivers') 静态配置
     * 2. 数据库中已启用、标记为编辑器提供者的插件
     */
    protected function discoverDrivers(): void
    {
        $configDrivers = Config::get('wise-editor.drivers', []);
        foreach ($configDrivers as $type => $info) {
            $className = is_array($info) ? ($info['driver'] ?? '') : $info;
            if ($className && class_exists($className)) {
                $this->registerDriver($type, $className);
            }
        }

        try {
            if (class_exists('\\app\\admin\\model\\PluginModel')) {
                $plugins = \app\admin\model\PluginModel::where('status', 1)
                    ->where('editor_provider', 1)
                    ->select();

                foreach ($plugins as $plugin) {
                    $providerClass = "plugin\\{$plugin->ident}\\EditorProvider";
                    if (class_exists($providerClass)) {
                        $provider = new $providerClass();
                        if ($provider instanceof \app\plugin\contract\EditorPluginInterface) {
                            $provider->registerEditors($this);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $this->discovered = true;
    }

    /**
     * 编辑器不可用时的退化成 textarea
     */
    protected function renderFallbackTextarea(string $name, $value, array $options = []): string
    {
        $id          = $options['id'] ?? ('editor_' . $name);
        $height      = ($options['height'] ?? 400) . 'px';
        $class       = 'form-control editor-fallback ' . ($options['class'] ?? '');
        $placeholder = $options['placeholder'] ?? '';
        $readonly    = !empty($options['readonly']) ? ' readonly' : '';

        $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<textarea name="%s" id="%s" class="%s" style="height:%s" placeholder="%s"%s data-editor-fallback="1">%s</textarea>',
            $name,
            $id,
            trim($class),
            $height,
            htmlspecialchars($placeholder, ENT_QUOTES),
            $readonly,
            $value
        );
    }
}

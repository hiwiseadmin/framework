<?php
declare(strict_types=1);

namespace app\common\view\driver;

use think\App;
use think\view\driver\Think as BaseThink;
use app\common\view\ViewScope;
use app\common\view\engine\PluginTemplate;

/**
 * 插件视图驱动（继承 think\view\driver\Think）
 *
 * 两层职责：
 *   1. 使用 PluginTemplate 替换默认模板引擎，使 tpl_replace_string
 *      在模板标签解析前生效，从而支持 {extend name="__PLUGIN_VIEW__/base"} 等写法；
 *   2. 覆盖 getTemplateFile()，在「原有路径未命中」时追加**包内视图兜底**
 *      （见 ViewScope），使视图可随包发布、独立升级。
 *
 * L1/L2 解析顺序：
 *   L1a app/{app}/view/{path}.html                  ← 宿主原生层（父类逻辑，优先）
 *   L1b app/{app}/view/{scope}/{path}.html          ← 宿主覆盖包内视图（本类新增，见 hostOverlay）
 *   L2  {pkgRoot}/view/{scope}/{path}.html          ← 包内兜底层（ViewScope）
 *
 * 说明：父类 think\view\driver\Think 的 $template 为 private 且硬编码
 * new think\Template，故通过反射替换为 PluginTemplate 实例。
 */
class Think extends BaseThink
{
    /**
     * 构造时的 view_path 基线。
     *
     * 每次解析前复位到该值，避免 L2 命中时设置的包内目录
     * 残留到同实例的下一次解析（Swoole 常驻下即为跨请求污染）。
     *
     * @var string
     */
    protected string $baseViewPath = '';

    /**
     * 应用实例的本地引用。
     *
     * 父类 `think\view\driver\Think` 的 `$app` 由构造器**属性提升**声明为 private，
     * 子类不可见（误用 `$this->app` 会得到 null 并触发
     * 「Call to a member function on null」），故在此显式持有。
     *
     * @var App
     */
    protected App $appRef;

    public function __construct(App $app, array $config = [])
    {
        parent::__construct($app, $config);

        $this->appRef = $app;

        $this->baseViewPath = (string) ($this->config['view_path'] ?? '');

        $template = new PluginTemplate($this->config);
        $template->setCache($app->cache);

        $template->extend('$Think', function (array $vars) {
            $type  = strtoupper(trim(array_shift($vars)));
            $param = implode('.', $vars);

            return match ($type) {
                'CONST'  => strtoupper($param),
                'CONFIG' => 'config(\'' . $param . '\')',
                'LANG'   => 'lang(\'' . $param . '\')',
                'NOW'    => "date('Y-m-d g:i a',time())",
                'LDELIM' => '\'' . ltrim((string) ($this->config['tpl_begin'] ?? '{'), '\\') . '\'',
                'RDELIM' => '\'' . ltrim((string) ($this->config['tpl_end'] ?? '}'), '\\') . '\'',
                default  => defined($type) ? $type : '\'\'',
            };
        });

        $template->extend('$Request', function (array $vars) {
            $method = array_shift($vars);
            if (!empty($vars)) {
                $params = implode('.', $vars);
                if ('true' != $params) {
                    $params = '\'' . $params . '\'';
                }
            } else {
                $params = '';
            }

            return 'app(\'request\')->' . $method . '(' . $params . ')';
        });

        $ref = new \ReflectionProperty(BaseThink::class, 'template');
        $ref->setAccessible(true);
        $ref->setValue($this, $template);
    }

    /**
     * 模板名 → 模板文件绝对路径（覆盖父类，追加包内视图兜底）
     *
     * 父类方法**不抛异常**（异常在 fetch() 中抛），因此可以在这里
     * 拿到候选路径后自行判断是否存在，再决定是否兜底。
     *
     * 解析顺序固定 —— 宿主优先，然后包内（显式 scope → 当前上下文）：
     *
     *   L1a app/{app}/view/{path}.html          父类原生逻辑（空参数 / 显式名 / @ 跨应用 / 绝对路径）
     *   L1b app/{app}/view/{scope}/{path}.html  宿主覆盖包内视图（仅显式 scope@path）
     *   L2  {scopeRoot}/{path}.html             fetch('wise-crontab@admin/index') 显式 scope
     *   L3  {currentScopeRoot}/{relative}.html  fetch() / fetch('admin/index') 按当前插件 / 当前包
     *
     * L1a 一旦命中即返回，行为与改造前 100% 一致；只有「文件确实不存在」
     * 才进入兜底，因此既有调用（admin 41 处 + 插件 59 处）零影响。
     *
     * L3 只在原始模板名**不含 `@`** 时启用，避免与原生「应用名@模板」语义
     * 或 L2 的显式 scope 交叉。L1b 同理只在显式 scope 时启用 —— 理由是
     * 现有的 59 处插件空参数 `fetch()` 已在服务端各就各位，不宜再叠加一层
     * 覆盖探测改变它们的命中结果。
     *
     * @param string $template 模板名
     * @return string 模板文件路径
     */
    protected function getTemplateFile(string $template): string
    {
        $this->resetViewPath();

        $file = parent::getTemplateFile($template);

        if ($file !== '' && is_file($file)) {
            return $file;
        }

        $suffix = (string) ($this->config['view_suffix'] ?? 'html');

        if (strpos($template, '@') !== false) {
            $overlay = $this->hostOverlay($template, $suffix);

            if ($overlay !== null) {
                return $overlay;
            }

            $hit = ViewScope::locate($template, $suffix);

            return $hit === null ? $file : $this->enterScopeView($hit);
        }

        $scope = $this->currentScope();

        if ($scope === null) {
            return $file;
        }

        $relative = $this->relativeTemplateName($file);

        if ($relative === '') {
            return $file;
        }

        $hit = ViewScope::locateInScope($scope, $relative);

        return $hit === null ? $file : $this->enterScopeView($hit);
    }

    /**
     * L1b：探测宿主覆盖层 —— `app/{当前应用}/view/{scope}/{path}`。
     *
     * 用途：包 / 插件把视图放在自己包里（L2），宿主（或第三方）要改其中
     * 某一个页面时，**不改包代码**，直接在宿主侧放同名文件即可覆盖。
     *
     * 位置固定为 `app/{当前应用}/view/{scope}/{相对模板名}`，例如：
     *
     *   app/admin/view/wise-crontab/admin/task/index.html
     *
     * 命中后 view_path 指向**宿主视图根**（`app/admin/view/`），因此覆盖层
     * 内部可以照旧写 `{extend name="public/base"}` —— 与宿主原生视图写法一致。
     *
     * 为什么不与 L2 合并：L2 是「包内兜底」，L1b 是「宿主优先」，
     * 两者方向相反；且 L1b 命中后 view_path 必须指向宿主目录，语义不同。
     *
     * 注意（现状边界）：仅对**显式** `scope@path` 生效。空参数 `fetch()`
     * 的覆盖仍走父类原生位置（`app/view/{scope}/...`），见 ViewScope 类注释。
     *
     * @param string $template 模板名（须含 `@`）
     * @param string $suffix   模板后缀
     * @return string|null 覆盖层文件绝对路径；未命中返回 null
     */
    protected function hostOverlay(string $template, string $suffix): ?string
    {
        $pos = strpos($template, '@');

        if ($pos === false || $pos === 0 || $pos === strlen($template) - 1) {
            return null;
        }

        $scope = substr($template, 0, $pos);
        $path  = trim(substr($template, $pos + 1), "/\\ \t\n\r\0\x0B");

        if ($path === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $scope)) {
            return null;
        }

        $relative = str_replace(['/', ':'], DIRECTORY_SEPARATOR, $path)
            . '.' . ltrim($suffix, '.');

        return $this->hostOverlayFile($scope, $relative);
    }

    /**
     * 按 scope + 相对模板名探测宿主覆盖层文件。
     *
     * @param string $scope    scope 名（包名 / 插件名）
     * @param string $relative 相对模板名（含后缀）
     * @return string|null 命中文件绝对路径；未命中返回 null
     */
    protected function hostOverlayFile(string $scope, string $relative): ?string
    {
        $app = (string) $this->appRef->http->getName();

        if ($app === '' || $app === $scope) {
            return null;
        }

        $base = $this->getViewPath($app);

        if ($base === '') {
            return null;
        }

        $file = $base . $scope . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');

        if (!is_file($file)) {
            return null;
        }

        return $this->enterScopeView(['file' => $file, 'root' => $base]);
    }

    /**
     * 接管为 scope 视图 —— 同步 view_path 并返回文件绝对路径。
     *
     * view_path 必须指向 **scope 的视图根**（而非文件所在子目录）：
     * 模板内 `{include file="product/form"}` 等相对引用由
     * `Template::parseTemplateFile()` 按 view_path 拼接，指向子目录会多拼一层。
     *
     * 只改本驱动实例（经 `config()` 同步到模板引擎），**不碰 Config::set 全局配置**；
     * 下次解析前 `resetViewPath()` 会复位，故无跨调用 / 跨请求污染。
     *
     * @param array{file: string, root: string} $hit ViewScope 命中结果
     * @return string 模板文件绝对路径
     */
    protected function enterScopeView(array $hit): string
    {
        $this->config(['view_path' => $hit['root']]);

        return $hit['file'];
    }

    /**
     * 当前请求的视图 scope（当前插件 / 当前包）。
     *
     * 由控制器基类在 initialize() 阶段写入 Request 的「中间件传递数据」：
     *
     *     $this->request->view_scope = $this->pluginIdent;   // plugin\BaseController
     *
     * 该载体是请求级的（`think\Request::__set` 存入 `$this->middleware`），
     * 不随进程常驻，Swoole 协程下天然隔离。
     *
     * @return string|null
     */
    protected function currentScope(): ?string
    {
        $scope = $this->appRef->request->view_scope ?? null;

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    /**
     * 从父类解析结果中剥出「相对模板名」。
     *
     * 父类返回 `{base}{relative}.{suffix}`，其中 base 为
     * `config['view_path']`（显式配置）或 `getViewPath()`（未配置时）。
     *
     * 说明：不能读 `$this->template->view_path` —— 父类的 `$template` 属性是
     * private，子类不可见，只能按父类规则复算 base。
     *
     * @param string $file 父类解析结果
     * @return string 相对模板名（含后缀）；无法判断时返回 ''
     */
    protected function relativeTemplateName(string $file): string
    {
        if ($file === '') {
            return '';
        }

        $base = $this->templateBasePath();

        if ($base !== '' && strpos($file, $base) === 0) {
            return substr($file, strlen($base));
        }

        return $base === '' ? ltrim($file, '/\\') : '';
    }

    /**
     * 复算父类在「非 `@` 模板名」场景下使用的视图根。
     *
     * 与 `think\view\driver\Think::parseTemplate()` 的 base 判定保持一致：
     * 配置优先，其次按当前应用名解析视图目录。
     */
    protected function templateBasePath(): string
    {
        $configured = (string) ($this->config['view_path'] ?? '');

        if ($configured !== '') {
            return $configured;
        }

        $app = (string) $this->appRef->http->getName();

        if ($app === '') {
            try {
                $request = $this->appRef->request;
                if (method_exists($request, 'layer') && $request->layer()) {
                    $app = (string) $request->layer();
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->getViewPath($app);
    }

    /**
     * 复位 view_path 到构造基线。
     *
     * 仅在值发生变化时才写回，避免每次解析都触发一次 config 合并。
     * 经 `config()` 写入会同步到模板引擎，故 `{include}` 的解析基准同样被复位。
     */
    protected function resetViewPath(): void
    {
        if ((string) ($this->config['view_path'] ?? '') !== $this->baseViewPath) {
            $this->config(['view_path' => $this->baseViewPath]);
        }
    }
}

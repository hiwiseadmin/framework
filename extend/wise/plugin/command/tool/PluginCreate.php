<?php
declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Filesystem;
use wise\plugin\PluginContainerKeys;

/**
 * 插件创建命令 — 从外部 .stub 模板生成插件骨架。
 *
 * 设计原则：**默认只生成「框架有读取点」的文件。**
 *   有读取点 → 必需 / 契约壳 → 生成
 *   无读取点（纯示例） → 需 --with-example 显式开启
 *
 * 三层档位（可组合）：
 *   core      必需，7 个文件，构成最小可运行单元（含 BootServiceProvider）
 *   contract  扩展点壳，6 个文件（每个 4~9 行），按需 --minimal 关闭
 *   example   model / service / validate 分层示例，用 --with-example
 *
 * 注意：插件**没有** Plugin.php 档位 —— 生命周期行为由 database/migrations、
 * database/seeds 与 event.php 承担（原 --with-plugin 选项已移除）。
 *
 * 生成结果：
 *   plugin:create blog --minimal      7 个文件
 *   plugin:create blog                13 个文件（默认）
 *   plugin:create blog --with-example 16 个文件
 *
 * 说明：core 档包含 controller/Index.php 与 view/index/index.html ——
 * route/plugin.php 与 manifest 菜单都指向 Index@index，它们必须与路由同生共死，
 * 否则默认产物就是「菜单 404 + 路由指向不存在的类」。
 *
 * ---
 *
 * **模板路径推导规则（唯一规则，不维护映射表）**：
 *
 *   stubs/plugin/ 下的目录结构 === 生成产物的目录结构，
 *   模板文件名 === 产物文件名，扩展名统一替换为 `.stub`。
 *
 *   {产物路径}                          {模板路径}
 *   manifest.json                   →  manifest.stub
 *   config/app.php                  →  config/app.stub
 *   controller/Index.php            →  controller/Index.stub
 *   model/Example.php               →  model/Example.stub
 *   validate/Example.php            →  validate/Example.stub
 *   service/ExampleService.php      →  service/ExampleService.stub
 *   view/index/index.html           →  view/index/index.stub
 *
 * 好处：新增/改名产物时只需同名改模板，**不可能出现「模板名与产物名不齐」**；
 *      model/Example 与 validate/Example 的类名同名也不再冲突（分属不同目录）。
 */
class PluginCreate extends Command
{
    /** 必需：缺失则插件不可用 */
    protected const TIER_CORE = 'core';

    /** 契约壳：扩展点的存在性声明 */
    protected const TIER_CONTRACT = 'contract';

    /** 示例：分层写法示例，默认不生成 */
    protected const TIER_EXAMPLE = 'example';

    /** 档位中文名（用于命令输出与 README 文件树） */
    protected const TIER_TEXT = [
        self::TIER_CORE     => '必需',
        self::TIER_CONTRACT => '契约壳',
        self::TIER_EXAMPLE  => '示例',
    ];

    /**
     * 产物定义。
     *
     * 键   = 输出文件相对路径（相对插件根目录）—— **同时决定模板路径**，
     *        见 resolveStubPath()：config/app.php → config/app.stub
     * tier = 档位
     * step = boot 链步骤（写入 README 文件契约表）
     * miss = 缺失后果（写入 README 文件契约表，{ident} 替换为插件标识）
     *
     * @var array<string, array{tier: string, step: string, miss: string}>
     */
    protected array $templates = [
        'manifest.json' => [
            'tier' => self::TIER_CORE,
            'step' => '扫描期',
            'miss' => '插件无法被扫描发现',
        ],
        'BootServiceProvider.php' => [
            'tier' => self::TIER_CORE,
            'step' => 'manifest 的 service_provider',
            'miss' => '插件不启动',
        ],
        'config/app.php' => [
            'tier' => self::TIER_CORE,
            'step' => 'Step 2',
            'miss' => "config('{ident}.xxx') 取不到默认值",
        ],
        'route/plugin.php' => [
            'tier' => self::TIER_CORE,
            'step' => 'Step 9',
            'miss' => '后台无路由',
        ],
        'controller/Index.php' => [
            'tier' => self::TIER_CORE,
            'step' => 'Step 9（路由指向）',
            'miss' => '默认路由指向不存在的控制器',
        ],
        'view/index/index.html' => [
            'tier' => self::TIER_CORE,
            'step' => '控制器渲染',
            'miss' => '默认页面渲染失败',
        ],
        'README.md' => [
            'tier' => self::TIER_CORE,
            'step' => '—',
            'miss' => '—',
        ],

        'common.php' => [
            'tier' => self::TIER_CONTRACT,
            'step' => 'Step 3',
            'miss' => '公共函数不可用',
        ],
        'config/console.php' => [
            'tier' => self::TIER_CONTRACT,
            'step' => 'Step 6',
            'miss' => '自定义 CLI 命令不注册',
        ],
        'event.php' => [
            'tier' => self::TIER_CONTRACT,
            'step' => 'Step 7',
            'miss' => '事件监听不生效',
        ],
        'middleware.php' => [
            'tier' => self::TIER_CONTRACT,
            'step' => 'Step 8',
            'miss' => '中间件不注册',
        ],
        'provider.php' => [
            'tier' => self::TIER_CONTRACT,
            'step' => 'Step 10',
            'miss' => 'DI 绑定不生效',
        ],
        'service.php' => [
            'tier' => self::TIER_CONTRACT,
            'step' => 'Step 11',
            'miss' => '子服务提供者不注册',
        ],

        'model/Example.php' => [
            'tier' => self::TIER_EXAMPLE,
            'step' => '—',
            'miss' => '—',
        ],
        'service/ExampleService.php' => [
            'tier' => self::TIER_EXAMPLE,
            'step' => '—',
            'miss' => '—',
        ],
        'validate/Example.php' => [
            'tier' => self::TIER_EXAMPLE,
            'step' => '—',
            'miss' => '—',
        ],
    ];

    /**
     * 模板目录路径。
     *
     * 本类位于 extend/wise/plugin/command/tool/，模板位于 extend/wise/plugin/stubs/plugin/，
     * 因此需要向上两级 —— 只写一级会指向 command/stubs/plugin/（不存在），
     * 导致所有模板静默跳过、命令产出 0 个文件。
     *
     * @var string
     */
    protected string $stubDir;

    /**
     * 本次执行中缺失的模板文件（用于失败时明确报错，避免静默产出空骨架）。
     *
     * @var array<int, string>
     */
    protected array $missingStubs = [];

    /**
     * 配置命令。
     */
    protected function configure()
    {
        $this->stubDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'stubs' . DIRECTORY_SEPARATOR
            . 'plugin' . DIRECTORY_SEPARATOR;

        $this->setName('plugin:create')
            ->setDescription('创建新的插件（按档位生成骨架，默认只生成有框架读取点的文件）')
            ->addArgument('name', Argument::REQUIRED, '插件名称（英文小写 + 下划线）')
            ->addOption('author', null, Option::VALUE_OPTIONAL, '作者名称', 'Unknown')
            ->addOption('plugin-version', null, Option::VALUE_OPTIONAL, '插件版本号', '1.0.0')
            ->addOption('description', null, Option::VALUE_OPTIONAL, '插件描述', '')
            ->addOption('minimal', null, Option::VALUE_NONE, '仅生成必需文件（7 个），不含契约壳与示例档')
            ->addOption('with-example', null, Option::VALUE_NONE, '附带生成 model / service / validate 分层示例（默认不生成）')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的插件');
    }

    /**
     * 执行命令。
     */
    protected function execute(Input $input, Output $output)
    {
        $pluginName = (string) $input->getArgument('name');

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $pluginName)) {
            $output->error('插件名称只能包含小写字母、数字和下划线，且必须以字母开头');
            return;
        }

        $force = (bool) $input->getOption('force');
        $pluginPath = root_path('plugins') . $pluginName . DIRECTORY_SEPARATOR;

        if (is_dir($pluginPath) && !$force) {
            $output->error("插件 [{$pluginName}] 已存在，使用 --force 选项强制覆盖");
            return;
        }

        $templates   = $this->selectTemplates($input);
        $withExample = (bool) $input->getOption('with-example');

        try {
            $this->createDirectoryStructure($pluginPath, $force, $withExample);

            $variables = [
                '{$name}'           => $pluginName,
                '{$version}'        => (string) $input->getOption('plugin-version'),
                '{$author}'         => (string) $input->getOption('author'),
                '{$description}'    => (string) ($input->getOption('description') ?: '插件描述'),
                '{$create_time}'    => date('Y-m-d H:i:s'),
                '{$tier_label}'     => $this->tierLabel($input),
                '{$contract_table}' => $this->renderContractTable($templates, $pluginName),
                '{$file_tree}'      => $this->renderFileTree(array_keys($templates), $withExample),
                '{$framework_major}' => $this->frameworkMajor() ?? '',
            ];

            $written = $this->createPluginFiles($pluginPath, $templates, $variables);

            if (!empty($this->missingStubs)) {
                $output->error('以下模板文件缺失，对应文件未生成：' . implode('、', array_unique($this->missingStubs)));
                $output->writeln('模板目录: ' . $this->stubDir);
                if (empty($written)) {
                    $output->error('没有任何文件被生成，请检查模板目录是否存在。');
                    return;
                }
            }

            $output->info("插件 [{$pluginName}] 创建成功！");
            $output->writeln('档位: <comment>' . $this->tierLabel($input) . '</comment>，共 ' . count($written) . ' 个文件');
            foreach ($written as $file) {
                $output->writeln('  <info>+</info> plugins/' . $pluginName . '/' . $file);
            }
            $output->writeln('路径: ' . $pluginPath);

            if ($this->refreshScannerCache()) {
                $output->writeln('扫描缓存: <info>已刷新</info>');
            } else {
                $output->warning('扫描缓存刷新失败，若 plugin:scan 看不到本插件，请清理 runtime/cache 后重试');
            }

            $output->writeln('下一步:');
            $output->writeln("  1) php think plugin:enable {$pluginName}");
            $output->writeln("  2) 访问 /plugin/{$pluginName}/index");
            $output->writeln('');
            $output->writeln('<comment>建表与初始数据用 database/migrations 与 database/seeds；</comment>');
            $output->writeln('<comment>安装/卸载后的外部副作用用 event.php 监听 plugin.installed / plugin.uninstalled。</comment>');
        } catch (\Exception $e) {
            $output->error('创建插件失败: ' . $e->getMessage());
        }
    }

    /**
     * 按档位筛选模板。
     *
     * core 恒生成；contract 默认生成（--minimal 关闭）；example 由 --with-example 开启。
     *
     * @return array<string, array{tier: string, step: string, miss: string}>
     */
    protected function selectTemplates(Input $input): array
    {
        $tiers = [self::TIER_CORE];

        if (!$input->getOption('minimal')) {
            $tiers[] = self::TIER_CONTRACT;
        }
        if ($input->getOption('with-example')) {
            $tiers[] = self::TIER_EXAMPLE;
        }

        $selected = [];
        foreach ($this->templates as $relativePath => $meta) {
            if (in_array($meta['tier'], $tiers, true)) {
                $selected[$relativePath] = $meta;
            }
        }

        return $selected;
    }

    /**
     * 档位中文描述，用于命令输出与 README。
     */
    protected function tierLabel(Input $input): string
    {
        $labels = ['必需'];

        if (!$input->getOption('minimal')) {
            $labels[] = '契约壳';
        }
        if ($input->getOption('with-example')) {
            $labels[] = '示例';
        }

        return implode(' + ', $labels);
    }

    /**
     * 当前框架主版本号（用于脚手架生成 `compatible_with: ["^1.0"]`）。
     *
     * 只取主版本：写死补丁号会让脚手架生成的插件在下次补丁升级后"看起来不兼容"。
     *
     * 返回 null 表示**版本未知**（`version.json` 缺失 → `0.0.0`，或主版本为 0）：
     * 此时应省略该字段，而不是生成 `^0.0` —— 未声明是"未知、不拦截"，错误的声明会直接拦截安装。
     */
    protected function frameworkMajor(): ?string
    {
        $version = \wise\plugin\compat\FrameworkCompatibility::currentVersion();
        $major   = explode('.', ltrim($version, 'vV'))[0] ?? '';

        return ctype_digit($major) && $major !== '0' ? $major : null;
    }

    /**
     * 创建目录结构。
     *
     * 只创建「无文件承载、但框架按硬编码路径读取」的目录；
     * 其余目录（config / controller / model / service / validate / view / route）
     * 在写入文件时由 createPluginFiles() 自动创建，避免产生空目录噪音。
     *
     * @param string $basePath    插件根目录
     * @param bool   $force       是否强制覆盖
     * @param bool   $withExample 是否启用示例档（决定是否建静态资源目录）
     */
    protected function createDirectoryStructure(string $basePath, bool $force, bool $withExample): void
    {
        if ($force && is_dir($basePath)) {
            Filesystem::deleteDirectory($basePath);
        }

        $directories = [
            'database/migrations',
            'database/seeds',
        ];

        if ($withExample) {
            $directories[] = 'statics/js';
            $directories[] = 'statics/css';
            $directories[] = 'statics/images';
        }

        foreach ($directories as $dir) {
            $fullPath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $dir) . DIRECTORY_SEPARATOR;

            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            $gitkeep = $fullPath . '.gitkeep';
            if (!is_file($gitkeep)) {
                file_put_contents($gitkeep, '');
            }
        }
    }

    /**
     * 从模板创建插件文件。
     *
     * @param string                $basePath  插件根目录
     * @param array<string, array>  $templates 已按档位筛选的产物定义（键为产物相对路径）
     * @param array<string, string> $variables 变量映射
     * @return array<int, string> 实际写入的文件相对路径
     */
    protected function createPluginFiles(string $basePath, array $templates, array $variables): array
    {
        $written = [];

        foreach ($templates as $relativePath => $meta) {
            $content = $this->loadAndRender($relativePath, $variables);

            if ($content === null) {
                $this->missingStubs[] = $this->resolveStubPath($relativePath);
                continue;
            }

            if ($relativePath === 'manifest.json' && $this->frameworkMajor() === null) {
                $content = preg_replace('/^[ \t]*"compatible_with"[^\r\n]*\R/m', '', $content, 1) ?? $content;
            }

            $filePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $dir = dirname($filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($filePath, $content);
            $written[] = $relativePath;
        }

        return $written;
    }

    /**
     * 渲染 README 的「文件契约表」。
     *
     * 只列出非示例档文件 —— 示例文件没有框架读取点，不构成契约。
     *
     * @param array<string, array> $templates 已筛选的模板定义
     * @param string               $ident     插件标识
     */
    protected function renderContractTable(array $templates, string $ident): string
    {
        $lines = [
            '| # | 文件 | 加载步骤 | 缺失后果 |',
            '|:--:|:---|:---|:---|',
        ];

        $index = 0;
        foreach ($templates as $relativePath => $meta) {
            if ($meta['tier'] === self::TIER_EXAMPLE) {
                continue;
            }

            $index++;
            $lines[] = sprintf(
                '| %d | `%s` | %s | %s |',
                $index,
                $relativePath,
                $meta['step'],
                str_replace('{ident}', $ident, (string) $meta['miss'])
            );
        }

        return implode("\n", $lines);
    }

    /**
     * 渲染 README 的「目录结构」文件树。
     *
     * @param array<int, string> $paths       文件相对路径列表
     * @param bool               $withExample 是否包含静态资源目录
     */
    protected function renderFileTree(array $paths, bool $withExample): string
    {
        $items = [];

        foreach ($paths as $path) {
            $tier = $this->templates[$path]['tier'] ?? self::TIER_CORE;
            $items[$path] = self::TIER_TEXT[$tier] ?? '';
        }

        $items['database/migrations/'] = '框架读取';
        $items['database/seeds/']      = '框架读取';

        if ($withExample) {
            $items['statics/js/']     = '静态资源';
            $items['statics/css/']    = '静态资源';
            $items['statics/images/'] = '静态资源';
        }

        ksort($items);

        $width = 0;
        foreach (array_keys($items) as $path) {
            $width = max($width, strlen($path));
        }

        $lines = [];
        $total = count($items);
        $index = 0;

        foreach ($items as $path => $label) {
            $index++;
            $branch = ($index === $total) ? '└── ' : '├── ';
            $lines[] = $branch . str_pad($path, $width) . '   # ' . $label;
        }

        return implode("\n", $lines);
    }

    /**
     * 由产物路径推导模板路径。
     *
     * 规则：目录结构一致，文件名一致，扩展名统一换成 `.stub`。
     *   config/app.php          →  config/app.stub
     *   controller/Index.php    →  controller/Index.stub
     *   view/index/index.html   →  view/index/index.stub
     *   manifest.json           →  manifest.stub
     *
     * @param string $relativePath 产物相对路径（相对插件根目录）
     * @return string 模板相对路径（相对 stubs/plugin/）
     */
    protected function resolveStubPath(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);

        $stub = preg_replace('/\.[^.\/]+$/', '', $normalized) . '.stub';

        return $stub;
    }

    /**
     * 清除插件扫描缓存。
     *
     * PluginScanner 会把「已发现插件列表」缓存 cache_ttl 秒（配置项
     * extend/wise/plugin/config/plugin.php 的 cache_ttl，默认 3600）。
     * 新建插件后不刷新，则 plugin:scan / plugin:enable 在缓存过期前
     * 完全看不到它 —— 表现为「命令说创建成功，紧接着启用却报插件不存在」。
     *
     * @return bool 是否刷新成功
     */
    protected function refreshScannerCache(): bool
    {
        try {
            $this->app->get(PluginContainerKeys::SCANNER)->refreshCache();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 加载模板文件并替换变量。
     *
     * @param string                $relativePath 产物相对路径，用于推导模板路径
     * @param array<string, string> $variables    变量映射
     * @return string|null 渲染后的内容，模板不存在返回 null
     */
    protected function loadAndRender(string $relativePath, array $variables): ?string
    {
        $stubFile = $this->resolveStubPath($relativePath);
        $stubPath = $this->stubDir . str_replace('/', DIRECTORY_SEPARATOR, $stubFile);

        if (!file_exists($stubPath)) {
            return null;
        }

        $template = file_get_contents($stubPath);
        if ($template === false) {
            return null;
        }

        return strtr($template, $variables);
    }
}

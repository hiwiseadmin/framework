<?php
declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 连接器脚手架生成命令
 *
 * 使用方式:
 *   php think connector:create stripe
 *   php think connector:create aliyun_sms --type message --name "阿里云短信"
 */
class ConnectorCreate extends Command
{
    /**
     * 支持的类型映射
     */
    protected array $typeMap = [
        'oauth'    => 'OAuth 登录',
        'webhook'  => 'Webhook 回调',
        'api'      => 'API 调用',
        'message'  => '消息推送',
        'payment'  => '支付网关',
        'storage'  => '云存储',
        'ai'       => 'AI 服务',
        'iot'      => 'IoT 设备',
    ];

    protected function configure()
    {
        $this->setName('plugin:connector:create')
            ->setDescription('从模板创建新的外部系统连接器')
            ->addArgument('name', Argument::REQUIRED, '连接器标识（英文，下划线命名）')
            ->addOption('type', 't', Option::VALUE_OPTIONAL, '连接器类型: oauth|webhook|api|message|payment|storage|ai|iot', 'api')
            ->addOption('title', null, Option::VALUE_OPTIONAL, '连接器显示名称', '')
            ->addOption('description', null, Option::VALUE_OPTIONAL, '连接器描述', '')
            ->addOption('author', null, Option::VALUE_OPTIONAL, '作者', '')
            ->addOption('output', 'o', Option::VALUE_OPTIONAL, '输出目录', '');
    }

    protected function execute(Input $input, Output $output)
    {
        $connectorName = $input->getArgument('name');
        $type = $input->getOption('type');
        $title = $input->getOption('title') ?: ucfirst($connectorName);
        $description = $input->getOption('description') ?: ($this->typeMap[$type] ?? '连接器') . '实现';
        $author = $input->getOption('author');
        $outputDir = $input->getOption('output');

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $connectorName)) {
            $output->error('连接器标识只能包含小写字母、数字和下划线');
            return 1;
        }

        if (!isset($this->typeMap[$type])) {
            $output->error("不支持的类型: {$type}，可选: " . implode(', ', array_keys($this->typeMap)));
            return 1;
        }

        // 确定输出目录
        if (empty($outputDir)) {
            $outputDir = root_path('packages') . 'connector-' . $connectorName . DIRECTORY_SEPARATOR;
        }

        if (is_dir($outputDir) && !empty(glob($outputDir . '*.php'))) {
            $output->warning("目录 {$outputDir} 已存在，将覆盖现有文件");
        }

        $vars = [
            'name'        => $connectorName,
            'title'       => $title,
            'description' => $description,
            'author'      => $author,
            'type'        => $type,
            'type_label'  => $this->typeMap[$type],
            'namespace'   => $this->toNamespace($connectorName),
            'class_name'  => $this->toClassName($connectorName),
            'create_time' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->generateFiles($outputDir, $vars, $output);
            $output->info("连接器 [{$connectorName}] 创建成功！");
            $output->info("目录: {$outputDir}");
            $output->info("下一步: 编辑配置并执行 php think connector:test {$connectorName}");
        } catch (\Exception $e) {
            $output->error("创建失败: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function generateFiles(string $baseDir, array $vars, Output $output): void
    {
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        $files = [
            'src/' . $vars['class_name'] . '.php' => $this->getConnectorTemplate($vars),
            'config/connector.php'                => $this->getConfigTemplate($vars),
            'README.md'                           => $this->getReadmeTemplate($vars),
        ];

        foreach ($files as $relativePath => $content) {
            $filePath = $baseDir . $relativePath;
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($filePath, $content);
            $output->writeln("  + {$relativePath}");
        }
    }

    // ============================================================
    // 模板
    // ============================================================

    protected function getConnectorTemplate(array $vars): string
    {
        return <<<TPL
<?php
declare(strict_types=1);

namespace {$vars['namespace']};

use wise\\support\\connector\\ConnectorInterface;
use wise\\support\\connector\\ConnectorResult;
use wise\\support\\connector\\ConnectorType;

/**
 * {$vars['title']} 连接器
 *
 * {$vars['description']}
 *
 * 实现 ConnectorInterface，通过 ExtPoint 注册:
 *   ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, '{$vars['name']}', self::class);
 */
class {$vars['class_name']} implements ConnectorInterface
{
    /**
     * 连接器配置
     */
    protected array \$config = [];

    public function getId(): string
    {
        return '{$vars['name']}';
    }

    public function getName(): string
    {
        return '{$vars['title']}';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::{$this->toConstCase($vars['type'])};
    }

    public function getDescription(): string
    {
        return '{$vars['description']}';
    }

    public function getActions(): array
    {
        return [
            // 'execute' => ['description' => '执行操作', 'params' => ['param1', 'param2']],
        ];
    }

    public function execute(string \$action, array \$input, array \$context = []): ConnectorResult
    {
        // TODO: 实现具体的连接器逻辑
        try {
            // \$result = \$this->callExternalApi(\$input);
            return ConnectorResult::ok(null, ['elapsed_ms' => 0]);
        } catch (\\Exception \$e) {
            return ConnectorResult::fail(\$e->getMessage(), 'CONNECTOR_ERROR');
        }
    }

    public function healthCheck(): bool
    {
        // TODO: 实现健康检查逻辑
        return true;
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'      => 'api_key',
                'type'     => 'text',
                'label'    => 'API 密钥',
                'required' => true,
            ],
            [
                'key'      => 'api_secret',
                'type'     => 'password',
                'label'    => 'API 密钥',
                'required' => true,
            ],
        ];
    }

    public function setConfig(array \$config): void
    {
        \$this->config = array_merge(\$this->config, \$config);
    }

    public function getConfig(): array
    {
        return \$this->config;
    }
}
TPL;
    }

    protected function getConfigTemplate(array $vars): string
    {
        return <<<TPL
<?php
/**
 * {$vars['title']} 连接器配置
 */
return [
    // 连接器标识
    'name'     => '{$vars['name']}',
    'title'    => '{$vars['title']}',
    'type'     => '{$vars['type']}',
    'enabled'  => false,

    // 凭据配置
    'credentials' => [
        'api_key'    => env('{$this->toUpperName($vars['name'])}_API_KEY', ''),
        'api_secret' => env('{$this->toUpperName($vars['name'])}_API_SECRET', ''),
    ],

    // 连接器选项
    'options' => [
        'timeout'    => 30,
        'base_url'   => '',
        'debug'      => false,
    ],
];
TPL;
    }

    protected function getReadmeTemplate(array $vars): string
    {
        return <<<TPL
# {$vars['title']} 连接器

- **标识**: {$vars['name']}
- **类型**: {$vars['type_label']}
- **创建时间**: {$vars['create_time']}

## 注册方式

```php
// 在 ServiceProvider::boot() 中注册
use wise\\support\\ExtPoint;
use wise\\support\\ExtPoints;

ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, '{$vars['name']}', {$vars['namespace']}\\{$vars['class_name']}::class);
```

## 使用方式

```php
\$manager = app(\\wise\\support\\connector\\ConnectorManager::class);
\$connector = \$manager->driver('{$vars['name']}');
\$result = \$connector->execute('action', ['params' => 'value']);
```

## 配置

编辑 `config/connector.php` 或通过环境变量配置:

```
{$this->toUpperName($vars['name'])}_API_KEY=your_key
{$this->toUpperName($vars['name'])}_API_SECRET=your_secret
```
TPL;
    }

    // ============================================================
    // 工具方法
    // ============================================================

    protected function toClassName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) . 'Connector';
    }

    protected function toNamespace(string $name): string
    {
        return 'connector\\' . $name;
    }

    protected function toUpperName(string $name): string
    {
        return strtoupper(str_replace('-', '_', $name));
    }

    protected function toConstCase(string $str): string
    {
        return strtoupper($str);
    }
}

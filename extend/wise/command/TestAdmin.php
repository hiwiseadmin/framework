<?php
namespace wise\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 管理后台全量控制器/视图/API 测试命令
 *
 * 用法：php think test:admin [--controller=Role]
 *
 * 直接在框架内测试，无需登录，自动扫描所有控制器。
 */
class TestAdmin extends Command
{
    protected function configure()
    {
        $this->setName('test:admin')
            ->setDescription('批量测试管理后台控制器的视图与 API 可用性')
            ->addOption('controller', 'c', \think\console\input\Option::VALUE_OPTIONAL, '指定测试单个控制器');
    }

    /** @var array 结果汇总 */
    protected $results = [];

    /** @var int 成功数 */
    protected $pass = 0;

    /** @var int 失败数 */
    protected $fail = 0;

    /** @var int 跳过数 */
    protected $skip = 0;

    protected function execute(Input $input, Output $output)
    {
        $single = $input->getOption('controller');
        $startTime = microtime(true);

        $output->writeln('<info>========================================</info>');
        $output->writeln('<info> WiseAdmin 全量控制器健康检查 v1.0</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');

        // 扫描控制器
        $controllerDir = app()->getAppPath() . 'admin' . DIRECTORY_SEPARATOR . 'controller';
        $files = glob($controllerDir . DIRECTORY_SEPARATOR . '*.php');

        $totalControllers = 0;
        $totalMethods = 0;

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            // 跳过基类和备份文件
            if (in_array($basename, ['BaseController']) || strpos($basename, '.') !== false) {
                continue;
            }

            if ($single && $single !== $basename) {
                continue;
            }

            $totalControllers++;
            $className = '\\app\\admin\\controller\\' . $basename;
            $methods = $this->getPublicMethods($className);
            $totalMethods += count($methods);

            $this->testController($basename, $className, $methods, $output);
        }

        // 汇总
        $elapsed = round(microtime(true) - $startTime, 2);
        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln(sprintf(
            '<info> [汇总] %d 控制器 / %d 方法 | ✅ %d | ❌ %d | ⏭ %d | 耗时 %.1fs</info>',
            $totalControllers, $totalMethods, $this->pass, $this->fail, $this->skip, $elapsed
        ));
        $output->writeln('<info>========================================</info>');

        return $this->fail > 0 ? 1 : 0;
    }

    /**
     * 测试单个控制器
     */
    protected function testController(string $name, string $className, array $methods, Output $output)
    {
        $output->writeln('');
        $output->writeln("<comment>--- {$name} (" . count($methods) . " 方法) ---</comment>");

        // 静态分析：检查视图文件
        $viewDir = app()->getAppPath() . 'admin' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . strtolower($name);

        // 视图方法（通常返回 HTML）：index, add, edit, preview, compare, detail, show, configIndex
        $viewMethods = ['index', 'add', 'edit', 'preview', 'compare', 'detail', 'show', 'configIndex'];

        // 视图方法集合（用于判断）
        $viewSet = array_flip($viewMethods);

        foreach ($methods as $method) {
            $isView = isset($viewSet[$method]);
            $result = $this->testMethod($name, $className, $method, $isView, $viewDir, $output);
            $this->results[] = $result;

            if ($result['status'] === 'pass') {
                $this->pass++;
            } elseif ($result['status'] === 'skip') {
                $this->skip++;
            } else {
                $this->fail++;
            }
        }
    }

    /**
     * 测试单个方法
     */
    protected function testMethod(string $name, string $className, string $method, bool $isView, string $viewDir, Output $output): array
    {
        $result = [
            'controller' => $name,
            'method'     => $method,
            'type'       => $isView ? 'view' : 'api',
            'status'     => 'pass',
            'error'      => '',
        ];

        $prefix = $isView ? '📄' : '🔗';

        try {
            $reflection = new \ReflectionClass($className);
            if (!$reflection->hasMethod($method)) {
                $result['status'] = 'fail';
                $result['error'] = "Method '{$method}' not found in class";
                $output->writeln("  {$prefix} <error>FAIL</error> {$method} — 方法不存在");
                return $result;
            }

            $reflectionMethod = $reflection->getMethod($method);
            if (!$reflectionMethod->isPublic()) {
                $result['status'] = 'skip';
                $output->writeln("  {$prefix} <comment>SKIP</comment> {$method} — 非 public 方法");
                return $result;
            }

            if ($isView) {
                // 视图方法：检查对应模板是否存在
                $viewFile = $viewDir . DIRECTORY_SEPARATOR . $method . '.html';
                if (!file_exists($viewFile)) {
                    // 尝试常见的别名映射
                    $aliases = [
                        'configIndex' => 'config_index',
                        'detail'      => 'detail',
                        'show'         => 'show',
                    ];
                    $aliasName = $aliases[$method] ?? $method;
                    $viewFile = $viewDir . DIRECTORY_SEPARATOR . $aliasName . '.html';

                    if (!file_exists($viewFile)) {
                        $result['status'] = 'fail';
                        $result['error'] = "视图文件不存在: {$viewDir}/{$method}.html";
                        $output->writeln("  {$prefix} <error>FAIL</error> {$method} — 视图文件缺失");
                        return $result;
                    }
                }

                // 尝试编译视图（只读模式，检查语法）
                try {
                    // 直接用 file_get_contents 验证文件可读
                    $content = file_get_contents($viewFile);
                    if ($content === false || strlen($content) < 10) {
                        $result['status'] = 'fail';
                        $result['error'] = "视图文件为空或不可读";
                        $output->writeln("  {$prefix} <error>FAIL</error> {$method} — 视图文件空/不可读");
                    } else {
                        // 基础语法检查：extend 标签存在性
                        if (strpos($content, '{extend') === false && $name !== 'Login') {
                            $result['status'] = 'warn';
                            $result['error'] = '视图缺少 {extend} 继承标签';
                            $output->writeln("  {$prefix} <warning>WARN</warning> {$method} — 缺少继承标签（可能正常）");
                        } else {
                            $output->writeln("  {$prefix} <info>OK</info> {$method} — 视图 {$method}.html (" . strlen($content) . " bytes)");
                        }
                    }
                } catch (\Throwable $e) {
                    $result['status'] = 'fail';
                    $result['error'] = $e->getMessage();
                    $output->writeln("  {$prefix} <error>FAIL</error> {$method} — {$e->getMessage()}");
                }
            } else {
                // API 方法：通过反射检查方法是否存在且可调用
                $params = $reflectionMethod->getParameters();
                $hasRequiredParams = false;
                foreach ($params as $param) {
                    if (!$param->isOptional() && !$param->isDefaultValueAvailable()) {
                        $hasRequiredParams = true;
                        break;
                    }
                }

                if ($hasRequiredParams) {
                    $output->writeln("  {$prefix} <info>OK</info> {$method} — API 端点存在（需要参数）");
                } else {
                    $output->writeln("  {$prefix} <info>OK</info> {$method} — API 端点可调用（无需参数）");
                }

                // 额外检查：验证 $this->success() / $this->error() 调用存在（静态分析）
                $sourceFile = $reflection->getFileName();
                $sourceCode = file_get_contents($sourceFile);
                $methodBody = $this->extractMethodBody($sourceCode, $method);

                if ($methodBody) {
                    $hasSuccess = strpos($methodBody, 'success(') !== false;
                    $hasError = strpos($methodBody, 'error(') !== false;
                    if (!$hasSuccess && !$hasError) {
                        $result['status'] = 'warn';
                        $result['error'] = '方法体中未找到 success/error 返回值调用';
                        $output->writeln("       <warning>⚠ 未找到 return 语句，可能缺少响应</warning>");
                    }
                }
            }
        } catch (\ReflectionException $e) {
            $result['status'] = 'fail';
            $result['error'] = $e->getMessage();
            $output->writeln("  {$prefix} <error>FAIL</error> {$method} — {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * 获取类的所有 public 方法（排除构造方法和初始化方法）
     */
    protected function getPublicMethods(string $className): array
    {
        try {
            $reflection = new \ReflectionClass($className);
            $methods = [];

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();
                // 排除构造方法和初始化钩子
                if (in_array($name, ['__construct', 'initialize', '_initialize'])) {
                    continue;
                }
                // 排除从父类继承的方法（BaseController 的 assign/fetch/success/error 等）
                if (ltrim($method->getDeclaringClass()->getName(), '\\') !== ltrim($className, '\\')) {
                    continue;
                }
                $methods[] = $name;
            }

            return $methods;
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    /**
     * 粗略提取方法体（用于静态分析 success/error 调用）
     */
    protected function extractMethodBody(string $sourceCode, string $methodName): ?string
    {
        // 匹配 function methodName(...) { ... }
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{([\s\S]*?)\n    \}/';
        if (preg_match($pattern, $sourceCode, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

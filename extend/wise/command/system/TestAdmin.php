<?php
namespace wise\command\system;

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

    /** @var int 警告数（不计入退出码） */
    protected $warn = 0;

    protected function execute(Input $input, Output $output)
    {
        $single = $input->getOption('controller');
        $startTime = microtime(true);

        $output->writeln('<info>========================================</info>');
        $output->writeln('<info> WiseAdmin 全量控制器健康检查 v1.0</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');

        $controllerDir = app()->getAppPath() . 'admin' . DIRECTORY_SEPARATOR . 'controller';
        $files = glob($controllerDir . DIRECTORY_SEPARATOR . '*.php');

        $totalControllers = 0;
        $totalMethods = 0;

        foreach ($files as $file) {
            $basename = basename($file, '.php');
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

        $elapsed = round(microtime(true) - $startTime, 2);
        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln(sprintf(
            '<info> [汇总] %d 控制器 / %d 方法 | ✅ %d | ❌ %d | ⚠ %d | ⏭ %d | 耗时 %.1fs</info>',
            $totalControllers, $totalMethods, $this->pass, $this->fail, $this->warn, $this->skip, $elapsed
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

        $viewDir = app()->getAppPath() . 'admin' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . strtolower($name);

        $viewMethods = ['index', 'add', 'edit', 'preview', 'compare', 'detail', 'show', 'configIndex'];

        $viewSet = array_flip($viewMethods);

        foreach ($methods as $method) {
            $isView = $this->isViewMethod($className, $method, $viewSet);
            $result = $this->testMethod($name, $className, $method, $isView, $viewDir, $output);
            $this->results[] = $result;

            if ($result['status'] === 'pass') {
                $this->pass++;
            } elseif ($result['status'] === 'skip') {
                $this->skip++;
            } elseif ($result['status'] === 'warn') {
                $this->warn++;
            } else {
                $this->fail++;
            }
        }
    }

    /**
     * 判断方法是否为「视图方法」
     *
     * 以方法体里是否真的调用了 `fetch()` / `view()` 为准：
     * 该方法名白名单（add / edit / detail / preview …）在很多控制器里其实是 JSON 写入端点，
     * 按名字硬判定会把它们误报成「视图文件缺失」。
     *
     * @param array<string,int> $viewSet 名称兜底白名单
     */
    protected function isViewMethod(string $className, string $method, array $viewSet): bool
    {
        $body = $this->methodBody($className, $method);
        if ($body !== null) {
            return (bool) preg_match('/(?:\bfetch|\bview)\s*\(/', $body);
        }

        return isset($viewSet[$method]);
    }

    /**
     * 取方法体源码（拿不到时返回 null）
     */
    protected function methodBody(string $className, string $method): ?string
    {
        try {
            $reflection = new \ReflectionMethod($className, $method);
            $file = $reflection->getFileName();
            if ($file === false || !is_file($file)) {
                return null;
            }

            return $this->extractMethodBody((string) file_get_contents($file), $method);
        } catch (\ReflectionException) {
            return null;
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
                $sourceFile = $reflectionMethod->getFileName();
                $sourceCode = $sourceFile !== false ? (string) file_get_contents($sourceFile) : '';
                $body = $sourceCode !== '' ? $this->extractMethodBody($sourceCode, $method) : null;

                $candidates = $this->viewCandidates($method, $body);
                $viewRoot   = dirname($viewDir);
                $viewFile   = $this->resolveViewFile($viewRoot, $name, $candidates);

                if ($viewFile === null) {
                    $result['status'] = 'fail';
                    $result['error'] = '视图文件不存在，候选：' . implode(', ', $candidates);
                    $output->writeln("  {$prefix} <error>FAIL</error> {$method} — 视图文件缺失（候选："
                        . implode(', ', $candidates) . '）');
                    return $result;
                }

                $templateName = str_replace($viewRoot . DIRECTORY_SEPARATOR, '', $viewFile);

                try {
                    $content = file_get_contents($viewFile);
                    if ($content === false || strlen($content) < 10) {
                        $result['status'] = 'fail';
                        $result['error'] = '视图文件为空或不可读';
                        $output->writeln("  {$prefix} <error>FAIL</error> {$method} — 视图文件空/不可读");
                    } else {
                        if (strpos($content, '{extend') === false && $name !== 'Login') {
                            $result['status'] = 'warn';
                            $result['error'] = '视图缺少 {extend} 继承标签';
                            $output->writeln("  {$prefix} <warning>WARN</warning> {$method} — 缺少继承标签（可能正常）");
                        } else {
                            $output->writeln("  {$prefix} <info>OK</info> {$method} — 视图 {$templateName} (" . strlen($content) . " bytes)");
                        }
                    }
                } catch (\Throwable $e) {
                    $result['status'] = 'fail';
                    $result['error'] = $e->getMessage();
                    $output->writeln("  {$prefix} <error>FAIL</error> {$method} — {$e->getMessage()}");
                }
            } else {
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
                if (in_array($name, ['__construct', 'initialize', '_initialize'])) {
                    continue;
                }
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
     * 视图模板候选名（按优先级）
     *
     * 1. 方法体里显式 fetch('xxx') / view('xxx') 的目标（最可信）
     *    形如 `admin@wise_dict/index` 会剥离 `admin@` 应用前缀
     * 2. 方法名 + 常见别名（configIndex → config_index 等）
     *
     * @param string      $method 方法名
     * @param string|null $body   方法体源码
     * @return list<string>
     */
    protected function viewCandidates(string $method, ?string $body): array
    {
        $candidates = [];

        if ($body !== null && $body !== '') {
            if (preg_match_all('/(?:fetch|view)\(\s*[\'"]([^\'"]+)[\'"]/', $body, $matches)) {
                foreach ($matches[1] as $template) {
                    $template = trim(str_replace('\\', '/', $template), '/');
                    $template = (string) preg_replace('/\.html$/', '', $template);
                    if (str_contains($template, '@')) {
                        $template = substr($template, strpos($template, '@') + 1);
                    }
                    if ($template !== '') {
                        $candidates[] = $template;
                    }
                }
            }
        }

        $aliases = [
            'configIndex' => 'config_index',
        ];
        $candidates[] = $aliases[$method] ?? $method;
        $candidates[] = $method;

        return array_values(array_unique($candidates));
    }

    /**
     * 解析视图文件（返回绝对路径，找不到返回 null）
     *
     * 查找顺序：
     *   1. 视图根目录下（跨控制器共享模板，如 attachment/index、attachment/category）
     *   2. 控制器视图目录下：先 snake_case（async_task）再全小写（asynctask）
     *
     * @param string       $viewRoot   视图根目录（app/admin/view）
     * @param string       $controller 控制器类名（如 AsyncTask）
     * @param list<string> $templates  模板候选名
     */
    protected function resolveViewFile(string $viewRoot, string $controller, array $templates): ?string
    {
        $dirs = [];
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $controller));
        foreach ([$snake, strtolower($controller)] as $dir) {
            if ($dir !== '' && !in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }

        foreach ($templates as $template) {
            $path = $viewRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.html';
            if (is_file($path)) {
                return $path;
            }
        }

        foreach ($dirs as $dir) {
            foreach ($templates as $template) {
                $path = $viewRoot . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.html';
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * 粗略提取方法体（用于静态分析 success/error 调用）
     */
    protected function extractMethodBody(string $sourceCode, string $methodName): ?string
    {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{([\s\S]*?)\n    \}/';
        if (preg_match($pattern, $sourceCode, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

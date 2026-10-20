<?php

declare(strict_types=1);

namespace wise\plugin\composer_bridge;

/**
 * Composer 引擎封装。
 *
 * 把"插件安装"从手写 HTTP 下载 + 解压，改造为调用 Composer 求解器。
 * 这样一来，插件与 wise-* 基础包、第三方包共用同一套依赖解析与版本冲突检测，
 * 从根本上解决"两个脱节依赖世界 + 多插件不同版本需求"的问题。
 *
 * 典型用法：
 *   $engine = new ComposerEngine();
 *   $pre  = $engine->checkInstallConflicts('wiseadmin/plugin-blog', '^1.2');
 *   if ($pre['conflict']) { /* 装前拦截，返回冲突详情 *\/ }
 *   $req  = $engine->requirePackage('wiseadmin/plugin-blog', '^1.2');
 *   // require 成功后 post-autoload-dump 会自动执行 `php think plugin:bridge`
 *   // 把 wise-plugin 类型的包桥接到 plugins/ 目录
 */
class ComposerEngine
{
    /**
     * Composer 项目根目录（存放 composer.json 的目录）。
     */
    private string $rootPath;

    /**
     * 检测到的 composer 可执行文件（composer 或 composer.phar）。
     */
    private ?string $composerBinary;

    /**
     * 注入 Composer 进程的环境变量。
     */
    private array $env;

    public function __construct(?string $rootPath = null, ?string $composerBinary = null)
    {
        $this->rootPath = rtrim($rootPath ?? $this->detectRootPath(), DIRECTORY_SEPARATOR);
        $this->composerBinary = $composerBinary ?? $this->detectComposer();
        $this->env = [
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
        ];
    }

    /**
     * 当前环境是否可用 Composer 引擎。
     */
    public function isAvailable(): bool
    {
        return $this->composerBinary !== null;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * 装前冲突预检：模拟 require，命中版本冲突时返回冲突详情。
     *
     * 借助 Composer 求解器，在真正改动 composer.json / 下载文件之前，
     * 针对"全部已安装插件 + wise-* 包"一次性判定是否存在版本冲突。
     *
     * @return array{conflict:bool,exitCode:int,output:string,blockers:array}
     */
    public function checkInstallConflicts(string $package, string $versionConstraint): array
    {
        $constraint = $versionConstraint === '' ? '' : ':' . $versionConstraint;
        $args = [
            'require',
            $package . $constraint,
            '--dry-run',
            '--no-scripts',
            '--no-interaction',
            '--no-audit',
        ];

        $run = $this->run($args);
        $combined = $run['output'] . "\n" . $run['stderr'];

        $conflict = $run['exitCode'] !== 0 || $this->outputHasConflict($combined);
        $blockers = $conflict ? $this->extractBlockers($combined) : [];

        return [
            'conflict' => $conflict,
            'exitCode' => $run['exitCode'],
            'output' => $combined,
            'blockers' => $blockers,
        ];
    }

    /**
     * 实际安装：执行 composer require。
     *
     * 该命令会：写入 composer.json、拉取依赖、生成 autoload，
     * 并触发 post-autoload-dump 脚本（含 `php think plugin:bridge`）把 wise-plugin 桥接到 plugins/。
     *
     * @return array{exitCode:int,output:string,stderr:string}
     */
    public function requirePackage(string $package, string $versionConstraint): array
    {
        $constraint = $versionConstraint === '' ? '' : ':' . $versionConstraint;

        return $this->run([
            'require',
            $package . $constraint,
            '--no-interaction',
        ]);
    }

    /**
     * 卸载：执行 composer remove，移除该插件对应的 Composer 依赖。
     */
    public function removePackage(string $package): array
    {
        return $this->run([
            'remove',
            $package,
            '--no-interaction',
        ]);
    }

    /**
     * 执行任意 composer 命令，返回分离的退出码与输出。
     *
     * @param string[] $args
     * @return array{exitCode:int,output:string,stderr:string}
     */
    public function run(array $args): array
    {
        if ($this->composerBinary === null) {
            return ['exitCode' => 127, 'output' => '', 'stderr' => 'composer not found'];
        }

        $cmd = $this->buildCommand($args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->rootPath,
            array_merge($_SERVER ?? [], $_ENV ?? [], $this->env)
        );

        if (!is_resource($proc)) {
            return ['exitCode' => 127, 'output' => '', 'stderr' => '无法启动 composer 进程'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [
            'exitCode' => $exitCode,
            'output' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /**
     * 拼接可执行命令。
     */
    private function buildCommand(array $args): string
    {
        if (str_ends_with((string) $this->composerBinary, '.phar')) {
            $base = (PHP_BINARY ?: 'php') . ' ' . escapeshellarg((string) $this->composerBinary);
        } else {
            $base = escapeshellarg((string) $this->composerBinary);
        }

        $quoted = array_map('escapeshellarg', $args);

        return $base . ' ' . implode(' ', $quoted);
    }

    /**
     * 判断输出是否包含依赖冲突特征。
     */
    private function outputHasConflict(string $output): bool
    {
        $patterns = [
            '/Cannot (install|satisfy) (the )?requirements/i',
            '/Problem \d+/i',
            '/Your requirements could not be resolved/i',
            '/Installation failed/i',
            '/Conflict/i',
            '/but these were not loaded|but .* is installed/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从 Composer 输出中提取冲突来源（以 "- " 开头的依赖链行）。
     *
     * @return string[]
     */
    private function extractBlockers(string $output): array
    {
        $blockers = [];
        $lines = preg_split('/\r?\n/', $output) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^[-*]\s+/', $trimmed) || preg_match('/requires|conflict|installed/i', $trimmed)) {
                $blockers[] = $trimmed;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * 定位 composer 可执行文件：环境变量 → 项目根 composer.phar → 全局 composer。
     */
    private function detectComposer(): ?string
    {
        $env = getenv('COMPOSER_BINARY');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $env;
        }

        $phar = $this->rootPath . DIRECTORY_SEPARATOR . 'composer.phar';
        if (is_file($phar)) {
            return $phar;
        }

        $global = $this->whichComposer();
        if ($global !== null) {
            return $global;
        }

        return null;
    }

    /**
     * 在 PATH 中查找全局 composer。
     */
    private function whichComposer(): ?string
    {
        $cmd = DIRECTORY_SEPARATOR === '\\' ? 'where composer 2>nul' : 'command -v composer 2>/dev/null';
        $out = @shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            return 'composer';
        }

        return null;
    }

    /**
     * 向上查找存放 composer.json 的目录作为 Composer 项目根。
     */
    private function detectRootPath(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        // 兜底：composer_bridge 位于 src/extend/wise/plugin/composer_bridge，故上溯 4 级即 src/
        return dirname(__DIR__, 4);
    }
}

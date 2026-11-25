<?php

declare(strict_types=1);

namespace wise\plugin\extract;

/**
 * 抽取规划器（`plugin:extract` 的只读阶段）。
 *
 * ## 边界（方桏就是"抽取边界不清"）
 *
 * v1 **不做任何猜测**：必须显式给出 `--from` 清单（文件或目录），命令只负责
 * 「按路径段映射到插件目录 + 检测冲突」。猜"哪些文件属于这个模块"必然猜错，
 * 而错了的代价是作者要在一个看起来生成了的插件里逐文件核对。
 *
 * 映射规则（取相对应用根路径中**第一个**命中段，其后原样保留）：
 *
 * | 源码路径段 | 插件内目标 |
 * |:--|:--|
 * | `controller` | `controller/...` |
 * | `model` | `model/...` |
 * | `validate` | `validate/...` |
 * | `service` / `logic` | 同名 |
 * | `middleware` | `middleware/...` |
 * | `view` | `view/...` |
 * | `statics` | `statics/...` |
 * | `migrations` | `database/migrations/...` |
 * | `seeds` / `seeders` | `database/seeds/...` |
 * | `route` | `route/...` |
 *
 * 命不中任何段时只有两条出路：单个文件用 `--to=` 显式指定目标，或把源码放到上述目录下。
 * 不提供"未知目录塞到 misc/"这种兜底 —— 那只会把问题推迟到运行期。
 */
final class ExtractPlanner
{
    /** 源码路径段 → 插件内目标目录（`migrations` 这类会改变层级的段显式列出） */
    public const SEGMENT_MAP = [
        'controller' => 'controller',
        'model'      => 'model',
        'validate'   => 'validate',
        'service'    => 'service',
        'logic'      => 'logic',
        'middleware' => 'middleware',
        'event'      => 'event',
        'job'        => 'job',
        'command'    => 'command',
        'view'       => 'view',
        'statics'    => 'statics',
        'migrations' => 'database/migrations',
        'migration'  => 'database/migrations',
        'seeds'      => 'database/seeds',
        'seeders'    => 'database/seeds',
        'route'      => 'route',
    ];

    /** 允许抽取的扩展名（其余一律拒绝，避免把二进制/大文件搬进插件） */
    public const ALLOWED_EXT = [
        'php', 'html', 'htm', 'tpl', 'js', 'mjs', 'css', 'scss', 'less',
        'json', 'md', 'txt', 'sql', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp',
        'woff', 'woff2', 'ttf', 'eot', 'map',
    ];

    /** 目录递归时直接跳过的目录名（任意层级） */
    public const SKIP_DIRS = ['node_modules', 'vendor', 'runtime', 'dist', 'backup'];

    /** 单文件上限（2MB）：控制器/视图远超此值本身就不正常 */
    private const MAX_FILE_BYTES = 2097152;

    /**
     * 生成抽取计划。
     *
     * @param string             $pluginDir 目标插件目录（绝对路径，已存在）
     * @param string             $appRoot   应用根（绝对路径）
     * @param array<int, string> $sources   `--from` 清单（文件或目录）
     * @param string|null        $to        单文件目标路径（相对插件根）
     * @param array<int, string> $strip     需要从目标路径前部剥离的段名（可多次出现）
     * @param bool               $force     允许覆盖已存在的目标文件
     *
     * @return array{
     *     files: array<int, array{source:string, target:string, kind:string, overwrite:bool}>,
     *     errors: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    public function plan(
        string $pluginDir,
        string $appRoot,
        array $sources,
        ?string $to = null,
        array $strip = [],
        bool $force = false
    ): array {
        $result = ['files' => [], 'errors' => [], 'warnings' => []];

        $appRoot   = $this->normalize($appRoot);
        $pluginDir = $this->normalize($pluginDir);
        $pluginsRoot = rtrim($this->normalize(plugin_path()), '/');

        $strip = array_values(array_filter(array_map('trim', $strip)));

        $candidates = [];

        foreach ($sources as $source) {
            $source = trim($source);

            if ($source === '') {
                continue;
            }

            $absolute = $this->normalize($this->absolutize($source, $appRoot));

            if (!file_exists($absolute)) {
                $result['errors'][] = '源不存在：' . $source;

                continue;
            }

            $real = $this->normalize((string) realpath($absolute));

            if ($real === '' || !$this->isInside($real, $appRoot)) {
                $result['errors'][] = '源必须位于应用根内：' . $source;

                continue;
            }

            if ($this->isInside($real, $pluginsRoot)) {
                $result['errors'][] = '不能从 plugins/ 里抽取（自抽自）：' . $source;

                continue;
            }

            if (is_dir($real)) {
                foreach ($this->collectFiles($real) as $file) {
                    $candidates[$file] = true;
                }

                continue;
            }

            $candidates[$real] = true;
        }

        if ($candidates === [] && $result['errors'] === []) {
            $result['errors'][] = '没有可抽取的文件（--from 为空或目录下没有受支持的文件）';
        }

        $usedTargets = [];

        foreach (array_keys($candidates) as $file) {
            $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

            if (!in_array($extension, self::ALLOWED_EXT, true)) {
                $result['warnings'][] = '跳过不受支持的扩展名：' . $this->relative($file, $appRoot);

                continue;
            }

            $size = (int) @filesize($file);

            if ($size > self::MAX_FILE_BYTES) {
                $result['errors'][] = sprintf('文件过大（%.1fMB）：%s', $size / 1048576, $this->relative($file, $appRoot));

                continue;
            }

            $target = $this->resolveTarget($file, $appRoot, $to, $strip, $result);

            if ($target === null) {
                continue;
            }

            if (isset($usedTargets[$target])) {
                $result['errors'][] = sprintf(
                    '多个源映射到同一目标 %s：%s 与 %s',
                    $target,
                    $usedTargets[$target],
                    $this->relative($file, $appRoot)
                );

                continue;
            }

            $usedTargets[$target] = $this->relative($file, $appRoot);
            $targetFile          = $pluginDir . '/' . $target;
            $overwrite           = is_file($targetFile);

            if ($overwrite && !$force) {
                $result['errors'][] = sprintf(
                    '目标已存在：%s（加 --force 覆盖，或先删除该文件）',
                    $target
                );

                continue;
            }

            if ($overwrite) {
                $result['warnings'][] = '将覆盖已有文件：' . $target;
            }

            $result['files'][] = [
                'source'    => $file,
                'target'    => $target,
                'kind'      => $extension === 'php' ? 'php' : 'asset',
                'overwrite' => $overwrite,
            ];
        }

        usort($result['files'], static fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        return $result;
    }

    /**
     * 递归收集目录下的文件（跳过 {@see self::SKIP_DIRS}）。
     *
     * @return array<int, string>
     */
    protected function collectFiles(string $dir): array
    {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $current): bool {
                        if (!$current->isDir()) {
                            return true;
                        }

                        return !in_array(strtolower($current->getFilename()), self::SKIP_DIRS, true);
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $this->normalize($file->getPathname());
                }
            }
        } catch (\Throwable $e) {
        }

        sort($files);

        return $files;
    }

    /**
     * 计算单个源文件在插件内的目标相对路径。
     *
     * @param array<int, string>    $strip
     * @param array<string, mixed>  $result 出错时写入 errors
     */
    protected function resolveTarget(string $file, string $appRoot, ?string $to, array $strip, array &$result): ?string
    {
        $relative = $this->relative($file, $appRoot);
        $segments = explode('/', $relative);

        array_pop($segments);

        foreach ($segments as $index => $segment) {
            $key = strtolower($segment);

            if (!isset(self::SEGMENT_MAP[$key])) {
                continue;
            }

            $rest = array_slice($segments, $index + 1);

            foreach ($strip as $stripSegment) {
                if ($rest !== [] && strcasecmp((string) $rest[0], $stripSegment) === 0) {
                    array_shift($rest);
                }
            }

            if (in_array('..', $rest, true)) {
                $result['errors'][] = '目标路径非法（含 ..）：' . $relative;

                return null;
            }

            return implode('/', array_merge([self::SEGMENT_MAP[$key]], $rest, [basename($file)]));
        }

        if ($to !== null && trim($to) !== '') {
            $target = trim(str_replace('\\', '/', $to), '/');

            if ($target === '' || in_array('..', explode('/', $target), true)) {
                $result['errors'][] = '--to 非法：' . $to;

                return null;
            }

            return $target;
        }

        $result['errors'][] = sprintf(
            '无法推断目标目录：%s（请放到 controller/model/view/statics/... 下，或用 --to 显式指定）',
            $relative
        );

        return null;
    }

    protected function absolutize(string $path, string $appRoot): string
    {
        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1) {
            return $path;
        }

        return rtrim($appRoot, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    protected function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    protected function isInside(string $path, string $root): bool
    {
        $path = $this->normalize($path);
        $root = $this->normalize($root);

        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    /**
     * 相对应用根的展示路径（用于输出，不含绝对路径噪音）。
     */
    protected function relative(string $file, string $appRoot): string
    {
        $file = $this->normalize($file);
        $root = $this->normalize($appRoot);

        if (str_starts_with($file, $root . '/')) {
            return substr($file, strlen($root) + 1);
        }

        return $file;
    }
}

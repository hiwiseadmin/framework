<?php

declare(strict_types=1);

namespace wise\plugin\extract;

/**
 * 抽取落盘器（`plugin:extract` 的唯一写入点）。
 *
 * 职责边界：
 *  - **不做规划**（哪些文件、落到哪由 {@see ExtractPlanner} 决定，规划失败时本类根本不会被调用）；
 *  - **不做决策**（覆盖与否由 `--force` 决定，冲突在规划阶段就已拦下）；
 *  - 只负责：建目录、`.php` 走一遍 {@see NamespaceRewriter}、其余按二进制复制、如实汇报。
 *
 * `--dry-run` 时同样走本类，只是 `$dryRun = true`：**用真实的读盘+改写路径产出计划**，
 * 而不是另写一套"假装预览"的逻辑 —— 两套逻辑必然漂移。
 */
final class ExtractWriter
{
    public function __construct(protected ?NamespaceRewriter $rewriter = null)
    {
    }

    /**
     * 写入抽取结果。
     *
     * @param string $pluginDir 目标插件目录（绝对路径）
     * @param array<int, array{source:string, target:string, kind:string, overwrite:bool}> $files 规划结果
     * @param bool   $dryRun    只读预览
     *
     * @return array{
     *     ok: bool,
     *     written: array<int, array{target:string, action:string, replaced:int}>,
     *     errors: array<int, string>,
     *     warnings: array<int, string>,
     *     stats: array{php:int, asset:int, replaced:int}
     * }
     */
    public function write(string $pluginDir, array $files, bool $dryRun = false): array
    {
        $pluginDir = rtrim(str_replace('\\', '/', $pluginDir), '/');

        $result = [
            'ok'      => true,
            'written' => [],
            'errors'  => [],
            'warnings' => [],
            'stats'   => ['php' => 0, 'asset' => 0, 'replaced' => 0],
        ];

        foreach ($files as $file) {
            $target = $pluginDir . '/' . $file['target'];

            if ($file['kind'] === 'php') {
                $code = @file_get_contents($file['source']);

                if ($code === false) {
                    $result['errors'][] = '源文件不可读：' . $file['source'];

                    continue;
                }

                $rewrite = $this->rewriter()->rewrite($code);

                foreach ($rewrite['unmapped'] as $name) {
                    $result['warnings'][] = sprintf(
                        '%s：命名空间 %s 未命中映射，已原样保留（可用 --map-namespace=a=b 补充）',
                        $file['target'],
                        $name
                    );
                }

                foreach ($rewrite['body_refs'] as $name) {
                    $result['warnings'][] = sprintf(
                        '%s：函数体内存在完全限定引用 %s，抽到插件后需人工确认',
                        $file['target'],
                        '\\' . $name
                    );
                }

                $result['stats']['php']++;
                $result['stats']['replaced'] += $rewrite['replaced'];

                if (!$dryRun && !$this->put($target, $rewrite['code'], $result)) {
                    continue;
                }

                $result['written'][] = [
                    'target'   => $file['target'],
                    'action'   => $file['overwrite'] ? 'overwrite' : 'create',
                    'replaced' => $rewrite['replaced'],
                ];

                continue;
            }

            $result['stats']['asset']++;

            if (!$dryRun && !$this->copy($file['source'], $target, $result)) {
                continue;
            }

            $result['written'][] = [
                'target'   => $file['target'],
                'action'   => $file['overwrite'] ? 'overwrite' : 'create',
                'replaced' => 0,
            ];
        }

        $result['ok'] = $result['errors'] === [];

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function put(string $target, string $content, array &$result): bool
    {
        if (!$this->ensureDir(dirname($target), $result)) {
            return false;
        }

        if (@file_put_contents($target, $content) === false) {
            $result['errors'][] = '写入失败：' . $target;

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function copy(string $source, string $target, array &$result): bool
    {
        if (!$this->ensureDir(dirname($target), $result)) {
            return false;
        }

        if (!@copy($source, $target)) {
            $result['errors'][] = '复制失败：' . $target;

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function ensureDir(string $dir, array &$result): bool
    {
        if ($dir === '' || is_dir($dir)) {
            return true;
        }

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $result['errors'][] = '无法创建目录：' . $dir;

            return false;
        }

        return true;
    }

    protected function rewriter(): NamespaceRewriter
    {
        return $this->rewriter ??= new NamespaceRewriter([]);
    }
}

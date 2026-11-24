<?php

declare(strict_types=1);

namespace wise\plugin\publish;

use wise\support\tools\ArchiveTool;

/**
 * 插件发布打包器（`plugin:publish` 专用）。
 *
 * 只做一件事：把 `plugins/{ident}/` 打成**可安装、可投稿**的 zip，并给出本地
 * `size` / `sha256` —— 前者用于在客户端提前拦住服务端 `wise-market.max_size` 超限，
 * 后者与服务端落库口径一致（`PluginStorageService` 用 `hash_file('sha256')`）。
 *
 * ## 包内结构：wrapped（`demo/manifest.json`）
 *
 * 两侧都兼容，但 wrapped 是各渠道的公共子集：
 *  - 本地安装器对「ZIP 内有一层子目录」有显式兼容（`PluginLifecycle` 安装逻辑）；
 *  - 服务端 `PluginValidateService` 按 flat / wrapped 双形态归一后再校验。
 *
 * ## 排除口径
 *
 * `ArchiveTool::compressDir()` 的 `skip` 只匹配 **basename**（任意层级生效）：
 *   - 不含通配符 → 精确匹配（目录名命中则整棵子树跳过）；
 *   - 含 `*` / `?` / `[abc]` → 按 glob 匹配，大小写不敏感（见 `ArchiveTool::matchesSkipPattern()`）。
 *
 * 因此可以放心写 `*.log` 这类规则；但也**不要**写路径式规则（`runtime/cache`）——
 * 只比 basename，路径不参与匹配。
 */
final class PublishPackager
{
    /**
     * 默认排除项（打包产物里不应出现的东西）。
     *
     * 目录/固定名用精确匹配；日志与 sourcemap 用 glob（任意层级、大小写不敏感）。
     * 业务目录（controller/model/view/statics）一律保留。
     */
    public const DEFAULT_SKIP = [
        '.git',
        '.svn',
        '.hg',
        '.idea',
        '.vscode',
        'node_modules',
        'runtime',
        'dist',
        'tests',
        '.DS_Store',
        '.env',
        '.env.local',
        '*.log',
        '*.map',
        '*.tmp',
        '*.swp',
    ];

    /** 服务端默认上限（`wise-market.max_size`，单位 MB → 这里存字节） */
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        protected string $pluginsRoot = '',
        protected ?ArchiveTool $archive = null
    ) {
    }

    /**
     * 打包插件目录。
     *
     * @param string             $ident     插件标识（目录名）
     * @param string             $version   版本号（仅用于产物文件名）
     * @param string             $outputDir 产物目录（绝对路径）
     * @param array<int, string> $extraSkip 额外排除项（按文件名精确匹配）
     *
     * @return array{ok: bool, path: string, size: int, sha256: string, error: string, skip: array<int, string>}
     */
    public function package(string $ident, string $version, string $outputDir, array $extraSkip = []): array
    {
        $source = $this->pluginRoot($ident);

        $result = ['ok' => false, 'path' => '', 'size' => 0, 'sha256' => '', 'error' => '', 'skip' => []];

        if (!is_dir($source)) {
            $result['error'] = '插件目录不存在：' . $source;

            return $result;
        }

        if (!is_file($source . DIRECTORY_SEPARATOR . 'manifest.json')) {
            $result['error'] = '插件目录缺少 manifest.json：' . $source;

            return $result;
        }

        $skip          = array_values(array_unique(array_merge(self::DEFAULT_SKIP, $this->normalizeSkip($extraSkip))));
        $result['skip'] = $skip;

        $outputDir = rtrim(str_replace('\\', '/', $outputDir), '/');

        if ($outputDir === '') {
            $result['error'] = '产物目录为空';

            return $result;
        }

        if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            $result['error'] = '无法创建产物目录：' . $outputDir;

            return $result;
        }

        $destination = $outputDir . '/' . $this->fileName($ident, $version);

        $archive = $this->archive ?? new ArchiveTool();

        $ok = $archive->compressDir($source, $destination, 'zip', [
            'include_root'        => true,
            'root_name'           => $ident,
            'skip'                => $skip,
            'skip_links'          => true,
            'preserve_empty_dirs' => true,
            'overwrite'           => true,
        ]);

        if (!$ok) {
            $result['error'] = $archive->getLastError() ?: '打包失败（无更多信息）';

            return $result;
        }

        $size   = (int) @filesize($destination);
        $sha256 = @hash_file('sha256', $destination);

        if ($size <= 0 || $sha256 === false) {
            @unlink($destination);
            $result['error'] = '打包结果不可读（size/sha256 计算失败）';

            return $result;
        }

        return [
            'ok'     => true,
            'path'   => $destination,
            'size'   => $size,
            'sha256' => $sha256,
            'error'  => '',
            'skip'   => $skip,
        ];
    }

    /**
     * 产物文件名：`{ident}-{version}.zip`（版本号里的非法字符替换为 `-`）。
     */
    public function fileName(string $ident, string $version): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version) ?: '0.0.0';

        return $ident . '-' . $safe . '.zip';
    }

    /**
     * 插件目录绝对路径。
     */
    public function pluginRoot(string $ident): string
    {
        $root = $this->pluginsRoot !== ''
            ? rtrim(str_replace('\\', '/', $this->pluginsRoot), '/')
            : rtrim(str_replace('\\', '/', plugin_path()), '/');

        return $root . '/' . $ident;
    }

    /**
     * @param array<int, string> $skip
     *
     * @return array<int, string>
     */
    protected function normalizeSkip(array $skip): array
    {
        $out = [];

        foreach ($skip as $item) {
            $item = trim((string) $item);

            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}

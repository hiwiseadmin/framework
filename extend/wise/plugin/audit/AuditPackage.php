<?php

declare(strict_types=1);

namespace wise\plugin\audit;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * 待审计包（目录或 zip）的只读视图。
 *
 * 目的：让「目录」与「待上传的 zip」走同一套规则 —— 作者在本地既能审源码目录，
 * 也能审最终产物，避免「目录干净、zip 里混进 node_modules」这类问题。
 *
 * 约定：
 *  - 路径一律使用 `/` 分隔的相对路径；
 *  - 支持扁平（manifest.json 在根）与包裹（唯一顶层目录内）两种结构；
 *  - `vendor/` `node_modules/` `runtime/` `.git/` 等目录不参与规则扫描（仅记录提示）。
 */
final class AuditPackage
{
    public const TYPE_DIR = 'dir';

    public const TYPE_ZIP = 'zip';

    /**
     * 不参与规则扫描的目录（任意层级同名目录都跳过）。
     */
    public const IGNORED_DIRS = ['vendor', 'node_modules', 'runtime', '.git', '.idea', '.vscode'];

    /** 单文件读取上限（2MB）：manifest / 控制器远超此值本身就不正常 */
    private const MAX_READ_BYTES = 2097152;

    private string $type;

    private string $path;

    /** 包裹结构的顶层目录名；扁平结构为空串 */
    private string $rootDir = '';

    /** @var array<string, int> 相对路径 => 字节数 */
    private array $files = [];

    /** @var array<int, string> 原始条目名（保留 `..` / 绝对路径等危险形态，供路径规则判定） */
    private array $rawNames = [];

    private int $totalSize = 0;

    private bool $hasIgnoredDirs = false;

    /** @var array<int, string> 顶层目录名（用于「多个根目录」判定） */
    private array $topLevelDirs = [];

    private function __construct(string $type, string $path)
    {
        $this->type = $type;
        $this->path = $path;
    }

    /**
     * 打开待审计目标。
     *
     * @param string $path 插件目录，或插件 zip 包
     *
     * @throws RuntimeException 路径不存在 / 不是目录或 zip / 压缩包无法打开
     */
    public static function open(string $path): self
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        if (is_dir($path)) {
            $package = new self(self::TYPE_DIR, $path);
            $package->scanDirectory();

            return $package;
        }

        if (is_file($path)) {
            $package = new self(self::TYPE_ZIP, $path);
            $package->scanZip();

            return $package;
        }

        throw new RuntimeException("路径不存在：{$path}");
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isDir(): bool
    {
        return $this->type === self::TYPE_DIR;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * 展示用标签：目录名或 zip 文件名。
     */
    public function label(): string
    {
        $base = basename($this->path);

        return $base !== '' ? $base : $this->path;
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * 结构类型：flat（manifest 在根）/ wrapped（唯一顶层目录内）/ broken（都没有）。
     */
    public function structureType(): string
    {
        if ($this->exists($this->rootDir . 'manifest.json')) {
            return $this->rootDir === '' ? 'flat' : 'wrapped';
        }

        return 'broken';
    }

    public function manifestPath(): string
    {
        return $this->rootDir . 'manifest.json';
    }

    /**
     * 相对路径是否存在于包内。
     */
    public function exists(string $relative): bool
    {
        $relative = $this->normalize($relative);

        if ($relative === '' || $this->isTraversal($relative)) {
            return false;
        }

        if (is_dir($this->path) && $this->type === self::TYPE_DIR && is_dir($this->path . '/' . $relative)) {
            return true;
        }

        return isset($this->files[$relative]);
    }

    /**
     * 读取文本内容（超出上限或读取失败返回 null）。
     */
    public function read(string $relative): ?string
    {
        $relative = $this->normalize($relative);

        if ($relative === '' || $this->isTraversal($relative)) {
            return null;
        }

        if (($this->files[$relative] ?? 0) > self::MAX_READ_BYTES) {
            return null;
        }

        if ($this->type === self::TYPE_DIR) {
            $full = $this->path . '/' . $relative;

            if (!is_file($full) || !is_readable($full)) {
                return null;
            }

            $content = @file_get_contents($full);

            return $content === false ? null : $content;
        }

        $zip = $this->openZip();
        $content = $zip->getFromName($relative);
        $zip->close();

        return $content === false ? null : $content;
    }

    /**
     * 文件清单（默认排除 vendor / node_modules 等目录）。
     *
     * @return array<string, int> 相对路径 => 字节数
     */
    public function files(bool $includeIgnored = false): array
    {
        if ($includeIgnored) {
            return $this->files;
        }

        $result = [];

        foreach ($this->files as $relative => $size) {
            if (!$this->isIgnored($relative)) {
                $result[$relative] = $size;
            }
        }

        return $result;
    }

    public function totalSize(): int
    {
        return $this->totalSize;
    }

    public function hasIgnoredDirs(): bool
    {
        return $this->hasIgnoredDirs;
    }

    /**
     * @return array<int, string>
     */
    public function topLevelDirs(): array
    {
        return $this->topLevelDirs;
    }

    /**
     * 原始条目名（未做路径归一），用于识别路径穿越、绝对路径等危险形态。
     *
     * @return array<int, string>
     */
    public function rawNames(): array
    {
        return $this->rawNames;
    }

    public function isIgnored(string $relative): bool
    {
        $segments = explode('/', $this->normalize($relative));

        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::IGNORED_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    private function scanDirectory(): void
    {
        $root = $this->path;

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                if ($current->isDir()) {
                    return !in_array(strtolower($current->getFilename()), self::IGNORED_DIRS, true);
                }

                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file) {
            $full = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($full, strlen(str_replace('\\', '/', $root))), '/');

            if ($relative === '') {
                continue;
            }

            $this->files[$relative] = (int) $file->getSize();
            $this->rawNames[]      = $relative;
            $this->totalSize       += (int) $file->getSize();
        }

        foreach (self::IGNORED_DIRS as $ignored) {
            if (is_dir($root . '/' . $ignored)) {
                $this->hasIgnoredDirs = true;
                break;
            }
        }

        $this->detectRootDir();
    }

    private function scanZip(): void
    {
        $zip = $this->openZip();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $raw  = (string) $stat['name'];
            $name = $this->normalize($raw);

            if ($name === '' || str_ends_with($raw, '/')) {
                continue;
            }

            $this->files[$name]  = (int) ($stat['size'] ?? 0);
            $this->rawNames[]    = $raw;
        }

        $zip->close();

        $this->totalSize = (int) (filesize($this->path) ?: 0);
        $this->detectRootDir();

        foreach ($this->files as $relative => $size) {
            if ($this->isIgnored($relative)) {
                $this->hasIgnoredDirs = true;
                break;
            }
        }
    }

    /**
     * 判定扁平 / 包裹结构。
     *
     * 规则（与 wise-market 的压缩包结构判定一致）：
     *  1. 根目录有 manifest.json → flat；
     *  2. 否则只有一个顶层目录，且该目录内有 manifest.json → wrapped；
     *  3. 其余保持 broken（由规则层报错）。
     */
    private function detectRootDir(): void
    {
        $this->topLevelDirs = [];
        $rootFiles = [];

        foreach ($this->files as $relative => $size) {
            $segments = explode('/', $relative);

            if (count($segments) === 1) {
                $rootFiles[$segments[0]] = true;
                continue;
            }

            $this->topLevelDirs[$segments[0]] = true;
        }

        $this->topLevelDirs = array_keys($this->topLevelDirs);
        sort($this->topLevelDirs);

        if (isset($rootFiles['manifest.json'])) {
            $this->rootDir = '';

            return;
        }

        if (count($this->topLevelDirs) === 1) {
            $candidate = $this->topLevelDirs[0];

            if (isset($this->files[$candidate . '/manifest.json'])) {
                $this->rootDir = $candidate . '/';
            }
        }
    }

    private function openZip(): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('缺少 ZipArchive 扩展，无法审计 zip 包');
        }

        $zip = new ZipArchive();

        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('无法打开压缩包：' . $this->path);
        }

        return $zip;
    }

    private function normalize(string $relative): string
    {
        return trim(str_replace('\\', '/', $relative), '/');
    }

    private function isTraversal(string $relative): bool
    {
        return in_array('..', explode('/', $relative), true) || str_starts_with($relative, '/');
    }
}

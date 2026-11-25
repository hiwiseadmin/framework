<?php

declare(strict_types=1);

namespace wise\plugin\audit;

use wise\plugin\compat\FrameworkCompatibility;
use wise\plugin\compat\VersionRange;
use wise\plugin\config\PluginConfigSchema;

/**
 * 本地预检执行器（纯静态：不连数据库、不联网、不写盘）。
 *
 * 覆盖范围严格限定为三类：
 *  A. 结构：manifest 位置、包根结构、禁止扩展名、路径穿越、包体积；
 *  B. 清单：字段类型 / 必填 / 菜单 / 权限 / 配置 / 静态发布 / 服务提供者；
 *  C. 代码：**低敏正则提示**（一律 warn，不阻断）。
 *
 * 不覆盖（服务端职责）：AST 深度扫描、后门判定、数据外回传、抄袭与许可比对、人工审核。
 */
final class AuditRunner
{
    /** 服务端上传上限（50MB），本地提前拦截 */
    private const MAX_PACKAGE_BYTES = 52428800;

    /** 禁止分发的扩展名（与 wise-market 的压缩包校验保持一致） */
    private const FORBIDDEN_EXTENSIONS = [
        'php3', 'php4', 'php5', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'com', 'vbs', 'ps1',
        'sh', 'bash', 'cgi', 'pl', 'py',
    ];

    /** 已知清单字段（用于拼写错误提示） */
    private const KNOWN_FIELDS = [
        'name', 'ident', 'title', 'display_name', 'version', 'description', 'author',
        'develop', 'service_provider', 'dependencies', 'require', 'packages', 'icon',
        'menu', 'menus', 'permissions', 'config', 'config_schema', 'config_tabs',
        'config_groups', 'slots', 'vendor', 'api', 'publish', 'capabilities', 'enabled', 'seed',
        'compatible_with',
    ];

    /** 代码提示总量上限（避免生成代码/大包刷屏） */
    private const MAX_CODE_ISSUES = 200;

    /** @var array<int, array{id: string, pattern: string, message: string, hint: string}> */
    private array $regexRules;

    /**
     * @param array<int, array{id: string, pattern: string, message: string, hint: string}> $regexRules
     */
    public function __construct(array $regexRules)
    {
        $this->regexRules = $regexRules;
    }

    /**
     * @return array<int, AuditIssue>
     */
    public function run(AuditPackage $package): array
    {
        $issues = [];

        if (!$this->checkStructure($package, $issues)) {
            return $issues;
        }

        $manifest = $this->checkManifest($package, $issues);

        if ($manifest !== null) {
            $this->checkCode($package, $issues);
        }

        return $issues;
    }

    /**
     * 结构检查。返回 false 表示存在「无法继续」的问题（如 manifest 缺失）。
     *
     * @param array<int, AuditIssue> $issues
     */
    private function checkStructure(AuditPackage $package, array &$issues): bool
    {
        $manifestPath = $package->manifestPath();

        if (!$package->exists($manifestPath)) {
            if (count($package->topLevelDirs()) > 1) {
                $this->add(
                    $issues,
                    AuditRuleSet::STRUCTURE_MULTIPLE_ROOTS,
                    '包根目录包含多个顶层目录且根目录没有 manifest.json：' . implode(' / ', array_slice($package->topLevelDirs(), 0, 6)),
                    '',
                    0,
                    '请把插件文件放到唯一目录内，或让 manifest.json 直接位于包根目录'
                );
            } else {
                $this->add(
                    $issues,
                    AuditRuleSet::STRUCTURE_MANIFEST_MISSING,
                    '未找到 manifest.json',
                    '',
                    0,
                    'manifest.json 是插件发现与元数据的唯一入口，必须位于插件根目录'
                );
            }

            return false;
        }

        $this->checkRawPaths($package, $issues);
        $this->checkExtensions($package, $issues);

        if ($package->totalSize() > self::MAX_PACKAGE_BYTES) {
            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_PACKAGE_TOO_LARGE,
                sprintf('包体积约 %.1fMB，超过 50MB 上限', $package->totalSize() / 1048576),
                '',
                0,
                '请排除 node_modules / 测试数据 / 大体积静态资源'
            );
        }

        if ($package->hasIgnoredDirs()) {
            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_VENDOR_DIR,
                '包含 vendor / node_modules / runtime 等目录（已跳过扫描）',
                '',
                0,
                '依赖目录不应随包分发；请确认打包时已排除'
            );
        }

        return true;
    }

    /**
     * 路径穿越 / 绝对路径检查。
     *
     * 用原始条目名判定：`AuditPackage` 在读文件时会归一化路径，
     * 但危险形态恰恰藏在归一化之前的字符串里。
     *
     * @param array<int, AuditIssue> $issues
     */
    private function checkRawPaths(AuditPackage $package, array &$issues): void
    {
        $reported = 0;

        foreach ($package->rawNames() as $raw) {
            $name = str_replace('\\', '/', $raw);
            $bad  = false;

            if (in_array('..', explode('/', $name), true)) {
                $bad = true;
            } elseif (str_starts_with($name, '/')) {
                $bad = true;
            } elseif (preg_match('~^[A-Za-z]:/~', $name) === 1) {
                $bad = true;
            } elseif (str_contains($name, "\0")) {
                $bad = true;
            }

            if (!$bad) {
                continue;
            }

            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_UNSAFE_PATH,
                '检测到不安全路径：' . $name,
                '',
                0,
                '解压时会写到插件目录之外，必须重命名或重新打包'
            );

            if (++$reported >= 5) {
                break;
            }
        }
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    private function checkExtensions(AuditPackage $package, array &$issues): void
    {
        $reported = 0;

        foreach (array_keys($package->files()) as $relative) {
            $ext = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));

            if (!in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                continue;
            }

            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_FORBIDDEN_EXTENSION,
                '禁止分发的文件类型：' . $relative,
                '',
                0,
                '请从包中移除；服务端会直接拒绝此类文件'
            );

            if (++$reported >= 10) {
                break;
            }
        }
    }

    /**
     * 清单检查。返回解析后的 manifest（无法解析时返回 null）。
     *
     * @param array<int, AuditIssue> $issues
     *
     * @return array<string, mixed>|null
     */
    private function checkManifest(AuditPackage $package, array &$issues): ?array
    {
        $raw = $package->read($package->manifestPath());

        if ($raw === null) {
            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_MANIFEST_INVALID,
                'manifest.json 无法读取（可能超过 2MB 或权限不足）',
                'manifest.json'
            );

            return null;
        }

        $manifest = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_MANIFEST_INVALID,
                'manifest.json 不是合法 JSON：' . json_last_error_msg(),
                'manifest.json'
            );

            return null;
        }

        if (!is_array($manifest) || (array_is_list($manifest) && $manifest !== [])) {
            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_MANIFEST_INVALID,
                'manifest.json 顶层必须是对象',
                'manifest.json'
            );

            return null;
        }

        $name = trim((string) ($manifest['name'] ?? ''));

        if ($name === '') {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_REQUIRED_FIELD,
                'manifest.json 缺少必需字段：name',
                'manifest.json',
                0,
                'name 同时是插件标识与命名空间片段'
            );

            return null;
        }

        $this->checkIdentity($package, $manifest, $name, $issues);
        $this->checkBasicFields($manifest, $name, $issues);
        $this->checkServiceProvider($package, $manifest, $name, $issues);
        $this->checkDependencies($manifest, $name, $issues);
        $this->checkPackages($manifest, $issues);
        $this->checkMenus($manifest, $issues);
        $this->checkPermissions($manifest, $issues);
        $this->checkConfig($manifest, $issues);
        $this->checkPublish($package, $manifest, $issues);
        $this->checkMiscTypes($manifest, $issues);
        $this->checkUnknownFields($manifest, $issues);

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkIdentity(AuditPackage $package, array $manifest, string $name, array &$issues): void
    {
        if (preg_match('~^[a-z][a-z0-9_]{1,49}$~', $name) !== 1) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_NAME_FORMAT,
                'name 不符合规范：' . $name,
                'manifest.json',
                0,
                '要求：小写字母开头，仅含小写字母/数字/下划线，长度 2–50'
            );
        }

        $ident = trim((string) ($manifest['ident'] ?? ''));

        if ($ident !== '' && $ident !== $name) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_IDENT_MISMATCH,
                sprintf('ident(%s) 与 name(%s) 不一致', $ident, $name),
                'manifest.json',
                0,
                '框架按 name、市场按 ident，二者必须一致'
            );
        }

        $dirName = '';

        if ($package->isDir()) {
            $dirName = basename($package->path());
        } elseif ($package->rootDir() !== '') {
            $dirName = trim($package->rootDir(), '/');
        }

        if ($dirName !== '' && strtolower($dirName) !== strtolower($name)) {
            $this->add(
                $issues,
                AuditRuleSet::STRUCTURE_DIR_NAME_MISMATCH,
                sprintf('目录名(%s) 与插件标识(%s) 不一致', $dirName, $name),
                '',
                0,
                '建议保持一致，便于安装、排查与市场展示'
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkBasicFields(array $manifest, string $name, array &$issues): void
    {
        foreach (['version', 'author'] as $field) {
            if (trim((string) ($manifest[$field] ?? '')) === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_REQUIRED_FIELD,
                    'manifest.json 缺少必需字段：' . $field,
                    'manifest.json'
                );
            }
        }

        $version = trim((string) ($manifest['version'] ?? ''));

        if ($version !== '' && preg_match('~^\d+\.\d+(\.\d+)?(-[A-Za-z0-9.]+)?$~', $version) !== 1) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_VERSION_FORMAT,
                '版本号格式不规范：' . $version,
                'manifest.json',
                0,
                '建议使用语义化版本（如 1.0.0 / 1.2.0-beta.1）'
            );
        }

        if (trim((string) ($manifest['title'] ?? '')) === '') {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_TITLE_MISSING,
                '缺少显示名称 title（后台会退化为目录名 ' . $name . '）',
                'manifest.json'
            );
        }

        if (trim((string) ($manifest['description'] ?? '')) === '') {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_DESCRIPTION_MISSING,
                '缺少 description',
                'manifest.json',
                0,
                '描述是市场详情页与审核判断的第一手信息'
            );
        }

        if (($manifest['develop'] ?? false) === true) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_DEVELOP_FLAG,
                'develop=true（开发模式标记）',
                'manifest.json',
                0,
                '上架包建议移除该字段或设为 false'
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkServiceProvider(AuditPackage $package, array $manifest, string $name, array &$issues): void
    {
        $declared = trim((string) ($manifest['service_provider'] ?? ''));
        $class    = ltrim($declared !== '' ? $declared : 'plugin\\' . $name . '\\BootServiceProvider', '\\');
        $prefix   = 'plugin\\' . $name . '\\';

        if (!str_starts_with($class, $prefix)) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_SERVICE_PROVIDER_MISMATCH,
                'service_provider 不在约定命名空间内：' . $class,
                'manifest.json',
                0,
                '约定为 plugin\\' . $name . '\\...；无法按约定定位文件，本条仅提示'
            );

            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (!$package->exists($relative)) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_SERVICE_PROVIDER_MISSING,
                '服务提供者文件不存在：' . $relative,
                'manifest.json',
                0,
                '插件启用后不会启动；请补文件或修正 service_provider'
            );

            return;
        }

        $code = $package->read($relative);

        if ($code === null) {
            return;
        }

        $short = (string) substr(strrchr('\\' . $class, '\\'), 1);

        if (preg_match('~\b(?:final\s+|abstract\s+)?class\s+' . preg_quote($short, '~') . '\b~', $code) !== 1) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_SERVICE_PROVIDER_MISMATCH,
                sprintf('文件 %s 中未找到类 %s', $relative, $short),
                $relative,
                0,
                '类名与文件名必须一致，否则插件会静默不启动'
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkDependencies(array $manifest, string $name, array &$issues): void
    {
        if (!array_key_exists('dependencies', $manifest)) {
            return;
        }

        $dependencies = $manifest['dependencies'];

        if (!is_array($dependencies)) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_DEPENDENCY_INVALID,
                'dependencies 必须是对象',
                'manifest.json'
            );

            return;
        }

        foreach ($dependencies as $ident => $constraint) {
            $ident = (string) $ident;

            if ($ident === '' || !is_string($constraint)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_DEPENDENCY_INVALID,
                    sprintf('依赖项 %s 的版本约束必须是字符串', $ident === '' ? '(空标识)' : $ident),
                    'manifest.json'
                );

                continue;
            }

            if ($ident === $name) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_DEPENDENCY_SELF,
                    '插件依赖了自身：' . $name,
                    'manifest.json'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkPackages(array $manifest, array &$issues): void
    {
        if (!array_key_exists('packages', $manifest)) {
            return;
        }

        $packages = $manifest['packages'];

        if (!is_array($packages)) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_PACKAGE_INVALID,
                'packages 必须是对象',
                'manifest.json'
            );

            return;
        }

        foreach ($packages as $package => $spec) {
            $package = (string) $package;

            if ($package === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PACKAGE_INVALID,
                    'packages 中存在空的包名',
                    'manifest.json'
                );

                continue;
            }

            if (is_string($spec)) {
                continue;
            }

            if (!is_array($spec) || !is_string($spec['constraint'] ?? null)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PACKAGE_INVALID,
                    sprintf('库包依赖 %s 必须是版本约束字符串，或 {constraint, required} 对象', $package),
                    'manifest.json'
                );

                continue;
            }

            if (array_key_exists('required', $spec) && !is_bool($spec['required'])) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PACKAGE_INVALID,
                    sprintf('库包依赖 %s 的 required 必须是布尔值', $package),
                    'manifest.json'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkMenus(array $manifest, array &$issues): void
    {
        if (!array_key_exists('menus', $manifest)) {
            return;
        }

        if (!is_array($manifest['menus'])) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_MENU_INVALID,
                'menus 必须是数组',
                'manifest.json'
            );

            return;
        }

        $paths = [];
        $this->walkMenus($manifest['menus'], 'menus', $paths, $issues);
    }

    /**
     * @param array<mixed>           $nodes
     * @param array<string, bool>    $paths
     * @param array<int, AuditIssue> $issues
     */
    private function walkMenus(array $nodes, string $label, array &$paths, array &$issues, int $depth = 0): void
    {
        if ($depth > 8) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_MENU_INVALID,
                $label . ' 嵌套层级过深（>8）',
                'manifest.json'
            );

            return;
        }

        foreach ($nodes as $index => $node) {
            $nodeLabel = $label . '[' . $index . ']';

            if (!is_array($node)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_MENU_INVALID,
                    $nodeLabel . ' 必须是对象',
                    'manifest.json'
                );

                continue;
            }

            $name = trim((string) ($node['name'] ?? ''));

            if ($name === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_MENU_INVALID,
                    $nodeLabel . ' 缺少 name',
                    'manifest.json'
                );
            }

            $type = strtolower(trim((string) ($node['type'] ?? 'menu')));

            if (!in_array($type, ['dir', 'menu', 'button'], true)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_MENU_TYPE_UNKNOWN,
                    sprintf('%s 的 type=%s 不是常见值（dir/menu/button）', $nodeLabel, $type),
                    'manifest.json'
                );
            }

            $path = trim((string) ($node['path'] ?? ''));

            if ($type !== 'dir' && $path === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_MENU_INVALID,
                    sprintf('%s(%s) 缺少 path', $nodeLabel, $name !== '' ? $name : '未命名'),
                    'manifest.json'
                );
            }

            if ($path !== '') {
                if (isset($paths[$path])) {
                    $this->add(
                        $issues,
                        AuditRuleSet::MANIFEST_MENU_DUPLICATE_PATH,
                        '菜单路径重复：' . $path,
                        'manifest.json',
                        0,
                        '重复路径会产生重复菜单或权限点冲突'
                    );
                } else {
                    $paths[$path] = true;
                }
            }

            if (!array_key_exists('children', $node)) {
                continue;
            }

            if (!is_array($node['children'])) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_MENU_INVALID,
                    $nodeLabel . '.children 必须是数组',
                    'manifest.json'
                );

                continue;
            }

            $this->walkMenus($node['children'], $nodeLabel . '.children', $paths, $issues, $depth + 1);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkPermissions(array $manifest, array &$issues): void
    {
        if (!array_key_exists('permissions', $manifest)) {
            return;
        }

        if (!is_array($manifest['permissions'])) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_PERMISSION_INVALID,
                'permissions 必须是数组',
                'manifest.json'
            );

            return;
        }

        $seen = [];

        foreach ($manifest['permissions'] as $index => $permission) {
            $label = 'permissions[' . $index . ']';

            if (!is_array($permission)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PERMISSION_INVALID,
                    $label . ' 必须是对象',
                    'manifest.json'
                );

                continue;
            }

            $permissionName = trim((string) ($permission['name'] ?? ''));
            $ident          = trim((string) ($permission['ident'] ?? ''));

            if ($permissionName === '' || $ident === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PERMISSION_INVALID,
                    $label . ' 缺少 name 或 ident',
                    'manifest.json'
                );

                continue;
            }

            if (isset($seen[$ident])) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PERMISSION_DUPLICATE,
                    '权限点标识重复：' . $ident,
                    'manifest.json'
                );
            } else {
                $seen[$ident] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkConfig(array $manifest, array &$issues): void
    {
        if (!array_key_exists('config', $manifest)) {
            return;
        }

        $config = $manifest['config'];

        if (!is_array($config)) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_CONFIG_INVALID,
                'config 必须是数组（对象映射或列表）',
                'manifest.json'
            );

            return;
        }

        $isList = array_is_list($config);

        foreach ($config as $key => $def) {
            $label = $isList ? 'config[' . $key . ']' : 'config.' . (string) $key;
            $fieldKey = $isList ? trim((string) (is_array($def) ? ($def['name'] ?? '') : '')) : (string) $key;

            if ($fieldKey === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_CONFIG_INVALID,
                    $label . ' 缺少 name（该配置项会被框架静默跳过）',
                    'manifest.json'
                );

                continue;
            }

            if (str_starts_with($fieldKey, PluginConfigSchema::RESERVED_PREFIX)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_CONFIG_KEY_RESERVED,
                    '配置键使用了保留前缀：' . $fieldKey,
                    'manifest.json',
                    0,
                    '以 `__` 开头的键属于框架状态，不会作为业务配置'
                );
            }

            if (!is_array($def)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_CONFIG_INVALID,
                    $label . ' 的定义必须是对象',
                    'manifest.json'
                );

                continue;
            }

            $type = (string) ($def['type'] ?? 'string');

            if (!in_array($type, PluginConfigSchema::TYPES, true)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_CONFIG_TYPE_UNKNOWN,
                    sprintf('%s 的 type=%s 未知（会被降级为 string）', $label, $type),
                    'manifest.json',
                    0,
                    '可用类型：' . implode(' / ', PluginConfigSchema::TYPES)
                );

                continue;
            }

            if (in_array($type, ['select', 'radio', 'checkbox'], true) && empty($def['options'])) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_CONFIG_OPTIONS_MISSING,
                    sprintf('%s（%s）缺少 options', $label, $fieldKey),
                    'manifest.json',
                    0,
                    '选项类配置没有 options 时后台渲染为空'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkPublish(AuditPackage $package, array $manifest, array &$issues): void
    {
        if (!array_key_exists('publish', $manifest)) {
            return;
        }

        if (!is_array($manifest['publish'])) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_PUBLISH_INVALID,
                'publish 必须是 {源目录: 目标目录} 对象',
                'manifest.json'
            );

            return;
        }

        foreach ($manifest['publish'] as $source => $target) {
            $source = trim((string) $source);
            $target = trim((string) $target);

            if ($source === '' || $target === '') {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PUBLISH_INVALID,
                    'publish 存在空源目录或空目标目录',
                    'manifest.json'
                );

                continue;
            }

            if (str_contains(str_replace('\\', '/', $target), '..')) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PUBLISH_INVALID,
                    'publish 目标目录越出 public：' . $target,
                    'manifest.json',
                    0,
                    '目标必须落在 public/ 下（如 public/statics/plugins/xxx）'
                );

                continue;
            }

            if ($package->isDir() && !$package->exists($source)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_PUBLISH_SOURCE_MISSING,
                    'publish 源目录不存在：' . $source,
                    'manifest.json',
                    0,
                    '源目录缺失时不会复制任何资源，页面会 404'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkMiscTypes(array $manifest, array &$issues): void
    {
        foreach (['api', 'slots', 'vendor', 'capabilities'] as $field) {
            if (array_key_exists($field, $manifest) && !is_array($manifest[$field])) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_TYPE_INVALID,
                    sprintf('%s 必须是对象/数组', $field),
                    'manifest.json'
                );
            }
        }

        if (isset($manifest['capabilities']['requires']) && !is_array($manifest['capabilities']['requires'])) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_CAPABILITY_INVALID,
                'capabilities.requires 必须是数组',
                'manifest.json'
            );
        }

        if (array_key_exists('icon', $manifest) && !is_string($manifest['icon']) && !is_array($manifest['icon'])) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_TYPE_INVALID,
                'icon 必须是字符串或对象',
                'manifest.json'
            );
        }

        if (array_key_exists('compatible_with', $manifest)) {
            $declared = $manifest['compatible_with'];
            $ranges   = FrameworkCompatibility::normalize($declared);

            if ($ranges === []) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_TYPE_INVALID,
                    'compatible_with 必须是字符串或字符串数组：' . (is_scalar($declared) ? (string) $declared : gettype($declared)),
                    'manifest.json',
                    0,
                    '例："^1.0" 或 ["^1.0", "^1.1"]；留空/不写表示未声明（不拦截）'
                );
            } else {
                foreach ($ranges as $range) {
                    if (!VersionRange::isValid($range)) {
                        $this->add(
                            $issues,
                            AuditRuleSet::MANIFEST_TYPE_INVALID,
                            'compatible_with 的范围语法非法：' . $range,
                            'manifest.json',
                            0,
                            '支持 ^1.0 / ~1.2 / 1.2.* / >=1.0 <2.0 / ^1.0 | ^2.0（与服务端同一套语法）'
                        );

                        break;
                    }
                }
            }
        }

        if (array_key_exists('seed', $manifest)) {
            $seed = $manifest['seed'];

            if (!is_string($seed) || !in_array(strtolower(trim($seed)), ['required', 'demo', 'none'], true)) {
                $this->add(
                    $issues,
                    AuditRuleSet::MANIFEST_TYPE_INVALID,
                    'seed 只能是 required / demo / none：' . (is_scalar($seed) ? (string) $seed : gettype($seed)),
                    'manifest.json',
                    0,
                    'required=基线数据（装完即执行）；demo=演示数据（--with-demo 才执行）；none=不执行'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, AuditIssue> $issues
     */
    private function checkUnknownFields(array $manifest, array &$issues): void
    {
        $unknown = array_values(array_diff(array_keys($manifest), self::KNOWN_FIELDS));

        foreach (array_slice($unknown, 0, 5) as $field) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_UNKNOWN_FIELD,
                '未知字段：' . (string) $field,
                'manifest.json',
                0,
                '框架不会读取该字段；若为拼写错误请修正（如 menu → menus）'
            );
        }

        if (count($unknown) > 5) {
            $this->add(
                $issues,
                AuditRuleSet::MANIFEST_UNKNOWN_FIELD,
                sprintf('另有 %d 个未知字段未列出', count($unknown) - 5),
                'manifest.json'
            );
        }
    }

    /**
     * 代码层低敏正则提示（只 warn，不阻断）。
     *
     * @param array<int, AuditIssue> $issues
     */
    private function checkCode(AuditPackage $package, array &$issues): void
    {
        $count = 0;

        foreach (array_keys($package->files()) as $relative) {
            if (strtolower((string) pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $code = $package->read($relative);

            if ($code === null) {
                continue;
            }

            $lines = preg_split('~\R~', $code) ?: [];

            foreach ($lines as $index => $line) {
                if (stripos($line, '@audit-ignore') !== false) {
                    continue;
                }

                foreach ($this->regexRules as $rule) {
                    if (preg_match($rule['pattern'], $line) !== 1) {
                        continue;
                    }

                    $snippet = trim($line);

                    if (mb_strlen($snippet) > 80) {
                        $snippet = mb_substr($snippet, 0, 77) . '...';
                    }

                    $this->add(
                        $issues,
                        $rule['id'],
                        $rule['message'] . '：' . $snippet,
                        $relative,
                        $index + 1,
                        $rule['hint']
                    );

                    if (++$count >= self::MAX_CODE_ISSUES) {
                        return;
                    }
                }
            }
        }
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    private function add(
        array &$issues,
        string $rule,
        string $message,
        string $file = '',
        int $line = 0,
        string $hint = ''
    ): void {
        $meta  = AuditRuleSet::rule($rule);
        $level = $meta['level'] ?? AuditIssue::WARN;

        if (str_starts_with($rule, 'code.regex.')) {
            $level = AuditIssue::WARN;
        }

        $issues[] = new AuditIssue($rule, $level, $message, $file, $line, $hint);
    }
}

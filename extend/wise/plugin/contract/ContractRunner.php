<?php

declare(strict_types=1);

namespace wise\plugin\contract;

use ParseError;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\support\menu\MenuSchema;

/**
 * 契约检查执行器（`plugin:test --unit` 的引擎）。
 *
 * **纯静态、零依赖、不连库、不联网、不执行包内代码**：
 *  - 语法检查用 `token_get_all(..., TOKEN_PARSE)`（只解析，不 include）；
 *  - 结构/清单/数据库/路由一律按文件内容做确定性判定。
 *
 * 与 `plugin:audit` 的分工（不要互相替代）：
 *  - `plugin:audit`：**包能不能被上传** —— 结构 / 清单 / 低敏正则（服务端上架闸门口径）；
 *  - `plugin:test` ：**插件写得对不对** —— namespace、provider、菜单/权限、迁移/种子命名、
 *    publish 映射、路由指向（作者自检口径）。
 * 两者都做的一小部分（manifest 存在/可解析）刻意保留：`plugin:test` 必须能独立运行。
 *
 * 未知 case id（外部用例集比本地新）→ 记入 `skipped`，**不判失败**：
 * 那是「用例版本不一致」，不是插件的问题（见 {@see ContractCaseSet} 类注释）。
 */
final class ContractRunner
{
    /** case id → 检查器方法（未列出的 case 一律 skipped） */
    protected const METHODS = [
        'contract.manifest_present'    => 'checkManifestPresent',
        'contract.manifest_parse'      => 'checkManifestParse',
        'contract.php_syntax'          => 'checkPhpSyntax',
        'contract.namespace_prefix'    => 'checkNamespacePrefix',
        'contract.provider_file'       => 'checkProviderFile',
        'contract.menus_shape'         => 'checkMenusShape',
        'contract.menus_path_scope'    => 'checkMenusPathScope',
        'contract.permissions_shape'   => 'checkPermissionsShape',
        'contract.migration_filename'  => 'checkMigrationFilename',
        'contract.migration_class'     => 'checkMigrationClass',
        'contract.migration_body'      => 'checkMigrationBody',
        'contract.seed_class'          => 'checkSeedClass',
        'contract.publish_map'         => 'checkPublishMap',
        'contract.route_targets'       => 'checkRouteTargets',
        'contract.caseset_version'     => 'checkCasesetVersion',
    ];

    /** 必须有 `plugin\{ident}\` 命名空间的目录（route/ 等无命名空间的文件不在内） */
    protected const NAMESPACED_DIRS = [
        'controller', 'model', 'validate', 'service', 'logic',
        'middleware', 'event', 'job', 'listener', 'command', 'provider',
    ];

    protected ?array $manifest = null;

    protected bool $manifestLoaded = false;

    /**
     * @param array<string, array{group:string, level:string, title:string}> $cases
     */
    public function __construct(
        protected array $cases,
        protected string $ident,
        protected bool $casesetMismatch = false,
        protected string $casesetVersion = '',
        protected string $builtinVersion = ''
    ) {
    }

    /**
     * 执行全部用例。
     *
     * @return array{
     *     issues: array<int, AuditIssue>,
     *     checks: int,
     *     skipped: array<string, string>
     * }
     */
    public function run(AuditPackage $package): array
    {
        $issues  = [];
        $checks  = 0;
        $skipped = [];

        foreach ($this->cases as $id => $meta) {
            $method = self::METHODS[$id] ?? null;

            if ($method === null) {
                $skipped[$id] = '本地未实现该用例（用例集版本与引擎不一致）';

                continue;
            }

            $checks++;

            foreach ($this->{$method}($package) as $issue) {
                $issues[] = $issue;
            }
        }

        return ['issues' => $issues, 'checks' => $checks, 'skipped' => $skipped];
    }


    protected function checkManifestPresent(AuditPackage $package): array
    {
        if ($package->structureType() !== 'broken') {
            return [];
        }

        return [$this->issue(
            'contract.manifest_present',
            '未找到 manifest.json（根目录或唯一顶层目录内都没有）',
            '',
            0,
            '包结构需为 flat（manifest 在根）或 wrapped（单层目录内有 manifest）'
        )];
    }

    protected function checkManifestParse(AuditPackage $package): array
    {
        if ($package->structureType() === 'broken') {
            return [];
        }

        $relative = $package->manifestPath();
        $raw      = $package->read($relative);

        if ($raw === null) {
            return [$this->issue('contract.manifest_parse', 'manifest.json 不可读或超过读取上限', $relative)];
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [$this->issue('contract.manifest_parse', 'JSON 解析失败：' . json_last_error_msg(), $relative)];
        }

        if (!is_array($data) || array_is_list($data)) {
            return [$this->issue('contract.manifest_parse', 'manifest.json 必须是 JSON 对象（不能是数组/标量）', $relative)];
        }

        return [];
    }

    protected function checkPhpSyntax(AuditPackage $package): array
    {
        $issues = [];

        foreach (array_keys($package->files()) as $relative) {
            if (!str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $code = $package->read($relative);

            if ($code === null) {
                continue;
            }

            try {
                $tokens = token_get_all($code, TOKEN_PARSE);
                unset($tokens);
            } catch (ParseError $e) {
                $issues[] = $this->issue(
                    'contract.php_syntax',
                    '语法错误：' . $e->getMessage(),
                    $relative,
                    $e->getLine()
                );
            }
        }

        return $issues;
    }

    protected function checkNamespacePrefix(AuditPackage $package): array
    {
        $issues = [];
        $prefix = 'plugin\\' . $this->ident . '\\';

        foreach (array_keys($package->files()) as $relative) {
            if (!str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $segments = explode('/', $relative);

            if (count($segments) < 2 || !in_array(strtolower($segments[0]), self::NAMESPACED_DIRS, true)) {
                continue;
            }

            $code = $package->read($relative);

            if ($code === null) {
                continue;
            }

            if (preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $code, $m) !== 1) {
                $issues[] = $this->issue(
                    'contract.namespace_prefix',
                    '缺少 namespace 声明',
                    $relative,
                    0,
                    '插件源码应声明 ' . rtrim($prefix, '\\')
                );

                continue;
            }

            $namespace = ltrim($m[1], '\\');

            if (stripos($namespace . '\\', $prefix) !== 0 && strcasecmp($namespace, rtrim($prefix, '\\')) !== 0) {
                $issues[] = $this->issue(
                    'contract.namespace_prefix',
                    sprintf('namespace 为 %s，应以 %s 开头', $namespace, rtrim($prefix, '\\')),
                    $relative
                );
            }
        }

        return $issues;
    }

    protected function checkProviderFile(AuditPackage $package): array
    {
        $manifest = $this->manifest($package);

        if ($manifest === null) {
            return [];
        }

        $class  = ltrim(trim((string) ($manifest['service_provider'] ?? '')), '\\');
        $prefix = 'plugin\\' . $this->ident . '\\';

        if ($class === '') {
            $class = $prefix . 'BootServiceProvider';
        }

        if (!str_starts_with($class, $prefix)) {
            return [];
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (!$this->pathExists($package, $relative)) {
            return [$this->issue(
                'contract.provider_file',
                'service_provider 指向的类文件不存在：' . $relative,
                $relative,
                0,
                'manifest.service_provider = ' . $class
            )];
        }

        $code = $package->read($relative);

        if ($code === null) {
            return [];
        }

        $short = substr($class, (int) strrpos($class, '\\') + 1);

        if (preg_match('/\bclass\s+' . preg_quote($short, '/') . '\b/', $code) !== 1) {
            return [$this->issue(
                'contract.provider_file',
                sprintf('文件里没有声明类 %s（类名需与 manifest 声明一致）', $short),
                $relative
            )];
        }

        return [];
    }


    protected function checkMenusShape(AuditPackage $package): array
    {
        $manifest = $this->manifest($package);

        if ($manifest === null || !array_key_exists('menus', $manifest)) {
            return [];
        }

        $menus = $manifest['menus'];

        if (!is_array($menus)) {
            return [$this->issue('contract.menus_shape', 'menus 必须是数组', $package->manifestPath())];
        }

        $issues = [];
        $this->validateMenuNodes($menus, '', $issues);

        return $issues;
    }

    /**
     * @param array<int|string, mixed>  $nodes
     * @param array<int, AuditIssue>    $issues
     */
    protected function validateMenuNodes(array $nodes, string $path, array &$issues): void
    {
        foreach ($nodes as $index => $node) {
            $where = $path === '' ? 'menus[' . $index . ']' : $path . '[' . $index . ']';

            if (!is_array($node)) {
                $issues[] = $this->issue('contract.menus_shape', $where . ' 不是对象');

                continue;
            }

            $name = trim((string) ($node['name'] ?? ''));

            if ($name === '') {
                $issues[] = $this->issue('contract.menus_shape', $where . ' 缺少 name');
            }

            if (array_key_exists('type', $node)) {
                $type = (string) $node['type'];

                if (!in_array($type, ['dir', 'menu', 'button'], true)) {
                    $issues[] = $this->issue(
                        'contract.menus_shape',
                        sprintf('%s 的 type 非法：%s（可选 dir / menu / button）', $where, $type)
                    );
                }
            }

            if (array_key_exists('path', $node)) {
                $nodePath = $node['path'];

                if (!is_string($nodePath)) {
                    $issues[] = $this->issue('contract.menus_shape', $where . ' 的 path 必须是字符串');
                } elseif ($nodePath !== '' && !str_starts_with($nodePath, '/')) {
                    $issues[] = $this->issue(
                        'contract.menus_shape',
                        sprintf('%s 的 path 应以 / 开头：%s', $where, $nodePath)
                    );
                }
            }

            foreach (MenuSchema::CHILD_KEYS as $childKey) {
                if (!array_key_exists($childKey, $node)) {
                    continue;
                }

                if (!is_array($node[$childKey])) {
                    $issues[] = $this->issue('contract.menus_shape', $where . ' 的 ' . $childKey . ' 必须是数组');

                    continue;
                }

                $children = MenuSchema::childrenOf($node);

                if ($children !== []) {
                    $this->validateMenuNodes($children, $where . '.' . $childKey, $issues);
                }

                break;
            }
        }
    }

    protected function checkMenusPathScope(AuditPackage $package): array
    {
        $manifest = $this->manifest($package);

        if ($manifest === null || !isset($manifest['menus']) || !is_array($manifest['menus'])) {
            return [];
        }

        $issues = [];
        $this->collectMenuPathIssues($manifest['menus'], $issues);

        return $issues;
    }

    /**
     * @param array<int|string, mixed> $nodes
     * @param array<int, AuditIssue>   $issues
     */
    protected function collectMenuPathIssues(array $nodes, array &$issues): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $path = isset($node['path']) && is_string($node['path']) ? $node['path'] : '';

            if ($path !== '' && !str_contains($path, $this->ident)) {
                $issues[] = $this->issue(
                    'contract.menus_path_scope',
                    '菜单 path 未包含插件标识：' . $path,
                    '',
                    0,
                    '常规写法是 /plugin/' . $this->ident . '/...；若为外部路由可忽略'
                );
            }

            $children = MenuSchema::childrenOf($node);

            if ($children !== []) {
                $this->collectMenuPathIssues($children, $issues);
            }
        }
    }

    protected function checkPermissionsShape(AuditPackage $package): array
    {
        $manifest = $this->manifest($package);

        if ($manifest === null || !array_key_exists('permissions', $manifest)) {
            return [];
        }

        $permissions = $manifest['permissions'];

        if (!is_array($permissions)) {
            return [$this->issue('contract.permissions_shape', 'permissions 必须是数组', $package->manifestPath())];
        }

        $issues = [];
        $seen   = [];

        foreach ($permissions as $index => $permission) {
            if (!is_array($permission)) {
                $issues[] = $this->issue('contract.permissions_shape', 'permissions[' . $index . '] 不是对象');

                continue;
            }

            $ident = trim((string) ($permission['ident'] ?? ''));

            if ($ident === '') {
                $issues[] = $this->issue('contract.permissions_shape', 'permissions[' . $index . '] 缺少 ident');

                continue;
            }

            if (preg_match('/^[A-Za-z0-9_.:*\-]+$/', $ident) !== 1) {
                $issues[] = $this->issue(
                    'contract.permissions_shape',
                    sprintf('permissions[%d] 的 ident 含空白或非法字符：%s', $index, $ident)
                );

                continue;
            }

            if (isset($seen[$ident])) {
                $issues[] = $this->issue('contract.permissions_shape', 'permissions 的 ident 重复：' . $ident);

                continue;
            }

            $seen[$ident] = true;
        }

        return $issues;
    }


    protected function checkMigrationFilename(AuditPackage $package): array
    {
        $issues = [];

        foreach (array_keys($package->files()) as $relative) {
            if (!$this->inDir($relative, 'database/migrations') || !str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $base = basename($relative, '.php');

            if (preg_match('/^\d{14}_[a-z0-9_]+$/', $base) !== 1) {
                $issues[] = $this->issue(
                    'contract.migration_filename',
                    '迁移文件名不符合规范：' . basename($relative),
                    $relative,
                    0,
                    '应为 {14 位时间戳}_{snake_case}.php（plugin:migrate:create 生成）'
                );
            }
        }

        return $issues;
    }

    protected function checkMigrationClass(AuditPackage $package): array
    {
        $issues = [];

        foreach (array_keys($package->files()) as $relative) {
            if (!$this->inDir($relative, 'database/migrations') || !str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $base = basename($relative, '.php');

            if (preg_match('/^\d{14}_(.+)$/', $base, $m) !== 1) {
                continue;
            }

            $expected = str_replace(' ', '', ucwords(str_replace('_', ' ', $m[1])));
            $code     = $package->read($relative);

            if ($code === null) {
                continue;
            }

            if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\b/', $code, $found) !== 1) {
                $issues[] = $this->issue('contract.migration_class', '未找到类声明', $relative);

                continue;
            }

            if (strcasecmp($found[1], $expected) !== 0) {
                $issues[] = $this->issue(
                    'contract.migration_class',
                    sprintf('类名 %s 与文件名不符（期望 %s）', $found[1], $expected),
                    $relative
                );
            }
        }

        return $issues;
    }

    protected function checkMigrationBody(AuditPackage $package): array
    {
        $issues = [];

        foreach (array_keys($package->files()) as $relative) {
            if (!$this->inDir($relative, 'database/migrations') || !str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $code = $package->read($relative);

            if ($code === null) {
                continue;
            }

            if (preg_match('/function\s+(up|change)\s*\(/', $code) !== 1) {
                $issues[] = $this->issue(
                    'contract.migration_body',
                    '迁移没有 up() 或 change() 方法',
                    $relative,
                    0,
                    '回滚语义建议同时提供 down() 或使用 change()'
                );
            }
        }

        return $issues;
    }

    protected function checkSeedClass(AuditPackage $package): array
    {
        $issues = [];

        foreach (array_keys($package->files()) as $relative) {
            if (!$this->inDir($relative, 'database/seeds') || !str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $base = basename($relative, '.php');
            $code = $package->read($relative);

            if ($code === null) {
                continue;
            }

            if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\b/', $code, $found) !== 1) {
                $issues[] = $this->issue('contract.seed_class', '未找到类声明', $relative);

                continue;
            }

            if (strcasecmp($found[1], $base) !== 0) {
                $issues[] = $this->issue(
                    'contract.seed_class',
                    sprintf('类名 %s 与文件名 %s 不一致', $found[1], $base),
                    $relative,
                    0,
                    'Seeder 类名按「文件名首字母大写」约定'
                );
            }
        }

        return $issues;
    }


    protected function checkPublishMap(AuditPackage $package): array
    {
        $manifest = $this->manifest($package);

        if ($manifest === null || !isset($manifest['publish']) || !is_array($manifest['publish'])) {
            return [];
        }

        $issues = [];

        foreach ($manifest['publish'] as $source => $target) {
            $source = trim((string) $source);
            $target = trim((string) $target);

            if ($source === '' || !$this->pathExists($package, $source)) {
                $issues[] = $this->issue(
                    'contract.publish_map',
                    'publish 源目录不存在：' . ($source === '' ? '(空)' : $source)
                );

                continue;
            }

            if ($target === '') {
                $issues[] = $this->issue('contract.publish_map', 'publish 目标为空（源：' . $source . '）');

                continue;
            }

            if (str_contains($target, '..')) {
                $issues[] = $this->issue('contract.publish_map', 'publish 目标不能包含 ..：' . $target);

                continue;
            }

            if (!str_starts_with($target, 'public/')) {
                $issues[] = $this->issue(
                    'contract.publish_map',
                    'publish 目标必须以 public/ 开头：' . $target,
                    '',
                    0,
                    '例：public/statics/plugins/' . $this->ident
                );
            }
        }

        return $issues;
    }

    protected function checkRouteTargets(AuditPackage $package): array
    {
        $issues = [];
        $prefix = 'plugin\\' . $this->ident . '\\';

        foreach (array_keys($package->files()) as $relative) {
            if (!$this->inDir($relative, 'route') || !str_ends_with(strtolower($relative), '.php')) {
                continue;
            }

            $code = $package->read($relative);

            if ($code === null) {
                continue;
            }

            if (preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_\\\\]*)@([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $code, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($matches as $hit) {
                $class  = str_replace('\\\\', '\\', $hit[1]);
                $action = $hit[2];

                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $controllerRelative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                if (!$this->pathExists($package, $controllerRelative)) {
                    $issues[] = $this->issue(
                        'contract.route_targets',
                        'route 声明的控制器文件不存在：' . $controllerRelative,
                        $relative,
                        0,
                        '声明为 ' . $class . '@' . $action
                    );
                }

            }
        }

        return $issues;
    }

    protected function checkCasesetVersion(AuditPackage $package): array
    {
        if (!$this->casesetMismatch) {
            return [];
        }

        return [$this->issue(
            'contract.caseset_version',
            sprintf('用例集版本 %s 与内置 %s 不一致', $this->casesetVersion, $this->builtinVersion),
            '',
            0,
            '未识别的 case 会被跳过（本地产物不作为上架依据）；请同步服务端导出的用例集'
        )];
    }


    /**
     * 解析后的 manifest（解析失败返回 null，由 manifest_parse 用例负责报错）。
     *
     * @return array<string, mixed>|null
     */
    protected function manifest(AuditPackage $package): ?array
    {
        if ($this->manifestLoaded) {
            return $this->manifest;
        }

        $this->manifestLoaded = true;

        if ($package->structureType() === 'broken') {
            return null;
        }

        $raw = $package->read($package->manifestPath());

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        return $this->manifest = $data;
    }

    /**
     * 包内是否存在该路径（文件或目录；目录在 zip 里表现为前缀）。
     */
    protected function pathExists(AuditPackage $package, string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        if ($relative === '') {
            return false;
        }

        if ($package->exists($relative)) {
            return true;
        }

        foreach (array_keys($package->files()) as $file) {
            if (str_starts_with($file, $relative . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 相对路径是否位于指定目录下（任意层级不参与，只看首段）。
     */
    protected function inDir(string $relative, string $dir): bool
    {
        return str_starts_with(str_replace('\\', '/', $relative), rtrim($dir, '/') . '/');
    }

    /**
     * 构造一条结果（级别取自用例集元数据，代码里不能临时降级）。
     */
    protected function issue(string $caseId, string $message, string $file = '', int $line = 0, string $hint = ''): AuditIssue
    {
        $level = $this->cases[$caseId]['level'] ?? AuditIssue::ERROR;

        return new AuditIssue($caseId, $level, $message, $file, $line, $hint);
    }
}

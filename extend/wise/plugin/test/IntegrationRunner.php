<?php

declare(strict_types=1);

namespace wise\plugin\test;

use think\facade\Db;
use wise\plugin\adapter\MigrationRunner;
use wise\plugin\audit\AuditIssue;
use wise\plugin\PluginManager;
use wise\plugin\scanner\PluginMetadata;

/**
 * 集成契约执行器（`plugin:test --mode=integration` 的引擎）。
 *
 * 走**真实生命周期**：安装（建表 + 菜单/权限 + 静态资源）→ 停用 → 启用 → 卸载，
 * 每一步都用「数据库里的既成事实」而不是「方法返回 true」来判定：
 *  - 安装：状态键 / 迁移记录 / 建出的表 / ws_menus 行 / ws_auth_permissions 行 / public 资源；
 *  - 卸载：迁移回滚后的**残留表**、菜单权限是否清空、资源是否删除、状态是否复位。
 *
 * ## 安全边界（必须由调用方保证）
 *
 * 本类会**建表、删表、写菜单权限、删 public 资源**，且没有任何「这是测试库吗」的判断 ——
 * 那是命令层的职责（`--expect-db` + 显式确认）。设计原则：**危险能力集中在被测对象上，
 * 库安全判定只放在唯一入口**，避免两处各判一半。
 *
 * ## 失败即清理
 *
 * 任一步出现 error 时，会尝试用 `uninstall(purge: true)` 把测试库恢复干净再返回，
 * 否则下次运行会从「上次装了一半」的状态开始，把真实问题掩盖成环境问题。
 * 清理本身失败只记 warn（不能因为清理失败再抛一次异常）。
 */
final class IntegrationRunner
{
    public function __construct(
        protected PluginManager $manager,
        protected ?MigrationRunner $migrations = null
    ) {
    }

    /**
     * 执行完整链路。
     *
     * @param PluginMetadata $metadata 扫描到的元数据（用于断言"声明了什么就应该落库什么"）
     * @param bool           $purge    卸载时用无痕模式（物理删除菜单/权限、清迁移记录、DROP 残留表）
     *
     * @return array{
     *     ok: bool,
     *     steps: array<int, array{id:string, title:string, ok:bool, ms:int, errors:int, warnings:int, details:array<int,string>}>,
     *     issues: array<int, AuditIssue>
     * }
     */
    public function run(string $ident, PluginMetadata $metadata, bool $purge = false): array
    {
        $steps  = [];
        $issues = [];

        $before  = count($issues);
        $start   = microtime(true);
        $details = [];

        try {
            if ($this->manager->isInstalled($ident, false)) {
                $details[] = '测试库中已存在安装状态：先执行一次无痕卸载，保证本次从「未安装」开始';

                $cleanup   = $this->manager->uninstall($ident, false, true);
                $details[] = '预清理：' . (string) ($cleanup['message'] ?? '已完成');
            } else {
                $details[] = '测试库中该插件未安装（符合预期起点）';
            }
        } catch (\Throwable $e) {
            $this->error($issues, 'integration.preflight', '前置清理失败：' . $this->describe($e));
        }

        $steps[] = $this->step('preflight', '前置检查（干净起点）', $start, $issues, $before, $details);

        if ($this->hasErrorSince($issues, $before)) {
            $this->emergencyCleanup($ident, $issues);

            return ['ok' => false, 'steps' => $steps, 'issues' => $issues];
        }

        $before  = count($issues);
        $start   = microtime(true);
        $details = [];

        try {
            $this->manager->install($ident);
            $this->assertInstalled($ident, $metadata, $issues, $details);
        } catch (\Throwable $e) {
            $this->error($issues, 'integration.install', '安装失败：' . $this->describe($e));
        }

        $steps[] = $this->step('install', '安装（建表 / 菜单 / 权限 / 资源）', $start, $issues, $before, $details);

        if ($this->hasErrorSince($issues, $before)) {
            $this->emergencyCleanup($ident, $issues);

            return ['ok' => false, 'steps' => $steps, 'issues' => $issues];
        }

        $before  = count($issues);
        $start   = microtime(true);
        $details = [];

        try {
            $this->manager->disable($ident);

            if ($this->manager->isEnabled($ident)) {
                $this->error($issues, 'integration.disable', '停用后启用状态仍为 true');
            } else {
                $details[] = '状态键已置为禁用';
            }

            if (!$this->manager->isInstalled($ident, false)) {
                $this->error($issues, 'integration.disable', '停用不应改变安装状态，但 isInstalled 变为 false');
            }
        } catch (\Throwable $e) {
            $this->error($issues, 'integration.disable', '停用失败：' . $this->describe($e));
        }

        $steps[] = $this->step('disable', '停用', $start, $issues, $before, $details);

        $before  = count($issues);
        $start   = microtime(true);
        $details = [];

        try {
            $this->manager->enable($ident);

            if (!$this->manager->isEnabled($ident)) {
                $this->error($issues, 'integration.enable', '启用后启用状态仍为 false');
            } else {
                $details[] = '状态键已置为启用';
            }

            $menuCount = $this->countPluginRows('menus', $ident, $issues);

            if ($menuCount !== null) {
                $details[] = '启用后菜单 ' . $menuCount . ' 条';
            }
        } catch (\Throwable $e) {
            $this->error($issues, 'integration.enable', '启用失败：' . $this->describe($e));
        }

        $steps[] = $this->step('enable', '启用', $start, $issues, $before, $details);

        $before  = count($issues);
        $start   = microtime(true);
        $details = [];

        try {
            $result = $this->manager->uninstall($ident, false, $purge);
            $this->assertUninstalled($ident, $result, $purge, $issues, $details);
        } catch (\Throwable $e) {
            $this->error($issues, 'integration.uninstall', '卸载失败：' . $this->describe($e));
            $this->emergencyCleanup($ident, $issues);
        }

        $steps[] = $this->step('uninstall', '卸载（回滚 + 清理 + 残留检查）', $start, $issues, $before, $details);

        if ($this->hasErrorSince($issues, $before)) {
            $this->emergencyCleanup($ident, $issues);
        }

        $ok = true;

        foreach ($issues as $issue) {
            if ($issue->isError()) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'steps' => $steps, 'issues' => $issues];
    }


    /**
     * @param array<int, AuditIssue> $issues
     * @param array<int, string>     $details
     */
    protected function assertInstalled(string $ident, PluginMetadata $metadata, array &$issues, array &$details): void
    {
        if (!$this->manager->isInstalled($ident, false)) {
            $this->error($issues, 'integration.install', '安装后 `__state.installed` 未写入（isInstalled=false）');
        }

        if (!$this->manager->isEnabled($ident)) {
            $this->error($issues, 'integration.install', '安装后未进入启用态（install 语义应为「装好即可用」）');
        }

        $this->assertMigrations($ident, $issues, $details);
        $this->assertManifestRows($ident, $metadata, $issues, $details);
        $this->assertAssets($ident, $issues, $details);
    }

    /**
     * @param array<int, AuditIssue> $issues
     * @param array<int, string>     $details
     */
    protected function assertMigrations(string $ident, array &$issues, array &$details): void
    {
        $runner = $this->migrations();

        if (!$runner->hasMigrations($ident)) {
            $details[] = '无迁移文件（跳过建表断言）';

            return;
        }

        $count = $runner->migrationCount($ident);
        $details[] = sprintf('迁移记录 %d 条', $count);

        if ($count <= 0) {
            $this->error($issues, 'integration.install', '存在迁移文件但 `ws_plugin_migrations` 无记录');
        }

        $declared = $runner->tableNames($ident);
        $existing = $runner->remainingTables($ident);

        $details[] = sprintf('声明建表 %d 张 / 实际存在 %d 张', count($declared), count($existing));

        if ($declared !== [] && $existing === []) {
            $this->error(
                $issues,
                'integration.install',
                '迁移执行后没有找到任何声明的数据表（检查 up() 是否真的建表）'
            );
        } elseif ($declared !== [] && count($existing) < count($declared)) {
            $missing = array_values(array_diff($declared, $existing));

            $this->error(
                $issues,
                'integration.install',
                sprintf('有 %d 张声明的表未建成：%s', count($missing), implode(', ', $missing))
            );
        }
    }

    /**
     * 菜单 / 独立权限：manifest 声明了就必须落库。
     *
     * @param array<int, AuditIssue> $issues
     * @param array<int, string>     $details
     */
    protected function assertManifestRows(string $ident, PluginMetadata $metadata, array &$issues, array &$details): void
    {
        if ($metadata->getMenus() !== []) {
            $menus = $this->countPluginRows('menus', $ident, $issues);
            $details[] = '菜单 ' . ($menus ?? 0) . ' 条';

            if ($menus !== null && $menus <= 0) {
                $this->error($issues, 'integration.install', 'manifest 声明了 menus，但 ws_menus 中没有该插件的节点');
            }
        }

        $standalone = [];

        foreach ($metadata->getPermissions() as $permission) {
            if (is_array($permission) && ($permission['type'] ?? 'custom') !== 'menu') {
                $standalone[] = (string) ($permission['ident'] ?? '');
            }
        }

        if ($standalone !== []) {
            $permissions = $this->countPluginRows('auth_permissions', $ident, $issues);
            $details[]   = '独立权限 ' . ($permissions ?? 0) . ' 条';

            if ($permissions !== null && $permissions <= 0) {
                $this->error(
                    $issues,
                    'integration.install',
                    'manifest 声明了独立权限，但 ws_auth_permissions 中没有该插件的记录'
                );
            }
        }
    }

    /**
     * @param array<int, AuditIssue> $issues
     * @param array<int, string>     $details
     */
    protected function assertAssets(string $ident, array &$issues, array &$details): void
    {
        $source = rtrim(str_replace('\\', '/', plugin_path()), '/') . '/' . $ident . '/statics';

        if (!is_dir($source)) {
            return;
        }

        $target    = rtrim(str_replace('\\', '/', public_path()), '/') . '/statics/plugins/' . $ident;
        $details[] = '静态资源 ' . (is_dir($target) ? '已复制' : '缺失');

        if (!is_dir($target)) {
            $this->error($issues, 'integration.install', '插件声明了 statics/，但 public/statics/plugins/' . $ident . ' 不存在');
        }
    }


    /**
     * @param array<string, mixed>   $result  PluginManager::uninstall() 的返回值
     * @param array<int, AuditIssue> $issues
     * @param array<int, string>     $details
     */
    protected function assertUninstalled(string $ident, array $result, bool $purge, array &$issues, array &$details): void
    {
        $residual = (array) ($result['residual_tables'] ?? []);

        $details[] = sprintf('残留表 %d 张', count($residual));

        if ($residual !== []) {
            $this->error(
                $issues,
                'integration.uninstall',
                sprintf(
                    '卸载后残留 %d 张表：%s（检查迁移 down() 是否真的删表，或未提供 down()/change()）',
                    count($residual),
                    implode(', ', array_slice($residual, 0, 5))
                )
            );
        }

        if (empty($result['fully_cleaned'])) {
            $this->error($issues, 'integration.uninstall', '卸载未完全清理（fully_cleaned=false）');
        }

        $cleanup = (array) ($result['cleanup'] ?? []);
        $labels  = ['menus_ok' => '菜单', 'permissions_ok' => '权限', 'assets_ok' => '静态资源'];

        foreach ($labels as $key => $label) {
            if (empty($cleanup[$key])) {
                $this->error($issues, 'integration.uninstall', $label . '清理失败（' . $key . '=false）');
            }
        }

        if ($this->manager->isInstalled($ident, false)) {
            $this->error($issues, 'integration.uninstall', '卸载后 `__state.installed` 仍为 true');
        }

        if ($this->manager->isEnabled($ident)) {
            $this->error($issues, 'integration.uninstall', '卸载后启用状态仍为 true');
        }

        $menus       = $this->countPluginRows('menus', $ident, $issues);
        $permissions = $this->countPluginRows('auth_permissions', $ident, $issues);

        $details[] = sprintf('未删除菜单 %d 条 / 权限 %d 条', $menus ?? 0, $permissions ?? 0);

        if ($menus !== null && $menus > 0) {
            $this->error($issues, 'integration.uninstall', '卸载后仍有 ' . $menus . ' 条未删除的菜单行');
        }

        if ($permissions !== null && $permissions > 0) {
            $this->error($issues, 'integration.uninstall', '卸载后仍有 ' . $permissions . ' 条未删除的权限行');
        }

        if ($purge && $this->migrations()->hasMigrations($ident)) {
            $records = $this->migrations()->migrationCount($ident);
            $details[] = '无痕模式迁移记录 ' . $records . ' 条';

            if ($records > 0) {
                $this->error($issues, 'integration.uninstall', '无痕卸载后迁移记录未清空（' . $records . ' 条）');
            }
        }

        $target = rtrim(str_replace('\\', '/', public_path()), '/') . '/statics/plugins/' . $ident;

        if (is_dir($target)) {
            $this->error($issues, 'integration.uninstall', '卸载后 public/statics/plugins/' . $ident . ' 仍然存在');
        }
    }


    /**
     * 尽力把测试库恢复干净（只记 warn：清理失败不能再掩盖原始失败）。
     *
     * @param array<int, AuditIssue> $issues
     */
    protected function emergencyCleanup(string $ident, array &$issues): void
    {
        try {
            if (!$this->manager->isInstalled($ident, false)) {
                return;
            }

            $result = $this->manager->uninstall($ident, false, true);

            $this->warning(
                $issues,
                'integration.cleanup',
                '测试中断，已尝试无痕卸载清理：' . (string) ($result['message'] ?? '已完成')
            );
        } catch (\Throwable $e) {
            $this->warning(
                $issues,
                'integration.cleanup',
                '测试中断且自动清理失败（请手工卸载该插件）：' . $this->describe($e)
            );
        }
    }


    /**
     * 统计某插件在指定表中的**未删除**行数（表内以 `plugin` 列归属）。
     *
     * 用 `Db::name()` 裸查询而不是模型：模型带软删全局作用域与事件，
     * 断言"库里到底还有没有行"必须看原始事实。返回 null 表示查询失败（已记 error）。
     *
     * @param array<int, AuditIssue> $issues
     */
    protected function countPluginRows(string $table, string $ident, array &$issues): ?int
    {
        try {
            return (int) Db::name($table)
                ->where('plugin', $ident)
                ->whereNull('delete_time')
                ->count();
        } catch (\Throwable $e) {
            $this->error($issues, 'integration.database', sprintf('查询 %s 失败：%s', $table, $this->describe($e)));

            return null;
        }
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    protected function hasErrorSince(array $issues, int $from): bool
    {
        for ($i = $from; $i < count($issues); $i++) {
            if ($issues[$i]->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成一条步骤记录（只统计本步骤新增的问题）。
     *
     * @param array<int, AuditIssue> $issues
     * @param array<int, string>     $details
     *
     * @return array{id:string, title:string, ok:bool, ms:int, errors:int, warnings:int, details:array<int,string>}
     */
    protected function step(
        string $id,
        string $title,
        int|float $start,
        array $issues,
        int $from,
        array $details
    ): array {
        $errors   = 0;
        $warnings = 0;

        for ($i = $from; $i < count($issues); $i++) {
            $issues[$i]->isError() ? $errors++ : $warnings++;
        }

        return [
            'id'       => $id,
            'title'    => $title,
            'ok'       => $errors === 0,
            'ms'       => (int) round((microtime(true) - (float) $start) * 1000),
            'errors'   => $errors,
            'warnings' => $warnings,
            'details'  => array_values($details),
        ];
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    protected function error(array &$issues, string $rule, string $message): void
    {
        $issues[] = new AuditIssue($rule, AuditIssue::ERROR, $message);
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    protected function warning(array &$issues, string $rule, string $message): void
    {
        $issues[] = new AuditIssue($rule, AuditIssue::WARN, $message);
    }

    protected function describe(\Throwable $e): string
    {
        return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
    }

    protected function migrations(): MigrationRunner
    {
        return $this->migrations ??= new MigrationRunner();
    }
}

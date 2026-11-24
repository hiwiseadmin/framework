<?php

declare(strict_types=1);

namespace wise\plugin\lifecycle;

use think\App;
use think\facade\Db;
use wise\support\context\ContextualLogger;
use wise\plugin\config\PluginSettings;
use wise\plugin\exception\PluginInstallException;
use wise\plugin\exception\PluginNotFoundException;
use wise\plugin\PluginManager;
use wise\plugin\PluginSupport;

/**
 * 插件安装状态的自检、自愈与修复。
 *
 * 从 PluginManager 拆出：这些方法的共同点是「只读事实 + 极少量状态回写」，
 * 与安装/卸载的事务流程无关，但被列表渲染（热路径）反复调用。
 *
 * 三处事实：状态键（plugin_config.__state） / 迁移记录（ws_plugin_migrations） / 实际数据表。
 * 三者对不上就是「数据残留·需修复」或「表已丢失」，必须显式暴露给运营。
 */
class PluginStateInspector extends PluginSupport
{
    /** @var string[]|null 请求级缓存：已执行过数据库迁移的插件标识 */
    protected ?array $migratedPlugins = null;

    /** @var array<string, bool> 请求级去重：本次请求内已完成安装状态自愈的插件 */
    protected array $healedInstalledState = [];

    /**
     * 配置服务（修复状态时需要补播种默认值）。
     */
    public function __construct(
        App $app,
        PluginManager $manager,
        protected PluginSettings $settings
    ) {
        parent::__construct($app, $manager);
    }

    /**
     * 清除本类的请求级缓存（安装/卸载/修复后由 PluginLifecycle 调用）。
     *
     * 迁移记录缓存与"已自愈"去重集合都属于"事实快照"，任何写操作后必须失效。
     */
    public function resetCaches(): void
    {
        $this->migratedPlugins      = null;
        $this->healedInstalledState = [];
    }

    /**
     * 检查插件是否已安装（迁移/填充等框架集成是否已执行）。
     *
     * 判定顺序：
     *   1. 插件目录未扫描到 → 未安装
     *   2. 有显式安装记录（plugin_config.__state.installed）→ 已安装
     *   3. 兼容历史数据：无迁移文件 → 视为已安装；有迁移文件但无任何迁移记录 → 未安装
     *
     * @param bool $heal 走到迁移记录兜底分支时是否补写状态键（S-1 自愈）。
     *                   自检内部传 false —— 自检要看到"未修复的事实"。
     */
    public function isInstalled(string $name, bool $heal = true): bool
    {
        PluginManager::validatePluginName($name);

        if (!isset($this->manager->getAllPlugins()[$name])) {
            return false;
        }

        if ($this->getStateManager()->isInstalled($name)) {
            return true;
        }

        if (!$this->getMigrationRunner()->hasMigrations($name)) {
            return false;
        }

        $this->migratedPlugins ??= $this->getMigrationRunner()->getMigratedPlugins();

        if (!in_array($name, $this->migratedPlugins, true)) {
            return false;
        }

        if ($heal) {
            $this->healInstalledState($name);
        }

        return true;
    }

    /**
     * 兜底判定命中时，把安装状态补写回状态键（S-1 自愈）。
     *
     * 自愈写入失败（只读库 / plugin_config 表缺失）不影响本次判定结果，仅记日志。
     */
    protected function healInstalledState(string $name): void
    {
        if (isset($this->healedInstalledState[$name])) {
            return;
        }
        $this->healedInstalledState[$name] = true;

        try {
            $this->getStateManager()->setInstalled($name, true);
            ContextualLogger::info(sprintf(
                '插件 [%s] 安装状态自愈：检测到迁移记录但缺少 __state.installed，已补写为 true',
                $name
            ));
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf('插件 [%s] 安装状态自愈失败：%s', $name, $e->getMessage()));
        }
    }

    /**
     * 获取插件当前所处的生命周期状态（四态互斥，常量见 PluginManager）。
     */
    public function getPluginState(string $name): string
    {
        return $this->resolveState($name, $this->inspectInstallConflict($name));
    }

    /**
     * 由自检结果推导生命周期状态（getPluginState 与列表共用，避免两处口径漂移）。
     *
     * @param array $inspect inspectInstallConflict() 的返回值
     */
    public function resolveState(string $name, array $inspect): string
    {
        if (($inspect['severity'] ?? 'ok') !== 'ok') {
            return PluginManager::STATE_INCONSISTENT;
        }

        if (empty($inspect['installed'])) {
            return PluginManager::STATE_NOT_INSTALLED;
        }

        return $this->manager->isEnabled($name)
            ? PluginManager::STATE_ENABLED
            : PluginManager::STATE_INSTALLED;
    }

    /**
     * 安装一致性自检：把三处事实（状态键 / 迁移记录 / 实际数据表）摆在一起（S-3）。
     *
     * 内部用 isInstalled($name, false) 取"未修复前的事实"，不能在自检里触发 S-1 自愈。
     *
     * @return array{
     *   installed: bool, state_key: bool, migration_rows: int,
     *   declared_tables: int, residual_tables: string[],
     *   conflict: bool, severity: 'ok'|'residual'|'dropped'
     * }
     */
    public function inspectInstallConflict(string $name): array
    {
        PluginManager::validatePluginName($name);

        $runner   = $this->getMigrationRunner();
        $declared = $runner->tableNames($name);
        $recorded = $this->getStateManager()->getResidualTables($name);
        $detected = $runner->remainingTables($name);

        $residual = array_values(array_unique(array_merge($recorded, $detected)));

        $severity = $this->installConflictSeverity($name);

        return [
            'installed'       => $this->isInstalled($name, false),
            'state_key'       => $this->getStateManager()->isInstalled($name),
            'migration_rows'  => $runner->migrationCount($name),
            'declared_tables' => count($declared) ?: count($recorded),
            'recorded_tables' => $recorded,
            'residual_tables' => $residual,
            'conflict'        => $severity !== 'ok',
            'severity'        => $severity,
        ];
    }

    /**
     * 安装一致性冲突级别（自检与列表共用的判定核心）。
     *
     *   ok       三处自洽（表在且已安装 / 无表且未安装）
     *   residual 建表迁移声明过表、库中仍有残留，但状态说"未安装"
     *   dropped  状态说"已安装"，但声明的表一张都不在
     */
    protected function installConflictSeverity(string $name): string
    {
        $runner = $this->getMigrationRunner();

        if ($this->getStateManager()->getResidualTables($name) !== []) {
            return 'residual';
        }

        $stateKey = $this->getStateManager()->isInstalled($name);

        $declared = $runner->tableNames($name);
        $residual = $runner->remainingTables($name);

        if ($residual !== [] && !$stateKey) {
            return 'residual';
        }

        if ($residual === [] && $stateKey && $declared !== []) {
            return 'dropped';
        }

        return 'ok';
    }

    /**
     * 【repair 模式】仅修复安装状态：**保留数据表**，把状态与框架集成补齐。
     *
     * 适用：表结构完好但状态键丢失（历史卸载/回滚不彻底）。
     * 不跑迁移、不跑 seed、不 drop 表、不删业务配置，因而是安全操作。
     *
     * @return array{mode: string, residual_tables: string[], state: string}
     * @throws PluginNotFoundException|PluginInstallException
     */
    public function repairInstallState(string $name): array
    {
        PluginManager::validatePluginName($name);
        $metadata = $this->manager->getPlugin($name);

        $runner   = $this->getMigrationRunner();
        $declared = $runner->tableNames($name);
        $recorded = $this->getStateManager()->getResidualTables($name);
        $residual = array_values(array_unique(array_merge($recorded, $runner->remainingTables($name))));

        if ($declared !== [] && $residual === []) {
            throw new PluginInstallException(sprintf(
                '插件 "%s" 声明的数据表一张都不存在，"仅修复状态"不适用，请改用「清库重装」重建数据表',
                $name
            ));
        }

        $this->getStateManager()->setInstalled($name, true);

        $this->getStateManager()->setResidualTables($name, []);

        try {
            Db::startTrans();
            $this->getMenuRegistrar()->register($metadata);
            $this->getPermissionRegistrar()->register($metadata);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new PluginInstallException('修复安装状态时菜单/权限注册失败：' . $e->getMessage());
        }

        try {
            $this->settings->seedDefaultConfig($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 修复安装状态：配置默认值播种失败：" . $e->getMessage());
        }

        $this->manager->initServiceProvider($name);

        $this->manager->clearPluginCache();
        $this->migratedPlugins      = null;
        $this->healedInstalledState = [];

        ContextualLogger::info(sprintf(
            '插件 [%s] 已仅修复安装状态（保留 %d 张残留数据表）',
            $name,
            count($residual)
        ));

        return [
            'mode'            => 'repair',
            'residual_tables' => $residual,
            'state'           => $this->getPluginState($name),
        ];
    }

    /**
     * 【force 模式第一步】清库：回滚并删除该插件的全部数据表与迁移记录。
     *
     * 只做"清空到未安装"，**不动插件目录与静态资源**，以便紧接着调用 install() 重建。
     *
     * @return array{residual_tables: string[], cleared: bool, message: string}
     */
    public function purgeResidual(string $name): array
    {
        PluginManager::validatePluginName($name);

        $runner   = $this->getMigrationRunner();
        $rollback = $runner->rollback($name, 0);
        $removed  = $runner->clearMigrationRecords($name);

        $recorded = $this->getStateManager()->getResidualTables($name);
        $residual = array_values(array_unique(array_merge($recorded, $runner->remainingTables($name))));

        $hardDropped = [];
        if ($residual !== []) {
            ContextualLogger::warning(sprintf(
                '插件 [%s] 清库重装：回滚后仍残留 %d 张数据表（多为迁移 down() 漏写 ->save()），转为直接删除：%s',
                $name,
                count($residual),
                implode(', ', $residual)
            ));

            $hardDropped = $runner->dropTables($residual);
            $residual    = $runner->remainingTables($name);
        }

        $this->getStateManager()->setResidualTables($name, $residual);

        if ($residual !== []) {
            ContextualLogger::error(sprintf(
                '插件 [%s] 清库重装：仍有 %d 张数据表无法删除：%s',
                $name,
                count($residual),
                implode(', ', $residual)
            ));
        }

        $this->migratedPlugins = null;

        return [
            'residual_tables' => $residual,
            'cleared'         => $residual === [],
            'hard_dropped'    => $hardDropped,
            'message'         => $residual === []
                ? sprintf(
                    '已清理插件 [%s] 的数据表（迁移记录 %d 条%s）',
                    $name,
                    $removed,
                    $hardDropped === [] ? '' : sprintf('，兜底直删 %d 张表', count($hardDropped))
                )
                : $rollback['message'],
        ];
    }
}

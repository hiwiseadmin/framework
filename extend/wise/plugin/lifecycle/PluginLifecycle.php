<?php

declare(strict_types=1);

namespace wise\plugin\lifecycle;

use think\App;
use think\facade\Db;
use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\support\tools\ArchiveTool;
use app\common\slot\SlotManager;
use wise\plugin\compat\FrameworkCompatibility;
use wise\plugin\config\PluginSettings;
use wise\plugin\exception\PluginDependencyException;
use wise\plugin\exception\PluginInstallException;
use wise\plugin\exception\PluginNotFoundException;
use wise\plugin\PluginManager;
use wise\plugin\PluginSupport;
use wise\plugin\scanner\PluginMetadata;

/**
 * 插件生命周期（安装 / 卸载 / 启用 / 禁用 / 升级）。
 *
 * 从 PluginManager 拆出：这里只负责"改状态 + 做框架集成 + 失败回滚"，
 * 不掺列表渲染、不掺配置读写、不掺业务插件逻辑。
 *
 * 事务边界（重要）：
 *   - 迁移 / 数据填充 / 菜单+权限注册 是**有副作用**的三步，失败必须回滚；
 *   - 插槽注册、配置默认值播种、生命周期事件属"可选集成/通知"，失败只告警。
 *
 * 生命周期事件统一走 ThinkPHP Event（插件在自身 event.php 监听）：
 *   plugin.installed / plugin.uninstalling / plugin.uninstalled / plugin.enabled / plugin.disabled
 */
class PluginLifecycle extends PluginSupport
{
    public function __construct(
        App $app,
        PluginManager $manager,
        protected PluginStateInspector $inspector,
        protected PluginSettings $settings
    ) {
        parent::__construct($app, $manager);
    }

    /**
     * 安装插件（完整框架集成版）。
     *
     * 流程：
     *   1. 依赖检查
     *   2. 复制静态资源
     *   3. 执行数据库迁移
     *   4. 执行数据填充（database/seeds，按 manifest `seed` 策略）
     *   5. 注册菜单 + 权限（事务）
     *   6. 注册前端插槽 + 播种配置默认值（幂等，失败不阻断）
     *   7. 初始化服务提供者
     *   8. 持久化安装/启用状态
     *   9. 触发 plugin.installed 事件
     *
     * 数据填充策略（{@see SeedPolicy}）：
     *   - `seed: required`（缺省）：安装时执行，失败回滚并阻断 —— 缺基线数据等于半个系统；
     *   - `seed: demo`：默认**跳过**，只有 `plugin:install --with-demo` 才执行，失败只告警；
     *   - `seed: none`：永不执行。
     *
     * 失败回滚：迁移/基线填充/菜单注册失败时清理已落下的副作用（见各步 catch）。
     *
     * @param bool $withDemo     是否写入演示数据（仅对 `seed: demo` 生效）
     * @param bool $ignoreCompat 是否忽略 `compatible_with` 声明（应急用）
     *
     * @throws PluginNotFoundException|PluginDependencyException|PluginInstallException
     */
    public function install(string $name, bool $withDemo = false, bool $ignoreCompat = false): PluginMetadata
    {
        PluginManager::validatePluginName($name);
        $metadata = $this->manager->getPlugin($name);

        $this->getDependencyChecker()->checkDependencies($name);

        $this->checkCapabilities($metadata);

        $this->assertFrameworkCompatible($name, $metadata->getCompatibleWith(), $ignoreCompat);

        $this->getAssetManager()->copyAssets($name);

        $migrateResult = $this->getMigrationRunner()->migrate($name);
        if (!$migrateResult['success']) {
            $this->getAssetManager()->removeAssets($name);
            throw new PluginInstallException($this->describeMigrationFailure($name, $migrateResult['message']));
        }

        $seedMode = SeedPolicy::normalize($metadata->getSeedMode());

        if (SeedPolicy::shouldRun($seedMode, $withDemo)) {
            $seedResult = $this->getMigrationRunner()->seed($name);

            if (!$seedResult['success']) {
                if (SeedPolicy::failureIsFatal($seedMode, $withDemo)) {
                    $this->getMigrationRunner()->rollback($name, 0);
                    $this->getAssetManager()->removeAssets($name);
                    throw new PluginInstallException("插件数据填充失败：{$seedResult['message']}");
                }

                ContextualLogger::warning(
                    "插件 [{$name}] 演示数据填充失败（已跳过，不影响安装）：{$seedResult['message']}"
                );
            }
        }

        try {
            Db::startTrans();
            $this->getMenuRegistrar()->register($metadata);
            $this->getPermissionRegistrar()->register($metadata);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            $this->getMigrationRunner()->rollback($name, 0);
            $this->getAssetManager()->removeAssets($name);

            throw new PluginInstallException("插件框架注册失败：" . $e->getMessage());
        }

        $slots = $metadata->getSlots();
        if (!empty($slots)) {
            try {
                SlotManager::registerFromManifest($name, $slots);
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件 [{$name}] 插槽注册失败：" . $e->getMessage());
            }
        }

        try {
            $this->settings->seedDefaultConfig($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 配置默认值播种失败：" . $e->getMessage());
        }

        $this->manager->initServiceProvider($name);

        $this->getStateManager()->setInstalled($name, true);
        $metadata->setEnabled(true);
        $this->getStateManager()->setEnabled($name, true);

        $this->trigger('plugin.installed', $name, $metadata);

        $this->manager->clearPluginCache();
        $this->inspector->resetCaches();

        return $metadata;
    }

    /**
     * 卸载插件（完整框架集成版）。
     *
     * @param bool $removeFiles 是否同时删除插件文件（默认 false）。true 时先备份到
     *                          `backup/{name}_{Ymd-His}.zip`，备份失败则放弃删除。
     * @param bool $purge       无痕模式：菜单/权限**物理删除**、残留表强制 DROP、
     *                          清空迁移记录。不可恢复。
     *
     * @return array{residual_tables: string[], fully_cleaned: bool, purge: bool,
     *               dropped_tables: string[], backup_archive: string|null,
     *               cleanup: array<string, bool>, message: string}
     * @throws PluginNotFoundException|PluginDependencyException
     */
    public function uninstall(string $name, bool $removeFiles = false, bool $purge = false): array
    {
        PluginManager::validatePluginName($name);
        $metadata = $this->manager->getPlugin($name);

        $dependents = $this->getDependencyChecker()->resolveDependents($name);
        if (!empty($dependents)) {
            throw new PluginDependencyException(
                sprintf('无法卸载插件 "%s"：以下插件依赖它：%s', $name, implode(', ', $dependents)),
                $dependents
            );
        }

        $this->trigger('plugin.uninstalling', $name, $metadata);

        $rollbackResult = $this->getMigrationRunner()->rollback($name, 0);

        $residualTables = $this->getMigrationRunner()->remainingTables($name);
        $rollbackClean  = $rollbackResult['success'] && $residualTables === [];

        if (!$rollbackClean) {
            ContextualLogger::error(sprintf(
                '插件 [%s] 卸载：数据库迁移回滚未彻底完成，残留 %d 张数据表 —— %s',
                $name,
                count($residualTables),
                $rollbackResult['message']
            ));
        }

        $droppedTables = [];
        if ($purge) {
            if ($residualTables !== []) {
                $droppedTables  = $this->getMigrationRunner()->dropTables($residualTables);
                $residualTables = $this->getMigrationRunner()->remainingTables($name);
            }

            $this->getMigrationRunner()->clearMigrationRecords($name);

            $rollbackClean = $residualTables === [];

            ContextualLogger::info(sprintf(
                '插件 [%s] 无痕卸载：强制删除残留数据表 %d 张，清空迁移记录',
                $name,
                count($droppedTables)
            ));
        }

        $menuCleaned = $this->getMenuRegistrar()->unregister($name, $purge);
        $permCleaned = $this->getPermissionRegistrar()->unregister($name, $purge);

        if (!$menuCleaned || !$permCleaned) {
            ContextualLogger::error(sprintf(
                '插件 [%s] 卸载：菜单/权限清理未全部成功（菜单=%s，权限=%s），'
                . '请复核 ws_menus / ws_auth_permissions 中 plugin=\'%s\' 的行',
                $name,
                $menuCleaned ? 'ok' : 'FAIL',
                $permCleaned ? 'ok' : 'FAIL',
                $name
            ));
        }

        try {
            SlotManager::unregisterFromManifest($name);
        } catch (\Throwable $e) {
            ContextualLogger::warning("插件 [{$name}] 插槽注销失败：" . $e->getMessage());
        }

        $metadata->setEnabled(false);
        $this->getStateManager()->clearState($name);
        $this->getConfig()->clear($name);

        if (!$rollbackClean) {
            $this->getStateManager()->setResidualTables($name, $residualTables);

            ContextualLogger::warning(sprintf(
                '插件 [%s] 卸载：残留 %d 张数据表，已登记为「数据残留·需修复」，可在插件列表用「重装」清理',
                $name,
                count($residualTables)
            ));
        }

        $assetsCleaned = $this->getAssetManager()->removeAssets($name);

        $backupArchive = null;
        $filesRemoved  = false;

        if ($removeFiles) {
            $targetPath = $this->getPluginDirectory() . DIRECTORY_SEPARATOR . $name;

            if (!is_dir($targetPath)) {
                $filesRemoved = true;
            } else {
                $backupArchive = $this->backupPluginDirectory($name, $targetPath);

                if ($backupArchive === null) {
                    ContextualLogger::error(sprintf(
                        '插件 [%s] 卸载：目录备份失败，已保留 plugins/%s 不删除',
                        $name,
                        $name
                    ));
                } else {
                    $this->removeDirectory($targetPath);
                    $filesRemoved = !file_exists($targetPath);

                    if (!$filesRemoved) {
                        ContextualLogger::warning(sprintf(
                            '插件 [%s] 卸载：目录删除未完成，请手工处理 plugins/%s（备份包已生成）',
                            $name,
                            $name
                        ));
                    }
                }
            }
        }

        $this->trigger('plugin.uninstalled', $name, $metadata);

        $this->manager->refreshScannerCache();
        $this->manager->clearPluginCache();
        $this->inspector->resetCaches();

        return [
            'residual_tables' => $residualTables,
            'fully_cleaned'   => $rollbackClean,
            'purge'           => $purge,
            'dropped_tables'  => $droppedTables,
            'backup_archive'  => $backupArchive,
            'cleanup'         => [
                'menus_ok'       => $menuCleaned,
                'permissions_ok' => $permCleaned,
                'assets_ok'      => $assetsCleaned,
                'files_removed'  => $filesRemoved,
                'trace_free'     => $purge,
            ],
            'message'         => $this->buildUninstallMessage($name, $rollbackClean, $residualTables, $menuCleaned, $permCleaned, $purge),
        ];
    }

    /**
     * 启用插件（纯状态位切换，不触碰数据库结构）。
     *
     * 职责边界：建表由 install()、删表由 uninstall()；
     * enable()/disable() 只翻转 enabled 并做框架集成（菜单/权限/服务提供者）。
     *
     * @throws PluginNotFoundException|PluginDependencyException|PluginInstallException
     */
    public function enable(string $name): void
    {
        PluginManager::validatePluginName($name);

        if (!$this->inspector->isInstalled($name)) {
            throw new PluginInstallException(
                "插件「{$name}」尚未安装，请先点击「安装」创建所需的数据表，再启用"
            );
        }

        $metadata = $this->manager->getPlugin($name);

        $this->getDependencyChecker()->checkDependencies($name);

        $this->checkCapabilities($metadata);

        $this->getAssetManager()->copyAssets($name);

        try {
            Db::startTrans();
            $this->getMenuRegistrar()->register($metadata);
            $this->getPermissionRegistrar()->register($metadata);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            ContextualLogger::error(sprintf(
                '插件 "%s" 启用时菜单/权限注册失败: %s',
                $name,
                $e->getMessage()
            ));
            throw $e;
        }

        $this->manager->initServiceProvider($name);

        $metadata->setEnabled(true);
        $this->getStateManager()->setEnabled($name, true);

        $this->trigger('plugin.enabled', $name, $metadata);

        $this->manager->clearPluginCache();
    }

    /**
     * 禁用插件。
     *
     * 设计原则：先落库再通知 —— 即使监听器出错，插件也一定被禁用。
     */
    public function disable(string $name): void
    {
        PluginManager::validatePluginName($name);
        $metadata = $this->manager->getPlugin($name);

        $metadata->setEnabled(false);
        $this->getStateManager()->setEnabled($name, false);

        $this->trigger('plugin.disabled', $name, $metadata);

        $this->manager->clearPluginCache();
    }

    /**
     * 升级插件。
     *
     * 流程：备份旧目录 → 下载/解压 → 复制资源 → 迁移 → 填充（按新 manifest 的 seed 策略，
     *      演示数据不重复灌）→ 补配置默认值 → 清理备份 → 用新 manifest 重注册菜单/权限并清理已删菜单
     *
     * @param bool $ignoreCompat 是否忽略新版本 `compatible_with` 声明（应急用）
     *
     * @throws PluginNotFoundException|PluginInstallException
     */
    public function upgrade(
        string $name,
        string $newVersion,
        string $packageUrl = '',
        bool $ignoreCompat = false
    ): PluginMetadata {
        PluginManager::validatePluginName($name);
        $metadata       = $this->manager->getPlugin($name);
        $currentVersion = $metadata->getVersion();

        if (version_compare($newVersion, $currentVersion, '<=')) {
            throw new PluginInstallException(
                "新版本（{$newVersion}）不高于当前版本（{$currentVersion}），无需升级"
            );
        }

        $pluginDir = $this->getPluginDirectory();
        $targetDir = $pluginDir . DIRECTORY_SEPARATOR . $name;
        $backupDir = $pluginDir . DIRECTORY_SEPARATOR . $name . '_backup_' . date('YmdHis');

        if (is_dir($targetDir)) {
            $this->copyDirectory($targetDir, $backupDir);
        }

        try {
            if (!empty($packageUrl)) {
                $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '_' . $newVersion . '.zip';
                $this->downloadFile($packageUrl, $tmpZip);

                $zip = new \ZipArchive();
                if ($zip->open($tmpZip) !== true) {
                    throw new PluginInstallException("安装包解压失败");
                }

                if (is_dir($targetDir)) {
                    $this->removeDirectory($targetDir);
                }
                $zip->extractTo($pluginDir);
                $zip->close();
                @unlink($tmpZip);

                $subDirs = glob($pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                if (!empty($subDirs)) {
                    $firstSub = basename($subDirs[0]);
                    if ($firstSub !== $name) {
                        $subManifest = $subDirs[0] . DIRECTORY_SEPARATOR . 'manifest.json';
                        if (is_file($subManifest)) {
                            if (is_dir($targetDir)) {
                                $this->removeDirectory($targetDir);
                            }
                            rename($subDirs[0], $targetDir);
                        }
                    }
                }
            }

            $this->assertFrameworkCompatible(
                $name,
                FrameworkCompatibility::fromManifestFile(
                    $targetDir . DIRECTORY_SEPARATOR . 'manifest.json'
                ),
                $ignoreCompat
            );

            $this->getAssetManager()->copyAssets($name);

            $migrateResult = $this->getMigrationRunner()->migrate($name);
            if (!$migrateResult['success']) {
                throw new PluginInstallException("升级迁移失败：" . $migrateResult['message']);
            }

            $seedMode = SeedPolicy::fromManifestFile($targetDir . DIRECTORY_SEPARATOR . 'manifest.json');

            if (SeedPolicy::shouldRun($seedMode, false)) {
                $seedResult = $this->getMigrationRunner()->seed($name);

                if (!$seedResult['success']) {
                    ContextualLogger::warning(
                        "插件 [{$name}] 升级数据填充失败（已跳过）：{$seedResult['message']}"
                    );
                }
            }

            try {
                $this->settings->seedDefaultConfig($name);
            } catch (\Throwable $e) {
                ContextualLogger::warning("插件 [{$name}] 配置默认值补齐失败：" . $e->getMessage());
            }

            if (is_dir($backupDir)) {
                $this->removeDirectory($backupDir);
            }

            $this->manager->refreshScannerCache();
            $this->manager->clearPluginCache();

            try {
                $metadata = $this->manager->getPlugin($name);

                Db::startTrans();
                $this->getMenuRegistrar()->register($metadata);
                $this->getPermissionRegistrar()->register($metadata);
                $pruned = $this->getMenuRegistrar()->prune($metadata);
                Db::commit();

                if (!empty($pruned)) {
                    ContextualLogger::info(sprintf(
                        '插件 "%s" 升级后清理了 %d 项 manifest 已不再声明的菜单（软删，可恢复）',
                        $name,
                        count($pruned)
                    ));
                }
            } catch (\Throwable $e) {
                Db::rollback();
                ContextualLogger::warning(sprintf(
                    '插件 "%s" 升级后菜单/权限重注册失败（可用 `php think plugin:menu-migrate` 补同步）：%s',
                    $name,
                    $e->getMessage()
                ));
            }

            return $this->manager->getPlugin($name);
        } catch (\Throwable $e) {
            if (is_dir($backupDir)) {
                if (is_dir($targetDir)) {
                    $this->removeDirectory($targetDir);
                }
                rename($backupDir, $targetDir);
                $this->getAssetManager()->copyAssets($name);
            }
            throw new PluginInstallException("插件升级失败：" . $e->getMessage());
        }
    }


    /**
     * 触发生命周期事件（异常隔离 —— 事件是通知，不承担事务语义）。
     *
     * 负载以**位置参数**传入（`[toArray()]`），避免 TP 把关联数组当作命名参数绑定。
     */
    protected function trigger(string $event, string $name, PluginMetadata $metadata): void
    {
        try {
            Event::trigger($event, [$metadata->toArray()]);
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 [%s] %s 事件监听器异常（操作结果不受影响）：%s',
                $name,
                $event,
                $e->getMessage()
            ));
        }
    }

    /**
     * 能力需求协商（manifest 顶层 capabilities.requires）。
     *
     * 与插件间依赖检查（DependencyChecker）互为补充：依赖检查回答"别的插件在不在"，
     * 本方法回答"运行时能力点有没有实现"——实现由提供方插件在其服务提供者里
     * `ExtPoint::register(能力名, 实现名, 实现)` 注册，解析由 CapabilityResolver 完成。
     *
     * 语义：
     *   - 必需能力（required 默认 true）缺失 → 抛 PluginInstallException **阻断**安装/启用；
     *   - 可选能力（required: false）缺失 → 仅记 info 日志，不阻断；
     *   - 未声明 capabilities 的插件 → 直接通过（绝大多数插件的路径）。
     */
    protected function checkCapabilities(PluginMetadata $metadata): void
    {
        $result = $this->getCapabilityResolver()->resolve($metadata);

        foreach ($result->getOptional() as $capability => $message) {
            ContextualLogger::info(sprintf(
                '插件 [%s] 可选能力提示：%s',
                $metadata->getIdent(),
                $message
            ));
        }

        if ($result->isSatisfied()) {
            return;
        }

        throw new PluginInstallException(sprintf(
            "插件 [%s] 能力需求未满足，无法继续：\n%s",
            $metadata->getIdent(),
            implode("\n", array_map(
                fn(string $msg): string => "  - {$msg}",
                $result->getMissingMessages()
            ))
        ));
    }

    /**
     * 框架兼容声明闸门（安装/升级共用）。
     *
     * 只有「声明了且确实不满足」才阻断：未声明一律放行 —— 存量插件没有该字段，
     * 把它当不兼容会把正常安装路径砸断。
     *
     * @param array<int, string> $ranges
     *
     * @throws PluginInstallException
     */
    protected function assertFrameworkCompatible(string $name, array $ranges, bool $ignoreCompat): void
    {
        if ($ranges === [] || $ignoreCompat) {
            return;
        }

        if (FrameworkCompatibility::check($ranges) !== FrameworkCompatibility::INCOMPATIBLE) {
            return;
        }

        throw new PluginInstallException(FrameworkCompatibility::violationMessage($name, $ranges));
    }

    /**
     * 为"迁移失败"补充可操作的排查指引。
     *
     * 典型场景：上次卸载没清掉数据表，再次安装时重跑 up() 建表撞 1050 "Table already exists"。
     */
    protected function describeMigrationFailure(string $name, string $message): string
    {
        $text = "插件迁移失败：{$message}";

        if (preg_match('/already exists|42S01|1050/i', $message) === 1) {
            $text .= sprintf(
                '。该插件的部分数据表已存在，但插件没有对应的迁移记录'
                . '（多为上次卸载未清理干净，或曾用全局 `php think migrate:run` 建表，记录落在全局 migrations 表）。'
                . '可先执行 `php think plugin:migrate:rollback -p %s --all` 清理残留表，再重新安装。',
                $name
            );
        }

        return $text;
    }

    /**
     * 组装卸载结果文案（表残留 / 菜单权限清理 两类异常都要说清）。
     *
     * @param array<int, string> $residualTables
     */
    private function buildUninstallMessage(
        string $name,
        bool $rollbackClean,
        array $residualTables,
        bool $menuCleaned,
        bool $permCleaned,
        bool $purge = false
    ): string {
        $message = $rollbackClean
            ? sprintf('插件 [%s] 已卸载，数据表与状态均已清理', $name)
            : sprintf(
                '插件 [%s] 已卸载，但有 %d 张数据表未清理干净：%s',
                $name,
                count($residualTables),
                implode('、', $residualTables)
            );

        if (!$menuCleaned || !$permCleaned) {
            $message .= sprintf(
                '；另有清理未完成项：%s%s',
                $menuCleaned ? '' : '菜单',
                $permCleaned ? '' : ($menuCleaned ? '权限' : '、权限')
            );
        }

        return $message . ($purge
            ? '（无痕模式：菜单与权限已物理删除，重装不会恢复）'
            : '（菜单与权限为软删，重装同名插件时会自动恢复）');
    }

    /**
     * 下载远程文件到本地路径。
     */
    protected function downloadFile(string $url, string $savePath): void
    {
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $context = stream_context_create([
            'http' => ['timeout' => 120],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new PluginInstallException("下载文件失败：{$url}");
        }

        if (file_put_contents($savePath, $content) === false) {
            throw new PluginInstallException("写入文件失败：{$savePath}");
        }
    }

    /**
     * 递归复制目录。
     */
    protected function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $sourcePrefix = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourcePrefix));
            $target       = $dest . DIRECTORY_SEPARATOR . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new \RuntimeException(sprintf(
                        '复制文件失败: %s → %s',
                        $item->getPathname(),
                        $target
                    ));
                }
            }
        }
    }

    /**
     * 删除插件目录前先备份为 zip 包（`backup/{插件名}_{Ymd-His}.zip`）。
     *
     * 任何一步失败返回 null —— 调用方据此**放弃删除目录**，避免"备份没成功却把源码删了"。
     */
    protected function backupPluginDirectory(string $name, string $sourceDir): ?string
    {
        try {
            $backupDir = rtrim($this->app->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'backup';

            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                ContextualLogger::error("插件 [{$name}] 卸载备份：无法创建备份目录 {$backupDir}");
                return null;
            }

            $archive = $backupDir . DIRECTORY_SEPARATOR . sprintf('%s_%s.zip', $name, date('Ymd-His'));

            $tool = new ArchiveTool();

            $ok = $tool->compressDir($sourceDir, $archive, 'zip', [
                'include_root'        => true,
                'root_name'           => $name,
                'skip'                => ['.git', '.svn', 'node_modules', '__pycache__', '.idea'],
                'preserve_empty_dirs' => false,
            ]);

            clearstatcache();

            $entries = $ok ? (array) $tool->listContents($archive) : [];

            if (!$ok || $entries === []) {
                @unlink($archive);

                ContextualLogger::error(sprintf(
                    '插件 [%s] 卸载备份失败：%s（包内条目 %d 个）',
                    $name,
                    $tool->getLastError() ?: '压缩包校验未通过',
                    count($entries)
                ));

                return null;
            }

            ContextualLogger::info("插件 [{$name}] 卸载前已备份插件目录到 {$archive}");

            return $archive;
        } catch (\Throwable $e) {
            ContextualLogger::error("插件 [{$name}] 卸载备份异常：" . $e->getMessage());

            return null;
        }
    }

    /**
     * 递归删除目录（失败聚合后抛异常，调用方据此判定"是否真的删掉了"）。
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        $errors = [];
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                if (!rmdir($path)) {
                    $errors[] = "Failed to remove directory: {$path}";
                }
            } else {
                if (!unlink($path)) {
                    $errors[] = "Failed to remove file: {$path}";
                }
            }
        }

        if (!rmdir($dir)) {
            $errors[] = "Failed to remove root directory: {$dir}";
        }

        if (!empty($errors)) {
            throw new PluginInstallException(sprintf(
                'Failed to fully remove plugin directory "%s": %s',
                $dir,
                implode('; ', $errors)
            ));
        }
    }
}

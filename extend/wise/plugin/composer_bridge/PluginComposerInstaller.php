<?php

declare(strict_types=1);

namespace wise\plugin\composer_bridge;

use wise\plugin\PluginManager;
use wise\plugin\exception\PluginDependencyException;
use wise\plugin\exception\PluginInstallException;

/**
 * 基于 Composer 引擎的插件安装编排器。
 *
 * 把"插件中心一键安装"从 zip 下载改造为：
 *   1) 装前冲突预检（Composer 求解器一次性判定全部已装依赖）
 *   2) composer require（拉取依赖并触发 plugin:bridge 桥接到 plugins/）
 *   3) 复用既有 PluginManager::install 完成框架集成（迁移、菜单、权限、钩子……）
 *
 * 约定：插件 composer 包名的"短名"必须与插件 ident（manifest 的 name）一致，
 * 即包 `wiseadmin/blog` 对应插件目录 `plugins/blog`。这样 composer require 后
 * 桥接出的目录名恰好等于 PluginManager 所需的 ident。
 */
class PluginComposerInstaller
{
    public function __construct(
        private ComposerEngine $engine,
        private PluginManager $manager
    ) {
    }

    /**
     * 通过 Composer 安装插件。
     *
     * @param string $composerPackage   插件 composer 包名，如 wiseadmin/blog
     * @param string $versionConstraint  版本约束，空字符串表示取最新版本
     * @param string $pluginIdent        插件目录标识（与 manifest 的 name 一致）
     *
     * @return array{metadata:mixed,composer:array}
     *
     * @throws PluginDependencyException 存在版本冲突（HTTP 409）
     * @throws PluginInstallException    环境不可用或安装失败
     */
    public function install(string $composerPackage, string $versionConstraint, string $pluginIdent): array
    {
        if (!$this->engine->isAvailable()) {
            throw new PluginInstallException(
                '未检测到 Composer 运行环境（composer 或 composer.phar），无法以 Composer 方式安装插件。'
            );
        }

        // 1) 装前冲突预检：把"多插件不同版本需求"问题交给 Composer 求解器统一判定
        $pre = $this->engine->checkInstallConflicts($composerPackage, $versionConstraint);
        if ($pre['conflict']) {
            throw new PluginDependencyException(
                sprintf('插件 %s@%s 与已安装依赖存在版本冲突，已终止安装。', $composerPackage, $versionConstraint ?: 'latest'),
                $pre['blockers']
            );
        }

        // 2) 实际安装：composer require 写入 composer.json、拉取依赖并触发 post-autoload-dump
        //    （含 `php think plugin:bridge`，把 wise-plugin 桥接到 plugins/ 目录）
        $req = $this->engine->requirePackage($composerPackage, $versionConstraint);
        if ($req['exitCode'] !== 0) {
            throw new PluginInstallException('Composer 安装失败：' . ($req['stderr'] ?: $req['output']));
        }

        // 3) 框架集成（迁移、菜单、权限、钩子、静态资源……）复用既有 PluginManager::install
        $metadata = $this->manager->install($pluginIdent);

        return [
            'metadata' => $metadata,
            'composer' => $req,
        ];
    }

    /**
     * 通过 Composer 卸载插件：先走框架卸载（含文件清理），再移除 composer 依赖。
     */
    public function uninstall(string $composerPackage, string $pluginIdent): array
    {
        $this->manager->uninstall($pluginIdent, true);
        $remove = $this->engine->removePackage($composerPackage);

        return ['composer' => $remove];
    }
}

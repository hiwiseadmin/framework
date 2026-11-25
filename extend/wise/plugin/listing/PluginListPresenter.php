<?php

declare(strict_types=1);

namespace wise\plugin\listing;

use think\App;
use think\facade\Cache;
use wise\plugin\compat\FrameworkCompatibility;
use wise\plugin\lifecycle\PluginStateInspector;
use wise\plugin\PluginManager;
use wise\plugin\PluginSupport;
use wise\plugin\scanner\PluginMetadata;

/**
 * 插件列表/市场展示（本地列表、单行、操作按钮、远程市场）。
 *
 * 从 PluginManager 拆出：**只负责"怎么展示"**，不承担任何写操作 ——
 * 安装/卸载/启停一律走 PluginLifecycle。
 *
 * 列表行结构必须与 `getLocalPluginList()` 完全一致：前端「安装/卸载后只刷新
 * 该行」依赖同一份行数据（单行出口见后台 row_ident 模式）。
 */
class PluginListPresenter extends PluginSupport
{
    public function __construct(
        App $app,
        PluginManager $manager,
        protected PluginStateInspector $inspector
    ) {
        parent::__construct($app, $manager);
    }

    /**
     * 获取在线插件列表（远程市场）。
     *
     * @param array $query 查询参数（page/limit/catid/keyword）
     */
    public function getOnlinePluginList(array $query = []): array
    {
        $config    = $this->app->config->get('plugin', []);
        $remoteUrl = $config['list_url'] ?? '';

        if (empty($remoteUrl)) {
            return [];
        }

        $query       = array_merge([
            'page'      => 1,
            'limit'     => 50,
            'catid'     => 0,
            'keyword'   => '',
            'framework' => FrameworkCompatibility::currentVersion(),
        ], $query);
        $queryString = http_build_query($query);
        $url         = $remoteUrl . (str_contains($remoteUrl, '?') ? '&' : '?') . $queryString;

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'header'  => "Accept: application/json\r\n",
                ],
            ]);
            $json = file_get_contents($url, false, $context);

            if ($json === false) {
                return [];
            }

            $remotePlugins = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            if (!isset($remotePlugins['code']) || (int) $remotePlugins['code'] !== 0 || !isset($remotePlugins['data']['list'])) {
                return [];
            }

            return [
                'category' => $remotePlugins['data']['category'] ?? [],
                'list'     => $remotePlugins['data']['list'],
                'total'    => $remotePlugins['data']['total'] ?? 0,
                'page'     => $remotePlugins['data']['page'] ?? 1,
                'limit'    => $remotePlugins['data']['limit'] ?? 50,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取本地插件列表。
     *
     * 分类由服务器端（远程插件市场）定义，本地插件本身不携带分类：
     * 能在远程列表中找到（ident 相同）时采用远程分类，纯本地插件归入「本地」。
     *
     * @param  array $query 查询参数（catid / keyword）
     * @return array{list: array, total: int, page: int, limit: int, category: array}
     */
    public function getLocalPluginList(array $query = []): array
    {
        $catid   = (string) ($query['catid'] ?? '');
        $keyword = trim((string) ($query['keyword'] ?? ''));

        $remoteCats = $this->getRemoteCategoryIndex();

        $plugins = $this->manager->getAllPlugins();
        $list    = [];
        $cats    = [];

        foreach ($plugins as $name => $metadata) {
            if ($keyword !== '') {
                $haystack = implode(' ', [
                    $metadata->getDisplayName(),
                    $metadata->getDescription(),
                    $metadata->getAuthor(),
                ]);
                if (stripos($haystack, $keyword) === false) {
                    continue;
                }
            }

            $category = $remoteCats[$name] ?? PluginManager::LOCAL_CATEGORY;

            if ($catid !== '' && $catid !== '0' && $category !== $catid) {
                continue;
            }

            $row    = $this->buildLocalRow($metadata, $category);
            $list[] = $row;

            $rowCategory = $row['category'];
            if (!isset($cats[$rowCategory])) {
                $cats[$rowCategory] = ['id' => $rowCategory, 'name' => $rowCategory];
            }
        }

        return [
            'total'    => count($list),
            'list'     => $list,
            'page'     => 1,
            'limit'    => max(count($list), 1),
            'category' => array_values($cats),
        ];
    }

    /**
     * 构建单个本地插件的列表行数据。
     *
     * 与 `getLocalPluginList()` 的列表项必须完全同构：前端「安装/卸载后只刷新
     * 该行」依赖本方法返回的行数据原地 updateRow。
     *
     * @param  string $category 分类名；空则按「远程分类索引」推导（回落「本地」）
     */
    public function buildLocalRow(PluginMetadata $metadata, string $category = ''): array
    {
        $ident = $metadata->getIdent();

        if ($category === '') {
            $category = $this->getRemoteCategoryIndex()[$ident] ?? PluginManager::LOCAL_CATEGORY;
        }

        $inspect = $this->inspector->inspectInstallConflict($ident);
        $state   = $this->inspector->resolveState($ident, $inspect);

        $actions = $this->buildActions($metadata, $inspect['installed']);
        if ($inspect['severity'] !== 'ok' && !in_array('reinstall', $actions, true)) {
            $actions[] = 'reinstall';
        }

        $missingPackages = $this->manager->missingPackages($metadata);
        $packageReport   = $this->manager->packageDependencyReport($metadata);

        $compatibleWith = $metadata->getCompatibleWith();
        $compatStatus   = FrameworkCompatibility::check($compatibleWith);

        return [
            'name'        => $metadata->getDisplayName(),
            'ident'       => $ident,
            'version'     => $metadata->getVersion(),
            'author'      => $metadata->getAuthor(),
            'description' => $metadata->getDescription(),
            'category'    => $category,
            'status'           => $state === PluginManager::STATE_ENABLED ? 1 : 0,
            'installed'        => $state === PluginManager::STATE_INCONSISTENT
                ? 0
                : ($inspect['installed'] ? 1 : 0),
            'state'            => $state,
            'severity'         => $inspect['severity'],
            'conflict'         => $inspect['conflict'] ? 1 : 0,
            'state_key'        => $inspect['state_key'] ? 1 : 0,
            'migration_rows'   => $inspect['migration_rows'],
            'declared_tables'  => $inspect['declared_tables'],
            'residual_tables'  => $inspect['residual_tables'],
            'missing_packages' => array_values(array_map(
                static fn (array $item): string => $item['package'],
                $missingPackages
            )),
            'blocked'      => $missingPackages !== [],
            'block_reason' => implode('；', array_map(
                static fn (array $item): string => $item['message'],
                $packageReport
            )),
            'develop'     => $metadata->isDevelop(),
            'compatible_with' => $compatibleWith,
            'compat_status'   => $compatStatus,
            'compat_note'     => FrameworkCompatibility::describe($compatibleWith),
            'framework_version' => FrameworkCompatibility::currentVersion(),
            'index_url'   => '/plugin/' . $ident,
            'download'    => 0,
            'actions'     => $actions,
        ];
    }

    /**
     * 获取单个插件的列表行数据（供安装/卸载后按行局部刷新）。
     *
     * @param  string $nameOrIdent 插件标识 / 目录名 / 标题
     * @return array|null 找不到插件时返回 null
     */
    public function getPluginRow(string $nameOrIdent): ?array
    {
        if ($nameOrIdent === '') {
            return null;
        }

        $plugins  = $this->manager->getAllPlugins();
        $metadata = $plugins[$nameOrIdent] ?? $plugins[$this->manager->resolveIdent($nameOrIdent)] ?? null;

        if (!$metadata instanceof PluginMetadata) {
            return null;
        }

        return $this->buildLocalRow($metadata);
    }

    /**
     * 获取「本地插件 ident → 远程分类名」的映射（60 秒缓存）。
     *
     * @return array<string, string>
     */
    protected function getRemoteCategoryIndex(): array
    {
        $cacheKey = 'plugin_remote_category_index';

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
        }

        $map = [];

        try {
            $remote = $this->getOnlinePluginList([
                'page'    => 1,
                'limit'   => 200,
                'catid'   => 0,
                'keyword' => '',
            ]);

            $catNames = [];
            foreach (($remote['category'] ?? []) as $cat) {
                if (!isset($cat['id'])) {
                    continue;
                }
                $catNames[(string) $cat['id']] = (string) ($cat['name'] ?? $cat['id']);
            }

            foreach (($remote['list'] ?? []) as $item) {
                $ident = (string) ($item['ident'] ?? ($item['name'] ?? ''));
                if ($ident === '') {
                    continue;
                }

                $catid = (string) ($item['catid'] ?? ($item['category_id'] ?? ''));
                if ($catid === '') {
                    continue;
                }

                $map[$ident] = $catNames[$catid] ?? $catid;
            }
        } catch (\Throwable $e) {
        }

        if ($map !== []) {
            try {
                Cache::set($cacheKey, $map, 60);
            } catch (\Throwable $e) {
            }
        }

        return $map;
    }

    /**
     * 构建插件当前可用操作列表。
     *
     * - 未安装 → ['install']
     * - 已安装 → ['uninstall']（有 config schema 时加 'config'；远程版本更高时加 'upgrade'）
     *
     * @param  bool   $installed  是否已安装（已跑迁移）
     * @param  string $newVersion 远程最新版本（空表示未知）
     * @return string[]
     */
    public function buildActions(PluginMetadata $metadata, bool $installed, string $newVersion = ''): array
    {
        if (!$installed) {
            return ['install'];
        }

        $actions = ['uninstall'];

        if (!empty($metadata->getConfigSchema())) {
            $actions[] = 'config';
        }

        if ($newVersion !== '' && version_compare($newVersion, $metadata->getVersion(), '>')) {
            $actions[] = 'upgrade';
        }

        return $actions;
    }
}

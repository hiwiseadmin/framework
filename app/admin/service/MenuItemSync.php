<?php
declare(strict_types=1);

namespace app\admin\service;

use app\admin\model\Menu;
use wise\plugin\manifest\MenuPathNormalizer;
use wise\support\contracts\MenuItemProvider;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\menu\MenuSchema;
use wise\support\menu\MenuTreePruner;

/**
 * 菜单声明同步器 —— ExtPoints::MENU_ITEM 的唯一消费方
 *
 * 把各包/插件在 `menu.item` 扩展点声明的菜单，幂等落地到 menus 表，
 * 并经 MenuPermissionBridge 自动派生权限（菜单 ↔ 权限双向绑定）。
 *
 * 与插件 manifest 菜单**共用同一落库原语**
 * （Menu::createMenusWithPermissions → MenuPermissionBridge::syncBatch），
 * 因此「插件菜单」与「库包菜单」行为完全一致。
 *
 * 设计约定：
 *  - **声明是真相，数据库是运行时**：同步幂等可重复执行；
 *  - 默认 `preserveExisting = true`：已存在菜单不改写字段，保留运营在后台的
 *    排序/改名/停用等改动，只补建缺失项、恢复软删除项；
 *    `force = true` 时以声明为准覆盖；
 *  - 父节点必须已存在，未匹配到则跳过（不自动创建系统目录）；
 *  - `prune = true` 时，软删除该实现下「声明中已不存在」的菜单（不物理删除）。
 *    差集判定（含「根容器 / 仍被声明引用的祖先链 / 整棵未声明子树」三条守卫）由
 *    {@see MenuTreePruner} 提供，**插件链路（插件升级、`plugin:menu-migrate --prune`）共用同一实现**；
 *  - 菜单树统一经 {@see \wise\support\menu\MenuSchema} 归一，与插件 manifest 菜单同源；
 *  - 同步后登记各实现的「声明指纹」，供后台懒同步（{@see MenuItemSync::syncIfStale()}）判断声明是否变化。
 *
 * @see \wise\command\menu\MenuSync 命令行入口：php think menu:sync（框架级命令，见 extend/wise/extend.json）
 */
class MenuItemSync
{
    /** 懒同步节流窗口（秒）：声明变化后最多每 N 秒自动同步一次 */
    private const STALE_THROTTLE_SECONDS = 300;

    /**
     * 同步全部（或指定）扩展点实现
     *
     * @param  array{package?:string, force?:bool, prune?:bool} $options
     * @return array{packages:int, created:int, existing:int, skipped:int, pruned:int, messages:array<int,string>}
     */
    public function syncAll(array $options = []): array
    {
        $packageMenus = $this->registerPackageMenuProviders(true);

        $only         = trim((string) ($options['package'] ?? ''));
        $pruneOrphans = !empty($options['pruneOrphans']);

        $stat = [
            'packages'     => 0,
            'created'      => 0,
            'existing'     => 0,
            'skipped'      => 0,
            'pruned'       => 0,
            'disabled'     => 0,
            'orphans'      => [],
            'orphanPruned' => 0,
            'messages'     => [],
        ];

        foreach (($packageMenus['errors'] ?? []) as $package => $message) {
            $stat['messages'][] = sprintf('<error>%s</error>: 包菜单声明无效：%s', $package, $message);
        }

        foreach (($packageMenus['skipped'] ?? []) as $package => $message) {
            $stat['messages'][] = sprintf('<comment>%s</comment>: %s', $package, $message);
        }

        $registered = [];
        $fingerprints = [];

        foreach (ExtPoint::all(ExtPoints::MENU_ITEM) as $name => $item) {
            $provider = $this->makeProvider($item['concrete'] ?? null);
            if ($provider === null) {
                $stat['messages'][] = sprintf('<comment>%s</comment>: 未实现 MenuItemProvider，已跳过', $name);
                $stat['skipped']++;
                continue;
            }

            $ident  = $provider->ident();
            $active = (bool) ($item['active'] ?? true);

            $registered[]           = $ident;
            $fingerprints[$ident]   = $this->fingerprint($provider, $active);

            if ($only !== '' && $ident !== $only) {
                continue;
            }

            if (!$active) {
                $disabled = $this->disable($ident);
                $stat['disabled'] += $disabled;
                $stat['messages'][] = sprintf(
                    '<comment>%s</comment>: 实现已停用，隐藏 %d 项菜单（status=0，配置与权限保留）',
                    $ident,
                    $disabled
                );
                continue;
            }

            $result = $this->sync($provider, $options);

            $stat['packages']++;
            $stat['created']  += $result['created'];
            $stat['existing'] += $result['existing'];
            $stat['pruned']   += $result['pruned'];
            if ($result['skipped'] > 0) {
                $stat['skipped'] += $result['skipped'];
            }
            $stat['messages'][] = $result['message'];
        }

        $stat['orphans'] = $this->diffOrphans($registered);

        foreach ($stat['orphans'] as $orphan) {
            if ($pruneOrphans) {
                $removed = $this->uninstall($orphan);
                $stat['orphanPruned'] += $removed;
                $stat['messages'][] = sprintf(
                    '<comment>%s</comment>: 实现已不存在，软删 %d 项菜单（权限保留，重装可恢复）',
                    $orphan,
                    $removed
                );
            } else {
                $stat['messages'][] = sprintf(
                    '<comment>%s</comment>: 实现已不存在但菜单仍在库中（清理请加 --prune-orphans）',
                    $orphan
                );
            }
        }

        $this->writeState([
            'idents'             => $registered,
            'fingerprints'       => $fingerprints,
            'last_stale_attempt' => 0,
        ]);

        return $stat;
    }

    /**
     * 懒同步：声明发生变化时（且超出节流窗口）自动同步一次
     *
     * 供后台请求钩子（app\admin\middleware\MenuStaleSync）调用：
     *  - 声明指纹与上次同步一致 → 直接返回空数组（不写库）；
     *  - 有变化且上次尝试已超过 {@see STALE_THROTTLE_SECONDS} → 执行 syncAll()；
     *  - 同步失败也不会在窗口内反复重试（进入前先打节流标记）。
     *
     * @return array syncAll() 结果；未触发时返回空数组
     */
    public function syncIfStale(): array
    {
        if (empty($this->staleIdents())) {
            return [];
        }

        $state = $this->loadState();
        if (time() - (int) $state['last_stale_attempt'] < self::STALE_THROTTLE_SECONDS) {
            return [];
        }

        $this->writeState([
            'idents'             => $state['idents'],
            'fingerprints'       => $state['fingerprints'],
            'last_stale_attempt' => time(),
        ]);

        return $this->syncAll();
    }

    /**
     * 计算「声明已变化」的实现标识
     *
     * 指纹包含启用态，因此能力市场停用/启用某实现也会被判定为「已变化」。
     *
     * @return string[]
     */
    public function staleIdents(): array
    {
        $this->registerPackageMenuProviders();

        $stored = $this->loadState()['fingerprints'];

        $stale = [];
        foreach (ExtPoint::all(ExtPoints::MENU_ITEM) as $item) {
            $provider = $this->makeProvider($item['concrete'] ?? null);
            if ($provider === null) {
                continue;
            }

            $ident = $provider->ident();
            $fp    = $this->fingerprint($provider, (bool) ($item['active'] ?? true));

            if (($stored[$ident] ?? '') !== $fp) {
                $stale[] = $ident;
            }
        }

        return $stale;
    }

    /**
     * 声明指纹：标识 + 启用态 + 容器声明 + 菜单树（含别名与顺序）
     *
     * 不依赖人工维护的版本号——只要声明内容（或启用态）变化，指纹即变化。
     * 容器声明必须计入：只改容器（名称 / 排序 / 锚点 / 节点分组）同样要触发懒同步。
     */
    protected function fingerprint(MenuItemProvider $provider, bool $active): string
    {
        $payload = json_encode([
            $provider->ident(),
            $active,
            $provider->containers(),
            $provider->items(),
        ], JSON_UNESCAPED_UNICODE);

        return md5($payload !== false ? $payload : '');
    }

    /**
     * 停用某实现的菜单：status=0（隐藏，保留全部配置与权限绑定）
     *
     * @return int 受影响行数
     */
    public function disable(string $ident): int
    {
        if ($ident === '' || $ident === 'system') {
            return 0;
        }

        return Menu::where('plugin', $ident)
            ->where('status', Menu::STATUS_ENABLE)
            ->update(['status' => Menu::STATUS_DISABLE]);
    }

    /**
     * 启用某实现的菜单：status=1
     *
     * @return int 受影响行数
     */
    public function enable(string $ident): int
    {
        if ($ident === '' || $ident === 'system') {
            return 0;
        }

        return Menu::where('plugin', $ident)
            ->where('status', Menu::STATUS_DISABLE)
            ->update(['status' => Menu::STATUS_ENABLE]);
    }

    /**
     * 卸载某实现的菜单
     *
     * - 默认**软删**（`delete_time`，可用 restore() 恢复；权限记录保留，
     *   重装时 syncBatch 走 withTrashed() 会自动恢复菜单）；
     * - `$purge = true` 时**物理删**，并软删其菜单派生权限
     *   （权限软删，保留角色关联记录，避免历史角色授权静默失效）。
     *
     * @return int 处理行数
     */
    public function uninstall(string $ident, bool $purge = false): int
    {
        if ($ident === '' || $ident === 'system') {
            return 0;
        }

        $query = $purge
            ? Menu::withTrashed()->where('plugin', $ident)
            : Menu::where('plugin', $ident);

        $count = 0;
        foreach ($query->select() as $menu) {
            if ($purge && (int) $menu->getAttr('permission_id') > 0) {
                app(MenuPermissionBridge::class)
                    ->deletePermissionById((int) $menu->getAttr('permission_id'));
            }

            if ($purge) {
                $menu->force()->delete();
            } else {
                $menu->delete();
            }
            $count++;
        }

        return $count;
    }

    /**
     * 同步单个实现的菜单声明（支持多容器）
     *
     * 流程：校验容器声明 → 归一菜单树 → 按节点 `container` 键分组 →
     * 逐容器解析（自持目录创建 / 挂靠既有目录）后落库（同一落库原语
     * Menu::createMenusWithPermissions，幂等 + 菜单派生权限）。
     *
     * 一个实现可拥有多个侧边栏分组目录；容器与节点同属 plugin=ident，
     * 因此 prune / uninstall / prune-orphans 天然覆盖全部容器（无需额外处理）。
     *
     * @param  MenuItemProvider $provider
     * @param  array{force?:bool, prune?:bool} $options
     * @return array{ident:string, created:int, existing:int, total:int, skipped:int, pruned:int, containers:int, message:string}
     */
    public function sync(MenuItemProvider $provider, array $options = []): array
    {
        $ident = $provider->ident();
        $force = !empty($options['force']);
        $prune = !empty($options['prune']);

        $declared = $this->prepareContainers($provider);
        $warnings = $declared['warnings'];

        if ($declared['containers'] === []) {
            return $this->skipResult($ident, $warnings === [] ? '未声明可用容器' : implode('；', $warnings));
        }

        $items  = MenuSchema::normalizeTree($provider->items());
        $groups = $this->groupByContainer($items, $declared['containers'], $warnings);
        $total  = MenuSchema::countNodes($items);

        if ($groups === []) {
            return $this->skipResult($ident, '声明中没有有效菜单节点');
        }

        $before = $this->countMenus($ident);

        $createdContainers = 0;
        $writtenContainers = 0;
        $entryKey          = (string) ($declared['entry'] ?? '');
        $entryId           = null;

        try {
            if ($entryKey !== '' && isset($declared['containers'][$entryKey])) {
                $entry = $this->resolveContainer($declared['containers'][$entryKey], $ident, $warnings);

                if ($entry === null) {
                    $warnings[] = '启动台入口容器未成功落库，声明 anchor="launcher" 的分组将回退「控制台」';
                } else {
                    $entryId = (int) $entry['id'];

                    if ($entry['created']) {
                        $createdContainers++;
                    }

                    if (isset($groups[$entryKey])) {
                        $writtenContainers++;
                        Menu::createMenusWithPermissions($ident, $groups[$entryKey]['items'], $entryId, 0, !$force);
                    }
                }
            }

            foreach ($groups as $key => $group) {
                if ($key === $entryKey) {
                    continue;
                }

                $container = $this->resolveContainer($declared['containers'][$key], $ident, $warnings, $entryId);

                if ($container === null) {
                    continue;
                }

                if ($container['created']) {
                    $createdContainers++;
                }

                $writtenContainers++;
                Menu::createMenusWithPermissions($ident, $group['items'], $container['id'], 0, !$force);
            }
        } catch (\Throwable $e) {
            return [
                'ident'      => $ident,
                'created'    => 0,
                'existing'   => 0,
                'total'      => $total,
                'skipped'    => 1,
                'pruned'     => 0,
                'containers' => 0,
                'message'    => sprintf('<error>%s</error>: 同步失败 - %s', $ident, $e->getMessage()),
            ];
        }

        if ($writtenContainers === 0) {
            return $this->skipResult($ident, $warnings === [] ? '没有可用的容器目录' : implode('；', $warnings));
        }

        $after    = $this->countMenus($ident);
        $created  = max(0, $after - $before - $createdContainers);
        $existing = max(0, $total - $created);

        $pruned = $prune ? count($this->prune($ident, $items)) : 0;

        $message = sprintf(
            '<info>%s</info>: 新增 %d，已存在 %d，共 %d 项；容器 %d 个（新建 %d）%s%s',
            $ident,
            $created,
            $existing,
            $total,
            $writtenContainers,
            $createdContainers,
            $force ? '，已强制覆盖' : '',
            $pruned > 0 ? sprintf('，清理 %d 项', $pruned) : ''
        );

        if ($warnings !== []) {
            $message .= '；<comment>' . implode('；', $warnings) . '</comment>';
        }

        return [
            'ident'      => $ident,
            'created'    => $created,
            'existing'   => $existing,
            'total'      => $total,
            'skipped'    => 0,
            'pruned'     => $pruned,
            'containers' => $writtenContainers,
            'message'    => $message,
        ];
    }

    /**
     * 计算孤儿实现：上次登记过、本次不再注册，且库中确有菜单
     *
     * 依赖运行期状态文件记录「上次注册集合」——因为包被 composer 卸载后
     * 扩展点实现直接消失，无从得知「曾经存在过哪些实现」。
     *
     * @param  string[] $registered 本次注册的全部实现标识
     * @return string[]
     */
    protected function diffOrphans(array $registered): array
    {
        $previous = $this->loadState()['idents'];
        if (empty($previous)) {
            return [];
        }

        $orphans = array_values(array_diff($previous, $registered));
        if (empty($orphans)) {
            return [];
        }

        return array_values(array_intersect($orphans, $this->knownMenuIdents()));
    }

    /**
     * 库中现存菜单的归属标识（排除 system）
     *
     * @return string[]
     */
    protected function knownMenuIdents(): array
    {
        $rows = Menu::where('plugin', '<>', 'system')
            ->field('plugin')
            ->group('plugin')
            ->select()
            ->toArray();

        return array_values(array_filter(array_column($rows, 'plugin')));
    }

    /**
     * 读取状态文件
     *
     * 结构：
     *   idents             上次同步登记的实现标识（用于识别「已卸载」的孤儿）
     *   fingerprints       上次同步各实现的声明指纹（用于懒同步判定）
     *   last_stale_attempt 上次懒同步尝试时间戳（节流窗口）
     *
     * @return array{idents:string[], fingerprints:array<string,string>, last_stale_attempt:int}
     */
    protected function loadState(): array
    {
        $state = ['idents' => [], 'fingerprints' => [], 'last_stale_attempt' => 0];

        $file = $this->stateFile();
        if (!is_file($file)) {
            return $state;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return $state;
        }

        if (is_array($data['idents'] ?? null)) {
            $state['idents'] = array_values(array_filter(
                $data['idents'],
                static fn($v): bool => is_string($v) && $v !== ''
            ));
        }

        if (is_array($data['fingerprints'] ?? null)) {
            $state['fingerprints'] = array_filter(
                $data['fingerprints'],
                static fn($v): bool => is_string($v)
            );
        }

        $state['last_stale_attempt'] = (int) ($data['last_stale_attempt'] ?? 0);

        return $state;
    }

    /**
     * 写入状态文件（整体覆盖）
     *
     * @param array{idents?:string[], fingerprints?:array<string,string>, last_stale_attempt?:int} $state
     */
    protected function writeState(array $state): void
    {
        $file = $this->stateFile();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'idents'             => array_values(array_unique(array_filter(
                (array) ($state['idents'] ?? []),
                static fn($v): bool => is_string($v) && $v !== ''
            ))),
            'fingerprints'       => (array) ($state['fingerprints'] ?? []),
            'last_stale_attempt' => (int) ($state['last_stale_attempt'] ?? 0),
        ];

        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 状态文件路径（运行期缓存，非持久业务数据）
     */
    protected function stateFile(): string
    {
        return app()->getRuntimePath() . 'menu_sync_state.json';
    }

    /**
     * 解析容器声明（纯声明层，不落库）
     *
     * 支持 key / name / anchor / icon / sort / mount / description。
     *
     * ── anchor（容器去向）三态，`mount = LAUNCHER` 优先级最高 ────────────────
     *   1. 缺省 / 'console'          → 控制台根下**自持**目录（plugin = ident，随包清理）
     *   2. 既有目录名（如「日志管理」） → 挂靠该目录，**不创建、不拥有**
     *   3. 'launcher' 或**同实现启动台入口容器的 key** → 挂到该入口下作子分组
     *
     * 同时确定「启动台入口」容器 = 第一个 `mount = LAUNCHER` 的容器：
     *   - 声明多个时只取首个，其余**降级为普通分组**（按各自 anchor 决定去向）并告警；
     *   - 完全没有入口容器时，`anchor = 'launcher'` 一律**告警并回退「控制台」**。
     *
     * @return array{
     *     containers: array<string, array{key:string, name:string, anchor:string, icon:string, sort:int, mount:int, description:string}>,
     *     entry: string,
     *     warnings: array<int, string>
     * }
     */
    protected function prepareContainers(MenuItemProvider $provider): array
    {
        $raw      = [];
        $warnings = [];

        foreach ($provider->containers() as $index => $decl) {
            if (!is_array($decl)) {
                continue;
            }

            $key = trim((string) ($decl['key'] ?? ''));
            if ($key === '') {
                $key = $index === 0 ? 'default' : 'container_' . $index;
            }

            if (isset($raw[$key])) {
                $warnings[] = sprintf('容器 key「%s」重复声明，仅保留首个', $key);
                continue;
            }

            $raw[$key] = [
                'key'         => $key,
                'name'        => trim((string) ($decl['name'] ?? '')),
                'anchor'      => trim((string) ($decl['anchor'] ?? '')),
                'icon'        => (string) ($decl['icon'] ?? ''),
                'sort'        => (int) ($decl['sort'] ?? 0),
                'mount'       => (int) ($decl['mount'] ?? Menu::MOUNT_MODULE),
                'description' => (string) ($decl['description'] ?? ''),
            ];
        }

        if ($raw === []) {
            return ['containers' => [], 'entry' => '', 'warnings' => $warnings];
        }

        $entryKey = '';

        foreach ($raw as $key => $decl) {
            if ((int) $decl['mount'] !== Menu::MOUNT_LAUNCHER) {
                continue;
            }

            if ($entryKey === '') {
                $entryKey = $key;
                continue;
            }

            $warnings[] = sprintf(
                '容器「%s」也声明了 mount=LAUNCHER，但启动台入口已由「%s」承担，'
                . '该容器已降级为普通分组（去向由其 anchor 决定）',
                $decl['name'] !== '' ? $decl['name'] : $key,
                $raw[$entryKey]['name'] !== '' ? $raw[$entryKey]['name'] : $entryKey
            );
            $raw[$key]['mount'] = Menu::MOUNT_MODULE;
        }

        $containers = [];

        foreach ($raw as $key => $decl) {
            $anchor    = $decl['anchor'];
            $lower     = strtolower($anchor);
            $isEntry   = $key === $entryKey;
            $anchorKind = 'catalog';

            if ($isEntry) {
                $anchorKind = 'launcher';

                if ($anchor !== '' && $lower !== 'console' && $lower !== 'launcher' && !isset($raw[$anchor])) {
                    $warnings[] = sprintf(
                        '容器「%s」是启动台入口（mount=LAUNCHER），其 anchor「%s」不参与落库（入口恒挂顶层）',
                        $decl['name'] !== '' ? $decl['name'] : $key,
                        $anchor
                    );
                }
            } elseif ($anchor === '' || $lower === 'console') {
                $anchorKind = 'console';
            } elseif ($lower === 'launcher' || (isset($raw[$anchor]) && $anchor === $entryKey)) {
                $anchorKind = 'launcher';
            }

            if ($anchorKind === 'launcher' && !$isEntry && $entryKey === '') {
                $warnings[] = sprintf(
                    '容器「%s」声明 anchor=「%s」，但本实现没有 mount=LAUNCHER 的启动台入口容器，已回退到「控制台」',
                    $decl['name'] !== '' ? $decl['name'] : $key,
                    $decl['anchor']
                );
                $anchorKind = 'console';
            }

            $name = $decl['name'];
            if ($name === '' && $anchorKind === 'catalog') {
                $name = $anchor;
            }

            if ($name === '') {
                $warnings[] = sprintf('容器 #%s 未声明 name，已忽略', (string) $key);
                continue;
            }

            $containers[$key] = [
                'key'         => $key,
                'name'        => $name,
                'anchor'      => $anchorKind === 'launcher' ? 'launcher' : ($anchorKind === 'console' ? 'console' : $anchor),
                'icon'        => $decl['icon'],
                'sort'        => $decl['sort'],
                'mount'       => $decl['mount'],
                'description' => $decl['description'],
            ];
        }

        return [
            'containers' => $containers,
            'entry'      => isset($containers[$entryKey]) ? $entryKey : '',
            'warnings'   => $warnings,
        ];
    }

    /**
     * 按节点 `container` 键分组
     *
     * 缺省或引用了未声明的容器时归入第一个容器，并记一条告警（不静默丢节点）。
     * `container` 仅用于分组，落库前移除，不写入 menus 表。
     *
     * @param  array<int, array<string,mixed>>    $items      已归一菜单树
     * @param  array<string, array<string,mixed>> $containers prepareContainers() 结果
     * @param  array<int, string>                 $warnings
     * @return array<string, array{items: array<int, array<string,mixed>>}>
     */
    protected function groupByContainer(array $items, array $containers, array &$warnings = []): array
    {
        $groups     = [];
        $defaultKey = (string) array_key_first($containers);

        foreach ($items as $node) {
            $key = trim((string) ($node[MenuSchema::CONTAINER_KEY] ?? ''));

            if ($key === '' || !isset($containers[$key])) {
                if ($key !== '') {
                    $warnings[] = sprintf(
                        '节点「%s」引用了未声明的容器「%s」，已归入容器「%s」',
                        (string) ($node['name'] ?? ''),
                        $key,
                        $containers[$defaultKey]['name']
                    );
                }
                $key = $defaultKey;
            }

            unset($node[MenuSchema::CONTAINER_KEY]);
            $groups[$key]['items'][] = $node;
        }

        return $groups;
    }

    /**
     * 解析单个容器为具体菜单节点 ID
     *
     * 去向优先级：`mount = LAUNCHER` **高于** `anchor`（见 MenuItemProvider 契约）：
     * - mount = LAUNCHER：挂**顶层**并标记菜单根 → 应用启动台条目 + 可钉选
     * - anchor = 'launcher'：挂到本实现的启动台入口容器下，作**子分组**
     *   （层级固定「入口(顶级) → 子分组(一级) → 页面(二级)」= 侧边栏可渲染的三层）
     * - anchor = 'console'：在控制台下创建/复用**本实现自持**的目录（plugin = ident）
     * - anchor = 已存在目录名：挂靠该目录（不创建、不拥有、不改其排序），缺失时告警并返回 null
     *
     * @param  array<string,mixed> $decl
     * @param  array<int, string>  $warnings
     * @param  int|null            $entryId 本实现启动台入口容器的节点 id（anchor='launcher' 的落点）
     * @return array{id:int, created:bool}|null
     */
    protected function resolveContainer(array $decl, string $ident, array &$warnings = [], ?int $entryId = null): ?array
    {
        if ((int) ($decl['mount'] ?? Menu::MOUNT_MODULE) === Menu::MOUNT_LAUNCHER) {
            return Menu::ensureContainer(0, $ident, $decl['name'], [
                'icon'        => $decl['icon'],
                'sort'        => $decl['sort'],
                'mount'       => Menu::MOUNT_LAUNCHER,
                'description' => $decl['description'],
            ]);
        }

        $anchor = (string) ($decl['anchor'] ?? 'console');

        if ($anchor === 'launcher') {
            if ($entryId !== null) {
                return Menu::ensureContainer($entryId, $ident, $decl['name'], [
                    'icon'        => $decl['icon'],
                    'sort'        => $decl['sort'],
                    'mount'       => Menu::MOUNT_MODULE,
                    'description' => $decl['description'],
                ]);
            }

            $warnings[] = sprintf(
                '容器「%s」的启动台入口未成功落库，已回退到「控制台」',
                $decl['name']
            );
            $anchor = 'console';
        }

        if ($anchor === 'console') {
            $consoleId = Menu::resolveConsoleRoot();

            if ($consoleId === null) {
                $warnings[] = '未找到系统顶级「控制台」节点，请先初始化系统菜单';
                return null;
            }

            return Menu::ensureContainer($consoleId, $ident, $decl['name'], [
                'icon'        => $decl['icon'],
                'sort'        => $decl['sort'],
                'mount'       => $decl['mount'],
                'description' => $decl['description'],
            ]);
        }

        $anchorId = Menu::resolveCatalogByName($anchor);

        if ($anchorId === null) {
            $warnings[] = sprintf('未找到挂靠目录「%s」，容器「%s」已跳过', $anchor, $decl['key']);
            return null;
        }

        $this->warnCatalogDepth($anchorId, $anchor, $decl, $warnings);

        return ['id' => $anchorId, 'created' => false];
    }

    /**
     * 挂靠既有目录的**层级告警**（只告警、不阻断）
     *
     * 侧边栏模板只渲染三层（系统顶级 → 一级目录 → 叶子），因此被挂靠的目录必须是
     * **一级目录**（其父为系统顶级，即 `parent_id = 0`）。若该目录本身在更深层级，
     * 其子节点会落到第 4 层而**渲染不出来** —— 这里提示运营到「菜单管理」调整。
     *
     * @param  int                 $anchorId 被挂靠目录的节点 id
     * @param  string              $anchor   被挂靠目录名
     * @param  array<string,mixed> $decl     容器声明
     * @param  array<int, string>  $warnings
     */
    protected function warnCatalogDepth(int $anchorId, string $anchor, array $decl, array &$warnings): void
    {
        $node = Menu::withTrashed()->where('id', $anchorId)->find();

        if ($node === null) {
            return;
        }

        $parentId   = (int) $node->getAttr('parent_id');
        $parentNode = $parentId > 0 ? Menu::withTrashed()->where('id', $parentId)->find() : null;

        if ($parentNode === null || (int) $parentNode->getAttr('parent_id') !== 0) {
            $warnings[] = sprintf(
                '容器「%s」挂靠的目录「%s」不在侧边栏一级（其父不是系统顶级），其子节点会超出三层渲染范围，'
                . '请在「菜单管理」中把该目录调到一级，或改用其它锚点',
                (string) ($decl['name'] ?? ''),
                $anchor
            );
        }
    }

    /**
     * 构造「跳过」结果（未声明容器 / 锚点缺失 / 无有效节点）
     *
     * @return array{ident:string, created:int, existing:int, total:int, skipped:int, pruned:int, containers:int, message:string}
     */
    protected function skipResult(string $ident, string $reason): array
    {
        return [
            'ident'      => $ident,
            'created'    => 0,
            'existing'   => 0,
            'total'      => 0,
            'skipped'    => 1,
            'pruned'     => 0,
            'containers' => 0,
            'message'    => sprintf('<comment>%s</comment>: 已跳过（%s）', $ident, $reason),
        ];
    }

    /**
     * 统计该实现名下的菜单总数（含软删除，保证前后口径一致）
     */
    protected function countMenus(string $ident): int
    {
        return Menu::withTrashed()->where('plugin', $ident)->count();
    }

    /**
     * 清理「声明中已不存在」的菜单（软删除，保留数据可恢复）
     *
     * 判定与守卫全部委托 {@see MenuTreePruner}（**与插件链路共用同一份口径**：
     * 插件侧 {@see \wise\plugin\adapter\MenuRegistrar::prune()} 也走这里）：
     *   - 身份键：有 path 用 path（**按 {@see MenuPathNormalizer} 归一**，故 `/plugin/x/y` ≡
     *     `/plugin/x/y/index`），无 path 用 name —— 与落库原语 `findOrCreateMenu()` 同一口径；
     *   - 插件根容器（`is_plugin_root=1`）永不删；
     *   - 仍被声明子树引用的父目录不删（避免悬挂 parent_id 的孤儿菜单）；
     *   - 与声明完全无关的整棵子树一并清（不留空壳目录）。
     *
     * 软删而非物理删：声明重新包含该节点时，落库原语走 `withTrashed()` 会恢复**同一行**
     * （连同 `permission_id` 与既有角色授权），即「清理是可撤销的」；权限行本身不在此删除。
     *
     * @param  string $ident  实现/插件标识
     * @param  array<int, array<string,mixed>> $items 声明树（建议先经 MenuSchema 归一）
     * @param  bool   $dryRun true = 只统计不落库（CLI 预检）
     * @return array<int, array{id:int,name:string,path:string,type:string}> 被清理（或将清理）的节点
     */
    public function prune(string $ident, array $items, bool $dryRun = false): array
    {
        if ($ident === '' || $ident === 'system') {
            return [];
        }

        $normalizer = MenuPathNormalizer::fromConfig();

        $models = [];
        $nodes  = [];

        foreach (Menu::where('plugin', $ident)->select() as $menu) {
            $id          = (int) $menu->getAttr('id');
            $models[$id] = $menu;
            $nodes[]     = [
                'id'             => $id,
                'parent_id'      => (int) $menu->getAttr('parent_id'),
                'path'           => $normalizer->normalize((string) $menu->getAttr('path')),
                'name'           => (string) $menu->getAttr('name'),
                'is_plugin_root' => (int) $menu->getAttr('is_plugin_root'),
            ];
        }

        if ($nodes === []) {
            return [];
        }

        [$paths, $names] = MenuTreePruner::collectIdentity($items);
        $paths           = array_values(array_unique(array_map(
            static fn (string $path): string => $normalizer->normalize($path),
            $paths
        )));

        $pruned = [];
        foreach (MenuTreePruner::resolve($nodes, $paths, $names) as $id) {
            $menu = $models[$id];

            $pruned[] = [
                'id'   => $id,
                'name' => (string) $menu->getAttr('name'),
                'path' => (string) $menu->getAttr('path'),
                'type' => (string) $menu->getAttr('type'),
            ];

            if (!$dryRun) {
                $menu->delete();
            }
        }

        return $pruned;
    }

    /**
     * 注册标准 Composer 包声明的菜单（`extra.think.menus` → menu.php）
     *
     * 说明：包菜单必须在**收集实现之前**注册，否则
     *   - `syncAll()` 会把包 ident 当成「已消失的实现」（差集 / 孤儿判定）；
     *   - `staleIdents()` 永远看不到包声明变化，懒同步失效。
     *
     * 失败**不阻断**插件菜单同步：单个包声明有误只影响它自己。
     *
     * @return array{registered: array<string, array<string, mixed>>, skipped: array<string, string>, errors: array<string, string>}
     */
    protected function registerPackageMenuProviders(bool $full = false): array
    {
        try {
            if (!class_exists(\app\common\service\PackageMenuRegistrar::class)) {
                return ['registered' => [], 'skipped' => [], 'errors' => []];
            }

            return app(\app\common\service\PackageMenuRegistrar::class)->register(false, $full);
        } catch (\Throwable $e) {
            return [
                'registered' => [],
                'skipped'    => [],
                'errors'     => ['*' => '包菜单注册失败：' . $e->getMessage()],
            ];
        }
    }

    /**
     * 实例化扩展点实现（类名 / 工厂闭包 / 对象），不符合契约时返回 null
     */
    protected function makeProvider(mixed $concrete): ?MenuItemProvider
    {
        try {
            if (is_string($concrete) && class_exists($concrete)) {
                $instance = app($concrete);
            } elseif ($concrete instanceof MenuItemProvider) {
                $instance = $concrete;
            } elseif (is_callable($concrete)) {
                $instance = $concrete();
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $instance instanceof MenuItemProvider ? $instance : null;
    }
}

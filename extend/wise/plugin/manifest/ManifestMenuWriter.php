<?php
declare(strict_types=1);

namespace wise\plugin\manifest;

use wise\support\menu\MenuSchema;

/**
 * ManifestMenuWriter — 插件 manifest `menus` 的读写内核（**唯一**的 manifest I/O 实现）。
 *
 * 两个调用方共用：
 *   - `wise\crud\CrudWriter`：`wise:crud --merge-manifest`（生成器产出菜单）；
 *   - `plugin:menu-derive --apply / --prune`（控制器反推菜单）。
 *
 * ## 写入合同（幂等 + 非破坏，方案 §4.5）
 *
 * | 规则 | 说明 |
 * |:---|:---|
 * | **幂等** | 同 `path`（按归一化**身份**：默认段写法、控制器段拼写都算同一个）已存在 → **原样保留**，不覆盖开发者手工调过的 `name/icon/sort`，也不补标记 |
 * | **标记** | 只对**工具新增**的节点打 {@see self::AUTO_MERGED_KEY}（「手工即刻意」，手工粘贴的片段不打） |
 * | **不破坏 JSON** | manifest 非法 JSON → 报错并跳过，绝不改写 |
 * | **分组** | 指定分组且 manifest 已有**同名 `dir`** → 追加进它的 `children`；**不新建 dir**（避免凭空造结构，归置交给后台「插件菜单布局」） |
 * | **回收** | {@see self::prune()} 只删「**带标记** 且 对应控制器/方法已不存在」的节点；**不带标记的一律不动** |
 * | **重排** | {@see self::sortLevels()} 按 `(sort, name)` 稳定重排每级；**只改数组顺序，不改字段值**（显式 opt-in） |
 *
 * 本类不做风格判断（`preferredForm` 由调用方决定），也不落库 —— 菜单入库仍是插件安装期行为。
 */
final class ManifestMenuWriter
{
    /**
     * 自动合并标记的键名。
     *
     * 非 manifest 规范字段（菜单解析只读已知键，多余键会被忽略），
     * 加下划线前缀表明「元信息」，便于开发者一眼分辨哪些菜单是自动合并进来的；
     * 也是 `--prune` 唯一可回收的依据。
     */
    public const AUTO_MERGED_KEY = '_auto_merged';

    /**
     * 自动合并标记的提示文案。
     *
     * 值里**刻意不放时间戳**：否则每次重复执行都会产生无意义的 diff。
     */
    public const AUTO_MERGED_NOTE = 'wise 自动合并（wise:crud / plugin:menu-derive）：请手动调整 name / icon / sort 等排版，确认后删除本字段';

    private MenuPathNormalizer $normalizer;

    public function __construct(?MenuPathNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? MenuPathNormalizer::fromConfig();
    }

    public function normalizer(): MenuPathNormalizer
    {
        return $this->normalizer;
    }

    /**
     * 读取 manifest。
     *
     * 同时返回 `shape`（`json_decode($raw)` 的对象形态）：`json_decode(..., true)` 把
     * 空对象 `{}` 变成空数组 `[]`，不带形态回写会把 `"dependencies": {}` 改成 `[]` ——
     * 写入后 diff 出现一片无关改动，甚至破坏 manifest 语义。`shape` 供 {@see self::save()} 还原。
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>, shape: mixed}
     */
    public function load(string $manifest): array
    {
        if (!is_file($manifest)) {
            return ['ok' => false, 'message' => '未找到 manifest.json：' . $manifest, 'data' => [], 'shape' => null];
        }

        $raw = file_get_contents($manifest);

        if ($raw === false) {
            return ['ok' => false, 'message' => 'manifest.json 不可读：' . $manifest, 'data' => [], 'shape' => null];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [
                'ok'      => false,
                'message' => 'manifest.json 不是合法 JSON，已跳过（请先修好它）：' . $manifest,
                'data'    => [],
                'shape'   => null,
            ];
        }

        return ['ok' => true, 'message' => '', 'data' => $data, 'shape' => json_decode($raw)];
    }

    /**
     * 写回 manifest（统一 `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` + 尾换行，
     * 保证 diff 干净）。
     *
     * @param array<string, mixed> $data  修改后的文档（关联数组）
     * @param mixed                $shape `load()` 返回的原始形态，用于还原空对象；null = 不还原
     * @return array{ok: bool, message: string}
     */
    public function save(string $manifest, array $data, mixed $shape = null): array
    {
        $encoded = json_encode(
            $this->restoreEmptyObjects($data, $shape),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            return ['ok' => false, 'message' => 'manifest.json 序列化失败：' . $manifest];
        }

        if (file_put_contents($manifest, $encoded . "\n") === false) {
            return ['ok' => false, 'message' => 'manifest.json 写入失败：' . $manifest];
        }

        return ['ok' => true, 'message' => '已写入：' . $manifest];
    }

    /**
     * 按原始文档形态还原「空对象」。
     *
     * 关联数组无法区分 `{}` 与 `[]`：解码后都是 `[]`。这里用原始形态树上对应位置是否为
     * `stdClass` 来判定 —— 是且当前为空，就还原成空对象；**新增**节点在形态树上没有对应
     * 位置（`null`），其 `children: []` 保持数组，符合 manifest 规范。
     *
     * @param mixed $data
     * @param mixed $shape
     * @return mixed
     */
    private function restoreEmptyObjects(mixed $data, mixed $shape): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        if ($shape instanceof \stdClass) {
            if ($data === []) {
                return new \stdClass();
            }

            $object = new \stdClass();

            foreach ($data as $key => $value) {
                $object->{$key} = $this->restoreEmptyObjects($value, $shape->{$key} ?? null);
            }

            return $object;
        }

        $restored = [];

        foreach ($data as $key => $value) {
            $restored[$key] = $this->restoreEmptyObjects(
                $value,
                is_array($shape) ? ($shape[$key] ?? null) : null
            );
        }

        return $restored;
    }

    /**
     * 按归一化**身份**递归查找节点（含分组子节点）。
     *
     * @param array<int|string, mixed> $menus
     * @return array<string, mixed>|null 命中的节点（原样返回，供上层报出实际写法）
     */
    public function find(array $menus, string $path): ?array
    {
        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }

            if ($this->normalizer->isSame((string) ($menu['path'] ?? ''), $path)) {
                return $menu;
            }

            $found = $this->find(MenuSchema::childrenOf($menu), $path);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 递归收集所有非空 path（含分组子节点）。
     *
     * @param array<int|string, mixed> $menus
     * @return array<int, string>
     */
    public function paths(array $menus): array
    {
        $paths = [];

        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }

            $path = trim((string) ($menu['path'] ?? ''));

            if ($path !== '') {
                $paths[] = $path;
            }

            foreach ($this->paths(MenuSchema::childrenOf($menu)) as $childPath) {
                $paths[] = $childPath;
            }
        }

        return $paths;
    }

    /**
     * 节点是否带「自动合并」标记（`--prune` 的唯一回收依据）。
     *
     * @param array<string, mixed> $node
     */
    public function isMarked(array $node): bool
    {
        return array_key_exists(self::AUTO_MERGED_KEY, $node);
    }

    /**
     * 追加一个菜单条目（幂等 + 非破坏）。
     *
     * @param array<int|string, mixed> $menus 菜单树（**引用修改**）
     * @param array<string, mixed>     $entry 待追加的条目（`path` 必填）
     * @param string|null              $group 期望分组：命中既有同名 `dir` 时放入其子节点
     * @return array{added: bool, target: string, message: string}
     */
    public function append(array &$menus, array $entry, ?string $group = null): array
    {
        $path = (string) ($entry['path'] ?? '');

        $existing = $this->find($menus, $path);

        if ($existing !== null) {
            $existingPath = (string) ($existing['path'] ?? '');

            return [
                'added'   => false,
                'target'  => '',
                'message' => $existingPath === $path
                    ? sprintf('已存在 path=%s 的菜单，原样保留（未覆盖手工调整）', $path)
                    : sprintf(
                        '已存在等价菜单（%s ≡ %s，仅默认段写法或控制器段拼写不同），原样保留（未覆盖手工调整）',
                        $existingPath,
                        $path
                    ),
            ];
        }

        if ($group !== null && $group !== '') {
            $targetKey = $this->findGroupKey($menus, $group);

            if ($targetKey !== null) {
                $childKey = $this->childKeyOf($menus[$targetKey]);
                $children = (array) ($menus[$targetKey][$childKey] ?? []);

                $children[] = $entry;

                $menus[$targetKey][$childKey] = array_values($children);

                return ['added' => true, 'target' => $group, 'message' => sprintf('已追加到分组「%s」：%s', $group, $path)];
            }
        }

        $menus[] = $entry;

        return ['added' => true, 'target' => '', 'message' => '已追加到顶级：' . $path];
    }

    /**
     * 回收陈旧项：删除「**带标记** 且 归一化身份不在 `$keptIdentities` 中」的节点。
     *
     * - 不带标记的节点（手工维护）**永不触碰**；
     * - 递归到分组子节点；子节点被删空后**分组本身保留**（它不是工具创建的）；
     * - 带标记但控制器仍存在的节点保留（例如手工调过 name 的自动节点）。
     *
     * @param array<int|string, mixed> $menus 菜单树（**引用修改**）
     * @param array<string, bool>      $keptIdentities 需保留的身份集合（`identity => true`）
     * @return array{removed: array<int, array{path: string, name: string}>}
     */
    public function prune(array &$menus, array $keptIdentities): array
    {
        $removed = [];

        $this->pruneLevel($menus, $keptIdentities, $removed);

        return ['removed' => $removed];
    }

    /**
     * @param array<int|string, mixed>              $menus
     * @param array<string, bool>                   $kept
     * @param array<int, array{path: string, name: string}> $removed
     */
    private function pruneLevel(array &$menus, array $kept, array &$removed): void
    {
        foreach ($menus as $key => $node) {
            if (!is_array($node)) {
                continue;
            }

            if ($this->pruneNode($menus[$key], $kept, $removed)) {
                unset($menus[$key]);
            }
        }

        // unset 会留下空洞：重新索引，保证 JSON 输出仍是数组
        $menus = array_values($menus);
    }

    /**
     * 按 `(sort, name)` **稳定**重排每一级节点（含分组子节点）。
     *
     * 只改**数组顺序**，不改任何字段值 —— 后台展示顺序由 `sort` 字段决定，本方法只是让
     * manifest 里的排列与 `sort` 一致，便于人工审阅、也让后续 diff 稳定（同级同 `sort`
     * 时给出确定次序；PHP 8 的排序是稳定排序，完全同键者保持原相对顺序）。
     *
     * 非列表结构（异常写法，如 `children` 写成单个对象）不动，避免把对象写成数组。
     *
     * @param array<int|string, mixed> $menus 菜单树（**引用修改**）
     */
    public function sortLevels(array &$menus): void
    {
        if (!array_is_list($menus)) {
            return;
        }

        foreach ($menus as &$node) {
            if (!is_array($node)) {
                continue;
            }

            foreach (MenuSchema::CHILD_KEYS as $childKey) {
                if (isset($node[$childKey]) && is_array($node[$childKey])) {
                    $this->sortLevels($node[$childKey]);
                }
            }
        }

        unset($node);

        usort($menus, static function (mixed $a, mixed $b): int {
            if (!is_array($a) || !is_array($b)) {
                return 0;
            }

            $bySort = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));

            return $bySort !== 0
                ? $bySort
                : strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    }

    /**
     * 递归处理单个节点。
     *
     * @param array<string, mixed>                          $node
     * @param array<string, bool>                           $kept
     * @param array<int, array{path: string, name: string}> $removed
     * @return bool 该节点是否应被删除
     */
    private function pruneNode(array &$node, array $kept, array &$removed): bool
    {
        foreach (MenuSchema::CHILD_KEYS as $childKey) {
            if (!isset($node[$childKey]) || !is_array($node[$childKey])) {
                continue;
            }

            if (isset($node[$childKey][0])) {
                $this->pruneLevel($node[$childKey], $kept, $removed);
            } elseif ($this->pruneNode($node[$childKey], $kept, $removed)) {
                // 单节点写法（非列表）被回收 → 移除整个 children 键
                unset($node[$childKey]);
            }
        }

        $path = trim((string) ($node['path'] ?? ''));

        if ($path === '' || !$this->isMarked($node)) {
            return false;
        }

        if (isset($kept[$this->normalizer->identity($path)])) {
            return false;
        }

        $removed[] = ['path' => $path, 'name' => (string) ($node['name'] ?? '')];

        return true;
    }

    /**
     * 找到同名分组（`dir` 节点）的键名。
     *
     * @param array<int|string, mixed> $menus
     */
    private function findGroupKey(array $menus, string $group): int|string|null
    {
        foreach ($menus as $key => $menu) {
            if (!is_array($menu)) {
                continue;
            }

            if ((string) ($menu['type'] ?? '') !== MenuSchema::TYPE_DIR) {
                continue;
            }

            if (trim((string) ($menu['name'] ?? '')) === $group) {
                return $key;
            }
        }

        return null;
    }

    /**
     * 取该节点实际使用的子节点键（保留既有别名写法）。
     *
     * @param array<string, mixed> $node
     */
    private function childKeyOf(array $node): string
    {
        foreach (MenuSchema::CHILD_KEYS as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                return $key;
            }
        }

        return MenuSchema::CHILD_KEYS[0];
    }
}

<?php
declare(strict_types=1);

namespace app\common\service;

use wise\support\contracts\MenuItemProvider;

/**
 * 由 `menu.php` 驱动的菜单声明实现（**零 PHP 包**用）
 *
 * 一个标准 Composer 包如果不想写 PHP 类、也不想要 ServiceProvider，只需在包根放一个
 * `menu.php`，并在 `composer.json` 里声明 `extra.think.menus` 指向它：
 *
 * ```json
 * "extra": { "think": { "menus": "menu.php" } }
 * ```
 *
 * 宿主通过 {@see PackageMenuRegistrar} 把它包装成本类实例并注册到
 * `ExtPoints::MENU_ITEM`，之后与「包自带 MenuItemProvider」「插件 manifest.menus」
 * 走**同一条**落库链路（{@see \app\admin\service\MenuItemSync}），
 * 因此幂等同步 / 差集清理 / 卸载清理的行为完全一致。
 *
 * ## 文件格式
 *
 * 返回 {@see MenuItemProvider} 两个方法的**数组化**结果（不是插件 manifest.menus 的平铺树）：
 *
 * ```php
 * return [
 *     'containers' => [ ['key' => 'entry', 'name' => '我的应用', 'mount' => MenuMount::LAUNCHER], ... ],
 *     'items'      => [ ['container' => 'entry', 'name' => '首页', 'path' => '/admin/x/index', 'type' => 'menu'], ... ],
 * ];
 * ```
 *
 * 支持 `.php`（返回数组）与 `.json`（JSON 对象）两种文件。
 *
 * ## ident
 *
 * **不在 menu.php 里写**：由 {@see PackageRegistry::identFor()} 统一给出
 * （`extra.think.ident` → 包名 basename），保证与 `menus.plugin`、wise-config
 * 命名空间、卸载清理键同源。
 */
final class PackageMenuProvider implements MenuItemProvider
{
    /**
     * @param array<string, mixed> $spec   菜单声明（containers / items）
     * @param string               $ident  实现标识（写入 menus.plugin）
     * @param string               $source 声明文件路径（排障用）
     */
    public function __construct(
        private array $spec,
        private string $ident,
        private string $source = ''
    ) {
    }

    public function ident(): string
    {
        return $this->ident;
    }

    /**
     * 容器声明（侧边栏一级目录 / 启动台入口）
     *
     * @return array<int, array<string, mixed>>
     */
    public function containers(): array
    {
        return $this->normalizeNodes($this->spec['containers'] ?? []);
    }

    /**
     * 菜单节点树
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return $this->normalizeNodes($this->spec['items'] ?? []);
    }

    /**
     * 声明来源文件（排障 / 展示）
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * 读取并校验 menu.php / menu.json
     *
     * @param  string $file 绝对路径
     * @return array<string, mixed>
     * @throws \RuntimeException 文件缺失、格式不支持或返回非数组
     */
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('菜单声明文件不存在：' . $file);
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $data = json_decode((string) file_get_contents($file), true);
        } elseif ($ext === 'php') {
            $data = include $file;
        } else {
            throw new \RuntimeException('菜单声明仅支持 .php / .json：' . $file);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('菜单声明必须返回数组：' . $file);
        }

        if (!isset($data['containers']) || !is_array($data['containers']) || $data['containers'] === []) {
            throw new \RuntimeException('菜单声明缺少 containers（至少 1 个容器）：' . $file);
        }

        if (isset($data['items']) && !is_array($data['items'])) {
            throw new \RuntimeException('菜单声明的 items 必须是数组：' . $file);
        }

        return [
            'containers' => $data['containers'],
            'items'      => $data['items'] ?? [],
        ];
    }

    /**
     * 过滤掉非数组节点（脏数据直接丢，交给 MenuSchema 再做字段级归一）
     *
     * @param  mixed $nodes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNodes($nodes): array
    {
        if (!is_array($nodes)) {
            return [];
        }

        return array_values(array_filter($nodes, static fn ($node): bool => is_array($node)));
    }
}

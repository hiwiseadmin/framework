<?php
declare(strict_types=1);

namespace app\admin\search;

use app\admin\contract\SearchProviderInterface;
use app\admin\facade\CurrentAdmin;
use app\admin\model\Menu;

/**
 * 菜单搜索提供者
 *
 * 直接复用 Menu::getMenuByRoleId() 获取当前用户的 RBAC 菜单树，
 * 确保只返回用户有权访问的菜单项。
 */
class MenuSearchProvider implements SearchProviderInterface
{
    public function getId(): string
    {
        return 'menu';
    }

    public function getName(): string
    {
        return '菜单';
    }

    public function getIcon(): string
    {
        return 'bi bi-folder';
    }

    public function search(string $keyword, int $limit = 10): array
    {
        try {
            $menus = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());
        } catch (\Throwable $e) {
            return [];
        }

        $results = [];
        $this->searchInTree($menus, $keyword, $results, $limit, '');

        return $results;
    }

    /**
     * 递归搜索菜单树
     */
    private function searchInTree(array $menus, string $keyword, array &$results, int $limit, string $breadcrumb): void
    {
        foreach ($menus as $menu) {
            if (count($results) >= $limit) {
                return;
            }

            $name          = $menu['name'] ?? '';
            $currentBread  = $breadcrumb ? $breadcrumb . ' / ' . $name : $name;

            // 仅搜索有 path 的菜单项（type=menu）
            if (($menu['type'] ?? '') === 'menu' && !empty($menu['path'])) {
                $score = $this->calcScore($keyword, $name);

                if ($score > 0) {
                    $results[] = [
                        'id'         => 'menu:' . ($menu['id'] ?? 0),
                        'title'      => $name,
                        'subtitle'   => $menu['path'] ?? '',
                        'url'        => $menu['path'] ?? '',
                        'icon'       => ($menu['icon'] ?? '') ?: 'bi bi-circle',
                        'category'   => '菜单',
                        'provider'   => 'menu',
                        'breadcrumb' => $currentBread,
                        'score'      => $score,
                    ];
                }
            }

            // 递归子菜单
            if (!empty($menu['childlist'])) {
                $this->searchInTree($menu['childlist'], $keyword, $results, $limit, $currentBread);
            }
        }
    }

    /**
     * 简单相关度评分：
     *  100 = 完全匹配
     *  80  = 前缀匹配
     *  60  = 包含匹配
     *  0   = 不匹配
     */
    private function calcScore(string $keyword, string $text): int
    {
        $text = trim($text);
        $kw   = trim($keyword);

        if ($text === $kw) {
            return 100;
        }
        if (mb_stripos($text, $kw) === 0) {
            return 80;
        }
        if (mb_stripos($text, $kw) !== false) {
            return 60;
        }
        return 0;
    }
}

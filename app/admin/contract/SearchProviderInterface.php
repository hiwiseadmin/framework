<?php
declare(strict_types=1);

namespace app\admin\contract;

/**
 * 搜索提供者接口
 *
 * 所有内置及插件提供的搜索源都需实现此接口，
 * 并在 SearchService 中注册后即可参与全局搜索。
 */
interface SearchProviderInterface
{
    /**
     * 提供者唯一标识
     * @return string 如 "menu" / "config" / "smartstock"
     */
    public function getId(): string;

    /**
     * 提供者显示名称
     * @return string 如 "菜单" / "系统配置"
     */
    public function getName(): string;

    /**
     * 图标 class（Bootstrap Icons）
     * @return string 如 "bi bi-folder"
     */
    public function getIcon(): string;

    /**
     * 执行搜索
     *
     * @param string $keyword 用户输入的关键词（已 trim 且非空）
     * @param int    $limit   最大返回条数
     * @return array<int, array{id: string, title: string, subtitle: string, url: string, icon: string, category: string, provider: string, breadcrumb: ?string, score: int}>
     */
    public function search(string $keyword, int $limit = 10): array;
}

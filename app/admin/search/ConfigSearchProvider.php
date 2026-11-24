<?php
declare(strict_types=1);

namespace app\admin\search;

use app\admin\contract\SearchProviderInterface;

/**
 * 系统配置搜索提供者
 *
 * 搜索 ws_configs 表中的 key / title / description 字段。
 * 仅搜索 status=1 的配置项，限制 10 条。
 */
class ConfigSearchProvider implements SearchProviderInterface
{
    public function getId(): string
    {
        return 'config';
    }

    public function getName(): string
    {
        return '系统配置';
    }

    public function getIcon(): string
    {
        return 'bi bi-gear';
    }

    public function search(string $keyword, int $limit = 10): array
    {
        try {
            $list = \wise\config\model\ConfigModel::where('status', 1)
                ->where(function ($query) use ($keyword) {
                    $query->where('key', 'like', "%{$keyword}%")
                          ->whereOr('title', 'like', "%{$keyword}%")
                          ->whereOr('description', 'like', "%{$keyword}%");
                })
                ->order('namespace', 'asc')
                ->order('group', 'asc')
                ->limit($limit)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $results = [];
        foreach ($list as $item) {
            $path  = ($item['namespace'] ?? 'default') . ':'
                   . ($item['group'] ?? 'system') . ':'
                   . ($item['key'] ?? '');

            $title = ($item['title'] ?? '') ?: ($item['key'] ?? '');
            $score = $this->calcScore($keyword, $title)
                   + $this->calcScore($keyword, $item['key'] ?? '')
                   + $this->calcScore($keyword, $item['description'] ?? '');

            $score = min($score, 70);

            if ($score > 0) {
                $results[] = [
                    'id'         => 'config:' . ($item['id'] ?? 0),
                    'title'      => $title,
                    'subtitle'   => $path,
                    'url'        => '/admin/wise_config/index',
                    'icon'       => 'bi bi-gear',
                    'category'   => '系统配置',
                    'provider'   => 'config',
                    'breadcrumb' => '系统 / 配置管理 / '
                                  . ($item['namespace'] ?? 'default')
                                  . ' / ' . ($item['group'] ?? 'system'),
                    'score'      => $score,
                ];
            }
        }

        return $results;
    }

    private function calcScore(string $keyword, string $text): int
    {
        $text = trim($text);
        $kw   = trim($keyword);
        if (empty($text)) {
            return 0;
        }
        if (mb_stripos($text, $kw) !== false) {
            return 30;
        }
        return 0;
    }
}

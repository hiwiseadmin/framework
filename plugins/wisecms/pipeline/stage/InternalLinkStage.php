<?php
namespace plugin\wisecms\pipeline\stage;

use plugin\wisecms\pipeline\PipelineStageInterface;
use plugin\wisecms\model\InternalLink;

class InternalLinkStage implements PipelineStageInterface
{
    public function getName(): string { return 'internal_link'; }
    public function getPriority(): int { return 55; }

    public function handle(array $data, string $scene = 'create'): array
    {
        if (empty($data['content'])) return $data;
        
        $siteId = $data['site_id'] ?? 1;
        $links = InternalLink::where('site_id', $siteId)->where('status', 1)->select()->toArray();
        if (empty($links)) return $data;

        $content = $data['content'];
        $inserted = 0;
        $maxLinks = 5;

        foreach ($links as $link) {
            if ($inserted >= $maxLinks) break;
            $keyword = preg_quote($link['keyword'], '/');
            // 仅在非标签文本中替换首次出现
            if (preg_match('/(?<!["\'>])(' . $keyword . ')(?![^<]*>)/iu', $content, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if ($pos !== false) {
                    $safeUrl   = htmlspecialchars($link['target_url'] ?? '', ENT_QUOTES, 'UTF-8');
                    $safeTitle = htmlspecialchars($link['target_title'] ?? '', ENT_QUOTES, 'UTF-8');
                    $safeKw    = htmlspecialchars($link['keyword'] ?? '', ENT_QUOTES, 'UTF-8');
                    $replacement = '<a href="' . $safeUrl . '" title="' . $safeTitle . '">' . $safeKw . '</a>';
                    $content = substr_replace($content, $replacement, $pos, strlen($link['keyword']));
                    $inserted++;
                }
            }
        }
        $data['content'] = $content;
        return $data;
    }
}

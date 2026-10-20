<?php
namespace plugin\wisecms\pipeline\stage;

use plugin\wisecms\pipeline\PipelineStageInterface;

class AutoDescriptionStage implements PipelineStageInterface
{
    public function getName(): string { return 'auto_description'; }
    public function getPriority(): int { return 20; }

    public function handle(array $data, string $scene = 'create'): array
    {
        if (!empty($data['description'])) return $data;
        
        $text = '';
        if (!empty($data['content'])) {
            $text = strip_tags($data['content']);
        } elseif (!empty($data['title'])) {
            $text = $data['title'];
        }
        if ($text) {
            $data['description'] = mb_strlen($text) > 200 
                ? mb_substr($text, 0, 200) . '...' 
                : $text;
        }
        return $data;
    }
}

<?php
namespace plugin\wisecms\pipeline;

interface PipelineStageInterface
{
    /**
     * 处理数据
     * @param array $data 内容数据
     * @param string $scene 场景: create|update
     * @return array 处理后的数据
     */
    public function handle(array $data, string $scene = 'create'): array;

    /**
     * 阶段名称
     */
    public function getName(): string;

    /**
     * 优先级（越小越早）
     */
    public function getPriority(): int;
}

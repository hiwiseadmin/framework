<?php
namespace plugin\wisecms\pipeline;

class ContentPipeline
{
    /** @var PipelineStageInterface[] */
    protected array $stages = [];

    /**
     * 注册处理阶段
     */
    public function registerStage(PipelineStageInterface $stage): self
    {
        $this->stages[$stage->getName()] = $stage;
        uasort($this->stages, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
        return $this;
    }

    /**
     * 移除阶段
     */
    public function removeStage(string $name): self
    {
        unset($this->stages[$name]);
        return $this;
    }

    /**
     * 获取所有阶段
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    /**
     * 执行完整处理链
     */
    public function process(array $data, string $scene = 'create'): array
    {
        foreach ($this->stages as $stage) {
            try {
                $data = $stage->handle($data, $scene);
            } catch (\Exception $e) {
                // 阶段失败不阻断整体流程，记录日志
                // Log::error("Pipeline stage [{$stage->getName()}] failed: " . $e->getMessage());
                continue;
            }
        }
        return $data;
    }
}

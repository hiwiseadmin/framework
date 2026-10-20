<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\model\Match3Event;
use plugin\match3game\service\level\Match3Random;

/**
 * 肉鸽事件节点服务（Phase 4 · 04 §"事件节点 → 随机抉择（风险/收益）"）
 *
 * 事件配置在 `match3_event` 表（策划自助维护，05 §B6.4 要求 ≥ 20 个）。
 * 抽取按 (level_seed, nodeId, layer) **确定性**：断点续玩看到同一个事件。
 *
 * 选项效果复用 `RunEffects` 的效果字典。
 */
class EventService
{
    protected Match3RunService $runs;

    public function __construct(?Match3RunService $runs = null)
    {
        $this->runs = $runs ?? new Match3RunService();
    }

    /**
     * 进入事件节点：抽取（或读回）事件
     *
     * @return array{event:array<string,mixed>, settled:bool, chosen:?string}
     * @throws Match3NodeException
     */
    public function enter(int $userId, string $runId, string $nodeId): array
    {
        $ctx     = $this->runs->loadCurrentNode($userId, $runId, $nodeId, Match3NodeService::NODE_EVENT);
        $run     = $ctx['run'];
        $nodeRow = $ctx['node'];

        $payload = is_array($nodeRow['node_payload'] ?? null) ? $nodeRow['node_payload'] : [];

        if (empty($payload['event'])) {
            $mapNode = Match3MapGenerator::findNode($run['map_data'], $nodeId);
            $layer   = (int) ($mapNode['layer'] ?? 1);

            $event = self::pick($this->loadEvents(), (int) $run['level_seed'], $nodeId, $layer);
            if ($event === null) {
                // 没有可用事件 → 空事件兜底（节点直接可过）
                $event = [
                    'event_id' => 'ev_empty',
                    'title'    => '寂静之地',
                    'desc'     => '什么也没有发生，你继续前进。',
                    'options'  => [['id' => 'leave', 'label' => '继续前进', 'effects' => []]],
                ];
            }

            $payload['event'] = $event;
            $this->runs->persistNodePayload((int) $nodeRow['id'], $payload);
        }

        return [
            'event'   => self::present((array) $payload['event']),
            'settled' => (bool) ($payload['settled'] ?? false),
            'chosen'  => $payload['choice'] ?? null,
        ];
    }

    /**
     * 选择事件选项
     *
     * @return array{applied:array<int,string>, failed:array<int,string>, nextNodes:array<int,string>, lives:int, coins:int}
     * @throws Match3NodeException
     */
    public function choose(int $userId, string $runId, string $nodeId, string $optionId): array
    {
        $ctx     = $this->runs->loadCurrentNode($userId, $runId, $nodeId, Match3NodeService::NODE_EVENT);
        $run     = $ctx['run'];
        $nodeRow = $ctx['node'];

        $payload = is_array($nodeRow['node_payload'] ?? null) ? $nodeRow['node_payload'] : [];

        if (!empty($payload['settled'])) {
            throw new Match3NodeException('该事件已结算', Match3NodeException::CODE_NODE_SETTLED);
        }

        $event = (array) ($payload['event'] ?? []);
        if ($event === []) {
            throw new Match3NodeException('事件数据缺失，请重新进入节点', Match3NodeException::CODE_NODE_NOT_FOUND);
        }

        $option = null;
        foreach ((array) ($event['options'] ?? []) as $opt) {
            if (is_array($opt) && (string) ($opt['id'] ?? '') === $optionId) {
                $option = $opt;
                break;
            }
        }
        if ($option === null) {
            throw new Match3NodeException('选项不存在: ' . $optionId, Match3NodeException::CODE_NODE_NOT_FOUND);
        }

        $meta = $this->runs->getMeta($userId);
        $pool = (new RelicService())->pool((array) ($meta['unlocked_relics'] ?? []));

        $result = RunEffects::apply($run, (array) ($option['effects'] ?? []), [
            'seed'       => (int) $run['level_seed'],
            'salt'       => (int) crc32('event' . $nodeId . $optionId),
            'pool'       => $pool,
            'obtainedAt' => $nodeId,
        ]);

        $payload['settled'] = true;
        $payload['choice']  = $optionId;
        $payload['applied'] = $result['applied'];
        $payload['failed']  = $result['failed'];

        $this->runs->persistRunState($runId, $run);
        $this->runs->persistNodePayload((int) $nodeRow['id'], $payload, true);

        return [
            'applied'   => $result['applied'],
            'failed'    => $result['failed'],
            'nextNodes' => Match3MapGenerator::nextNodeIds($run['map_data'], $nodeId),
            'lives'     => (int) $run['lives'],
            'coins'     => (int) $run['coins'],
            'relics'    => array_column((array) $run['relics'], 'id'),
        ];
    }

    /* ======================================================================
     *  纯逻辑
     * ====================================================================== */

    /**
     * 确定性抽取事件（可离线单测）
     *
     * @param  array<int,array<string,mixed>> $events
     * @return array<string,mixed>|null
     */
    public static function pick(array $events, int $seed, string $nodeId, int $layer): ?array
    {
        $candidates = array_values(array_filter($events, static function ($e) {
            return (int) ($e['enabled'] ?? 1) === 1
                && is_array($e['options'] ?? null)
                && $e['options'] !== [];
        }));

        // 优先满足层限制；若全部不满足则放宽（避免无事件可出）
        $byLayer = array_values(array_filter($candidates, static fn ($e) => (int) ($e['min_layer'] ?? 1) <= $layer));
        if ($byLayer !== []) {
            $candidates = $byLayer;
        }

        if ($candidates === []) {
            return null;
        }

        $weights = [];
        foreach ($candidates as $e) {
            $weights[(string) ($e['event_id'] ?? '')] = max(1, (int) ($e['weight'] ?? 100));
        }

        $pickedId = (string) Match3Random::weightedPick($seed, $weights, (int) crc32('ev' . $nodeId), $layer);

        foreach ($candidates as $e) {
            if ((string) ($e['event_id'] ?? '') === $pickedId) {
                return $e;
            }
        }

        return $candidates[0];
    }

    /**
     * 对客户端的展示结构
     *
     * @param  array<string,mixed> $event
     * @return array<string,mixed>
     */
    public static function present(array $event): array
    {
        $options = [];
        foreach ((array) ($event['options'] ?? []) as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $options[] = [
                'id'    => (string) ($opt['id'] ?? ''),
                'label' => (string) ($opt['label'] ?? ''),
                'desc'  => (string) ($opt['desc'] ?? ''),
                'risk'  => (bool) ($opt['risk'] ?? false),
                // 预览"会发生什么"，但不暴露 effects 原始结构
                'preview' => RunEffects::describe((array) ($opt['effects'] ?? [])),
            ];
        }

        return [
            'id'      => (string) ($event['event_id'] ?? ''),
            'title'   => (string) ($event['title'] ?? ''),
            'desc'    => (string) ($event['desc'] ?? ''),
            'icon'    => (string) ($event['icon'] ?? ''),
            'options' => $options,
        ];
    }

    /**
     * 读取启用的事件配置
     *
     * @return array<int,array<string,mixed>>
     */
    protected function loadEvents(): array
    {
        return Match3Event::where('enabled', 1)->select()->toArray();
    }
}

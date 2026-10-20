<?php

declare(strict_types=1);

namespace plugin\match3game\service;

use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\exception\Match3Exception;
use plugin\match3game\model\Match3Prop;

/**
 * 局内/开局道具编排（文档 §2.3 / §7.1 use_props / §7.2 items_used）
 *
 * 职责：
 *   - 校验道具定义存在且合法（单局使用上限）
 *   - 计算道具带来的加步数（add_moves）
 *   - 结算时实际扣减背包由 RewardBridgeService 完成
 */
class PropUsageService
{
    /** @var array 道具定义缓存 [code => Match3Prop 数组] */
    protected array $propCache = [];

    /**
     * 校验道具使用清单（结构与单局限量）
     *
     * @param array $itemsUsed [{'item'=>string, 'count'=>int}, ...]
     * @return array 校验后的规范化清单 [code => count]
     * @throws Match3Exception 道具不存在或超单局限量
     */
    public function validateAndNormalize(array $itemsUsed): array
    {
        $normalized = [];

        foreach ($itemsUsed as $item) {
            $code  = (string) ($item['item'] ?? ($item['code'] ?? ''));
            $count = (int) ($item['count'] ?? 0);

            if ($code === '' || $count <= 0) {
                continue;
            }

            $prop = $this->getProp($code);
            if ($prop === null) {
                throw Match3Exception::from(Match3ErrorCode::SETTLE_INVALID, "道具不存在: {$code}");
            }

            $limit = (int) ($prop['per_battle_limit'] ?? 0);
            if ($limit > 0 && $count > $limit) {
                throw Match3Exception::from(
                    Match3ErrorCode::SETTLE_INVALID,
                    sprintf('道具 %s 超出单局使用上限 %d', $code, $limit)
                );
            }

            $normalized[$code] = ($normalized[$code] ?? 0) + $count;
        }

        return $normalized;
    }

    /**
     * 计算道具清单带来的加步数（add_moves 类）
     *
     * @param array $normalized [code => count]
     */
    public function calcAddedMoves(array $normalized): int
    {
        $added = 0;

        foreach ($normalized as $code => $count) {
            $prop = $this->getProp($code);
            if ($prop === null) {
                continue;
            }
            $effect = $prop['effect'] ?? [];
            if (($effect['type'] ?? '') === 'add_moves') {
                $added += (int) ($effect['count'] ?? 0) * $count;
            }
        }

        return $added;
    }

    /**
     * 按关卡 props 协议换算实际扣费数量（02 §5.2）
     *
     * props = {
     *   "allowed": [...],
     *   "costOnUse": {"hammer":1, "bomb":2, ...},   // 每次使用的扣费量
     *   "freePerTry": 1                             // 每局免费使用次数（总量）
     * }
     *
     * 未配置 props 时按使用次数 1:1 扣费（兼容未配置关卡）。
     *
     * @param array      $normalized [code => 使用次数]
     * @param array|null $props      关卡 props 配置
     * @return array [code => 实际扣费数量]
     */
    public function costOf(array $normalized, ?array $props): array
    {
        if (!is_array($props) || $props === []) {
            return $normalized;
        }

        $costOnUse  = (array) ($props['costOnUse'] ?? []);
        $freeRemain = (int) ($props['freePerTry'] ?? 0);

        $out = [];
        foreach ($normalized as $code => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            // 先扣免费额度
            if ($freeRemain > 0) {
                $free = min($freeRemain, $count);
                $count       -= $free;
                $freeRemain  -= $free;
            }
            if ($count <= 0) {
                continue;
            }

            $unit = (int) ($costOnUse[$code] ?? 1);
            $out[$code] = $count * max(1, $unit);
        }

        return $out;
    }

    /**
     * 读取道具定义（带缓存）
     *
     * @return array|null 道具定义数组（含 effect/price/per_battle_limit）
     */
    public function getProp(string $code): ?array
    {
        if (isset($this->propCache[$code])) {
            return $this->propCache[$code];
        }

        $row = Match3Prop::where('code', $code)->find();

        return $this->propCache[$code] = $row ? $row->toArray() : null;
    }

    /**
     * 判断道具是否属于局内道具（kind=2，结算后扣减）
     */
    public function isBattleProp(string $code): bool
    {
        $prop = $this->getProp($code);
        return $prop !== null && (int) ($prop['kind'] ?? 0) === Match3Prop::KIND_BATTLE;
    }
}

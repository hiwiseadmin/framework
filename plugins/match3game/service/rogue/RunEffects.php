<?php

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

use plugin\match3game\service\level\Match3Random;

/**
 * 肉鸽局内效果字典（Phase 4）
 *
 * 商店、休息、事件三类节点共用同一套效果定义 —— 策划只写 effects，不用碰代码。
 *
 * 【效果列表】
 *   coin          value:N          灵玉 +N（负数扣减）
 *   life          value:N          失败机会 ±N（夹在 [0, maxLives]）
 *   buff_moves    value:N          本局**后续每个节点**步数 +N
 *   score_buff    value:N          本局分数加成 +N%（作用于 node buffs）
 *   coin_buff     value:N          本局灵玉加成 +N%
 *   relic         rarity?/relic_id? 获得 1 个遗物（指定 id 或按稀有度随机）
 *   curse                          随机获得 1 个诅咒遗物（等价 relic + rarity=cursed）
 *   remove_curse  value:N          移除 N 个诅咒遗物（默认 1）
 *   upgrade       relic_id         将指定遗物升级 1 级（仅"可升级"遗物）
 *   stone         value:N          立即获得 meta 灵石（记在 run.buffs.stone）
 *
 * 【纯逻辑】本类不碰数据库：遗物候选池由调用方通过 `$ctx['pool']` 传入，
 * 因此可以完整离线单测（见 tests/verify-rogue-ext.php）。
 */
final class RunEffects
{
    public const KIND_COIN         = 'coin';
    public const KIND_LIFE         = 'life';
    public const KIND_BUFF_MOVES   = 'buff_moves';
    public const KIND_SCORE_BUFF   = 'score_buff';
    public const KIND_COIN_BUFF    = 'coin_buff';
    public const KIND_RELIC        = 'relic';
    public const KIND_CURSE        = 'curse';
    public const KIND_REMOVE_CURSE = 'remove_curse';
    public const KIND_UPGRADE      = 'upgrade';
    public const KIND_STONE        = 'stone';

    /** 遗物最大升级等级 */
    public const MAX_RELIC_LEVEL = 2;

    /**
     * 应用一组效果（直接修改 $run，不改数据库）
     *
     * @param array<string,mixed> $run  run 行（会就地修改 coins/lives/relics/buffs）
     * @param array<int,array<string,mixed>> $effects
     * @param array<string,mixed> $ctx {
     *     seed:int, salt:int,
     *     pool:array<int,array<string,mixed>>   遗物候选池（含 relic_id/rarity/patch）
     * }
     * @return array{applied:array<int,string>, failed:array<int,string>, gained:array<string,mixed>}
     */
    public static function apply(array &$run, array $effects, array $ctx = []): array
    {
        $applied = [];
        $failed  = [];
        $gained  = ['relics' => [], 'coins' => 0, 'lives' => 0, 'stone' => 0, 'buffs' => []];

        $seed    = (int) ($ctx['seed'] ?? 0);
        $salt    = (int) ($ctx['salt'] ?? 0);
        $pool    = (array) ($ctx['pool'] ?? []);
        $maxLife = (int) ($ctx['maxLives'] ?? RogueRules::BASE_LIVES);

        // 补全 run 结构
        $run['coins']  = (int) ($run['coins'] ?? 0);
        $run['lives']  = (int) ($run['lives'] ?? $maxLife);
        $run['relics'] = is_array($run['relics'] ?? null) ? $run['relics'] : [];
        $run['buffs']  = is_array($run['buffs'] ?? null) ? $run['buffs'] : [];

        foreach ($effects as $i => $effect) {
            if (!is_array($effect)) {
                $failed[] = "[{$i}] 效果不是对象";
                continue;
            }

            $kind  = (string) ($effect['kind'] ?? '');
            $value = (int) ($effect['value'] ?? 0);

            switch ($kind) {
                case self::KIND_COIN:
                    $run['coins'] = max(0, $run['coins'] + $value);
                    $gained['coins'] += $value;
                    $applied[] = "灵玉 " . self::signed($value);
                    break;

                case self::KIND_LIFE:
                    $before      = $run['lives'];
                    $run['lives'] = max(0, min($maxLife, $before + $value));
                    $delta        = $run['lives'] - $before;
                    $gained['lives'] += $delta;
                    $applied[] = '失败机会 ' . self::signed($delta);
                    break;

                case self::KIND_BUFF_MOVES:
                    $run['buffs']['moves'] = (int) ($run['buffs']['moves'] ?? 0) + $value;
                    $gained['buffs']['moves'] = $run['buffs']['moves'];
                    $applied[] = "后续每关步数 " . self::signed($value);
                    break;

                case self::KIND_SCORE_BUFF:
                    $run['buffs']['scorePercent'] = (int) ($run['buffs']['scorePercent'] ?? 0) + $value;
                    $gained['buffs']['scorePercent'] = $run['buffs']['scorePercent'];
                    $applied[] = "分数 " . self::signed($value) . '%';
                    break;

                case self::KIND_COIN_BUFF:
                    $run['buffs']['coinPercent'] = (int) ($run['buffs']['coinPercent'] ?? 0) + $value;
                    $gained['buffs']['coinPercent'] = $run['buffs']['coinPercent'];
                    $applied[] = "灵玉 " . self::signed($value) . '%';
                    break;

                case self::KIND_RELIC:
                case self::KIND_CURSE:
                    $rarity = $kind === self::KIND_CURSE ? 'cursed' : (string) ($effect['rarity'] ?? '');
                    $relic  = self::pickRelic($pool, $run['relics'], (string) ($effect['relic_id'] ?? ''), $rarity, $seed, $salt + $i);

                    if ($relic === null) {
                        $failed[] = '没有可获得的遗物（池已抽干或全部持有）';
                        break;
                    }
                    $run['relics'][]           = ['id' => $relic['relic_id'], 'obtainedAt' => (string) ($ctx['obtainedAt'] ?? 'event'), 'lv' => 1];
                    $gained['relics'][]        = $relic['relic_id'];
                    $applied[]                 = '获得遗物「' . $relic['name'] . '」';
                    break;

                case self::KIND_REMOVE_CURSE:
                    $need    = max(1, $value);
                    $removed = [];
                    foreach ($run['relics'] as $idx => $r) {
                        if (count($removed) >= $need) {
                            break;
                        }
                        $cfg = self::findRelic($pool, (string) ($r['id'] ?? ''));
                        if ($cfg !== null && ($cfg['rarity'] ?? '') === 'cursed') {
                            $removed[] = $r['id'];
                            unset($run['relics'][$idx]);
                        }
                    }
                    $run['relics'] = array_values($run['relics']);
                    if ($removed === []) {
                        $failed[] = '没有可移除的诅咒遗物';
                        break;
                    }
                    $applied[] = '移除诅咒遗物 ' . implode('、', $removed);
                    break;

                case self::KIND_UPGRADE:
                    $targetId = (string) ($effect['relic_id'] ?? '');
                    $ok       = false;
                    foreach ($run['relics'] as $idx => $r) {
                        if ((string) ($r['id'] ?? '') !== $targetId) {
                            continue;
                        }
                        $cfg = self::findRelic($pool, $targetId);
                        if ($cfg === null || !RelicService::isUpgradable((array) ($cfg['patch'] ?? []))) {
                            $failed[] = "遗物不可升级: {$targetId}";
                            break;
                        }
                        $lv = (int) ($r['lv'] ?? 1);
                        if ($lv >= self::MAX_RELIC_LEVEL) {
                            $failed[] = "遗物已达最高等级: {$targetId}";
                            break;
                        }
                        $run['relics'][$idx]['lv'] = $lv + 1;
                        $applied[]                 = "遗物「{$cfg['name']}」升至 Lv" . ($lv + 1);
                        $ok                        = true;
                        break;
                    }
                    if (!$ok && $failed === []) {
                        $failed[] = "未持有该遗物: {$targetId}";
                    }
                    break;

                case self::KIND_STONE:
                    $run['buffs']['stone'] = (int) ($run['buffs']['stone'] ?? 0) + $value;
                    $gained['stone']       = $run['buffs']['stone'];
                    $applied[]             = "灵石 " . self::signed($value);
                    break;

                default:
                    $failed[] = "未知效果类型: {$kind}";
            }
        }

        return ['applied' => $applied, 'failed' => $failed, 'gained' => $gained];
    }

    /**
     * 效果预览文案（给客户端展示"这个选项会发生什么"）
     *
     * @param array<int,array<string,mixed>> $effects
     * @return array<int,string>
     */
    public static function describe(array $effects): array
    {
        $out = [];
        foreach ($effects as $e) {
            if (!is_array($e)) {
                continue;
            }
            $kind  = (string) ($e['kind'] ?? '');
            $value = (int) ($e['value'] ?? 0);

            $out[] = match ($kind) {
                self::KIND_COIN         => '灵玉 ' . self::signed($value),
                self::KIND_LIFE         => '失败机会 ' . self::signed($value),
                self::KIND_BUFF_MOVES   => '后续每关步数 ' . self::signed($value),
                self::KIND_SCORE_BUFF   => '分数 ' . self::signed($value) . '%',
                self::KIND_COIN_BUFF    => '灵玉 ' . self::signed($value) . '%',
                self::KIND_RELIC        => '获得遗物' . (($e['rarity'] ?? '') !== '' ? '（' . $e['rarity'] . '）' : ''),
                self::KIND_CURSE        => '获得诅咒遗物',
                self::KIND_REMOVE_CURSE => '移除 ' . max(1, $value) . ' 个诅咒遗物',
                self::KIND_UPGRADE      => '升级遗物 ' . (string) ($e['relic_id'] ?? ''),
                self::KIND_STONE        => '灵石 ' . self::signed($value),
                default                 => $kind,
            };
        }

        return $out;
    }

    /* ---------------- 内部 ---------------- */

    /**
     * 选取遗物（指定 id 优先；否则按稀有度；再否则全池）
     *
     * @param array<int,array<string,mixed>> $pool
     * @param array<int,array<string,mixed>> $owned
     * @return array<string,mixed>|null
     */
    protected static function pickRelic(array $pool, array $owned, string $relicId, string $rarity, int $seed, int $salt): ?array
    {
        $ownedIds = array_map(static fn ($r) => (string) ($r['id'] ?? ''), $owned);

        $candidates = array_values(array_filter($pool, static function ($r) use ($ownedIds, $relicId, $rarity) {
            $id = (string) ($r['relic_id'] ?? '');
            if ($id === '' || in_array($id, $ownedIds, true)) {
                return false;
            }
            if ($relicId !== '') {
                return $id === $relicId;
            }
            if ($rarity !== '') {
                return (string) ($r['rarity'] ?? '') === $rarity;
            }
            return true;
        }));

        if ($candidates === []) {
            return null;
        }

        $weights = [];
        foreach ($candidates as $r) {
            $weights[(string) $r['relic_id']] = max(1, (int) ($r['weight'] ?? 100));
        }

        $pickedId = (string) Match3Random::weightedPick($seed, $weights, $salt, crc32('effectRelic'));

        foreach ($candidates as $r) {
            if ((string) $r['relic_id'] === $pickedId) {
                return $r;
            }
        }

        return $candidates[0];
    }

    /**
     * @param array<int,array<string,mixed>> $pool
     * @return array<string,mixed>|null
     */
    protected static function findRelic(array $pool, string $relicId): ?array
    {
        foreach ($pool as $r) {
            if ((string) ($r['relic_id'] ?? '') === $relicId) {
                return $r;
            }
        }

        return null;
    }

    protected static function signed(int $v): string
    {
        return ($v >= 0 ? '+' : '') . $v;
    }
}

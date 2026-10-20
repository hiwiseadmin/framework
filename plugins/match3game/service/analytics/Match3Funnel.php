<?php

declare(strict_types=1);

namespace plugin\match3game\service\analytics;

use plugin\match3game\contract\Match3Constants;
use wise\support\context\ContextualLogger;

/**
 * 鍩嬬偣婕忔枟锛圥6-B05 路 06 搂涔濓細start/enter/pass/fail/clear 鍏ㄦ紡鏂楅綈锛?
 *
 * 銆愪簨浠舵竻鍗曘€?
 *   鍥哄畾鍏冲崱閾捐矾锛歮atch3.level.start / level.pass / level.fail
 *   鑲夐附灞€閾捐矾  锛歮atch3.run.start / run.clear / run.lose / run.abandon
 *   鑲夐附鑺傜偣閾捐矾锛歮atch3.node.enter / node.pass / node.fail
 *   鍐崇瓥鍒嗘瀽    锛歮atch3.relic.pick锛堥仐鐗╅€夋嫨鍒嗗竷锛夈€乵atch3.run.abandon锛堥€€鍑虹偣鍒嗗竷锛?
 *   L3 鍙娴嬫€?锛歮atch3.replay锛堥噸鏀惧懡涓巼 / 闄嶇骇鐜?/ 鍒嗘暟鍋忓樊鍒嗗竷锛?
 *   澧為暱/褰掑洜   锛歮atch3.invite锛堥個璇凤級銆乵atch3.attribution锛堜拱閲忓綊鍥狅級
 *
 * 銆愯璁°€?
 *   路 鏋勯€犻€昏緫 `build()` 鏄?*绾嚱鏁?*锛堝彲绂荤嚎鍗曟祴锛宍tests/verify-funnel.php`锛?
 *   路 鍙戝皠閫昏緫 `emit()` 寰堣杽锛氬啓缁撴瀯鍖栨棩蹇?+ 杞彂涓嬫父鍒嗘瀽鏈嶅姟锛坈lass_exists 瀹堝崼锛?
 *   路 涓嬫父涓嶅彲鐢ㄦ椂**缁濅笉褰卞搷涓绘祦绋?*锛坱ry/catch 鍚炴帀锛?
 *
 * 銆愪负浠€涔堜笉鐢?RewardBridgeService::reportAnalytics銆?
 *   瀹冩槸 protected锛屼笖璇箟鏄?鍙戝鍚庣殑闄勫甫涓婃姤"锛涙紡鏂楄瑕嗙洊鐨勬槸**鍏ㄩ摼璺簨浠?*锛?
 *   鍏朵腑寰堝骞朵笉鍙戝锛堣繘鑺傜偣銆佸け璐ャ€佹斁寮冿級銆傚洜姝ょ嫭绔嬫垚涓€涓湇鍔°€?
 *
 * 銆愭棩蹇楅噰闆嗐€戠粺涓€鍓嶇紑 `[match3][funnel]`锛屾棩蹇楃郴缁熸寜璇ュ墠缂€寤虹储寮曞嵆鍙嚭婕忔枟鎶ヨ〃銆?
 */
final class Match3Funnel
{
    /* ---------------- 鍥哄畾鍏冲崱閾捐矾 ---------------- */
    public const LEVEL_START = 'match3.level.start';
    public const LEVEL_PASS  = 'match3.level.pass';
    public const LEVEL_FAIL  = 'match3.level.fail';

    /* ---------------- 鑲夐附閾捐矾 ---------------- */
    public const RUN_START   = 'match3.run.start';
    public const RUN_CLEAR   = 'match3.run.clear';
    public const RUN_LOSE    = 'match3.run.lose';
    public const RUN_ABANDON = 'match3.run.abandon';

    public const NODE_ENTER = 'match3.node.enter';
    public const NODE_PASS  = 'match3.node.pass';
    public const NODE_FAIL  = 'match3.node.fail';

    /* ---------------- 鍐崇瓥 / 鍙娴?---------------- */
    public const RELIC_PICK = 'match3.relic.pick';
    public const REPLAY     = 'match3.replay';

    /* ---------------- 澧為暱 / 褰掑洜 ---------------- */
    public const INVITE      = 'match3.invite';
    public const ATTRIBUTION = 'match3.attribution';

    /** 瀛楃涓插瓧娈垫渶澶ч暱搴︼紙闃叉鎶?schema 涔嬬被鐨勫ぇ瀵硅薄濉炶繘鍩嬬偣锛?*/
    public const MAX_STR = 64;

    /** 灞炴€ч敭涓婇檺 */
    public const MAX_KEYS = 24;

    /** 鏃ュ織鍓嶇紑锛堟棩蹇楃郴缁熸寜姝ゅ缓绱㈠紩锛?*/
    public const LOG_PREFIX = '[match3][funnel] ';

    /** 涓嬫父鍒嗘瀽鏈嶅姟锛堝彲閫夛紝鏈帴鍏ユ椂璺宠繃锛?*/
    public const ANALYTICS_CLASS = \wise\game\ops\service\AnalyticsService::class;

    /**
     * 鏋勯€犱簨浠讹紙**绾嚱鏁?*锛屾棤鍓綔鐢紝鍙绾垮崟娴嬶級
     *
     * @param  array<string,mixed> $props
     * @return array{event:string, user_id:int, game_id:string, ts:int, props:array<string,mixed>}
     */
    public static function build(string $event, int $userId, array $props, ?int $tsMs = null): array
    {
        return [
            'event'   => substr(trim($event), 0, 48),
            'user_id' => $userId,
            'game_id' => Match3Constants::GAME_ID,
            'ts'      => $tsMs ?? (int) round(microtime(true) * 1000),
            'props'   => self::sanitize($props),
        ];
    }

    /**
     * 鍙戝皠浜嬩欢锛堟棩蹇?+ 涓嬫父鍒嗘瀽鏈嶅姟锛?
     *
     * @param array<string,mixed> $props
     */
    public static function emit(string $event, int $userId, array $props = []): void
    {
        $ev = self::build($event, $userId, $props);

        // 鈶?缁撴瀯鍖栨棩蹇楋紙閲囬泦渚у缓绱㈠紩鍗冲彲鍑烘紡鏂楁姤琛級
        ContextualLogger::info(self::LOG_PREFIX . $ev['event'], [
            'user_id' => $ev['user_id'],
            'ts'      => $ev['ts'],
            'props'   => $ev['props'],
        ]);

        // 鈶?涓嬫父鍒嗘瀽鏈嶅姟锛堟湭鎺ュ叆鏃堕潤榛樿烦杩囷級
        if (!class_exists(self::ANALYTICS_CLASS)) {
            return;
        }

        try {
            $analytics = app()->make(self::ANALYTICS_CLASS);
            $analytics->trackEvent(Match3Constants::GAME_ID, $ev['event'], $ev['user_id'], $ev['props']);
        } catch (\Throwable $e) {
            // 鍩嬬偣澶辫触缁濅笉褰卞搷涓绘祦绋?
            ContextualLogger::warning('[match3] 鍩嬬偣涓婃姤澶辫触: ' . $e->getMessage(), ['event' => $ev['event']]);
        }
    }

    /* ======================================================================
     *  璇箟鍖栧揩鎹锋柟娉曪紙璋冪敤鐐规洿鍙锛?
     * ====================================================================== */

    /**
     * 閲嶆斁鍙娴嬫€э紙L3 鎸囨爣锛?
     *
     * 杩欐槸鍒ゆ柇"PHP 閲嶆斁鑳藉惁鍒?strict / 鏄惁闇€瑕?Node"鐨勬暟鎹潵婧愶細
     *   mode=degraded 鈫?寮曟搸鏃犳硶蹇犲疄閲嶆斁锛堥檷绾х巼锛?
     *   suspicious=1  鈫?涓婃姤鍒嗚秴杩囬噸鏀句笂鐣岋紙鍛戒腑鐜囷級
     *   delta         鈫?涓婃姤鍒?- 閲嶆斁鍒嗭紙鍋忓樊鍒嗗竷锛?
     *
     * @param array<string,mixed> $extra
     */
    /**
     * 鍗曟潯璁板綍**鏄惁绠楀垎姝?*锛圫5-4锛?
     *
     * 鍒ゅ畾锛歚mode === 'degraded'`锛堝紩鎿庢棤娉曞繝瀹為噸鏀撅級鎴?`suspicious !== 0`
     * 锛堜笂鎶ュ垎瓒呰繃閲嶆斁涓婄晫锛夈€備换涓€鍛戒腑閮芥剰鍛崇潃
     * 銆屾湇鍔＄閲嶆斁涓嶅嚭瀹㈡埛绔殑缁撴灉銆嶁€斺€?瑕佷箞绠楁硶婕傜Щ锛岃涔堜笂鎶ヤ笉瀹炪€?
     */
    public static function isDivergent(array $props): bool
    {
        // ① 引擎降级：本端重放不出结果（口径不明，等于没校验）
        if ((string) ($props['mode'] ?? '') === 'degraded') {
            return true;
        }

        // ② 分数超上界：上报分 > 重放分
        if ((int) ($props['suspicious'] ?? 0) !== 0) {
            return true;
        }

        // ③ 逐回合棋盘摘要不一致（S5-3 产出）：分数可能对得上，但**过程**不是同一盘棋，
        //    这比"分数偏高"更早暴露算法漂移 —— 观察期 `checkpoint_strict=false` 时
        //    它不拒单，只能靠这里被统计到。
        return (int) ($props['checkpointMismatch'] ?? 0) !== 0;
    }

    /**
     * 分桶维度：**客户端包版本**，缺失时回落算法版本（S5-4）
     *
     * 【为什么允许回落】客户端包版本是**可选**上报字段（老包不带），
     *   而"算法版本" `fill_version` 每次提交都有，且它才是"会不会算错"的直接相关量。
     *   一律按 clientVersion 分桶的话，未上报的会全部落进 `unknown` 一个桶，
     *   等于把最需要区分的样本混在一起。
     *
     * @param array<string,mixed> $props
     */
    public static function bucketOf(array $props): string
    {
        $client = trim((string) ($props['clientVersion'] ?? ''));
        if ($client !== '') {
            return $client;
        }

        $fill = trim((string) ($props['fillVersion'] ?? ''));
        if ($fill !== '') {
            return 'fill:' . $fill;
        }

        return 'unknown';
    }

    /**
     * 超阈值的版本 → **强更名单**（S5-4 的 actionable 输出）
     *
     * ⚠️ 强更 ≠ 拒单：只是提示更新，**成绩照常判定**（宁可放过，不可误杀）。
     *
     * @param  array<int,array<string,mixed>> $records
     * @return array<int,string> 需要强更的版本（分歧率 ≥ 阈值）
     */
    public static function versionsToForceUpdate(array $records, float $threshold = 0.05, int $minSamples = 20): array
    {
        $out = [];

        foreach (self::divergence($records, $threshold) as $version => $bucket) {
            // 样本太少时率不稳定（1/1 = 100%），不能据此强更
            if ((int) $bucket['total'] < $minSamples) {
                continue;
            }
            if (!empty($bucket['alert'])) {
                $out[] = (string) $version;
            }
        }

        return $out;
    }

    /**
     * 鍒嗘鐜囩粺璁★紙S5-4锛夛細鎸?**`clientVersion`** 鑱氬悎
     *
     * 銆愭暟鎹簮銆戞湰绫?`emit()` / `replay()` 钀戒笅鐨?*缁撴瀯鍖栨棩蹇?*
     *   锛坄ContextualLogger::info('[Match3] 鈥?, ['props' => 鈥)`锛夈€?
     *   鐩戞帶渚ф妸鏃ュ織瑙ｆ瀽鎴愩€岃褰曘€嶅杺杩涙潵鍗冲彲 鈥斺€?鏈柟娉?*涓嶇鏁版嵁搴?*锛屽洜姝ゅ彲鍗曟祴銆?
     *
     * 銆愬彛寰勩€?
     *   路 `total`    = 璇ョ増鏈殑鎻愪氦鏁帮紙鍚檷绾т笌姝ｅ父锛?
     *   路 `mismatch` = 鍏朵腑琚?`isDivergent()` 鍒や负鍒嗘鐨勬潯鏁?
     *   路 `rate`     = mismatch / total锛坱otal = 0 鏃惰 0锛?
     *   路 `alert`    = rate >= $threshold 鈥斺€?鍛戒腑搴斿璇ョ増鏈?*寮烘洿**
     *
     * 鈿狅笍 寮烘洿 鈮?鎷掑崟锛氬彧鏄彁绀烘洿鏂帮紝**鎴愮哗鐓у父鍒ゅ畾**锛堜笉鍙鏉€锛夈€?
     *
     * @param  array<int,array<string,mixed>> $records 褰掍竴鍖栬褰曪紙瀛楁鍦ㄩ《灞傛垨 `props` 閲屽潎鍙級
     * @return array<string,array<string,mixed>>
     */
    public static function divergence(array $records, float $threshold = 0.05): array
    {
        $buckets = [];

        foreach ($records as $row) {
            if (!is_array($row)) {
                continue;
            }

            $props   = is_array($row['props'] ?? null) ? (array) $row['props'] : $row;
            $version = self::bucketOf($props);

            $bucket = $buckets[$version] ?? [
                'total'              => 0,
                'mismatch'           => 0,
                'mismatchDegraded'   => 0,
                'mismatchScore'      => 0,
                'mismatchCheckpoint' => 0,
            ];

            $bucket['total']++;
            if (self::isDivergent($props)) {
                $bucket['mismatch']++;

                // 三类成因分开记：定位"该修引擎 / 该查作弊 / 该查算法漂移"全靠它
                if ((string) ($props['mode'] ?? '') === 'degraded') {
                    $bucket['mismatchDegraded']++;
                } elseif ((int) ($props['checkpointMismatch'] ?? 0) !== 0) {
                    $bucket['mismatchCheckpoint']++;
                } else {
                    $bucket['mismatchScore']++;
                }
            }

            $buckets[$version] = $bucket;
        }

        foreach ($buckets as $version => $bucket) {
            $rate = $bucket['total'] > 0 ? $bucket['mismatch'] / $bucket['total'] : 0.0;

            $buckets[$version]['rate']  = $rate;
            $buckets[$version]['alert'] = $rate >= $threshold;
        }

        ksort($buckets);

        return $buckets;
    }

    public static function replay(
        int $userId,
        string $mode,
        bool $replayed,
        bool $suspicious,
        ?int $replayScore,
        ?int $claimScore,
        array $extra = []
    ): void {
        self::emit(self::REPLAY, $userId, array_merge([
            'mode'        => $mode,
            'replayed'    => $replayed ? 1 : 0,
            'suspicious'  => $suspicious ? 1 : 0,
            'replayScore' => $replayScore,
            'claimScore'  => $claimScore,
            'delta'       => ($claimScore !== null && $replayScore !== null) ? ($claimScore - $replayScore) : null,
        ], $extra));
    }

    /* ======================================================================
     *  鍐呴儴
     * ====================================================================== */

    /**
     * 灞炴€ф竻娲楋細鍘绘帀闈炴爣閲忋€佹埅鏂暱瀛楃涓层€侀檺鍒堕敭鏁伴噺
     *
     * 鐩殑锛氬煁鐐规暟鎹細琚暱鏈熷瓨鍌ㄥ拰鑱氬悎锛岀粷涓嶈兘鎶?schema / ops 杩欑澶у璞″啓杩涘幓銆?
     *
     * @param  array<string,mixed> $props
     * @return array<string,mixed>
     */
    protected static function sanitize(array $props): array
    {
        $out = [];

        foreach ($props as $key => $value) {
            if (count($out) >= self::MAX_KEYS) {
                break;
            }

            $key = is_string($key) ? substr($key, 0, 40) : (string) $key;
            if ($key === '') {
                continue;
            }

            if ($value === null || is_bool($value)) {
                $out[$key] = $value === null ? null : ($value ? 1 : 0);
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $out[$key] = is_float($value) ? round($value, 4) : $value;
                continue;
            }

            if (is_string($value)) {
                $out[$key] = mb_substr($value, 0, self::MAX_STR);
                continue;
            }

            if (is_array($value)) {
                // 鏁扮粍鍙繚鐣?鏍囬噺鍏冪礌"锛屼笖鍘嬫垚閫楀彿鍒嗛殧锛堝 relics 鍒楄〃锛?
                $flat = [];
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $flat[] = (string) $item;
                    }
                    if (count($flat) >= 16) {
                        break;
                    }
                }
                $out[$key] = $flat === [] ? '' : implode(',', $flat);
                continue;
            }

            // 瀵硅薄绛夛細鍙绫诲瀷锛屼笉璁板唴瀹?
            $out[$key] = '[' . get_debug_type($value) . ']';
        }

        return $out;
    }

}

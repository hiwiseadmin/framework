<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * 三消棋盘内核（**唯一一份** PHP 侧棋盘逻辑）
 *
 * 【为什么要有这个类】
 *   `LevelSimulator`（AI 通关率估算）与 `Match3PhpReplayEngine`（L3 重放校验）
 *   需要**完全相同**的棋盘语义：构建、交换、连线、消除、重力、补货、连锁。
 *   如果各写一份，就会出现"仓库内两套三消实现"，正是跨端漂移的根源。
 *   因此把内核抽到这里，两边都调它。
 *
 * 【设计约束（为兼容性刻意如此）】
 *   全部是 **static + 数组进数组出**，方法体从 `LevelSimulator` **逐字搬移**，
 *   不做任何"顺手优化"——因为 `LevelSimulator` 的结果被关卡生成器的 seed 筛选依赖，
 *   行为漂移会导致已生成的关卡无法复现。
 *   重构前后用 `tests/_baseline_dump.php` 抓的基线做逐值比对（9 个 CASE + 棋盘指纹）。
 *
 * 【分数口径可配置】
 *   `scoreRule = ['perCell' => int, 'comboMul' => float[]]` 由调用方传入：
 *     · LevelSimulator 传自己的 `SCORE_PER_CELL = 10`（估算用，保持不变）
 *     · Match3PhpReplayEngine 传 `rules.scorePerCell`（缺失时 60），
 *       **这是"把魔法数字变成协议字段"的关键** —— 与客户端对齐只改 schema，不改代码。
 */
final class Match3Board
{
    /**
     * 重放引擎的默认单格基础分
     *
     * 与 `SubmissionValidator::SCORE_PER_CELL` 取同一常量口径（协议约定值），
     * 可被 `rules.scorePerCell` 覆盖。
     */
    public const DEFAULT_SCORE_PER_CELL = 60;

    /** 默认连锁倍率（02 §2.2 T9） */
    public const COMBO_MUL = [1.0, 1.2, 1.5, 2.0, 2.5, 3.0];

    /** 单回合最大连锁次数（防御死循环） */
    public const MAX_CASCADE = 50;

    /** 覆盖层字符 → 目标层名 */
    public const OVERLAY_LAYER = [
        'I' => 'ice', 'J' => 'ice', 'L' => 'jelly',
        'K' => 'snow', 'Q' => 'chocolate', 'W' => 'poison',
    ];

    /** 不可交换的固定元素（牢笼/石块/藤蔓/太妃糖） */
    public const NON_SWAPPABLE_ENTITY = 'XCGT';

    /** 锁定覆盖层 */
    public const LOCKED_OVERLAY = 'N';

    /**
     * 冰封覆盖层字符（冰）
     *
     * 【语义】与 `N`（锁定）一样，冰**锁住这一格**：
     *   · 不能参与交换（`swappable`）；
     *   · 格内元素**不下落**，别的元素也不能穿过它（`gravity` 把冰格当隔板）。
     *
     * 【为什么必须有这条】2026-09-27 真机反馈的三条 bug（冰封元素还能被拖走 / 冰"先能打碎、之后再也打不碎" /
     * 冰封元素跟着掉落）**同源**：覆盖层当时只参与"目标计数"，从未参与交换与重力判定。
     *
     * ⚠️ TS 侧同名常量在 `match3game/src/core/match3-board.ts`，**改动必须两边同步**（跨语言比对会拦）。
     */
    public const FROZEN_OVERLAY = 'IJ';

    /**
     * 冰封覆盖层字符 → **剩余层数**（`01-level-layout-design.md`：`I`=单层冰、`J`=双层冰）
     */
    public const ICE_LAYERS = ['I' => 1, 'J' => 2];

    /** 剩余层数 → 冰封字符（减层后写回） */
    public const ICE_CHAR_BY_LAYERS = [1 => 'I', 2 => 'J'];

    /* ======================================================================
     *  棋盘构建
     * ====================================================================== */

    /**
     * 由 schema 构建可玩棋盘状态
     *
     * ⚠️ 方法体与重构前的 `LevelSimulator::buildState()` 完全一致。
     * `$seed` 必须是**已派生好**的最终种子（调用方决定派生方式）。
     *
     * @return array{
     *   rows:int, cols:int, playable:array, blocked:array, overlay:array,
     *   color:array, colors:array, index:array
     * }
     */
    public static function buildState(array $schema, int $seed): array
    {
        $board   = (array) ($schema['board'] ?? []);
        $rows    = (int) ($board['rows'] ?? 9);
        $cols    = (int) ($board['cols'] ?? 9);
        $regions = (array) ($schema['regions'] ?? []);

        $playable = [];
        $blocked  = [];
        $overlay  = [];
        for ($r = 0; $r < $rows; $r++) {
            $playable[$r] = array_fill(0, $cols, false);
            $blocked[$r]  = array_fill(0, $cols, false);
            $overlay[$r]  = array_fill(0, $cols, '.');
        }

        foreach ($regions as $rg) {
            $terrain = (array) ($rg['layers']['terrain'] ?? []);
            $ovLayer = (array) ($rg['layers']['overlay'] ?? []);
            $enLayer = (array) ($rg['layers']['entity'] ?? []);
            $originR = (int) ($rg['origin']['r'] ?? 0);
            $originC = (int) ($rg['origin']['c'] ?? 0);

            foreach ($terrain as $r => $line) {
                $gr = $originR + (int) $r;
                if ($gr < 0 || $gr >= $rows) {
                    continue;
                }
                $line = (string) $line;
                for ($c = 0; $c < strlen($line); $c++) {
                    $gc = $originC + $c;
                    if ($gc < 0 || $gc >= $cols) {
                        continue;
                    }
                    $ch = $line[$c];
                    if (str_contains(LevelSchemaFactory::STANDABLE_TERRAIN, $ch)) {
                        $playable[$gr][$gc] = true;
                    }
                    if (isset($ovLayer[$r][$c]) && $ovLayer[$r][$c] !== '.') {
                        $overlay[$gr][$gc] = (string) $ovLayer[$r][$c];
                    }
                    if (isset($enLayer[$r][$c]) && str_contains(self::NON_SWAPPABLE_ENTITY, (string) $enLayer[$r][$c])) {
                        $blocked[$gr][$gc] = true;
                    }
                }
            }
        }

        // 元素池：palette.gems × regions[0].spawn.weights
        $pool   = LevelSchemaFactory::elementPool($schema);
        $colors = [];
        $index  = [];
        foreach ($pool as $p) {
            $index[$p['element']] = count($colors);
            $colors[] = $p['element'];
        }
        if ($colors === []) {
            $colors = ['red_gem', 'blue_gem', 'yellow_gem'];
            $index  = array_flip($colors);
            $index  = array_map('intval', $index);
        }

        // 填充颜色（不可站立格保持 null）
        //
        // ⚠️ 逐格避开**初始连线**（`fill_version` v1 → v2，2026-09-22 契约变更）：
        //    原先均匀采样会产生"该消却不消"的永久死块（实测 12/12 个 seed 都有）。
        //    与 TS `match3-board.ts::pickSeedWithoutRun` 必须逐字同序。
        $color = [];
        for ($r = 0; $r < $rows; $r++) {
            $color[$r] = array_fill(0, $cols, null);
            for ($c = 0; $c < $cols; $c++) {
                $color[$r][$c] = $playable[$r][$c]
                    ? self::pickColorSeedAvoidingRun($seed, $r, $c, count($colors), $color[$r], $color)
                    : null;
            }
        }

        return [
            'rows'     => $rows,
            'cols'     => $cols,
            'playable' => $playable,
            'blocked'  => $blocked,
            'overlay'  => $overlay,
            'color'    => $color,
            'colors'   => $colors,
            'index'    => $index,
        ];
    }

    /**
     * 按 (seed, r, c) 派生的填充色
     *
     * 注意方法名带 `Seed` 后缀：重构前叫 `LevelSimulator::pickColor($seed, ...)`，
     * 语义就是"以 $seed 为基派生"，不是"某个 pickColor 实例方法"。
     */
    public static function pickColorSeed(int $seed, int $r, int $c, int $colorCount): int
    {
        $v = Match3Random::next($seed, $r, $c, crc32('fill'));

        return (int) min($colorCount - 1, (int) floor($v * $colorCount));
    }

    /**
     * 取一个**不会与已填格构成初始连线**的颜色
     *
     * 【为什么需要（`fill_version` v1 → v2，2026-09-22 契约变更）】
     *   原先逐格均匀采样、不避开初始匹配：6 色 9×9 期望约 3.5 处 3 连，
     *   实测 12/12 个 seed 都会出现 —— 玩家看到"该消却不消"的永久死块。
     *
     * 【做法】按扫描顺序（行升序、列升序）逐格取色，因此只可能与**左侧两格 / 上方两格**
     *   构成连线；冲突时按 `(base + shift) % colorCount` 顺延，最多 `colorCount` 次。
     *
     * ⚠️ 与 TS `match3-board.ts::pickSeedWithoutRun` 必须逐字同序 ——
     *    跨语言向量（`export-board-vectors.php` ↔ `tests/match3-board.test.ts`）逐值比对。
     *
     * @param  array<int, int|null>       $row    当前行已填的格子（左邻）
     * @param  array<int, array<int, int|null>> $placed 已完成的整行（上邻）
     */
    public static function pickColorSeedAvoidingRun(
        int $seed,
        int $r,
        int $c,
        int $colorCount,
        array $row,
        array $placed
    ): int {
        $base = self::pickColorSeed($seed, $r, $c, $colorCount);

        // 少于 3 色时无法保证避开（也不构成三消玩法），保持原采样
        if ($colorCount < 3) {
            return $base;
        }

        for ($shift = 0; $shift < $colorCount; $shift++) {
            $value   = ($base + $shift) % $colorCount;
            $leftRun = ($row[$c - 1] ?? null) === $value && ($row[$c - 2] ?? null) === $value;
            $upRun   = ($placed[$r - 1][$c] ?? null) === $value
                && ($placed[$r - 2][$c] ?? null) === $value;

            if (!$leftRun && !$upRun) {
                return $value;
            }
        }

        return $base; // 理论不可达（$colorCount ≥ 3 时总能找到不连线的颜色）
    }

    /* ======================================================================
     *  消除 / 掉落 / 补货
     * ====================================================================== */

    /**
     * 找出所有 ≥minMatch 的连线格
     *
     * @return array<int, array{0:int,1:int}>
     */
    public static function findMatches(array $state, int $minMatch): array
    {
        $rows = $state['rows'];
        $cols = $state['cols'];
        $color = $state['color'];
        $hits = [];

        // 横向
        for ($r = 0; $r < $rows; $r++) {
            $runStart = 0;
            for ($c = 1; $c <= $cols; $c++) {
                $same = $c < $cols
                    && $color[$r][$c] !== null
                    && $color[$r][$c] === $color[$r][$runStart];
                if ($same) {
                    continue;
                }
                $len = $c - $runStart;
                if ($color[$r][$runStart] !== null && $len >= $minMatch) {
                    for ($i = $runStart; $i < $c; $i++) {
                        $hits["{$r},{$i}"] = [$r, $i];
                    }
                }
                $runStart = $c;
            }
        }

        // 纵向
        for ($c = 0; $c < $cols; $c++) {
            $runStart = 0;
            for ($r = 1; $r <= $rows; $r++) {
                $same = $r < $rows
                    && $color[$r][$c] !== null
                    && $color[$r][$c] === $color[$runStart][$c];
                if ($same) {
                    continue;
                }
                $len = $r - $runStart;
                if ($color[$runStart][$c] !== null && $len >= $minMatch) {
                    for ($i = $runStart; $i < $r; $i++) {
                        $hits["{$i},{$c}"] = [$i, $c];
                    }
                }
                $runStart = $r;
            }
        }

        return array_values($hits);
    }

    /**
     * 移除命中格：计分 + 覆盖层破除
     *
     * @param array<int, array{0:int,1:int}> $cells
     * @param array{perCell:int, comboMul:float[]} $scoreRule
     */
    public static function removeCells(
        array &$state,
        array $cells,
        int $combo,
        array &$collected,
        array &$cleared,
        array $scoreRule
    ): int {
        $comboMul = $scoreRule['comboMul'] ?: self::COMBO_MUL;
        $perCell  = (int) ($scoreRule['perCell'] ?? self::DEFAULT_SCORE_PER_CELL);

        $mul     = $comboMul[min($combo, count($comboMul) - 1)];
        $removed = 0;

        /*
         * ① 冰：先结算"命中"（**自身 + 上下左右相邻**），被冰吸走的格本轮不消元素
         *
         *  规则与 TS 侧同名函数逐句对应（见 13 §四十三）：
         *   · 直击冰封格 → 冰先碎、**元素留下**，再消一次才消元素；
         *   · 相邻被消除 → 冰也会化（经典规则）。
         */
        $absorbed = [];
        $iceHits  = [];

        $markIce = static function (array $state, int $r, int $c) use (&$iceHits): void {
            if ($r < 0 || $r >= $state['rows'] || $c < 0 || $c >= $state['cols']) {
                return;
            }
            if (self::isFrozen($state, $r, $c)) {
                $iceHits[$r . ',' . $c] = true;
            }
        };

        foreach ($cells as [$r, $c]) {
            if (self::isFrozen($state, $r, $c)) {
                $iceHits[$r . ',' . $c]  = true;
                $absorbed[$r . ',' . $c] = true;
            }

            $markIce($state, $r - 1, $c);
            $markIce($state, $r + 1, $c);
            $markIce($state, $r, $c - 1);
            $markIce($state, $r, $c + 1);
        }

        foreach (array_keys($iceHits) as $key) {
            [$r, $c] = array_map('intval', explode(',', (string) $key));
            $ov   = $state['overlay'][$r][$c];
            $left = (self::ICE_LAYERS[$ov] ?? 1) - 1;

            if ($left > 0) {
                $state['overlay'][$r][$c] = self::ICE_CHAR_BY_LAYERS[$left] ?? 'I';
                continue;
            }

            // 化尽 → 计入目标（数的是"破了几块冰"，不是打了几层）
            if (isset(self::OVERLAY_LAYER[$ov])) {
                $layer = self::OVERLAY_LAYER[$ov];
                $cleared[$layer] = ($cleared[$layer] ?? 0) + 1;
            }
            $state['overlay'][$r][$c] = '.';

            // 【§五十 A1 已撤销 · 2026-10-02】被**相邻震碎**（本次没直击这一格）→ 只碎冰、
            //    **元素留下**（与"直击"同口径："先破冰，再消一次才消元素"）。
            //    TS 侧同名改动在 `match3game/src/core/match3-board.ts`，**必须逐句对齐**。
        }

        // ② 元素：其余覆盖层照旧"命中即破"，然后消元素
        foreach ($cells as [$r, $c]) {
            if (isset($absorbed[$r . ',' . $c])) {
                continue; // 被冰吸走：元素留下
            }

            $ov = $state['overlay'][$r][$c];
            if ($ov !== '.' && isset(self::OVERLAY_LAYER[$ov])) {
                $layer = self::OVERLAY_LAYER[$ov];
                $cleared[$layer] = ($cleared[$layer] ?? 0) + 1;
                $state['overlay'][$r][$c] = '.';
            }

            if ($state['color'][$r][$c] === null) {
                continue;
            }
            $gem = $state['colors'][$state['color'][$r][$c]];
            $collected[$gem] = ($collected[$gem] ?? 0) + 1;
            $removed++;

            $state['color'][$r][$c] = null;
        }

        return (int) round($removed * $perCell * $mul);
    }

    /**
     * 重力（相位 1）：每列可玩格内的元素向下压实，**冰格是隔板**
     *
     * 【冰封格是"隔板"】2026-09-27 真机 bug ③：冰封元素本该定在原地，实际却跟着掉落
     * （下方被消除后它掉到底部，而冰留在原格 → 该格变成"冰盖着空格"，随后触发 bug ② 再也打不碎）。
     * 口径：**冰格内的元素不动**，且冰格不可穿越 —— 同一列被冰格切成若干段，每段各自向下压实。
     *
     * ⚠️ 不可站立格**透明**：既不承载也不隔断（元素穿过它继续往下）。
     */
    private static function compactColumns(array &$state): void
    {
        $rows = $state['rows'];

        for ($c = 0; $c < $state['cols']; $c++) {
            $stack = [];
            $write = $rows - 1;

            for ($r = $rows - 1; $r >= 0; $r--) {
                if (!$state['playable'][$r][$c]) {
                    continue;
                }
                if (self::isFrozen($state, $r, $c)) {
                    // 冰封格：先落定它下面那一段，再把它当成新的"底部"
                    self::settleSegment($state, $c, $write, $stack);
                    $stack = [];
                    $write = $r - 1;
                    continue;
                }
                if ($state['color'][$r][$c] !== null) {
                    $stack[] = $state['color'][$r][$c];
                    $state['color'][$r][$c] = null;
                }
            }

            self::settleSegment($state, $c, $write, $stack);
        }
    }

    /** 目标格能不能落：在盘内、可站立、非冰、且为空 */
    private static function canLandAt(array $state, int $r, int $c): bool
    {
        if ($r < 0 || $r >= $state['rows'] || $c < 0 || $c >= $state['cols']) {
            return false;
        }
        if (!$state['playable'][$r][$c] || self::isFrozen($state, $r, $c)) {
            return false;
        }

        return $state['color'][$r][$c] === null;
    }

    /**
     * 这一格还能不能**沿本列继续下落**（相位 1 压实之后的判定）
     *
     * 压实完成后，"正下方"只可能是：冰格（隔板）/ 别的宝石（堆着）/ 盘外 / 不可站立格。
     * 空的可玩格不可能出现 —— 出现的话压实早就把这颗宝石挪下去了。
     * 这四种都算"落不下去"。
     */
    private static function cannotFall(array $state, int $r, int $c): bool
    {
        $nr = $r + 1;
        if ($nr >= $state['rows']) {
            return true;
        }

        return self::isFrozen($state, $nr, $c)
            || $state['color'][$nr][$c] !== null
            || !$state['playable'][$nr][$c];
    }

    /**
     * 这一格是不是**被冰封死的空位**（冰下口袋）
     *
     * 【判据】它自己那一列的**上方**存在冰格 → 冰上面的宝石落不进来，只能靠补货凭空生成。
     * 【为什么区分】"被别的宝石挡住"时若可滑向任意空的斜下格，整堆宝石会向两侧**摊开**
     * （非经典手感）。加这道判据后只去填"重力永远填不到"的冰下口袋。
     */
    private static function shadowedByIce(array $state, int $r, int $c): bool
    {
        for ($up = $r - 1; $up >= 0; $up--) {
            if (self::isFrozen($state, $up, $c)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 这一格是不是**死格**：重力永远填不进去，补货也不该往里凭空塞
     *
     * 【判据（两条同时成立才算）】
     *   ① 它自己那一列的**上方有冰**（`shadowedByIce`）→ 冰上面的宝石落不下来；
     *   ② 左右两个"斜上方"（`$r-1, $c±1`）**都不可用**（出界 / 不可站立 / 被冰封）
     *      → 也没有宝石能斜滑进来（斜落只跨紧邻一格）。
     *
     * 【典型形态（开心消消乐同款）】横向三连冰 `1abc2`：a 下方由左边的 1 斜落填、
     * c 下方由右边的 2 斜落填，**b 下方谁也进不来 → 保持空位**；冰化开后自愈。
     *
     * ⚠️ 补货必须跳过死格：否则会凭空生一颗，且表现层会让它"从冰块里钻出来"。
     */
    private static function isSealed(array $state, int $r, int $c): bool
    {
        if (!self::shadowedByIce($state, $r, $c)) {
            return false; // 本列上方没冰 → 新宝石能从上方落进来
        }

        $up = $r - 1;
        if ($up < 0) {
            return false;
        }

        foreach ([-1, 1] as $dc) {
            $nc = $c + $dc;
            if ($nc < 0 || $nc >= $state['cols']) {
                continue;
            }
            // 这个"斜上方"能站宝石且不是冰 → 就可能有宝石从那儿斜滑进来
            if ($state['playable'][$up][$nc] && !self::isFrozen($state, $up, $nc)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 重力（相位 2）：**落不下去时斜落一格**（一轮每个候选最多动一次）
     *
     * 【规则（G1 方案 A · 2026-10-02 改，与 TS 端逐句对应）】
     *   · **触发**：本列落不下去（正下方是冰 / 别的宝石 / 盘外 / 不可站立）；
     *   · **方向**：优先**右下**，不行再**左下**（只跨一格）；
     *   · **目标格**：盘内、可站立、非冰、为空；
     *   · **去处限制**（避免摊开）：
     *       - 正下方是**冰** → 任意空的斜下格都能去（绕冰，旧规则保留）；
     *       - 只是被**别的宝石**挡住 → **只能去"被冰封死的空位"**；
     *   · 不可站立格依旧透明；斜落只是位移：不消耗步数、不影响得分。
     *
     * @return bool 本轮是否发生了位移
     */
    private static function slideAroundIce(array &$state): bool
    {
        $moved = false;

        // 扫描顺序固定（自下而上、左→右）→ 与 TS 端结果逐值一致
        for ($r = $state['rows'] - 2; $r >= 0; $r--) {
            for ($c = 0; $c < $state['cols']; $c++) {
                if (!$state['playable'][$r][$c] || self::isFrozen($state, $r, $c)) {
                    continue;
                }
                if ($state['color'][$r][$c] === null) {
                    continue;
                }
                if (!self::cannotFall($state, $r, $c)) {
                    continue; // 还能沿本列直落 → 交给相位 1
                }

                $blockedByIce = self::isFrozen($state, $r + 1, $c);

                foreach ([1, -1] as $dc) {
                    $nc = $c + $dc;
                    // 优先右下
                    if (!self::canLandAt($state, $r + 1, $nc)) {
                        continue;
                    }
                    // 只是"被宝石挡住"时只填冰下口袋 —— 否则会向两侧摊开
                    if (!$blockedByIce && !self::shadowedByIce($state, $r + 1, $nc)) {
                        continue;
                    }
                    $state['color'][$r + 1][$nc] = $state['color'][$r][$c];
                    $state['color'][$r][$c] = null;
                    $moved = true;
                    break;
                }
            }
        }

        return $moved;
    }

    /**
     * 重力：列内压实 + 被冰挡住时斜落，迭代到稳定
     *
     * 【为什么是"压实 → 斜落 → 再压实"】斜落一格后该宝石往往还能继续往下掉，
     * 反复两相位的效果就是"绕着冰走楼梯"；只做一次斜落会让宝石悬在半空。
     * 迭代上限 `rows × cols + 8`（与 TS 端同值）—— 每轮至少下沉一行，正常盘面远达不到。
     */
    public static function gravity(array &$state): void
    {
        self::compactColumns($state);

        $maxRounds = $state['rows'] * $state['cols'] + 8;

        for ($round = 0; $round < $maxRounds; $round++) {
            if (!self::slideAroundIce($state)) {
                return; // 稳定
            }
            self::compactColumns($state);
        }
    }

    /**
     * 落定一列中的一段（`gravity` 内部用）
     *
     * @param int[] $stack 已按"自下而上"收集的取值
     */
    private static function settleSegment(array &$state, int $c, int $write, array $stack): void
    {
        $i = 0;
        for ($r = $write; $r >= 0 && $i < count($stack); $r--) {
            if (!$state['playable'][$r][$c] || self::isFrozen($state, $r, $c)) {
                continue;
            }
            $state['color'][$r][$c] = $stack[$i++];
        }
    }

    /**
     * 补货：把空的可玩格按派生种子填满
     */
    public static function refill(array &$state, int $seed): void
    {
        $colorCount = count($state['colors']);

        for ($r = 0; $r < $state['rows']; $r++) {
            for ($c = 0; $c < $state['cols']; $c++) {
                if (!$state['playable'][$r][$c] || $state['color'][$r][$c] !== null) {
                    continue;
                }
                // 死格（被冰封死、谁也进不来）→ **保持空位**，不凭空补货（见 `isSealed`）
                if (self::isSealed($state, $r, $c)) {
                    continue;
                }
                $state['color'][$r][$c] = self::pickColorSeed($seed, $r, $c, $colorCount);
            }
        }
    }

    /**
     * 确定性洗牌
     */
    public static function shuffleBoard(array &$state, int $seed): void
    {
        $cells = [];
        for ($r = 0; $r < $state['rows']; $r++) {
            for ($c = 0; $c < $state['cols']; $c++) {
                if ($state['playable'][$r][$c]) {
                    $cells[] = [$r, $c];
                }
            }
        }

        $shuffled = Match3Random::shuffle($seed, $cells, crc32('shuffleCells'));
        $values   = [];
        foreach ($cells as [$r, $c]) {
            $values[] = $state['color'][$r][$c];
        }
        $values = Match3Random::shuffle($seed, $values, crc32('shuffleVals'));

        foreach ($shuffled as $i => [$r, $c]) {
            $state['color'][$r][$c] = $values[$i] ?? null;
        }
    }

    /**
     * 完整连锁结算（消除 → 掉落 → 补货，直到不成线）
     *
     * 补货种子由 `$seedForCombo($combo)` 提供 —— 模拟器与重放引擎的
     * 派生盐不同（模拟器多一个试玩序号 k），因此把派生逻辑外置。
     *
     * @param  callable $seedForCombo fn(int $combo): int
     * @param  array{perCell:int, comboMul:float[]} $scoreRule
     * @param  int|null $maxCombo 出参：本次连锁的最大连击层数（重放引擎统计 maxCombo 用）
     * @return int 本次连锁累计得分
     */
    public static function resolveCascade(
        array &$state,
        int $minMatch,
        callable $seedForCombo,
        array $scoreRule,
        array &$collected,
        array &$cleared,
        ?int &$maxCombo = null
    ): int {
        $total = 0;
        $depth = 0;

        for ($combo = 0; $combo < self::MAX_CASCADE; $combo++) {
            $cells = self::findMatches($state, $minMatch);
            if ($cells === []) {
                break;
            }

            $total += self::removeCells($state, $cells, $combo, $collected, $cleared, $scoreRule);
            self::gravity($state);
            self::refill($state, (int) $seedForCombo($combo));
            $depth++;
        }

        if ($maxCombo !== null) {
            $maxCombo = max($maxCombo, $depth);
        }

        return $total;
    }

    /* ======================================================================
     *  交换
     * ====================================================================== */

    public static function swap(array &$state, int $r1, int $c1, int $r2, int $c2): void
    {
        $tmp = $state['color'][$r1][$c1];
        $state['color'][$r1][$c1] = $state['color'][$r2][$c2];
        $state['color'][$r2][$c2] = $tmp;
    }

    /**
     * 这一格是否被**冰封**（不能交换、不参与掉落）
     *
     * 只认**冰**（`I`/`J`）：果冻/积雪/巧克力/毒液只是"覆盖物"，不锁格。
     * 锁定层 `N` 另有 `LOCKED_OVERLAY` 判断（它也不在 `OVERLAY_LAYER` 里，所以不会被消除）。
     */
    public static function isFrozen(array $state, int $r, int $c): bool
    {
        $ov = (string) ($state['overlay'][$r][$c] ?? '.');

        // ⚠️ 空串必须显式排除：`str_contains('IJ', '')` 是 `true`
        return $ov !== '.' && $ov !== '' && str_contains(self::FROZEN_OVERLAY, $ov);
    }

    public static function swappable(array $state, int $r, int $c): bool
    {
        if (!$state['playable'][$r][$c] || $state['blocked'][$r][$c]) {
            return false;
        }
        if (($state['overlay'][$r][$c] ?? '.') === self::LOCKED_OVERLAY) {
            return false;
        }
        // 冰封格不能交换（真机 bug ①：冰封着的元素还能被拖走）
        if (self::isFrozen($state, $r, $c)) {
            return false;
        }

        return $state['color'][$r][$c] !== null;
    }

    /**
     * 以 (r,c) 为中心，返回横/纵连线中达到 minMatch 的格子总数（0 = 不成线）
     */
    public static function lineCountAt(array $state, int $r, int $c, int $minMatch): int
    {
        $color = $state['color'][$r][$c] ?? null;
        if ($color === null) {
            return 0;
        }

        $total = 0;

        // 横向
        $left = $c;
        while ($left - 1 >= 0 && $state['color'][$r][$left - 1] === $color) {
            $left--;
        }
        $right = $c;
        while ($right + 1 < $state['cols'] && $state['color'][$r][$right + 1] === $color) {
            $right++;
        }
        $hLen = $right - $left + 1;
        if ($hLen >= $minMatch) {
            $total += $hLen;
        }

        // 纵向
        $top = $r;
        while ($top - 1 >= 0 && $state['color'][$top - 1][$c] === $color) {
            $top--;
        }
        $bottom = $r;
        while ($bottom + 1 < $state['rows'] && $state['color'][$bottom + 1][$c] === $color) {
            $bottom++;
        }
        $vLen = $bottom - $top + 1;
        if ($vLen >= $minMatch) {
            $total += $vLen;
        }

        return $total;
    }

    /**
     * 是否存在至少一步有效交换（贪心判据）
     */
    public static function hasAnyMove(array $state, int $minMatch): bool
    {
        $rows = $state['rows'];
        $cols = $state['cols'];

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if (!self::swappable($state, $r, $c)) {
                    continue;
                }
                foreach ([[0, 1], [1, 0]] as [$dr, $dc]) {
                    $r2 = $r + $dr;
                    $c2 = $c + $dc;
                    if ($r2 >= $rows || $c2 >= $cols || !self::swappable($state, $r2, $c2)) {
                        continue;
                    }
                    if ($state['color'][$r][$c] === $state['color'][$r2][$c2]) {
                        continue;
                    }

                    self::swap($state, $r, $c, $r2, $c2);
                    $hits = self::lineCountAt($state, $r, $c, $minMatch)
                          + self::lineCountAt($state, $r2, $c2, $minMatch);
                    self::swap($state, $r, $c, $r2, $c2);

                    if ($hits > 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /* ======================================================================
     *  诊断 / 口径
     * ====================================================================== */

    /**
     * 棋盘颜色层指纹（用于"棋盘分歧"检测：与客户端 checkpoint 的 h 同源）
     *
     * 只取颜色层，不含覆盖层——因为覆盖层的破除依赖消除顺序，
     * 分歧诊断时先定位到颜色层（RNG/填充/重力）更聚焦。
     */
    public static function colorFingerprint(array $state): string
    {
        $rows = [];
        foreach ($state['color'] as $row) {
            $rows[] = implode(',', array_map(static fn ($v) => $v === null ? '-' : (string) $v, $row));
        }

        return StableStringify::fingerprint($rows);
    }

    /**
     * 分数口径（协议字段优先）
     *
     * `rules.scorePerCell` / `rules.comboMul` 缺失时用默认值 ——
     * 这样与客户端对齐只需改 schema，不需要改代码、不需要发版。
     *
     * @param  array<string,mixed> $schema
     * @return array{perCell:int, comboMul:float[]}
     */
    public static function scoreRule(array $schema): array
    {
        $rules = (array) ($schema['rules'] ?? []);

        $perCell = (int) ($rules['scorePerCell'] ?? self::DEFAULT_SCORE_PER_CELL);

        $comboMul = self::COMBO_MUL;
        if (isset($rules['comboMul']) && is_array($rules['comboMul']) && $rules['comboMul'] !== []) {
            $comboMul = array_map('floatval', array_values($rules['comboMul']));
        }

        return ['perCell' => max(1, $perCell), 'comboMul' => $comboMul];
    }
}

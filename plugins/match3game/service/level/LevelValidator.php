<?php
/**
 * 关卡 Schema 校验器（Level Schema v1）
 *
 * 【为什么需要它】
 * 关卡是以「三层字符地图 + rules」形式存储的自由文本，编辑或程序生成都可能写坏：
 *   · 三层行数/列长不一致 → 前端解析错位
 *   · 没有生成器 S        → 棋盘永远补不上货，开局即死
 *   · 目标数量 = 0        → 开局即通关
 *   · 使用了图例外的字符   → 前端降级为空白格
 * 肉鸽阶段（Phase 3）的遗物 patch 是对 schema 的自由修改，风险更大：
 *   · 破釜沉舟叠加多次 → 步数变成负数
 *   · 一线天叠加      → 棋盘列数过小，与地形行长度不匹配
 *
 * 本校验器是**保险丝**：在保存/生成/patch 之后、下发给客户端之前执行。
 *
 * 【执行时机】
 *   ① 发布前：后台关卡保存（Level::save）+ AI 模拟器（Phase 2）
 *   ② 运行时：Match3NodeService::enterNode() 下发前（Phase 3）
 *
 * 【校验项】对应 01 §10.3 + 02 §9
 *   meta / board / regions（含字符合法、region 不重叠）/ rules（含目标可达）/ portals
 *
 * 迁移自 docs/match3/code/LevelValidator.php，异常类型由 Match3NodeException
 * 换成本插件的 Match3Exception（Phase 3 前不引入 rogue 包的异常）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\exception\Match3Exception;

class LevelValidator
{
    /** 棋盘尺寸合理区间 */
    public const MIN_BOARD_SIZE = 3;
    public const MAX_BOARD_SIZE = 15;

    /** 默认图例：terrain（01 §4.2 + 02 §9.2） */
    public const TERRAIN_CHARS = '.oS#DP><^v*';

    /** 默认图例：entity（01 §4.2 + 02 §9.2） */
    public const ENTITY_CHARS = '.0123456?HVBM CXGTabcdef';

    /** 默认图例：overlay（01 §4.2 + 02 §9.2） */
    public const OVERLAY_CHARS = '.IJLKQWN';

    /** 可作 collect 目标层的 overlay 类型 */
    public const CLEARABLE_LAYERS = ['ice', 'jelly', 'snow', 'vine', 'chocolate', 'poison', 'bubble', 'toffee'];

    /**
     * 校验关卡 schema
     *
     * @param  \stdClass|array<string,mixed> $level
     * @return array<int, string> 错误列表；空数组 = 通过
     */
    public static function validate($level): array
    {
        $errors = [];

        // 统一为数组访问形态
        $lv = is_object($level) ? json_decode(json_encode($level, JSON_UNESCAPED_UNICODE), true) : $level;

        if (!is_array($lv)) {
            return ['关卡 schema 格式非法'];
        }

        $errors = array_merge($errors, self::checkMeta($lv));
        $errors = array_merge($errors, self::checkBoard($lv));
        $errors = array_merge($errors, self::checkRegions($lv));
        $errors = array_merge($errors, self::checkRules($lv));
        $errors = array_merge($errors, self::checkPortals($lv));

        return $errors;
    }

    /**
     * 断言式校验：失败直接抛异常
     *
     * @throws Match3Exception
     */
    public static function assertValid($level): void
    {
        $errors = self::validate($level);
        if ($errors !== []) {
            throw Match3Exception::from(
                Match3ErrorCode::LEVEL_SCHEMA_INVALID,
                '关卡校验失败: ' . implode('; ', $errors)
            );
        }
    }

    /* ======================================================================
     *  各项校验
     * ====================================================================== */

    private static function checkMeta(array $lv): array
    {
        $errors = [];
        if (empty($lv['schema'])) {
            $errors[] = '缺少 schema 字段';
        }
        if (empty($lv['code'])) {
            $errors[] = '缺少 code 字段';
        }
        return $errors;
    }

    private static function checkBoard(array $lv): array
    {
        $errors = [];
        $board  = $lv['board'] ?? null;

        if (!is_array($board)) {
            return ['缺少 board 定义'];
        }

        foreach (['cols', 'rows'] as $k) {
            $v = $board[$k] ?? null;
            if (!is_numeric($v)) {
                $errors[] = "board.{$k} 缺失或不是数字";
                continue;
            }
            if ($v < self::MIN_BOARD_SIZE || $v > self::MAX_BOARD_SIZE) {
                $errors[] = "board.{$k}={$v} 超出合理区间 [" . self::MIN_BOARD_SIZE . ',' . self::MAX_BOARD_SIZE . ']';
            }
        }

        return $errors;
    }

    private static function checkRegions(array $lv): array
    {
        $errors  = [];
        $regions = $lv['regions'] ?? null;

        if (!is_array($regions) || $regions === []) {
            return ['regions 为空'];
        }

        $occupied = []; // 全局坐标占用表（用于 region 重叠检测）

        foreach ($regions as $i => $rg) {
            $prefix = "regions[{$i}]";

            $terrain = $rg['layers']['terrain'] ?? null;
            if (!is_array($terrain) || $terrain === []) {
                $errors[] = "{$prefix}.layers.terrain 为空";
                continue;
            }

            // 地形层每行长度必须一致
            $rowLen = strlen((string)$terrain[0]);
            foreach ($terrain as $r => $row) {
                if (strlen((string)$row) !== $rowLen) {
                    $errors[] = "{$prefix}.terrain 第 {$r} 行长度 " . strlen((string)$row) . " != {$rowLen}";
                }
            }

            // 字符合法性（01 §10.3 字符合法）
            foreach ($terrain as $r => $row) {
                $bad = self::unknownChars((string)$row, self::TERRAIN_CHARS);
                if ($bad !== []) {
                    $errors[] = "{$prefix}.terrain 第 {$r} 行含未知字符: " . implode('', $bad);
                }
            }

            // entity / overlay 层若存在，尺寸必须与 terrain 一致
            foreach (['entity', 'overlay'] as $layer) {
                $data = $rg['layers'][$layer] ?? null;
                if (!is_array($data) || $data === []) {
                    continue;
                }
                if (count($data) !== count($terrain)) {
                    $errors[] = "{$prefix}.layers.{$layer} 行数 " . count($data) . ' != terrain ' . count($terrain);
                    continue;
                }
                foreach ($data as $r => $row) {
                    if (strlen((string)$row) !== $rowLen) {
                        $errors[] = "{$prefix}.layers.{$layer} 第 {$r} 行长度与 terrain 不一致";
                    }
                    $legend = $layer === 'entity' ? self::ENTITY_CHARS : self::OVERLAY_CHARS;
                    $bad    = self::unknownChars((string)$row, $legend);
                    if ($bad !== []) {
                        $errors[] = "{$prefix}.layers.{$layer} 第 {$r} 行含未知字符: " . implode('', $bad);
                    }
                }
            }

            // 需要重力补货的 region 必须有生成器
            $gravity = $rg['gravity'] ?? 'down';
            if ($gravity !== 'none') {
                $hasSpawner = false;
                foreach ($terrain as $row) {
                    if (str_contains((string)$row, 'S')) {
                        $hasSpawner = true;
                        break;
                    }
                }
                $spawnMode = $rg['spawn']['mode'] ?? 'top';
                if (!$hasSpawner && $spawnMode === 'top') {
                    $errors[] = "{$prefix} gravity={$gravity} 但 terrain 中没有生成器 S";
                }
            }

            // region 不重叠（01 §10.3）：有效格（非 '.'）的全局坐标不得冲突
            $originC = (int) ($rg['origin']['c'] ?? 0);
            $originR = (int) ($rg['origin']['r'] ?? 0);

            // region 必须落在 board 边界内（防"一线天"类遗物把 board.cols 改小后地形越界）
            $board = $lv['board'] ?? null;
            if (is_array($board) && is_numeric($board['cols'] ?? null) && is_numeric($board['rows'] ?? null)) {
                if ($originC + $rowLen > (int) $board['cols']) {
                    $errors[] = "{$prefix} 超出 board 边界: origin.c({$originC}) + 列数({$rowLen}) > board.cols(" . (int) $board['cols'] . ')';
                }
                if ($originR + count($terrain) > (int) $board['rows']) {
                    $errors[] = "{$prefix} 超出 board 边界: origin.r({$originR}) + 行数(" . count($terrain) . ') > board.rows(' . (int) $board['rows'] . ')';
                }
            }

            foreach ($terrain as $r => $row) {
                $line = (string)$row;
                for ($c = 0; $c < strlen($line); $c++) {
                    if ($line[$c] === '.') {
                        continue;
                    }
                    $key = ($originR + $r) . ',' . ($originC + $c);
                    if (isset($occupied[$key])) {
                        $errors[] = "{$prefix} 与 {$occupied[$key]} 在全局坐标 ({$key}) 重叠";
                        continue;
                    }
                    $occupied[$key] = $prefix;
                }
            }
        }

        return $errors;
    }

    private static function checkRules(array $lv): array
    {
        $errors = [];
        $rules  = $lv['rules'] ?? null;

        if (!is_array($rules)) {
            return ['缺少 rules 定义'];
        }

        // 步数：patch 最常破坏的字段
        if (isset($rules['moves']) && (!is_numeric($rules['moves']) || $rules['moves'] <= 0)) {
            $errors[] = 'rules.moves 非法: ' . var_export($rules['moves'] ?? null, true) . '（遗物叠加可能导致步数≤0）';
        }

        // 时间：限时关同理
        if (isset($rules['timeLimit']) && (!is_numeric($rules['timeLimit']) || $rules['timeLimit'] <= 0)) {
            $errors[] = 'rules.timeLimit 非法';
        }

        // 目标
        $goals = $rules['goals'] ?? null;
        if (!is_array($goals) || $goals === []) {
            $errors[] = 'rules.goals 为空';
        } else {
            foreach ($goals as $i => $g) {
                if (!is_array($g) || empty($g['type'])) {
                    $errors[] = "rules.goals[{$i}] 缺少 type";
                    continue;
                }
                if (isset($g['count']) && (!is_numeric($g['count']) || $g['count'] <= 0)) {
                    $errors[] = "rules.goals[{$i}] ({$g['type']}) 数量非法: " . var_export($g['count'], true);
                }
            }

            // 目标可达（01 §10.3）：clear 目标层必须在可清除层内
            foreach ($goals as $i => $g) {
                if (($g['type'] ?? '') !== 'clear') {
                    continue;
                }
                $layer = (string) ($g['layer'] ?? '');
                if ($layer !== '' && !in_array($layer, self::CLEARABLE_LAYERS, true)) {
                    $errors[] = "rules.goals[{$i}] clear 目标层 {$layer} 不可清除";
                }
            }
        }

        // 三星阈值
        if (isset($rules['starScores'])) {
            $ss = $rules['starScores'];
            if (!is_array($ss) || count($ss) !== 3) {
                $errors[] = 'rules.starScores 必须是 3 个阈值';
            } else {
                $prev = -1;
                foreach ($ss as $i => $v) {
                    if (!is_numeric($v) || $v < 0) {
                        $errors[] = "rules.starScores[{$i}] 非法";
                    } elseif ($v <= $prev) {
                        $errors[] = "rules.starScores[{$i}] 必须递增";
                    }
                    $prev = (int)$v;
                }
            }
        }

        // 最小消除数
        if (isset($rules['minMatch'])) {
            $mm = $rules['minMatch'];
            if (!is_numeric($mm) || $mm < 2 || $mm > 5) {
                $errors[] = 'rules.minMatch 应在 [2,5]：' . var_export($mm, true);
            }
        }

        // 提示次数上限（**只影响表现层**，不参与重放；可选，缺省 3，0 = 不提示）
        if (isset($rules['hintLimit'])) {
            $hl = $rules['hintLimit'];
            if (!is_numeric($hl) || $hl < 0) {
                $errors[] = 'rules.hintLimit 应为非负整数：' . var_export($hl, true);
            }
        }

        // 奖励回合（目标达成后的剩余步数换消除；**会改变最终分数** → 两端必须同源）
        if (isset($rules['endgameBonus'])) {
            $eb = $rules['endgameBonus'];
            if (!is_array($eb)) {
                $errors[] = 'rules.endgameBonus 必须是对象';
            } else {
                if (isset($eb['enabled']) && !is_bool($eb['enabled'])) {
                    $errors[] = 'rules.endgameBonus.enabled 必须是布尔';
                }
                if (isset($eb['cellsPerMove']) && (!is_numeric($eb['cellsPerMove']) || $eb['cellsPerMove'] < 1)) {
                    $errors[] = 'rules.endgameBonus.cellsPerMove 应为 ≥1 的整数：' . var_export($eb['cellsPerMove'], true);
                }
            }
        }

        return $errors;
    }

    private static function checkPortals(array $lv): array
    {
        $errors  = [];
        $portals = $lv['portals'] ?? null;

        if (!is_array($portals) || $portals === []) {
            return [];
        }

        $regionIds = [];
        foreach (($lv['regions'] ?? []) as $rg) {
            $regionIds[$rg['id'] ?? ''] = $rg;
        }

        foreach ($portals as $i => $p) {
            foreach (['from', 'to'] as $side) {
                $ref = $p[$side] ?? null;
                if (!is_array($ref) || !isset($ref['region'])) {
                    $errors[] = "portals[{$i}].{$side} 缺少 region";
                    continue;
                }
                if (!isset($regionIds[$ref['region']])) {
                    $errors[] = "portals[{$i}].{$side} 引用了不存在的 region: {$ref['region']}";
                }
            }
        }

        return $errors;
    }

    /**
     * 返回字符串中出现、但不在图例内的字符（去重）
     *
     * @return array<int, string>
     */
    private static function unknownChars(string $row, string $legend): array
    {
        $bad = [];
        $len = strlen($row);
        for ($i = 0; $i < $len; $i++) {
            $ch = $row[$i];
            if (!str_contains($legend, $ch) && !isset($bad[$ch])) {
                $bad[$ch] = $ch;
            }
        }
        return array_values($bad);
    }
}

<?php
/**
 * 操作序列（ops）合法性校验
 *
 * 【作用】
 *   重放校验的第一层（L1）。不需要完整游戏引擎，就能抓掉一大半伪造提交：
 *     · 交换了两个不相邻的格子
 *     · 交换了跨 region 的格子（多布局关卡里不可能）
 *     · 移动了不可移动的元素（石块/牢笼/藤蔓/太妃糖/掉落物）
 *     · 移动了被锁定的格子（Boss 技能 lockCells）
 *     · 操作步数超过关卡上限
 *
 * 【抓不到什么】
 *   用 AI 外挂算出的"合法但超常"操作 —— 这个只能靠 L3 完整重放 +
 *   行为检测（操作间隔、反应时间），见 02 §8 / 05 风险表。
 *
 * 迁移自 docs/match3/code/Match3OpsValidator.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

class Match3OpsValidator
{
    /** 不可主动交换的元素字符（默认图例，01 §4.2） */
    public const NON_SWAPPABLE_ENTITY = ['X', 'C', 'G', 'T'];

    /** 锁定覆盖层字符 */
    public const LOCKED_OVERLAY = 'N';



    /** 可站立的地形字符 */
    public const STANDABLE_TERRAIN = ['o', '>', '<', '^', 'v', 'P', 'D'];

    /**
     * 校验操作序列
     *
     * @param  \stdClass|array<string,mixed> $schema 最终关卡 schema
     * @param  array<int, array<string,mixed>> $ops
     * @return array<int, string> 错误列表；空 = 合法
     */
    public static function validate($schema, array $ops, int $extraMoves = 0): array
    {
        $errors = [];

        if ($ops === []) {
            return ['操作序列为空'];
        }

        $maxMoves = self::getInt($schema, 'rules.moves');
        if ($maxMoves === null) {
            $maxMoves = PHP_INT_MAX; // 无限步关卡（endless）不做步数校验
        } else {
            // 基础步数 + **局内加步**。
            // 加步有两个来源，都不体现在 `rules.moves` 里：
            //   ① `prop:add_moves` 道具（重放能算出来，但这里的交换计数原先漏算）
            //   ② 看激励视频加步（复活 / 中途加步）—— 服务端只埋点，由客户端 `claim.addedMoves` 上报
            // 不加这一项，"用过加步道具或复活过"的**正常玩家**提交会被判"交换次数超上限"（11001）。
            $maxMoves += max(0, $extraMoves);
        }

        $swapCount = 0;

        foreach ($ops as $i => $op) {
            if (!is_array($op)) {
                $errors[] = "ops[{$i}] 不是对象";
                continue;
            }

            $type = (string)($op['t'] ?? '');

            if ($type === 'swap') {
                $swapCount++;
                $errors = array_merge($errors, self::validateSwap($schema, $op, $i));
            } elseif ($type === 'prop') {
                $errors = array_merge($errors, self::validateProp($schema, $op, $i));
            } else {
                $errors[] = "ops[{$i}] 未知操作类型: {$type}";
            }
        }

        if ($swapCount > $maxMoves) {
            $errors[] = "交换次数 {$swapCount} 超过关卡步数上限 {$maxMoves}";
        }

        return $errors;
    }

    /**
     * 单条 swap 校验
     *
     * @param array<string,mixed> $op
     * @return array<int, string>
     */
    public static function validateSwap($schema, array $op, int $i): array
    {
        $errors = [];
        $a = $op['a'] ?? null;
        $b = $op['b'] ?? null;

        if (!is_array($a) || !is_array($b) || count($a) < 2 || count($b) < 2) {
            return ["ops[{$i}] swap 缺少合法坐标 a/b"];
        }

        [$ac, $ar] = [(int)$a[0], (int)$a[1]];
        [$bc, $br] = [(int)$b[0], (int)$b[1]];

        $cellA = self::cellAt($schema, $ac, $ar);
        $cellB = self::cellAt($schema, $bc, $br);

        if ($cellA === null) {
            $errors[] = "ops[{$i}] 坐标 a=({$ac},{$ar}) 不在任何 region 内";
        }
        if ($cellB === null) {
            $errors[] = "ops[{$i}] 坐标 b=({$bc},{$br}) 不在任何 region 内";
        }
        if ($cellA === null || $cellB === null) {
            return $errors;
        }

        // 相邻性：曼哈顿距离必须为 1
        $dist = abs($ac - $bc) + abs($ar - $br);
        if ($dist !== 1) {
            $errors[] = "ops[{$i}] 交换的两格不相邻: a=({$ac},{$ar}) b=({$bc},{$br})";
        }

        // 同 region：多布局关卡中跨区交换不可能
        if (($cellA['region'] ?? '') !== ($cellB['region'] ?? '')) {
            $errors[] = "ops[{$i}] 跨 region 交换: {$cellA['region']} vs {$cellB['region']}";
        }

        // 可站立
        if (!in_array($cellA['terrain'], self::STANDABLE_TERRAIN, true)) {
            $errors[] = "ops[{$i}] a=({$ac},{$ar}) 地形不可站立: {$cellA['terrain']}";
        }
        if (!in_array($cellB['terrain'], self::STANDABLE_TERRAIN, true)) {
            $errors[] = "ops[{$i}] b=({$bc},{$br}) 地形不可站立: {$cellB['terrain']}";
        }

        // 可交换（非障碍 / 非锁定 / 非固定元素）
        if (!self::isSwappable($cellA)) {
            $errors[] = "ops[{$i}] a=({$ac},{$ar}) 元素不可交换: {$cellA['entity']}";
        }
        if (!self::isSwappable($cellB)) {
            $errors[] = "ops[{$i}] b=({$bc},{$br}) 元素不可交换: {$cellB['entity']}";
        }

        return $errors;
    }

    /**
     * 单条 prop 校验
     *
     * @param array<string,mixed> $op
     * @return array<int, string>
     */
    public static function validateProp($schema, array $op, int $i): array
    {
        $errors  = [];
        $propId  = (string)($op['p'] ?? '');
        $allowed = self::getArray($schema, 'props.allowed');

        if ($propId === '') {
            return ["ops[{$i}] prop 缺少道具 id"];
        }

        // 白名单校验（关卡未配置 props 时不做限制）
        if ($allowed !== null && !in_array($propId, $allowed, true)) {
            $errors[] = "ops[{$i}] 道具不在白名单: {$propId}";
        }

        // 需要坐标的道具
        $needPos = ['hammer', 'bomb', 'rainbow', 'paint'];
        if (in_array($propId, $needPos, true)) {
            $at = $op['at'] ?? null;
            if (!is_array($at) || count($at) < 2) {
                $errors[] = "ops[{$i}] 道具 {$propId} 需要 at 坐标";
                return $errors;
            }
            $cell = self::cellAt($schema, (int)$at[0], (int)$at[1]);
            if ($cell === null) {
                $errors[] = "ops[{$i}] 道具目标格不存在: (" . (int)$at[0] . ',' . (int)$at[1] . ')';
            } elseif ($cell['terrain'] === '.') {
                $errors[] = "ops[{$i}] 道具目标格为空洞";
            }
        }

        return $errors;
    }

    /**
     * 格子是否可被主动交换
     *
     * @param array<string,mixed> $cell
     */
    public static function isSwappable(array $cell): bool
    {
        // ⚠️ 这里**只能拦"整局都不会变"的东西**（地形、固定障碍、锁定层 `N`）——
        //    因为本文件是 **L1：静态校验**，拿的是 `LevelSchemaFactory::fromRow()` 的**初始关卡定义**，
        //    **不重放**、看不到当前棋盘。
        //
        //    【2026-09-27 的线上误杀】曾在这里加过"冰封格不可交换"，结果把合法提交判死：
        //    `ops[3] a=(3,5) b=(3,4) 元素不可交换: 0` —— 那两格**开局**确实有冰，但提交到这一步时
        //    冰早被消除（`OVERLAY_LAYER` 里 `I`/`J` → ice，被消除即置 `.`）。
        //    冰是**中途可变**的，静态校验必然误杀 → 已撤掉。冰的权威判定在 **L3 重放引擎**
        //    （`Match3Board::swappable()`，看的是当前棋盘）+ 客户端本地预判（`swappable()`）两处。
        $overlay = (string) ($cell['overlay'] ?? '.');

        // 锁定覆盖层（Boss 技能产出）：**不参与消除**（不在 `OVERLAY_LAYER` 里），整局不变，可以静态拦
        if ($overlay === self::LOCKED_OVERLAY) {
            return false;
        }

        $entity = (string)($cell['entity'] ?? '0');

        // 固定障碍类元素
        if (in_array($entity, self::NON_SWAPPABLE_ENTITY, true)) {
            return false;
        }

        // 掉落收集物（a-f）受重力控制，不可主动交换
        if (preg_match('/^[a-f]$/', $entity)) {
            return false;
        }

        return true;
    }

    /**
     * 定位全局坐标所属的格子信息
     *
     * @param  \stdClass|array<string,mixed> $schema
     * @return array{region:string, lc:int, lr:int, terrain:string, entity:string, overlay:string}|null
     */
    public static function cellAt($schema, int $c, int $r): ?array
    {
        $arr = is_object($schema)
            ? json_decode(json_encode($schema, JSON_UNESCAPED_UNICODE), true)
            : $schema;

        foreach (($arr['regions'] ?? []) as $rg) {
            $terrain = $rg['layers']['terrain'] ?? null;
            if (!is_array($terrain) || $terrain === []) {
                continue;
            }

            $oc = (int)($rg['origin']['c'] ?? 0);
            $or = (int)($rg['origin']['r'] ?? 0);
            $cols = strlen((string)$terrain[0]);
            $rows = count($terrain);

            $lc = $c - $oc;
            $lr = $r - $or;
            if ($lc < 0 || $lr < 0 || $lc >= $cols || $lr >= $rows) {
                continue;
            }

            $entityLayer  = $rg['layers']['entity'] ?? null;
            $overlayLayer = $rg['layers']['overlay'] ?? null;

            return [
                'region'  => (string)($rg['id'] ?? ''),
                'lc'      => $lc,
                'lr'      => $lr,
                'terrain' => (string)$terrain[$lr][$lc],
                'entity'  => is_array($entityLayer) && isset($entityLayer[$lr][$lc])
                    ? (string)$entityLayer[$lr][$lc]
                    : '0',
                'overlay' => is_array($overlayLayer) && isset($overlayLayer[$lr][$lc])
                    ? (string)$overlayLayer[$lr][$lc]
                    : '.',
            ];
        }

        return null;
    }

    /* ---------------- 工具：从 schema 读值 ---------------- */

    private static function getInt($schema, string $path): ?int
    {
        $cur = self::read($schema, $path);
        return is_numeric($cur) ? (int)$cur : null;
    }

    private static function getArray($schema, string $path): ?array
    {
        $cur = self::read($schema, $path);
        return is_array($cur) ? $cur : null;
    }

    private static function read($schema, string $path)
    {
        $arr = is_object($schema)
            ? json_decode(json_encode($schema, JSON_UNESCAPED_UNICODE), true)
            : $schema;

        $cur = $arr;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }
}

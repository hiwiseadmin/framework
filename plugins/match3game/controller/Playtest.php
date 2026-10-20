<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\service\level\DifficultyCurve;
use plugin\match3game\service\level\Match3Board;
use plugin\match3game\service\level\LevelGenerationService;
use plugin\match3game\service\level\LevelSchemaFactory;

/**
 * 测试工具（Phase 2 升级为「关卡体检台」）
 *
 *   GET  playtest                 选关页
 *   GET  playtest/run             seed 确定性棋盘 sanityCheck（可消除性 + 元素统计）
 *   POST playtest/simulate        贪心 AI 体检：LevelValidator + N 次模拟通关率
 *   POST playtest/generate        按 generation 配置预览一张随机关卡（不落库）
 */
class Playtest extends Base
{
    public function index()
    {
        $levels = Match3LevelDef::where('status', 1)
            ->field('code, name, board_rows, board_cols')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        return $this->assign(['levels' => $levels])->fetch();
    }

    /**
     * 确定性棋盘 sanityCheck
     *
     * 【算法口径】与玩家实际拿到的棋盘同源：走 `Match3Board::buildState()`
     * （mulberry32 + deriveSeed），即 L3 重放引擎与客户端共同遵循的唯一权威实现。
     * 早期版本使用 BoardSeedService（xorshift32）生成，与对局实际棋盘不一致，
     * 会导致「后台显示开局有解、玩家进去却无解」，已统一到 Match3Board。
     * 详见 docs/match3/11-board-algo-decision.md。
     */
    public function run()
    {
        $levelCode = (string) $this->request->param('level_code', '');
        $seed      = $this->request->param('seed/d', 0);

        $level = Match3LevelDef::where('code', $levelCode)->find();
        if (!$level) {
            return $this->error('关卡不存在');
        }

        if ($seed < 1) {
            return $this->error('请输入有效的 seed（>= 1）');
        }

        $schema = $level->toSchema();
        $state  = Match3Board::buildState($schema, $seed);
        $minMatch = max(2, (int) (($schema['rules']['minMatch'] ?? 3)));

        // 元素下标 → 元素码（不可站立格保持 null），与前端 renderBoard 的入参一致
        $board = [];
        $stats = [];
        foreach ($state['color'] as $r => $row) {
            $board[$r] = [];
            foreach ($row as $c => $idx) {
                if ($idx === null) {
                    $board[$r][$c] = null;
                    continue;
                }
                $gem = $state['colors'][$idx] ?? null;
                $board[$r][$c] = $gem;
                if ($gem !== null) {
                    $stats[$gem] = ($stats[$gem] ?? 0) + 1;
                }
            }
        }

        return $this->success('', [
            'seed'     => $seed,
            'rows'     => $state['rows'],
            'cols'     => $state['cols'],
            'playable' => Match3Board::hasAnyMove($state, $minMatch),
            'board'    => $board,
            'stats'    => $stats,
        ]);
    }

    /**
     * 贪心 AI 体检：Schema 校验 + 通关率
     */
    public function simulate()
    {
        $levelCode = (string) $this->request->param('level_code', '');
        $seed      = $this->request->param('seed/d', 0);
        $tries     = $this->request->param('tries/d', 20);

        $level = Match3LevelDef::where('code', $levelCode)->find();
        if (!$level) {
            return $this->error('关卡不存在');
        }

        $seed  = $seed > 0 ? $seed : random_int(1, 2147483647);
        $tries = max(1, min(200, $tries));

        $config  = config('match3game.match3.generation', []);
        $service = new LevelGenerationService(null, null, is_array($config) ? $config : []);

        return $this->success('', array_merge(
            ['seed' => $seed, 'code' => $levelCode],
            $service->inspect($level->toSchema(), $seed, $tries)
        ));
    }

    /**
     * 按 generation 配置预览一张随机关卡（不落库）
     *
     * body: { generation: json 字符串或数组, seed, d, node_id }
     */
    public function generate()
    {
        $raw        = $this->request->param('generation', '');
        $generation = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($generation) || $generation === []) {
            return $this->error('generation 配置不能为空（例：{"mode":"generator"}）');
        }

        $seed   = $this->request->param('seed/d', 0);
        $seed   = $seed > 0 ? $seed : random_int(1, 2147483647);
        $nodeId = (string) $this->request->param('node_id', 'preview');

        $config = config('match3game.match3.generation', []);
        $config = is_array($config) ? $config : [];

        $d = $this->request->param('d/f', 0.0);
        $d = $d > 0 ? (float) $d : (float) ($config['default_d'] ?? 1.0);
        $d = DifficultyCurve::clamp($d, 1.0, 5.0);

        // 基础 schema：借一个已有关卡提供 palette / 默认规则
        $base   = $this->baseSchema();
        $svc    = new LevelGenerationService(null, null, $config);
        $result = $svc->generate($base, $generation, $seed, $d, $nodeId);

        return $this->success('', [
            'seed'     => $result['seed'],
            'd'        => $d,
            'attempts' => $result['attempts'],
            'degraded' => $result['degraded'],
            'errors'   => $result['errors'],
            'quality'  => $result['quality'],
            'schema'   => $result['schema'],
            'preview'  => $this->previewOf((array) $result['schema']),
        ]);
    }

    /**
     * 取一个已发布关卡作为生成基底（只取 palette，规则会被生成器覆盖）
     *
     * @return array<string,mixed>
     */
    protected function baseSchema(): array
    {
        $row = Match3LevelDef::where('status', 1)->order('sort_order', 'asc')->find();

        return $row ? $row->toSchema() : [
            'schema'  => 'match3/level@1',
            'code'    => 'GEN-BASE',
            'name'    => '生成基底',
            'board'   => ['cols' => 9, 'rows' => 9, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
            'palette' => ['gems' => ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'], 'theme' => 'orchard'],
            'regions' => [[
                'id'     => 'main',
                'origin' => ['c' => 0, 'r' => 0],
                'gravity' => 'down',
                'layers' => ['terrain' => array_merge(['SSSSSSSSS'], array_fill(0, 8, 'ooooooooo')), 'entity' => [], 'overlay' => []],
                'spawn'  => ['mode' => 'top', 'weights' => [1, 1, 1, 1, 1, 1]],
            ]],
            'rules'   => ['mode' => 'moves', 'moves' => 30, 'minMatch' => 3, 'goals' => [['type' => 'score', 'count' => 2000]], 'starScores' => [2000, 4000, 6000]],
        ];
    }

    /**
     * 生成关卡的紧凑预览（三层字符地图 + 规则摘要），供后台表格直接渲染
     *
     * @return array<string,mixed>
     */
    protected function previewOf(array $schema): array
    {
        if ($schema === []) {
            return [];
        }

        $schema = LevelSchemaFactory::fromRow($schema);
        $region = $schema['regions'][0] ?? [];

        return [
            'code'     => $schema['code'] ?? '',
            'board'    => $schema['board'] ?? [],
            'terrain'  => $region['layers']['terrain'] ?? [],
            'entity'   => $region['layers']['entity'] ?? [],
            'overlay'  => $region['layers']['overlay'] ?? [],
            'gems'     => $schema['palette']['gems'] ?? [],
            'moves'    => (int) ($schema['rules']['moves'] ?? 0),
            'goals'    => $schema['rules']['goals'] ?? [],
            'stars'    => $schema['rules']['starScores'] ?? [],
        ];
    }
}

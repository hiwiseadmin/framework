<?php
/**
 * 水管工关卡定义提供者
 *
 * 从 pipe_level_def 表读取关卡数据，转换为 StageProviderInterface 协议。
 * 这是典型"数据库 → 数组"的 mapping 实现。
 */
namespace plugin\pipe_game\provider;

use think\facade\Db;
use wise\game\stage\contract\StageProviderInterface;
use wise\game\stage\exception\ErrorCode;
use wise\game\stage\exception\GameStageException;

class PipeStageProvider implements StageProviderInterface
{
    /** @var string 水管工关卡定义表 */
    protected string $table = 'pipe_level_def';

    public function getDefinition(string $stageCode): array
    {
        $row = Db::name($this->table)->where('code', $stageCode)->find();

        if (!$row) {
            throw new GameStageException(
                "关卡不存在: {$stageCode}",
                ErrorCode::STAGE_NOT_FOUND->value
            );
        }

        return [
            'code'        => $row['code'],
            'name'        => $row['name'],
            'chapter'     => $row['chapter'],
            'difficulty'  => (int) $row['difficulty'],
            'description' => $row['description'] ?? '',
            // 水管工特有数据统一放在 extra 中
            'extra'       => [
                'grid_size'    => json_decode($row['grid_size'], true),    // [5, 5]
                'pipe_layout'  => json_decode($row['pipe_layout'], true),  // 初始管道布局 0/1 矩阵
                'entrance'     => json_decode($row['entrance'], true),     // [row, col] 入口坐标
                'exit'         => json_decode($row['exit'], true),         // [row, col] 出口坐标
                'max_steps'    => (int) ($row['max_steps'] ?? 99),
                'time_limit'   => (int) ($row['time_limit'] ?? 300),
            ],
        ];
    }

    public function listChapters(string $gameId): array
    {
        // 获取所有不重复的章节
        $rows = Db::name($this->table)
            ->field('chapter')
            ->group('chapter')
            ->order('chapter')
            ->select()
            ->toArray();

        $chapters = [];
        foreach ($rows as $index => $row) {
            // 获取该章节下的所有关卡
            $stages = Db::name($this->table)
                ->where('chapter', $row['chapter'])
                ->field('code, name, difficulty')
                ->order('sort_order, code')
                ->select()
                ->toArray();

            $chapters[] = [
                'id'     => $row['chapter'],
                'name'   => $row['chapter'],
                'order'  => $index + 1,
                'stages' => array_map(function ($s) {
                    return [
                        'code'       => $s['code'],
                        'name'       => $s['name'],
                        'difficulty' => (int) $s['difficulty'],
                        'unlocked'   => true, // 业务层可在此判断解锁条件
                    ];
                }, $stages),
            ];
        }

        return $chapters;
    }

    public function listStages(string $gameId): array
    {
        return Db::name($this->table)
            ->field('code, name, chapter, difficulty, description')
            ->order('chapter, sort_order, code')
            ->select()
            ->toArray();
    }
}

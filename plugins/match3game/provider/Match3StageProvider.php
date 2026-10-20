<?php

declare(strict_types=1);

/**
 * 消消乐关卡定义提供者（Level Schema v1）
 *
 * 从 match3_level_def 读取 Level Schema v1 关卡，转换为 StageProviderInterface 协议。
 * 完整 schema 放在 extra 中（01 §10.2），与 fixed 关卡结构完全一致。
 */
namespace plugin\match3game\provider;

use think\facade\Db;
use wise\game\stage\contract\StageProviderInterface;
use wise\game\stage\exception\GameStageException;
use wise\game\stage\exception\ErrorCode;
use plugin\match3game\service\level\LevelSchemaFactory;

class Match3StageProvider implements StageProviderInterface
{
    /** @var string 消消乐关卡定义表 */
    protected string $table = 'match3_level_def';

    public function getDefinition(string $stageCode): array
    {
        $row = Db::name($this->table)->where('code', $stageCode)->find();

        if (!$row) {
            throw new GameStageException(
                "关卡不存在: {$stageCode}",
                ErrorCode::STAGE_NOT_FOUND->value
            );
        }

        // JSON 列在 Db 查询下是字符串，统一解码为数组后交给工厂装配
        foreach (['regions', 'portals', 'rules', 'palette', 'behaviours', 'actors', 'props', 'generation'] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $row[$col] = json_decode($row[$col], true);
            }
        }

        $schema = LevelSchemaFactory::fromRow($row);

        return [
            'code'        => $row['code'],
            'name'        => $row['name'],
            'chapter'     => $row['chapter'],
            'difficulty'  => (int) $row['difficulty'],
            'description' => $row['description'] ?? '',
            // 完整 Level Schema v1（01 §10.2）
            'extra'       => $schema,
        ];
    }

    public function listChapters(string $gameId): array
    {
        $rows = Db::name($this->table)
            ->field('chapter')
            ->where('status', 1)
            ->group('chapter')
            ->order('chapter')
            ->select()
            ->toArray();

        $chapters = [];
        foreach ($rows as $index => $row) {
            $stages = Db::name($this->table)
                ->where('chapter', $row['chapter'])
                ->where('status', 1)
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
                        'unlocked'   => true, // 解锁状态由插件 LevelProgressService 判定
                    ];
                }, $stages),
            ];
        }

        return $chapters;
    }

    public function listStages(string $gameId): array
    {
        return Db::name($this->table)
            ->field('code, name, chapter, difficulty, description, status')
            ->order('chapter, sort_order, code')
            ->select()
            ->toArray();
    }
}

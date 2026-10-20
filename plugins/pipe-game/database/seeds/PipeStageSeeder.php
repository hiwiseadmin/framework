<?php

declare(strict_types=1);

use think\migration\Seeder;

/**
 * 水管工示例关卡种子数据
 *
 * 包含 3 个示例关卡：简单(5x5)、普通(6x6)、困难(7x7)
 */
class PipeStageSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            [
                'code'        => 'L001',
                'name'        => '初识管道',
                'chapter'     => '第一章：入门',
                'difficulty'  => 1,
                'description' => '连接入口到出口，熟悉管道操作',
                'grid_size'   => '[5, 5]',
                'pipe_layout' => json_encode([
                    [1, 0, 0, 0, 0],
                    [1, 1, 0, 1, 0],
                    [0, 1, 0, 1, 0],
                    [0, 1, 1, 1, 0],
                    [0, 0, 0, 1, 1],
                ]),
                'entrance'    => '[0, 0]',
                'exit'        => '[4, 4]',
                'max_steps'   => 99,
                'time_limit'  => 300,
                'sort_order'  => 1,
                'create_time' => $now,
                'update_time' => $now,
            ],
            [
                'code'        => 'L002',
                'name'        => '弯弯绕绕',
                'chapter'     => '第一章：入门',
                'difficulty'  => 1,
                'description' => '管道变得更弯了，需要仔细规划路径',
                'grid_size'   => '[5, 5]',
                'pipe_layout' => json_encode([
                    [1, 0, 0, 0, 0],
                    [1, 1, 1, 0, 0],
                    [0, 0, 1, 1, 0],
                    [0, 0, 0, 1, 0],
                    [0, 0, 0, 1, 1],
                ]),
                'entrance'    => '[0, 0]',
                'exit'        => '[4, 4]',
                'max_steps'   => 50,
                'time_limit'  => 200,
                'sort_order'  => 2,
                'create_time' => $now,
                'update_time' => $now,
            ],
            [
                'code'        => 'L003',
                'name'        => '十字路口',
                'chapter'     => '第一章：入门',
                'difficulty'  => 2,
                'description' => '多条路径可选，只有一条能到达出口',
                'grid_size'   => '[6, 6]',
                'pipe_layout' => json_encode([
                    [1, 0, 0, 0, 0, 0],
                    [1, 1, 0, 1, 0, 0],
                    [0, 1, 0, 1, 1, 0],
                    [0, 1, 1, 0, 1, 0],
                    [0, 0, 0, 0, 1, 0],
                    [0, 0, 0, 0, 1, 1],
                ]),
                'entrance'    => '[0, 0]',
                'exit'        => '[5, 5]',
                'max_steps'   => 40,
                'time_limit'  => 180,
                'sort_order'  => 3,
                'create_time' => $now,
                'update_time' => $now,
            ],
        ];

        $table = $this->table('pipe_level_def');
        $table->insert($data)->save();
    }
}

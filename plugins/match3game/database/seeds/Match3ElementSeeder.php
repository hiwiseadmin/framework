<?php

declare(strict_types=1);

use think\migration\Seeder;

/**
 * 消消乐元素/障碍物字典种子（Level Schema v1 图例对齐）
 *
 * 6 色普通宝石（entity 层 1~6）+ 3 种覆盖物（overlay 层 I/J/L/K，01 §4.2）。
 * code 同时作为 schema.palette.gems 与 collect 目标的 target 使用。
 */
class Match3ElementSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $elements = [
            // —— 普通色块（kind=1，可用于生成与消除）——
            ['code' => 'red_gem',    'name' => '红宝石', 'kind' => 1, 'color' => '#e74c3c', 'weight' => 20, 'clear_rule' => null,         'icon' => null, 'status' => 1, 'sort_order' => 1],
            ['code' => 'blue_gem',   'name' => '蓝宝石', 'kind' => 1, 'color' => '#3498db', 'weight' => 20, 'clear_rule' => null,         'icon' => null, 'status' => 1, 'sort_order' => 2],
            ['code' => 'yellow_gem', 'name' => '黄宝石', 'kind' => 1, 'color' => '#f1c40f', 'weight' => 20, 'clear_rule' => null,         'icon' => null, 'status' => 1, 'sort_order' => 3],
            ['code' => 'green_gem',  'name' => '绿宝石', 'kind' => 1, 'color' => '#2ecc71', 'weight' => 20, 'clear_rule' => null,         'icon' => null, 'status' => 1, 'sort_order' => 4],
            ['code' => 'purple_gem', 'name' => '紫宝石', 'kind' => 1, 'color' => '#9b59b6', 'weight' => 20, 'clear_rule' => null,         'icon' => null, 'status' => 1, 'sort_order' => 5],
            ['code' => 'orange_gem', 'name' => '橙宝石', 'kind' => 1, 'color' => '#e67e22', 'weight' => 15, 'clear_rule' => null,         'icon' => null, 'status' => 1, 'sort_order' => 6],
            // —— 覆盖物（kind=3，清除型；对应 overlay 层字符 I/J/L/K）——
            ['code' => 'ice',   'name' => '冰块', 'kind' => 3, 'color' => '#aee1f9', 'weight' => 0, 'clear_rule' => 'ice_break',   'icon' => null, 'status' => 1, 'sort_order' => 10],
            ['code' => 'jelly', 'name' => '果冻', 'kind' => 3, 'color' => '#f5b7b1', 'weight' => 0, 'clear_rule' => 'jelly_break', 'icon' => null, 'status' => 1, 'sort_order' => 11],
            ['code' => 'snow',  'name' => '雪块', 'kind' => 3, 'color' => '#ecf0f1', 'weight' => 0, 'clear_rule' => 'snow_break',  'icon' => null, 'status' => 1, 'sort_order' => 12],
        ];

        $rows = [];
        foreach ($elements as $e) {
            $e['create_time'] = $now;
            $e['update_time'] = $now;
            $rows[] = $e;
        }

        $table = $this->table('match3_element');
        $table->insert($rows)->save();
    }
}

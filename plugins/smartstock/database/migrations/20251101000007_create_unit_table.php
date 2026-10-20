<?php

use think\migration\Migrator;
use think\migration\db\Column;
use think\facade\Db;

class CreateUnitTable extends Migrator
{
    public function up()
    {
        $table = $this->table('smartstock_unit', [
            'engine'    => 'InnoDB',
            'comment'   => '计量单位表',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
        ]);

        $table->addColumn(Column::string('name', 50)->setComment('单位名称'))
            ->addColumn(Column::string('code', 20)->setComment('单位编码'))
            ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // 建表后写入默认计量单位
        $this->seedDefaultUnits();
    }

    public function down(): void
    {
        if ($this->hasTable('smartstock_unit')) {
            $this->table('smartstock_unit')->drop()->save();
        }
    }

    /**
     * 默认计量单位（表为空才写入，保证幂等）
     */
    protected function seedDefaultUnits(): void
    {
        if (Db::name('smartstock_unit')->count() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = [
            ['name' => '件',  'code' => 'jian',  'sort_order' => 10, 'status' => 1],
            ['name' => '箱',  'code' => 'xiang', 'sort_order' => 20, 'status' => 1],
            ['name' => '千克', 'code' => 'kg',    'sort_order' => 30, 'status' => 1],
            ['name' => '克',  'code' => 'g',     'sort_order' => 40, 'status' => 1],
            ['name' => '升',  'code' => 'l',     'sort_order' => 50, 'status' => 1],
            ['name' => '个',  'code' => 'ge',    'sort_order' => 60, 'status' => 1],
        ];

        foreach ($rows as &$row) {
            $row['create_time'] = $now;
            $row['update_time'] = $now;
        }
        unset($row);

        $this->table('smartstock_unit')->insert($rows)->saveData();
    }
}

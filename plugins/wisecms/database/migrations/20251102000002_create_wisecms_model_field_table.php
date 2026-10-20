<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsModelFieldTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_model_field', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS模型字段表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('model_id')->setSigned(false)->setComment('模型ID'))
            ->addColumn(Column::string('field', 30)->setComment('字段标识'))
            ->addColumn(Column::string('name', 50)->setComment('字段名称'))
            ->addColumn(Column::string('form_type', 20)->setComment('表单类型'))
            ->addColumn(Column::string('data_type', 20)->setDefault('varchar')->setComment('数据库类型'))
            ->addColumn(Column::integer('length')->setDefault(0)->setComment('字段长度'))
            ->addColumn(Column::string('default_value', 255)->setDefault('')->setComment('默认值'))
            ->addColumn(Column::text('setting')->setNullable()->setComment('字段设置(JSON)'))
            ->addColumn(Column::string('tips', 255)->setDefault('')->setComment('提示信息'))
            ->addColumn(Column::string('errortips', 255)->setDefault('')->setComment('错误提示'))
            ->addColumn(Column::tinyInteger('is_system')->setDefault(0)->setComment('系统字段(主表)'))
            ->addColumn(Column::tinyInteger('is_base')->setDefault(1)->setComment('基本字段'))
            ->addColumn(Column::tinyInteger('is_required')->setDefault(0)->setComment('必填'))
            ->addColumn(Column::tinyInteger('is_unique')->setDefault(0)->setComment('唯一'))
            ->addColumn(Column::tinyInteger('is_search')->setDefault(0)->setComment('可搜索'))
            ->addColumn(Column::tinyInteger('is_fulltext')->setDefault(0)->setComment('全文索引'))
            ->addColumn(Column::tinyInteger('is_add')->setDefault(1)->setComment('添加页显示'))
            ->addColumn(Column::tinyInteger('is_edit')->setDefault(1)->setComment('编辑页显示'))
            ->addColumn(Column::tinyInteger('is_list')->setDefault(0)->setComment('列表页显示'))
            ->addColumn(Column::string('pattern', 255)->setDefault('')->setComment('验证正则'))
            ->addColumn(Column::integer('group_id')->setDefault(0)->setComment('字段分组'))
            ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            // 后台字段列表按 model_id 过滤 + sort_order 排序，复合索引可免回表排序
            ->addIndex(['model_id', 'sort_order'])
            ->addIndex(['model_id', 'field'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_model_field')) {
            $this->table('wisecms_model_field')->drop()->save();
        }
    }
}

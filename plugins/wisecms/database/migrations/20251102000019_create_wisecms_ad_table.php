<?php
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 广告系统（**计划功能**，P2 架构治理决策：本表保留不动）
 *
 * 现状：表结构 + 后台 CRUD 已就绪，但**暂无前台消费方**（无广告服务、无 `{cms:...}` 广告标签、
 * 无前台视图引用），因此静态扫描会把它误判成「死代码」。
 *
 * 结论：广告属计划中的能力，**不纳入死代码清理范围**。
 *   后续接入前台投放时再评估：广告位展示渲染、以及是否需要 `site_id` 做按站点投放。
 *   在那之前，请勿删除本表 / Ad 模型 / Ad 控制器 / admin/ad 视图。
 */
class CreateWisecmsAdTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_ad', [
            'engine'=>'InnoDB',
            'comment'=>'CMS广告表',
            'collation'=>'utf8mb4_general_ci'
        ]);
        
        $table->addColumn(Column::string('space_name',50)->setComment('广告位标识'))
            ->addColumn(Column::string('name',100)->setComment('广告名称'))
            ->addColumn(Column::string('type',20)->setDefault('image')->setComment('image/html/code'))
            ->addColumn(Column::text('content')->setNullable()->setComment('广告内容'))
            ->addColumn(Column::string('link',500)->setDefault('')->setComment('链接'))
            ->addColumn(Column::integer('sort')->setDefault(0))->addColumn(Column::tinyInteger('status')->setDefault(1))
            ->addColumn(Column::datetime('start_time')->setNullable()->setComment('生效时间(NULL=不限)'))
            ->addColumn(Column::datetime('end_time')->setNullable()->setComment('失效时间(NULL=不限)'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['space_name','status'])->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_ad')) {
            $this->table('wisecms_ad')->drop()->save();
        }
    }
}

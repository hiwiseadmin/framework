<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsSeoAuditTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_seo_audit', [
            'engine' => 'InnoDB', 'comment' => 'CMS SEO审查记录表', 'collation' => 'utf8mb4_general_ci',
        ]);
        $table->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            ->addColumn(Column::decimal('total_score', 5, 2)->setDefault(0.00)->setComment('SEO总分(100)'))
            ->addColumn(Column::text('checks')->setNullable()->setComment('各项检查结果(JSON)'))
            ->addColumn(Column::text('issues')->setNullable()->setComment('问题列表(JSON)'))
            ->addColumn(Column::datetime('audit_time')->setNullable()->setComment('SEO审查时间'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['content_id'])
            ->addIndex(['audit_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_seo_audit')) {
            $this->table('wisecms_seo_audit')->drop()->save();
        }
    }
}

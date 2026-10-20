<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsContentQualityTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_content_quality', [
            'engine' => 'InnoDB', 'comment' => 'CMS内容质量评分表', 'collation' => 'utf8mb4_general_ci',
        ]);
        $table->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            ->addColumn(Column::decimal('total_score', 5, 2)->setDefault(0.00)->setComment('总分(100)'))
            ->addColumn(Column::decimal('completeness', 5, 2)->setDefault(0.00)->setComment('内容完整度'))
            ->addColumn(Column::decimal('seo_score', 5, 2)->setDefault(0.00)->setComment('SEO优化度'))
            ->addColumn(Column::decimal('readability', 5, 2)->setDefault(0.00)->setComment('可读性'))
            ->addColumn(Column::decimal('originality', 5, 2)->setDefault(0.00)->setComment('原创性'))
            ->addColumn(Column::text('suggestions')->setNullable()->setComment('优化建议(JSON)'))
            ->addColumn(Column::char('level', 1)->setDefault('C')->setComment('等级A/B/C/D/F'))
            ->addColumn(Column::datetime('check_time')->setNullable()->setComment('评分时间'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['content_id'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_content_quality')) {
            $this->table('wisecms_content_quality')->drop()->save();
        }
    }
}

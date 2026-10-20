<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsSeoPushLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_seo_push_log', [
            'engine' => 'InnoDB', 'comment' => 'CMS搜索推送日志表', 'collation' => 'utf8mb4_general_ci',
        ]);
        $table->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            ->addColumn(Column::string('engine', 20)->setComment('baidu/google/bing'))
            ->addColumn(Column::string('url', 500)->setComment('推送URL'))
            ->addColumn(Column::integer('response_code')->setDefault(0)->setComment('响应码'))
            ->addColumn(Column::text('response_body')->setNullable()->setComment('响应内容'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('0失败1成功'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['content_id'])
            ->addIndex(['engine'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_seo_push_log')) {
            $this->table('wisecms_seo_push_log')->drop()->save();
        }
    }
}

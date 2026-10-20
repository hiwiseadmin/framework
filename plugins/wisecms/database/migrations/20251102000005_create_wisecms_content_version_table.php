<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsContentVersionTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_content_version', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS内容版本表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            ->addColumn(Column::longText('data')->setNullable()->setComment('内容数据(JSON)'))
            ->addColumn(Column::integer('version')->setDefault(1)->setComment('版本号'))
            ->addColumn(Column::string('remark', 255)->setDefault('')->setComment('修改说明'))
            ->addColumn(Column::string('create_user', 50)->setDefault('')->setComment('操作人'))
            ->addColumn(Column::string('title', 255)->setDefault('')->setComment('标题快照'))
            ->addColumn(Column::string('data_hash', 32)->setDefault('')->setComment('快照哈希（去重）'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            // 版本号由 max(version)+1 生成（读后写），并发下会重号 —— 用唯一键兜底，
            // 写入侧捕获重复后重试自增（见 ContentVersionService::capture）
            ->addIndex(['content_id', 'version'], ['unique' => true, 'name' => 'idx_content_version'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_content_version')) {
            $this->table('wisecms_content_version')->drop()->save();
        }
    }
}

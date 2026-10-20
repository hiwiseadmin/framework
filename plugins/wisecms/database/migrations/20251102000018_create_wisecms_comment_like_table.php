<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsCommentLikeTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_comment_like', [
            'engine' => 'InnoDB', 
            'comment' => 'CMS评论点赞表', 
            'collation' => 'utf8mb4_general_ci',
        ]);
        $table->addColumn(Column::integer('comment_id')->setSigned(false)->setComment('评论ID'))
            ->addColumn(Column::integer('member_id')->setSigned(false)->setDefault(0)->setComment('会员ID'))
            ->addColumn(Column::tinyInteger('type')->setDefault(1)->setComment('1赞 0踩'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['comment_id', 'member_id'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_comment_like')) {
            $this->table('wisecms_comment_like')->drop()->save();
        }
    }
}

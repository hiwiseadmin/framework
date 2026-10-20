<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsCommentTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_comment', [
            'engine' => 'InnoDB', 
            'comment' => 'CMS评论表', 
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        $table->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            // P1-26：评论按站点隔离。原实现只能经 content_id 关联内容间接判断归属 ——
            // 后台列表无法按站点过滤（多站点下跨站显示），且内容被删除后评论就"无主"。
            // 该列由 CommentService::create() 从所属内容推导，**不接受调用方传值**。
            ->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::integer('parent_id')->setSigned(false)->setDefault(0)->setComment('父评论ID'))
            ->addColumn(Column::tinyInteger('depth')->setDefault(0)->setComment('嵌套深度'))
            ->addColumn(Column::integer('root_id')->setSigned(false)->setDefault(0)->setComment('根评论ID'))
            ->addColumn(Column::integer('member_id')->setSigned(false)->setDefault(0)->setComment('会员ID'))
            ->addColumn(Column::string('username', 50)->setDefault('')->setComment('评论人'))
            ->addColumn(Column::text('content')->setComment('评论内容'))
            ->addColumn(Column::integer('like_count')->setDefault(0)->setComment('点赞数'))
            ->addColumn(Column::integer('dislike_count')->setDefault(0)->setComment('踩数'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('0待审 1通过 2拒绝'))
            ->addColumn(Column::string('ip', 45)->setDefault('')->setComment('IP'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            // 后台评论列表：site_id(+status) 过滤 + 按 id 倒序（InnoDB 二级索引隐含主键，故可覆盖排序）
            ->addIndex(['site_id', 'status'])
            // 前台评论列表：content_id + status(已审) + parent_id 三条件同时命中
            ->addIndex(['content_id', 'status', 'parent_id'])
            // 子评论/楼中楼：按父评论取已通过列表
            ->addIndex(['parent_id', 'status'])
            // 频率限制：每次发评论都按 ip 取最后一条（原缺索引 → 全表扫 + filesort）
            ->addIndex(['ip'])
            ->addIndex(['member_id'])
            ->addIndex(['root_id'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_comment')) {
            $this->table('wisecms_comment')->drop()->save();
        }
    }
}

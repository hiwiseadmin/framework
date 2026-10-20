<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsTagTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_tag', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS标签表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::string('name', 50)->setComment('标签名（站点内唯一）'))
            // alias 用于标签页 URL 解析（Tag::where('alias', ...)），**站点内**必须唯一；
            // 可为空 —— MySQL 唯一索引允许多个 NULL，避免历史/异常数据（空别名）互相冲突
            ->addColumn(Column::string('alias', 50)->setNullable()->setComment('别名（站点内唯一）'))
            // 本站引用计数：本站「已发布 + 未回收」内容对该标签的引用数（ContentService::recountTags）
            ->addColumn(Column::integer('count')->setDefault(0)->setComment('引用计数（本站口径）'))
            ->addColumn(Column::string('seo_title', 100)->setDefault('')->setComment('SEO标题'))
            ->addColumn(Column::string('seo_keywords', 255)->setDefault('')->setComment('SEO关键词'))
            ->addColumn(Column::string('seo_description', 500)->setDefault('')->setComment('SEO描述'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            // 站点隔离（P2）：词表按站点独立，name / alias 的唯一性由「全局唯一」收敛为「站点内唯一」，
            // 否则 A 站建过的标签名（或 slug）会挡住 B 站建同名标签（MySQL 唯一键跨站冲突）。
            ->addIndex(['site_id'])
            ->addIndex(['site_id', 'name'], ['unique' => true])
            ->addIndex(['site_id', 'alias'], ['unique' => true])
            // 标签云按 count 倒序取热门：站点内排序 → 复合索引（原单列 ['count'] 已无查询可用）
            ->addIndex(['site_id', 'count'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_tag')) {
            $this->table('wisecms_tag')->drop()->save();
        }
    }
}

<?php
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 友情链接表（B-1）
 *
 * 单表承载文字 / 图片型友链，分组用 group_name 字符串（见 docs/wisecms-friendlink-design.md §2）。
 * 前台热查询为 site_id + status=1 + 有效期窗口 + order by sort desc，由 idx_site_status_sort 覆盖。
 */
class CreateWisecmsFriendLinkTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_friend_link', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS友情链接表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setDefault(1)->setComment('所属站点ID'))
            ->addColumn(Column::string('group_name', 50)->setDefault('')->setComment('分组名称，空=默认组'))
            ->addColumn(Column::string('name', 100)->setDefault('')->setComment('链接名称/网站名'))
            ->addColumn(Column::string('url', 500)->setDefault('')->setComment('链接地址（仅 http/https）'))
            ->addColumn(Column::string('logo', 500)->setDefault('')->setComment('Logo 地址（图片型友链）'))
            ->addColumn(Column::string('description', 255)->setDefault('')->setComment('描述/备注'))
            ->addColumn(Column::boolean('is_image')->setDefault(0)->setComment('是否图片型 0=文字 1=图片'))
            ->addColumn(Column::string('target', 10)->setDefault('_blank')->setComment('打开方式 _blank/_self'))
            ->addColumn(Column::boolean('nofollow')->setDefault(0)->setComment('是否输出 rel=nofollow'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序（越大越前）'))
            ->addColumn(Column::boolean('status')->setDefault(1)->setComment('状态 0=隐藏 1=显示'))
            ->addColumn(Column::integer('click_count')->setDefault(0)->setComment('点击次数'))
            ->addColumn(Column::datetime('start_time')->setNullable()->setComment('生效时间(NULL=不限)'))
            ->addColumn(Column::datetime('end_time')->setNullable()->setComment('失效时间(NULL=不限)'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['site_id', 'status', 'sort'], ['name' => 'idx_site_status_sort'])
            ->addIndex(['site_id', 'group_name'], ['name' => 'idx_site_group'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_friend_link')) {
            $this->table('wisecms_friend_link')->drop()->save();
        }
    }
}

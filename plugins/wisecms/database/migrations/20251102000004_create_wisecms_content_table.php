<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * CMS 内容主表
 *
 * 时间字段约定（全插件统一）：`create_time` / `update_time` 为 DATETIME（可空），
 * 由模型 `autoWriteTimestamp` 自动维护；不使用 int 时间戳列。
 * `publish_time` 为「发布时间（计划或实际）」，NULL = 未设置。
 */
class CreateWisecmsContentTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_content', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS内容主表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::integer('catid')->setSigned(false)->setComment('栏目ID'))
            ->addColumn(Column::integer('model_id')->setSigned(false)->setDefault(0)->setComment('模型ID'))
            ->addColumn(Column::string('title', 200)->setComment('标题'))
            ->addColumn(Column::text('content')->setNullable()->setComment('正文内容'))
            ->addColumn(Column::string('alias', 100)->setDefault('')->setComment('别名'))
            // 与 catid 组成唯一键 (catid, slug)；可为空 —— 唯一索引允许多个 NULL，
            // 避免同一栏目下多篇「未填 slug」的内容互相冲突（写入侧把空串归一为 NULL）
            ->addColumn(Column::string('slug', 200)->setNullable()->setComment('URL别名'))
            ->addColumn(Column::string('keywords', 200)->setDefault('')->setComment('关键词'))
            ->addColumn(Column::string('description', 500)->setDefault('')->setComment('描述'))
            ->addColumn(Column::integer('read_time')->setDefault(0)->setComment('阅读时长(分钟)'))
            ->addColumn(Column::integer('word_count')->setDefault(0)->setComment('字数统计'))
            ->addColumn(Column::string('thumb', 255)->setDefault('')->setComment('缩略图'))
            ->addColumn(Column::text('cover_images')->setNullable()->setComment('多封面图(JSON)'))
            ->addColumn(Column::string('og_image', 500)->setDefault('')->setComment('OG:Image'))
            ->addColumn(Column::string('canonical_url', 500)->setDefault('')->setComment('Canonical URL'))
            ->addColumn(Column::string('url', 255)->setDefault('')->setComment('自定义URL'))
            ->addColumn(Column::string('username', 50)->setDefault('')->setComment('发布人'))
            ->addColumn(Column::string('source_type', 20)->setDefault('manual')->setComment('来源'))
            ->addColumn(Column::string('source_url', 500)->setDefault('')->setComment('来源URL'))
            ->addColumn(Column::decimal('seo_score', 5, 2)->setDefault(0.00)->setComment('SEO评分'))
            ->addColumn(Column::decimal('quality_score', 5, 2)->setDefault(0.00)->setComment('质量评分'))
            ->addColumn(Column::char('quality_level', 1)->setNullable()->setComment('质量等级'))
            ->addColumn(Column::char('seo_level', 1)->setNullable()->setComment('SEO等级'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('状态:0草稿1待审2已发布3已归档'))
            ->addColumn(Column::tinyInteger('is_top')->setDefault(0)->setComment('置顶'))
            ->addColumn(Column::tinyInteger('is_recommend')->setDefault(0)->setComment('推荐'))
            ->addColumn(Column::integer('posids')->setDefault(0)->setComment('推荐位(位运算)'))
            ->addColumn(Column::string('groupids_view', 100)->setDefault('')->setComment('阅读权限'))
            ->addColumn(Column::integer('view_count')->setDefault(0)->setComment('浏览量'))
            ->addColumn(Column::integer('listorder')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::datetime('publish_time')->setNullable()->setComment('发布时间(NULL=未设置)'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['catid', 'status', 'create_time'])
            ->addIndex(['site_id'])
            ->addIndex(['site_id', 'status', 'create_time'], ['name' => 'idx_site_status_create'])
            ->addIndex(['site_id', 'status', 'view_count'], ['name' => 'idx_site_status_view'])
            ->addIndex(['model_id'])
            ->addIndex(['status', 'listorder'])
            ->addIndex(['create_time'])
            ->addIndex(['view_count'])
            ->addIndex(['catid', 'slug'], ['unique' => true])
            ->create();

        // 全文索引
        $this->execute("ALTER TABLE `{$this->getTablePrefix()}wisecms_content` ADD FULLTEXT INDEX `ft_title_keywords` (`title`, `keywords`, `description`)");
    }

    protected function getTablePrefix(): string
    {
        return $this->getAdapter()->getOptions()['prefix'] ?? 'ws_';
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_content')) {
            $this->table('wisecms_content')->drop()->save();
        }
    }
}

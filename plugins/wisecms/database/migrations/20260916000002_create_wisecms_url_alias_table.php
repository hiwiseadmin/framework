<?php
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * URL 别名索引表（当前 URL + 历史 URL）
 *
 * 该表同时承担两个职责（参考 Drupal path_alias / WordPress post_name + wp_old_slugs）：
 *   1. 解析索引：url_path → (entity_type, entity_id)，伪静态请求据此定位实体；
 *   2. 301 历史：is_current=0 的行是「曾经用过的 URL」，命中后 301 到当前 URL。
 *
 * url_path 为规范化路径（无前导斜杠、含后缀），如 news/tech/hello.html。
 */
class CreateWisecmsUrlAliasTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_url_alias', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS URL别名索引表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setDefault(0)->setComment('站点ID'))
            ->addColumn(Column::string('entity_type', 20)->setDefault('content')->setComment('实体类型 content/category/tag/page'))
            ->addColumn(Column::integer('entity_id')->setDefault(0)->setComment('实体ID'))
            ->addColumn(Column::string('url_path', 255)->setDefault('')->setComment('规范化路径（无前导斜杠，含后缀）'))
            ->addColumn(Column::tinyInteger('is_current')->setDefault(1)->setComment('1=当前规范URL 0=历史URL(用于301)'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['site_id', 'url_path'], ['unique' => true, 'name' => 'uniq_site_url'])
            ->addIndex(['entity_type', 'entity_id', 'is_current'], ['name' => 'idx_entity'])
            ->addIndex(['is_current'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_url_alias')) {
            $this->table('wisecms_url_alias')->drop()->save();
        }
    }
}

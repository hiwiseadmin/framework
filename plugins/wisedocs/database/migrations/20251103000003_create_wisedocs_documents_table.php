<?php

use think\migration\Migrator;
use think\migration\db\Column;
use think\facade\Db;

/**
 * 创建帮助文档主表（P1 结构修复后的最终形态）。
 *
 * 项目尚未发布，历史增量迁移（repair 补列 / 删遗留 tags 列 / 建全文索引）
 * 已合并至本文件，新装即为最终结构，不再依赖增量迁移。
 */
class CreateWiseDocsDocumentsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_documents', [
            'engine'    => 'InnoDB',
            'comment'   => '帮助文档表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('project_id')->setComment('项目ID'))
              ->addColumn(Column::integer('category_id')->setComment('分类ID'))
              ->addColumn(Column::string('title', 200)->setComment('标题'))
              ->addColumn(Column::string('slug', 200)->setUnique()->setComment('URL标识'))
              ->addColumn(Column::text('content')->setComment('内容'))
              ->addColumn(Column::string('excerpt', 500)->setNullable()->setComment('摘要'))
              ->addColumn(Column::string('cover', 255)->setNullable()->setComment('封面'))
              ->addColumn(Column::text('description')->setComment('描述'))
              ->addColumn(Column::tinyInteger('content_type')->setDefault(1)->setNullable()->setComment('内容类型:1文章,2代码,3组件,4API'))
              ->addColumn(Column::string('code_language', 30)->setNullable()->setComment('代码语言'))
              ->addColumn(Column::string('api_method', 10)->setNullable()->setComment('API方法'))
              ->addColumn(Column::string('api_endpoint', 255)->setNullable()->setComment('API端点'))
              ->addColumn(Column::text('demo_config')->setNullable()->setComment('演示配置(JSON)'))
              ->addColumn(Column::text('extra_fields')->setNullable()->setComment('额外字段(JSON)'))
              ->addColumn(Column::integer('view_count')->setComment('浏览次数'))
              ->addColumn(Column::integer('helpful_count')->setComment('有帮助次数'))
              ->addColumn(Column::integer('unhelpful_count')->setComment('无帮助次数'))
              ->addColumn(Column::tinyInteger('is_featured')->setDefault(0)->setComment('是否推荐'))
              ->addColumn(Column::tinyInteger('is_top')->setDefault(0)->setComment('是否置顶'))
              ->addColumn(Column::integer('author_id')->setNullable()->setComment('作者ID'))
              ->addColumn(Column::datetime('published_at')->setNullable()->setComment('发布时间'))
              ->addColumn(Column::integer('sort_order')->setComment('排序'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用，1:正常'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->addIndex('project_id')
              ->addIndex(['category_id'], ['name' => 'idx_wisedocs_documents_category'])
              ->addIndex('status')
              ->addIndex('content_type')
              ->addIndex('is_featured')
              ->addIndex('is_top')
              ->addIndex('author_id')
              ->addIndex(['project_id', 'status', 'content_type'], ['name' => 'idx_wisedocs_documents_list'])
              ->create();

        $this->addFulltextIndex();
    }

    /**
     * 为 title / excerpt / content 建全文索引（可选搜索引擎）。
     *
     * 失败静默跳过：未建索引时搜索服务会自动回退为 LIKE。
     */
    protected function addFulltextIndex(): void
    {
        $tableName = (string) Db::name('wisedocs_documents')->getTable();

        // 1) 优先 ngram 解析器（中文友好）
        try {
            $this->execute(
                "ALTER TABLE `{$tableName}` ADD FULLTEXT `ft_wisedocs_documents` (`title`, `excerpt`, `content`) WITH PARSER ngram"
            );
            return;
        } catch (\Throwable $e) {
            // 继续尝试普通 FULLTEXT
        }

        // 2) 退化：普通 FULLTEXT
        try {
            $this->execute(
                "ALTER TABLE `{$tableName}` ADD FULLTEXT `ft_wisedocs_documents` (`title`, `excerpt`, `content`)"
            );
        } catch (\Throwable $e) {
            // 引擎不支持全文索引：忽略，搜索保持 LIKE
        }
    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_documents')) {
            $this->table('wisedocs_documents')->drop()->save();
        }
    }
}

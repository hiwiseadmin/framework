<?php
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * URL 规则表
 *
 * 以「类型 + 作用域」描述前台 URL 的模式模板，例如：
 *   type=content, scope=global, pattern=/{catdir}/{slug}.html
 *   type=category, scope=category, scope_id=5, pattern=/{catdir}/list_{page}.html
 *
 * 生效优先级：entity > category > model > global，且站点精确(site_id>0) > 全站(site_id=0)。
 * 详见 docs/wisecms-url-rule-design.md
 */
class CreateWisecmsUrlRuleTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_url_rule', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS URL规则表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setDefault(0)->setComment('站点ID，0=全站通用'))
            ->addColumn(Column::string('scope_type', 20)->setDefault('global')->setComment('作用域 global/model/category/entity'))
            ->addColumn(Column::integer('scope_id')->setDefault(0)->setComment('作用域ID（model_id/catid/实体ID）'))
            ->addColumn(Column::string('type', 20)->setDefault('content')->setComment('类型 content/category/tag/page'))
            ->addColumn(Column::string('pattern', 255)->setDefault('')->setComment('URL模式模板'))
            ->addColumn(Column::integer('priority')->setDefault(0)->setComment('优先级，越大越优先'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 1启用 0停用'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['site_id', 'type', 'scope_type'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_url_rule')) {
            $this->table('wisecms_url_rule')->drop()->save();
        }
    }
}

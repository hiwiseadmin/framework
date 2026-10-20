<?php

use think\migration\Seeder;

class WisecmsSeeder extends Seeder
{
    public function run(): void
    {
        // 插入默认模型
        $articleModelId = $this->insertModel('article', '文章模型', '文章、新闻等通用内容模型');
        $this->insertModelFields($articleModelId, [
            ['field' => 'content', 'name' => '正文内容', 'form_type' => 'editor', 'data_type' => 'mediumtext', 'is_system' => 0, 'is_base' => 1, 'is_required' => 1, 'sort_order' => 1],
            ['field' => 'author', 'name' => '作者', 'form_type' => 'text', 'data_type' => 'varchar', 'length' => 50, 'is_system' => 0, 'is_base' => 1, 'sort_order' => 2],
            ['field' => 'source', 'name' => '来源', 'form_type' => 'text', 'data_type' => 'varchar', 'length' => 100, 'is_system' => 0, 'is_base' => 1, 'sort_order' => 3],
        ]);

        $pageModelId = $this->insertModel('page', '单页模型', '关于我们、联系方式等单页');
        $this->insertModelFields($pageModelId, [
            ['field' => 'content', 'name' => '页面内容', 'form_type' => 'editor', 'data_type' => 'mediumtext', 'is_system' => 0, 'is_base' => 1, 'is_required' => 1, 'sort_order' => 1],
        ]);

        // 插入默认栏目
        $this->insertCategory(['name' => '新闻中心', 'alias' => 'news', 'model_id' => $articleModelId, 'parent_id' => 0, 'sort' => 1]);
        $this->insertCategory(['name' => '产品动态', 'alias' => 'products', 'model_id' => $articleModelId, 'parent_id' => 0, 'sort' => 2]);
        $this->insertCategory(['name' => '关于我们', 'alias' => 'about', 'model_id' => $pageModelId, 'type' => 1, 'parent_id' => 0, 'sort' => 3]);
    }

    protected function insertModel(string $name, string $title, string $description = ''): int
    {
        $data = [
            'name'        => $name,
            'title'       => $title,
            'table_name'  => 'wisecms_content_' . $name,
            'description' => $description,
            'is_system'   => 0,
            'status'      => 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];

        $exists = \think\facade\Db::name('wisecms_model')->where('name', $name)->find();
        if ($exists) {
            return $exists['id'];
        }

        return \think\facade\Db::name('wisecms_model')->insertGetId($data);
    }

    protected function insertModelFields(int $modelId, array $fields): void
    {
        foreach ($fields as $field) {
            $exists = \think\facade\Db::name('wisecms_model_field')
                ->where('model_id', $modelId)
                ->where('field', $field['field'])
                ->find();

            if ($exists) continue;

            $data = array_merge([
                'model_id'     => $modelId,
                'is_system'    => 0,
                'is_base'      => 1,
                'is_required'  => 0,
                'is_unique'    => 0,
                'is_search'    => 0,
                'is_fulltext'  => 0,
                'is_add'       => 1,
                'is_edit'      => 1,
                'is_list'      => 0,
                'data_type'    => 'varchar',
                'length'       => 255,
                'default_value' => '',
                'pattern'      => '',
                'tips'         => '',
                'errortips'    => '',
                'sort_order'   => 0,
                'status'       => 1,
                'create_time'  => date('Y-m-d H:i:s'),
            ], $field);

            \think\facade\Db::name('wisecms_model_field')->insert($data);
        }
    }

    protected function insertCategory(array $data): void
    {
        $exists = \think\facade\Db::name('wisecms_category')->where('alias', $data['alias'])->find();
        if ($exists) return;

        $data = array_merge([
            'site_id'     => 1,
            'type'        => 0,
            'list_rows'   => 15,
            'is_nav'      => 1,
            'status'      => 1,
            'create_time' => date('Y-m-d H:i:s'),
        ], $data);

        \think\facade\Db::name('wisecms_category')->insert($data);
    }
}

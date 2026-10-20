<?php
namespace plugin\wisedocs\validate;

use think\Validate;
use plugin\wisedocs\model\Tag as TagModel;

class Tag extends Validate
{
    protected $rule = [
        'name' => 'require|max:50|unique:wisedocs_tags',
        'slug' => 'max:50|alphaDash|unique:wisedocs_tags',
    ];
    
    protected $message = [
        'name.require' => '标签名称不能为空',
        'name.max' => '标签名称最多50个字符',
        'name.unique' => '标签名称已存在',
        'slug.max' => 'URL标识最多50个字符',
        'slug.alphaDash' => 'URL标识只能包含字母、数字、下划线和破折号',
        'slug.unique' => 'URL标识已存在',
    ];
    
    protected $scene = [
        'create' => ['name', 'slug'],
    ];

    /**
     * 更新场景：唯一性校验排除自身
     */
    public function sceneUpdate()
    {
        return $this->only(['name', 'slug'])
            ->remove('name', 'unique')
            ->remove('slug', 'unique')
            ->append('name', 'unique:wisedocs_tags,name^id')
            ->append('slug', 'unique:wisedocs_tags,slug^id');
    }

    // 自动生成slug
    public function check(array $data, array | string $rules = []): bool
    {
        // 如果没有提供slug，自动生成
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = $this->generateSlug($data['name']);
        }
        
        return parent::check($data, $rules);
    }
    
    // 生成slug
    protected function generateSlug($name)
    {
        // 仅保留 ASCII 字母/数字，其余（中文、空格、符号）统一折叠为 '-'
        // 注意：不能用 /[^\w]+/u —— UTF-8 模式下 \w 会匹配中文，生成的标识会含
        // 非 ASCII 字符，随后被 alphaDash 规则判为非法（“标识只能包含字母、数字…”）。
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim((string) $slug, '-');

        // 纯中文等无法生成有效 slug 时给出兜底
        if ($slug === '') {
            $slug = 'tag-' . substr(md5($name . microtime(true)), 0, 8);
        }
        
        // 确保唯一性
        $originalSlug = $slug;
        $i = 1;
        while (TagModel::where('slug', $slug)->find()) {
            $slug = $originalSlug . '-' . $i;
            $i++;
        }
        
        return $slug;
    }
}
<?php
namespace plugin\wisedocs\validate;

use think\Validate;
use plugin\wisedocs\model\Category;

class Document extends Validate
{
    protected $rule = [
        'category_id' => 'require|integer|checkCategory',
        'title' => 'require|max:200',
        'slug' => 'require|max:200|alphaDash|unique:wisedocs_documents',
        'content' => 'require',
        'content_type' => 'require|in:1,2,3,4',
        'status' => 'require|in:0,1',
        'is_featured' => 'in:0,1',
        'is_top' => 'in:0,1',
        'sort_order' => 'integer',
        'excerpt' => 'max:500',
        'cover' => 'max:255',
        'code_language' => 'requireIf:content_type,2|max:30',
        'api_method' => 'requireIf:content_type,4|in:GET,POST,PUT,DELETE,PATCH',
        'api_endpoint' => 'requireIf:content_type,4|max:255',
        // 组件型（D4）：示例代码不参与净化，改由沙箱隔离执行，
        // 这里只做长度闸门，具体的「是否为空 / 总字节」校验在控制器内完成。
        'demo_html' => 'max:20000',
        'demo_css' => 'max:20000',
        'demo_js' => 'max:20000',
        'demo_height' => 'integer',
    ];
    
    protected $message = [
        'category_id.require' => '请选择分类',
        'category_id.checkCategory' => '分类不存在或已禁用',
        'title.require' => '请输入文档标题',
        'title.max' => '标题最多200个字符',
        'slug.require' => '请输入URL标识',
        'slug.alphaDash' => 'URL标识只能包含字母、数字、下划线和破折号',
        'slug.unique' => 'URL标识已存在',
        'content.require' => '请输入文档内容',
        'content_type.require' => '请选择内容类型',
        'status.require' => '请选择状态',
        'code_language.requireIf' => '代码型文档请填写代码语言',
        'api_method.requireIf' => 'API 型文档请选择请求方法',
        'api_endpoint.requireIf' => 'API 型文档请填写接口端点',
        'demo_html.max' => 'HTML 结构最多 20000 个字符',
        'demo_css.max' => 'CSS 样式最多 20000 个字符',
        'demo_js.max' => 'JavaScript 最多 20000 个字符',
        'demo_height.integer' => '演示区域高度必须是整数',
    ];

    /** 全量编辑字段（含类型专属字段，使 requireIf / max 等规则真正生效） */
    protected const EDIT_FIELDS = [
        'category_id', 'title', 'slug', 'content', 'content_type', 'status',
        'is_featured', 'is_top', 'sort_order', 'excerpt', 'cover',
        'code_language', 'api_method', 'api_endpoint',
        'demo_html', 'demo_css', 'demo_js', 'demo_height',
    ];

    protected $scene = [
        'create' => self::EDIT_FIELDS,
        'update' => self::EDIT_FIELDS,
        'api' => ['title', 'slug', 'content', 'api_method', 'api_endpoint'],
    ];
    
    // 自定义验证规则：检查分类是否存在
    protected function checkCategory($value, $rule, $data = [])
    {
        $category = Category::where('id', $value)
            ->where('status', 1)
            ->find();
            
        return $category ? true : '分类不存在或已禁用';
    }
    
    // 检查slug唯一性（排除自身）
    public function sceneUpdate()
    {
        return $this->only(self::EDIT_FIELDS)
          ->remove('slug', 'unique')
          ->append('slug', 'unique:wisedocs_documents,slug^id');
    }
}
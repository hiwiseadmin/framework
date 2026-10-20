<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;
use think\model\concern\SoftDelete;

class Content extends BaseModel
{
    use SoftDelete;

    protected $name = 'wisecms_content';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';

    // JSON 字段
    protected $json = ['cover_images'];

    /**
     * 关联栏目
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'catid', 'id');
    }

    /**
     * 关联内容模型
     */
    public function model()
    {
        return $this->belongsTo(Model::class, 'model_id', 'id');
    }

    /**
     * 关联标签
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, TagRelation::class, 'tag_id', 'content_id');
    }

    /**
     * 关联版本
     */
    public function versions()
    {
        return $this->hasMany(ContentVersion::class, 'content_id', 'id')->order('version', 'desc');
    }

    /**
     * 状态文本
     */
    public function getStatusTextAttr($value, $data): string
    {
        $map = [0 => '草稿', 1 => '待审核', 2 => '已发布', 3 => '已归档'];
        return $map[$data['status'] ?? 0] ?? '未知';
    }

    /**
     * 获取SEO分数文本
     */
    public function getSeoLevelTextAttr($value, $data): string
    {
        $map = ['A' => '优秀', 'B' => '良好', 'C' => '一般', 'D' => '较差', 'F' => '很差'];
        return $map[$data['seo_level'] ?? 'C'] ?? '未评分';
    }
}

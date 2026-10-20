<?php
namespace plugin\wisedocs\model;

use think\Model;
use think\facade\Db;

class Tag extends Model
{
    protected $name = 'wisedocs_tags';
    
    /**
     * 自动时间戳（M5：显式声明）
     *
     * 原实现依赖 `config/database.php` 的全局 `auto_timestamp` 开关；一旦部署时关闭，
     * `create_time` / `update_time` 便不再写入。此处显式声明，行为不再随全局配置漂移。
     */
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';
    
    protected $type = [
        'id' => 'integer',
        'count' => 'integer',
    ];

    /**
     * 使用该标签的文档（多对多，P1 新增关联表 wisedocs_document_tags）
     */
    public function documents()
    {
        return $this->belongsToMany(Document::class, 'wisedocs_document_tags', 'document_id', 'tag_id');
    }

    // 标签使用统计+1
    public function incrementCount($tagIds)
    {
        if (!is_array($tagIds)) {
            $tagIds = [$tagIds];
        }
        
        return $this->whereIn('id', $tagIds)->inc('count')->update();
    }
    
    // 获取热门标签
    public function getHotTags($limit = 20)
    {
        return $this->order('count DESC')
            ->limit($limit)
            ->select();
    }
    
    // 根据标签名获取标签ID（不存在则创建）
    public function getTagIdsByNames($tagNames)
    {
        if (empty($tagNames)) {
            return [];
        }
        
        $tagIds = [];
        foreach ($tagNames as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;
            
            $tag = $this->where('name', $tagName)->find();
            if (!$tag) {
                // 创建新标签
                $slug = $this->generateSlug($tagName);
                $tag = $this->create([
                    'name' => $tagName,
                    'slug' => $slug,
                    'count' => 0,
                ]);
            }
            
            $tagIds[] = $tag->id;
        }
        
        return $tagIds;
    }
    
    // 生成标签slug
    protected function generateSlug($name)
    {
        // 仅保留 ASCII 字母/数字（见 validate/Tag::generateSlug 的同源说明）
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim((string) $slug, '-');

        // 纯中文等无法生成有效 slug 时给出兜底，避免 slug 唯一约束冲突
        if ($slug === '') {
            $slug = 'tag-' . substr(md5($name . microtime(true)), 0, 8);
        }

        // 确保唯一性
        $originalSlug = $slug;
        $i = 1;
        while ($this->where('slug', $slug)->find()) {
            $slug = $originalSlug . '-' . $i;
            $i++;
        }
        
        return $slug;
    }
    
    // 标签云数据
    public function getTagCloud($limit = 50)
    {
        $tags = $this->order('count DESC')
            ->limit($limit)
            ->select()
            ->toArray();
        
        if (empty($tags)) {
            return [];
        }
        
        // 计算标签大小（1-5级）
        $maxCount = max(array_column($tags, 'count'));
        $minCount = min(array_column($tags, 'count'));
        
        foreach ($tags as &$tag) {
            if ($maxCount == $minCount) {
                $tag['size'] = 3;
            } else {
                $tag['size'] = 1 + round(4 * ($tag['count'] - $minCount) / ($maxCount - $minCount));
            }
        }
        
        return $tags;
    }
}
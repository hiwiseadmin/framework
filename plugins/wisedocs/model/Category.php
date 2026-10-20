<?php
namespace plugin\wisedocs\model;

use plugin\BaseModel;

class Category extends BaseModel
{
    protected $name = 'wisedocs_categories';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';

    /**
     * 父分类
     */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * 子分类
     */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * 分类下的文档
     */
    public function documents()
    {
        return $this->hasMany(Document::class, 'category_id');
    }

    // 获取树形结构
    public function getTree($status = null)
    {
        $query = $this->order('sort_order DESC, id ASC');
        
        if (!is_null($status)) {
            $query->where('status', $status);
        }
        
        $categories = $query->select()->toArray();
        
        return $this->buildTree($categories);
    }
    
    // 构建树形结构
    protected function buildTree($items, $parentId = 0)
    {
        $tree = [];
        foreach ($items as $item) {
            if ($item['parent_id'] == $parentId) {
                // children 恒定存在（叶子为 []），避免消费方按「键是否存在」判断而踩空
                $item['children'] = $this->buildTree($items, $item['id']);
                $tree[] = $item;
            }
        }
        return $tree;
    }
    
    // 获取所有子分类ID
    public function getChildrenIds($categoryId, $includeSelf = true)
    {
        $ids = $includeSelf ? [$categoryId] : [];
        
        $children = $this->where('parent_id', $categoryId)->column('id');
        foreach ($children as $childId) {
            $ids = array_merge($ids, $this->getChildrenIds($childId, true));
        }
        
        return $ids;
    }
    
    // 更新路径
    public function updatePath($id)
    {
        $category = $this->find($id);
        if (!$category) {
            return false;
        }
        
        if ($category->parent_id > 0) {
            $parent = $this->find($category->parent_id);
            $path = $parent->path . $parent->id . ',';
            $level = $parent->level + 1;
        } else {
            $path = '';
            $level = 1;
        }
        
        $category->save([
            'path' => $path,
            'level' => $level,
        ]);
        
        // 更新子分类
        $children = $this->where('parent_id', $id)->select();
        foreach ($children as $child) {
            $this->updatePath($child->id);
        }
        
        return true;
    }
}
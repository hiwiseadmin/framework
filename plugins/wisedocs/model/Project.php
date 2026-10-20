<?php

declare(strict_types=1);

namespace plugin\wisedocs\model;

use plugin\BaseModel;

/**
 * 文档空间（P1 新增）
 *
 * 支撑「多空间文档中心」定位（设计 D1）：每个空间可绑定独立域名与主题，
 * 分类与文档按 project_id 归属空间。
 */
class Project extends BaseModel
{
    protected $name = 'wisedocs_projects';

    protected $type = [
        'id'         => 'integer',
        'sort_order' => 'integer',
        'status'     => 'integer',
    ];

    public function categories()
    {
        return $this->hasMany(Category::class, 'project_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'project_id');
    }
}

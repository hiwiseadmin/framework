<?php

declare(strict_types=1);

namespace plugin\wisedocs\model;

use plugin\BaseModel;

/**
 * 文档发布快照模型
 *
 * 每次「发布」写入一条，记录该次发布后的正式内容。
 * 用途：发布前差异对比（当前编辑稿 vs 最近一次发布）、后续可扩展为版本回溯。
 *
 * 注意：本表只有 create_time，没有 update_time，故关闭自动时间戳、
 * 由调用方显式写入 create_time（ThinkPHP 默认会同时写两个时间字段）。
 */
class Revision extends BaseModel
{
    /** 表名（必须显式指定） */
    protected $name = 'wisedocs_document_revisions';

    /** 关闭自动时间戳：表内无 update_time */
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id'          => 'integer',
        'document_id' => 'integer',
        'admin_id'    => 'integer',
    ];

    /**
     * 所属文档
     */
    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

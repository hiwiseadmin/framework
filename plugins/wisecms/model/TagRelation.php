<?php
namespace plugin\wisecms\model;

use think\model\Pivot;

/**
 * 内容 ↔ 标签 关联表（多对多中间表）
 *
 * ⚠️ 必须继承 `\think\model\Pivot`（而非 `plugin\BaseModel`）：
 * ThinkPHP 的 `BelongsToMany::newPivot()` 会强制校验中间表模型是 `Pivot` 的实例，
 * 否则抛 `pivot model must extends: \think\model\Pivot` ——
 * `Content::with(['tags'])`（后台内容编辑、前台内容详情）会直接 500。
 * 见 vendor/topthink/think-orm/src/model/relation/BelongsToMany.php:142-152。
 *
 * 触发表：`wisecms_tag_relation`（含 create_time/update_time，故 timeStamp 保持 true）。
 */
class TagRelation extends Pivot
{
    protected $name = 'wisecms_tag_relation';

    // 自动时间戳：create_time / update_time 均为 DATETIME（见建表迁移的时间列约定）
    protected $autoWriteTimestamp = true;
}

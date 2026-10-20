<?php
namespace plugin\wisecms\field\editor;

use plugin\wisecms\field\base\FieldBase;

class Update extends FieldBase
{
    /**
     * 更新处理（更新时）
     */
    public function updateFilter($value, array $fieldInfo = [])
    {
        // TODO(动态字段底座): 附件引用追踪表(wisecms_attachment_relation)尚未建立，
        // 待附件关系功能落地后，在此实现内容与附件的关联维护。
        return $value;
    }
}

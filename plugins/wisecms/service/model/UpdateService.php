<?php
namespace plugin\wisecms\service\model;

/**
 * 字段更新服务（四阶段之 Update，仅自定义字段 is_system=0）
 *
 * 负责"内容被更新时"的字段处理：遍历模型自定义字段，调用各字段 Update 类
 * （回退 Form 类）的 updateFilter 进行更新时的特殊处理。
 */
class UpdateService
{
    /**
     * 字段注册中心
     */
    protected FieldRegistry $fields;

    public function __construct(?FieldRegistry $fields = null)
    {
        $this->fields = $fields ?? new FieldRegistry();
    }

    /**
     * 处理更新数据（更新时字段处理）
     *
     * 返回处理后的自定义字段值（字段名 => 值）。
     */
    public function processData(int $modelId, array $data): array
    {
        $fields = $this->fields->getCustomFields($modelId);

        $processed = [];

        foreach ($fields as $field) {
            $fieldName = $field['field'];
            $value = $data[$fieldName] ?? $field['default_value'] ?? '';

            // 创建字段 Update 对象（优先 Update 类，回退到 Form）
            $fieldObj = FieldFactory::createUpdate($field['form_type'], $field, $value, $data);

            // 更新过滤
            $processed[$fieldName] = $fieldObj->updateFilter($value);
        }

        return $processed;
    }
}

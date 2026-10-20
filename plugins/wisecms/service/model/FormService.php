<?php
namespace plugin\wisecms\service\model;

class FormService
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
     * 生成模型自定义字段表单（is_system=0）
     *
     * 系统字段（title/content/栏目等）由内容表单模板固定渲染，此处仅生成副表自定义字段。
     *
     * @param string $scene 场景：add（添加页，按 is_add 过滤）/ edit（编辑页，按 is_edit 过滤）
     */
    public function generateForm(int $modelId, array $data = [], string $scene = 'edit'): array
    {
        // 获取模型自定义字段
        $fields = $this->fields->getCustomFields($modelId);
        
        $formData = [
            'base' => [],   // 基本字段
            'senior' => [], // 高级字段
        ];
        
        foreach ($fields as $field) {
            // 字段级展示控制（C-4）：add 场景看 is_add，edit 场景看 is_edit
            if ($scene === 'add' && empty($field['is_add'])) {
                continue;
            }
            if ($scene === 'edit' && empty($field['is_edit'])) {
                continue;
            }

            $value = $data[$field['field']] ?? $field['default_value'];
            
            // 创建字段对象
            $fieldObj = FieldFactory::create($field['form_type'], $field, $value, $data);
            
            // 生成表单HTML
            $formHtml = $fieldObj->getFormHtml($field, $value);

            // 获取验证规则
            $validateRules = $fieldObj->getValidateRules($field);
            
            $fieldInfo = [
                'name' => $field['name'],
                'field' => $field['field'],
                'form' => $formHtml,
                'rules' => $validateRules,
                'tips' => $field['tips'] ?? '',
                'is_required' => $field['is_required'],
            ];
            
            // 按字段分组
            if ($field['is_base']) {
                $formData['base'][$field['field']] = $fieldInfo;
            } else {
                $formData['senior'][$field['field']] = $fieldInfo;
            }
        }
        
        return $formData;
    }
}

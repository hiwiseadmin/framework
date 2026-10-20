<?php
namespace plugin\wisecms\service\model;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\field\base\FieldBase;
use plugin\wisecms\model\Model;
use think\facade\Db;

class InputService
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
     * 处理提交数据（入库前处理，仅自定义字段 is_system=0）
     *
     * 返回处理后的自定义字段值（字段名 => 值），用于写入副表。
     */
    public function processData(int $modelId, array $data): array
    {
        $fields = $this->fields->getCustomFields($modelId);

        $processed = [];

        foreach ($fields as $field) {
            $fieldName = $field['field'];
            $value = $data[$fieldName] ?? $field['default_value'] ?? '';

            // 创建字段 Input 对象（优先 Input 类，回退到 Form）
            $fieldObj = FieldFactory::createInput($field['form_type'], $field, $value, $data);

            // 输入过滤
            $processedValue = $fieldObj->inputFilter($value);

            // 字段级校验（必填/长度/正则）
            $this->validateField($field, $processedValue);

            // 唯一性验证
            if ($field['is_unique']) {
                $this->validateUnique($field, $processedValue, $data['id'] ?? 0);
            }

            $processed[$fieldName] = $processedValue;
        }

        return $processed;
    }

    /**
     * 唯一性验证
     */
    protected function validateUnique(array $field, $value, $id = 0): void
    {
        $model = Model::find($field['model_id']);
        if (!$model) {
            return;
        }
        $tableName = $model->table_name . '_data';

        $query = Db::name($tableName)
            ->where($field['field'], $value);

        if ($id > 0) {
            $query->where('id', '<>', $id);
        }

        if ($query->find()) {
            throw new WisecmsException($field['name'] . '的值已存在', ErrorCode::DUPLICATE);
        }
    }

    /**
     * 字段级校验（必填 / 长度 / 正则）
     */
    protected function validateField(array $field, $value): void
    {
        // 必填校验
        if (!empty($field['is_required']) && ($value === '' || $value === null || $value === [])) {
            throw new WisecmsException($field['name'] . '不能为空', ErrorCode::VALIDATE_FAILED);
        }

        // 长度校验
        // UIUX-P1-32：`length` 仅对 varchar/char 构成真实约束；text/mediumtext/longtext
        // 无字符上限，配置残留的 length（如 editor 字段默认 255）不得卡死富文本正文。
        if (
            !empty($field['length'])
            && FieldBase::isLengthBounded((string) ($field['data_type'] ?? ''))
            && is_string($value) && $value !== ''
        ) {
            if (mb_strlen($value, 'UTF-8') > (int) $field['length']) {
                throw new WisecmsException($field['name'] . '长度不能超过' . $field['length'] . '个字符', ErrorCode::VALIDATE_FAILED);
            }
        }

        // 正则校验
        if (!empty($field['pattern']) && is_string($value) && $value !== '') {
            $result = @preg_match($field['pattern'], $value);
            if ($result === 0) {
                throw new WisecmsException($field['name'] . '格式不正确', ErrorCode::VALIDATE_FAILED);
            }
        }
    }
}

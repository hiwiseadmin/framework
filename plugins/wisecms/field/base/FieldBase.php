<?php
namespace plugin\wisecms\field\base;

/**
 * 字段基类
 * 所有字段类型的 Form/Input/Output/Update 等类的共同基类
 *
 * 提供：
 * - 属性存储（fieldInfo/value/data）
 * - 通用验证规则
 * - 默认过滤器（子类可覆盖）
 */
abstract class FieldBase
{
    /** @var array 字段配置信息 */
    protected array $fieldInfo = [];

    /** @var mixed 当前字段值 */
    protected $value = null;

    /** @var array 完整提交数据 */
    protected array $data = [];

    /**
     * 设置字段配置信息
     */
    public function setFieldInfo(array $fieldInfo): self
    {
        $this->fieldInfo = $fieldInfo;
        return $this;
    }

    /**
     * 设置当前值
     */
    public function setValue($value): self
    {
        $this->value = $value;
        return $this;
    }

    /**
     * 设置全部提交数据
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 生成表单 HTML（子类应覆盖）
     */
    public function getFormHtml(array $fieldInfo, $value = ''): string
    {
        return '';
    }

    /**
     * 存储列是否受字符长度上限约束（UIUX-P1-32）。
     *
     * `length` 只对定长/变长字符列（`varchar` / `char`）构成真实数据库约束；
     * **text 家族**（`tinytext` / `text` / `mediumtext` / `longtext`）没有字符上限，
     * 字段配置里残留的 `length`（如默认 255）对它们只是历史默认值，不应当作校验上限。
     *
     * 反例（修复前）：`editor` 字段 `data_type=mediumtext`、`length=255`，
     * 提交任何 >255 字的富文本正文都会被 `InputService::validateField()` 拒绝，
     * 导致编辑器字段实际不可用；前端 `getValidateRules()` 也会带上无意义的 `max:255`。
     *
     * `data_type` 缺省时按「有上限」处理，保持对旧调用方的行为兼容。
     *
     * @param string $dataType 字段的存储类型（`varchar` / `text` / `mediumtext` ...）
     * @return bool true=套用 `length` 上限；false=不做长度校验
     */
    public static function isLengthBounded(string $dataType): bool
    {
        return !in_array(strtolower(trim($dataType)), ['tinytext', 'text', 'mediumtext', 'longtext'], true);
    }

    /**
     * 获取验证规则（默认实现，子类可覆盖并调用 parent）
     */
    public function getValidateRules(array $fieldInfo): array
    {
        $rules = [];
        if (!empty($fieldInfo['is_required'])) {
            $rules[] = 'require';
        }
        // UIUX-P1-32：text 家族无字符上限，不得下发 max:N
        if (!empty($fieldInfo['length']) && self::isLengthBounded((string) ($fieldInfo['data_type'] ?? ''))) {
            $rules[] = 'max:' . $fieldInfo['length'];
        }
        return $rules;
    }

    /**
     * 输入过滤 — 入库前处理（子类可覆盖）
     *
     * @param mixed $value     当前字段值
     * @param array $fieldInfo 字段配置
     * @return mixed
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        return $value;
    }

    /**
     * 输出过滤 — 出库后处理（子类可覆盖）
     *
     * @param mixed $value     当前字段值
     * @param array $fieldInfo 字段配置
     * @return mixed
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        return $value;
    }

    /**
     * 更新过滤 — 更新时处理（子类可覆盖）
     *
     * 与 inputFilter 的区别：本阶段面向"已有内容被更新"的场景，
     * 用于需要在更新时执行的特殊逻辑（如附件引用变更、关联关系维护）。
     *
     * @param mixed $value     当前字段值
     * @param array $fieldInfo 字段配置
     * @return mixed
     */
    public function updateFilter($value, array $fieldInfo = [])
    {
        return $value;
    }

    /**
     * 归一化字段 `setting` 为数组（与 normalizeOptions 同属「形状归一化」家族，UIUX-P0-31）。
     *
     * `setting` 在链路上有两种形态：
     *   - **数组**：`FieldRegistry::getFields()` 经 `ModelField::$json = ['setting']` 已解码，
     *     `normalize()` 再兜底为数组 —— 这是 FormService / InputService / OutputService 的入口形态；
     *   - **JSON 字符串**：直接来自 `ModelField` 实例、或旧调用方手工拼的参数。
     *
     * PHP 8 下 `json_decode(array)` 抛 `TypeError`（不是 warning、也不可被 `??` 兜住），
     * 因此两者必须统一经本方法解析，禁止各字段类自行 `json_decode($fieldInfo['setting'])`。
     *
     * @param mixed $setting 数组 | JSON 字符串 | null
     * @return array
     */
    public static function normalizeSetting($setting): array
    {
        if (is_array($setting)) {
            return $setting;
        }
        if (is_string($setting) && $setting !== '') {
            $decoded = json_decode($setting, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 归一化「选项」为 `['值' => '文本']` 数组（select/radio/checkbox 共用，UIUX-P1-25）。
     *
     * 配置表单用多行文本编辑，每行 `值|显示文本`（缺 `|` 时值=文本=整行）；
     * 提交后 `setting[options]` 存的是字符串，消费方调用本方法解析。
     * 同时兼容历史已存数组形态（直接原样返回）。
     *
     * @param mixed $options 数组或「值|文本」多行字符串
     * @return array
     */
    public static function normalizeOptions($options): array
    {
        if (is_array($options)) {
            return $options;
        }
        if (!is_string($options) || trim($options) === '') {
            return [];
        }

        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $options) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '|') !== false) {
                [$key, $val] = array_pad(explode('|', $line, 2), 2, '');
            } else {
                $key = $line;
                $val = $line;
            }
            $result[trim($key)] = trim($val);
        }
        return $result;
    }

    /**
     * 把「值 => 文本」数组序列化成「值|文本」多行文本（供 EditForm 回显，UIUX-P1-25）。
     */
    public static function optionsToText(array $options): string
    {
        $lines = [];
        foreach ($options as $key => $val) {
            $lines[] = $key . '|' . $val;
        }
        return implode("\n", $lines);
    }
}

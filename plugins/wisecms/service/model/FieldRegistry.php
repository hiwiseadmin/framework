<?php
namespace plugin\wisecms\service\model;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\model\ModelField;

/**
 * 字段注册中心
 *
 * 作为 WiseCMS 字段体系的唯一 schema 入口，统一：
 *   1. 加载某模型的启用字段定义（含系统字段与自定义字段）；
 *   2. 消费 field/<type>/config.php 的类型元数据；
 *   3. 缓存（L1 请求级 + L3 Redis/文件），避免各服务重复查表。
 *
 * 所有需要"某模型有哪些字段、各自什么类型/约束"的代码都应通过本类获取，
 * 避免 FormService/InputService/OutputService 各自查表导致的字段名与语义不一致。
 */
class FieldRegistry
{
    /** @var CacheService */
    protected $cache;

    public function __construct(?CacheService $cache = null)
    {
        $this->cache = $cache ?? new CacheService();
    }

    /**
     * 获取模型全部启用字段（含 setting 解码与类型元数据合并）
     */
    public function getFields(int $modelId): array
    {
        $key = sprintf(CacheService::KEY_MODEL_FIELDS, $modelId);

        return $this->cache->remember($key, function () use ($modelId) {
            $fields = ModelField::where('model_id', $modelId)
                ->where('status', 1)
                ->order('sort_order', 'asc')
                ->select()
                ->toArray();

            return $this->normalize($fields);
        }, 3600);
    }

    /**
     * 获取单个字段定义
     */
    public function getField(int $modelId, string $fieldName): ?array
    {
        foreach ($this->getFields($modelId) as $field) {
            if (($field['field'] ?? '') === $fieldName) {
                return $field;
            }
        }
        return null;
    }

    /**
     * 获取系统字段（主表字段，is_system = 1）
     */
    public function getSystemFields(int $modelId): array
    {
        return array_values(array_filter(
            $this->getFields($modelId),
            static fn(array $field): bool => (int) ($field['is_system'] ?? 0) === 1
        ));
    }

    /**
     * 获取自定义字段（副表字段，is_system = 0）
     */
    public function getCustomFields(int $modelId): array
    {
        return array_values(array_filter(
            $this->getFields($modelId),
            static fn(array $field): bool => (int) ($field['is_system'] ?? 0) === 0
        ));
    }

    /**
     * 清除某模型的字段缓存
     */
    public function clear(int $modelId): void
    {
        $this->cache->delete(sprintf(CacheService::KEY_MODEL_FIELDS, $modelId));
    }

    /**
     * 规范化字段定义：
     *   - 统一 setting 为数组（兼容原始 JSON 字符串与模型已解码数组）
     *   - 合并 field/<type>/config.php 类型元数据到 type_config
     *   - data_type 为空时回退到类型配置的 field_type
     */
    protected function normalize(array $fields): array
    {
        foreach ($fields as &$field) {
            $setting = $field['setting'] ?? [];
            if (is_string($setting)) {
                $setting = json_decode($setting, true) ?: [];
            }
            $field['setting'] = is_array($setting) ? $setting : [];

            $typeConfig = FieldFactory::getFieldTypeConfig((string) ($field['form_type'] ?? ''));
            $field['type_config'] = $typeConfig;

            if (empty($field['data_type']) && !empty($typeConfig['field_type'])) {
                $field['data_type'] = $typeConfig['field_type'];
            }
        }
        unset($field);

        return $fields;
    }
}

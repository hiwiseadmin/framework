<?php
namespace plugin\wisecms\service\model;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;

/**
 * 字段工厂类 - 根据表单类型创建对应的字段处理对象
 */
class FieldFactory
{
    /** @var array 各字段类型对应的 Form 处理类，仅特殊字段需要 */
    protected static array $formClassMap = [
        'text'      => \plugin\wisecms\field\text\Form::class,
        'textarea'  => \plugin\wisecms\field\textarea\Form::class,
        'number'    => \plugin\wisecms\field\number\Form::class,
        'select'    => \plugin\wisecms\field\select\Form::class,
        'radio'     => \plugin\wisecms\field\radio\Form::class,
        'checkbox'  => \plugin\wisecms\field\checkbox\Form::class,
        'toggle'    => \plugin\wisecms\field\toggle\Form::class,
        'date'      => \plugin\wisecms\field\date\Form::class,
        'datetime'  => \plugin\wisecms\field\datetime\Form::class,
        'color'     => \plugin\wisecms\field\color\Form::class,
        'image'     => \plugin\wisecms\field\image\Form::class,
        'images'    => \plugin\wisecms\field\images\Form::class,
        'file'      => \plugin\wisecms\field\file\Form::class,
        'markdown'  => \plugin\wisecms\field\markdown\Form::class,
        'tags'      => \plugin\wisecms\field\tags\Form::class,
        'editor'    => \plugin\wisecms\field\editor\Form::class,
    ];

    // ========== Input / Output / Update 类映射 ==========

    /** @var array 各字段类型对应的 Input 处理类，仅特殊字段需要 */
    protected static array $inputClassMap = [
        'editor'   => \plugin\wisecms\field\editor\Input::class,
        'datetime' => \plugin\wisecms\field\datetime\Input::class,
        'images'   => \plugin\wisecms\field\images\Input::class,
        'file'     => \plugin\wisecms\field\file\Input::class,
        'checkbox' => \plugin\wisecms\field\checkbox\Input::class,
        'toggle'   => \plugin\wisecms\field\toggle\Input::class,
        'tags'     => \plugin\wisecms\field\tags\Input::class,
        'number'   => \plugin\wisecms\field\number\Input::class,
    ];

    /** @var array 各字段类型对应的 Output 处理类，仅特殊字段需要 */
    protected static array $outputClassMap = [
        'editor'   => \plugin\wisecms\field\editor\Output::class,
        'markdown' => \plugin\wisecms\field\markdown\Output::class,
        'textarea' => \plugin\wisecms\field\textarea\Output::class,
        'datetime' => \plugin\wisecms\field\datetime\Output::class,
        'images'   => \plugin\wisecms\field\images\Output::class,
        'checkbox' => \plugin\wisecms\field\checkbox\Output::class,
        'tags'     => \plugin\wisecms\field\tags\Output::class,
        'select'   => \plugin\wisecms\field\select\Output::class,
        'radio'    => \plugin\wisecms\field\radio\Output::class,
    ];

    /** @var array 各字段类型对应的 Update 处理类，仅特殊字段需要 */
    protected static array $updateClassMap = [
        'editor' => \plugin\wisecms\field\editor\Update::class,
    ];

    // ========== 工厂方法 ==========

    /**
     * 创建字段 Form 对象（生成表单 HTML + 验证规则）
     */
    public static function create(string $formType, array $field, $value, array $data): object
    {
        $className = static::$formClassMap[$formType] ?? \plugin\wisecms\field\text\Form::class;
        return static::instantiate($className, $field, $value, $data);
    }

    /**
     * 创建字段 Input 对象（入库前输入处理）
     * 若该字段类型未注册 Input 类，则回退到 Form 类
     */
    public static function createInput(string $formType, array $field, $value, array $data): object
    {
        $className = static::$inputClassMap[$formType]
            ?? static::$formClassMap[$formType]
            ?? \plugin\wisecms\field\text\Form::class;
        return static::instantiate($className, $field, $value, $data);
    }

    /**
     * 创建字段 Output 对象（出库后输出处理）
     * 若该字段类型未注册 Output 类，则回退到 Form 类
     */
    public static function createOutput(string $formType, array $field, $value, array $data): object
    {
        $className = static::$outputClassMap[$formType]
            ?? static::$formClassMap[$formType]
            ?? \plugin\wisecms\field\text\Form::class;
        return static::instantiate($className, $field, $value, $data);
    }

    /**
     * 创建字段 Update 对象（更新时的特殊处理）
     * 若该字段类型未注册 Update 类，则回退到 Form 类
     */
    public static function createUpdate(string $formType, array $field, $value, array $data): object
    {
        $className = static::$updateClassMap[$formType]
            ?? static::$formClassMap[$formType]
            ?? \plugin\wisecms\field\text\Form::class;
        return static::instantiate($className, $field, $value, $data);
    }

    /**
     * 注册自定义 Input 类
     */
    public static function registerInput(string $formType, string $className): void
    {
        static::$inputClassMap[$formType] = $className;
    }

    /**
     * 注册自定义 Output 类
     */
    public static function registerOutput(string $formType, string $className): void
    {
        static::$outputClassMap[$formType] = $className;
    }

    /**
     * 注册自定义 Update 类
     */
    public static function registerUpdate(string $formType, string $className): void
    {
        static::$updateClassMap[$formType] = $className;
    }

    /**
     * 获取所有支持的字段类型列表（供UI配置使用）
     */
    public static function getAllFieldTypes(): array
    {
        return array_keys(static::$formClassMap);
    }

    /**
     * 获取字段类型元数据（消费 field/<type>/config.php）
     */
    public static function getFieldTypeConfig(string $type): array
    {
        if (!isset(static::$formClassMap[$type])) {
            return [];
        }
        return static::loadFieldTypeConfig($type);
    }

    /**
     * 获取字段类型列表（含显示名/图标，供字段类型下拉使用）
     */
    public static function getFieldTypeList(): array
    {
        $list = [];
        foreach (array_keys(static::$formClassMap) as $type) {
            $config = static::loadFieldTypeConfig($type);
            $list[] = [
                'value' => $type,
                'name'  => $config['name'] ?? $type,
                'icon'  => $config['icon'] ?? '',
            ];
        }
        return $list;
    }

    /**
     * 加载字段类型配置
     */
    protected static function loadFieldTypeConfig(string $type): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $type)) {
            return [];
        }
        $configFile = __DIR__ . '/../../field/' . $type . '/config.php';
        if (!is_file($configFile)) {
            return [];
        }
        $config = include $configFile;
        return is_array($config) ? $config : [];
    }

    /**
     * 获取字段添加表单HTML
     */
    public static function getAddFormHtml(string $fieldType): string
    {
        $className = "plugin\\wisecms\\field\\{$fieldType}\\AddForm";
        if (class_exists($className) && method_exists($className, 'getAddForm')) {
            return $className::getAddForm();
        }
        return '';
    }

    /**
     * 获取字段编辑表单HTML
     */
    public static function getEditFormHtml(string $fieldType, array $setting = []): string
    {
        $className = "plugin\\wisecms\\field\\{$fieldType}\\EditForm";
        if (class_exists($className) && method_exists($className, 'getEditForm')) {
            return $className::getEditForm($setting);
        }
        return '';
    }

    // ========== 内部工具 ==========

    /**
     * 实例化并初始化字段处理对象
     */
    private static function instantiate(string $className, array $field, $value, array $data): object
    {
        if (!class_exists($className)) {
            throw new WisecmsException("Field class [{$className}] not found", ErrorCode::SAVE_FAILED);
        }

        $instance = new $className();
        $instance->setFieldInfo($field);
        $instance->setValue($value);
        $instance->setData($data);
        return $instance;
    }
}

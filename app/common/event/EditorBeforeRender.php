<?php
declare(strict_types=1);

namespace app\common\event;

/**
 * 编辑器渲染前事件
 *
 * 监听者可以通过此事件修改编辑器渲染参数。
 */
class EditorBeforeRender
{
    /** @var string 编辑器类型 */
    public string $type;

    /** @var string 表单字段名 */
    public string $name;

    /** @var mixed 初始值 */
    public $value;

    /** @var array 渲染选项（可修改） */
    public array $options;

    /** @var \app\common\contract\EditorDriverInterface 编辑器驱动实例 */
    public $driver;

    public function __construct(string $type, string $name, $value, array $options, $driver)
    {
        $this->type    = $type;
        $this->name    = $name;
        $this->value   = $value;
        $this->options = $options;
        $this->driver  = $driver;
    }
}

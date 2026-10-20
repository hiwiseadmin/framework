<?php

declare(strict_types=1);

namespace think\template;

/**
 * think\template\TagLib 的零依赖替身（仅用于 `tests/run.php` 自检）
 *
 * 背景：`plugin\wisecms\taglib\Cms` 继承 `think\template\TagLib`，而零依赖自检
 * 刻意不引导 Composer / think-template（vendor 可能未安装）。若基类不存在，
 * 加载 Cms 会 `Class not found` 致命错误，导致「编译产物」用例无法执行。
 *
 * 因此 run.php 会在 `class_exists(\think\template\TagLib::class)` 为假时引入本替身；
 * vendor 存在时不会被引入，Cms 使用的是真实基类。
 *
 * 替身只实现 Cms 编译期真正用到的能力：
 *   · `autoBuildVar()`：仅当模板属性写成 `$var` 时才会被调用（字面量属性不触发）。
 *     真实实现会改写变量表达式，替身原样返回即可满足「产物形状」断言。
 *
 * 注意：本文件不得被 PSR-4 自动加载（其命名空间不属于 plugin\wisecms）。
 */
class TagLib
{
    /** @var mixed 模板引擎实例（替身下可为任意值） */
    public $tpl;

    /**
     * @param mixed $tpl
     */
    public function __construct($tpl = null)
    {
        $this->tpl = $tpl;
    }

    /**
     * 自动识别构建变量（替身：原样返回）
     *
     * @param string $name
     */
    public function autoBuildVar(&$name)
    {
        return $name;
    }
}

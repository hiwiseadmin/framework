<?php
/**
 * 测试替身：plugin\BaseModel（think\Model 子类）
 *
 * 项目未引入 PHPUnit，verify-*.php 在无框架环境下需要加载 Model 类来调用其
 * **静态纯函数**（状态文案映射等）。这里给出最小替身，避免拉起整个 ThinkPHP。
 *
 * ⚠️ 只用于离线自检，不要在生产代码里引用。
 */

declare(strict_types=1);

namespace plugin;

class BaseModel
{
    protected $name = '';

    protected $createTime = false;

    protected $updateTime = false;

    protected $deleteTime = false;

    protected $type = [];

    protected $json = [];

    protected $jsonAssoc = false;
}

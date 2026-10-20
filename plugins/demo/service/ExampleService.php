<?php
declare(strict_types=1);

namespace plugin\demo\service;

/**
 * demo 示例服务
 */
class ExampleService
{
    /**
     * 获取插件信息。
     */
    public function getInfo(): array
    {
        return [
            'name'    => 'demo',
            'version' => '1.0.0',
            'author'  => 'Unknown',
        ];
    }
}

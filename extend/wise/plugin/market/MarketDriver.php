<?php

declare(strict_types=1);

namespace wise\plugin\market;

use wise\plugin\contract\MarketDriverInterface;

/**
 * MarketDriver 是市场驱动的抽象基类。
 *
 * 它为具体的市场驱动实现（如 LocalMarket 和 HttpMarket）提供通用功能和模板。
 */
abstract class MarketDriver implements MarketDriverInterface
{
    /**
     * 驱动配置。
     *
     * @var array
     */
    protected array $config = [];

    /**
     * 创建一个新的 MarketDriver 实例。
     *
     * @param array $config 驱动配置
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 获取驱动配置。
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}

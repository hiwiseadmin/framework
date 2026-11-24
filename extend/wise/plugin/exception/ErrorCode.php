<?php

declare(strict_types=1);

namespace wise\plugin\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * 插件系统业务错误码（app 层号段 1000~1099）
 *
 * 号段分配约定（见 wise-support\exception\WiseException 号段全景）：
 *  1000~1099  → 插件系统（wise\plugin）
 *
 * @package wise\plugin\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 通用插件错误 */
    case PLUGIN_ERROR             = 1000;

    /** 插件未找到 */
    case PLUGIN_NOT_FOUND         = 1001;

    /** 插件安装/卸载/升级失败 */
    case PLUGIN_INSTALL_ERROR     = 1002;

    /** 插件配置错误 */
    case PLUGIN_CONFIG_ERROR      = 1003;

    /** 插件依赖不满足 */
    case PLUGIN_DEPENDENCY_ERROR  = 1004;

    /** 插件循环依赖 */
    case PLUGIN_CIRCULAR_DEPENDENCY = 1005;

    /**
     * 错误码对应的默认中文说明
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::PLUGIN_ERROR              => '插件处理失败',
            self::PLUGIN_NOT_FOUND          => '插件不存在',
            self::PLUGIN_INSTALL_ERROR      => '插件安装失败',
            self::PLUGIN_CONFIG_ERROR       => '插件配置错误',
            self::PLUGIN_DEPENDENCY_ERROR   => '插件依赖不满足',
            self::PLUGIN_CIRCULAR_DEPENDENCY => '检测到插件循环依赖',
        };
    }
}

<?php
/**
 * 测试替身：wise\support\exception\ErrorCodeInterface
 *
 * 仅用于离线自检脚本：`exception/Match3ErrorCode.php` 实现该接口，
 * 而接口文件在 `src/packages/wiseadmin/wise-support/` 里。
 *
 * 正常情况下自检脚本会直接 require **真实接口文件**（它是纯契约、零依赖，
 * 没有理由复制一份）；只有包目录结构变化、真实文件找不到时才用本替身兜底，
 * 避免"一个路径变化就让整套自检跑不起来"。
 */

declare(strict_types=1);

namespace wise\support\exception;

interface ErrorCodeInterface extends \BackedEnum
{
    public function defaultMessage(): string;
}

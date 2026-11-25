<?php
declare(strict_types=1);

namespace app\api\controller;

use app\common\controller\Attachment as CommonAttachment;
use app\common\middleware\AttachmentLogin;

/**
 * api 应用附件控制器（模块化分发入口）
 *
 * /api/attachment/{action} 由 think api 应用按「模块/控制器」语义自动分发到本类：
 *   upload / getList / info / download / delete。
 *
 * 登录控制：
 *   - 不在 route/api.php 的 group('api') 统一中间件链内（附件接口无需继承其链），
 *   - 改由控制器 $middleware 挂载附件专用登录中间件 AttachmentLogin：
 *     后台 admin 会话 / 前台 member JWT / api 端 token 任一登录即可；
 *     download 默认免登（白名单见 AttachmentLogin::$except）。
 *
 * 本类仅为模块分发适配层，业务实现在 app\common\controller\Attachment。
 */
class Attachment extends CommonAttachment
{
    /**
     * 控制器级中间件（think Url 控制器分发会自动执行）
     *
     * @var array
     */
    protected $middleware = [
        AttachmentLogin::class,
    ];
}

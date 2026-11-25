<?php
return [
    \think\middleware\LoadLangPack::class,

    \wise\login\middleware\IpGuardMiddleware::class,

    \app\admin\middleware\RememberLogin::class,

    \wise\auth\middleware\RbacMiddleware::class,

    \wise\login\middleware\TwoFactorMiddleware::class,

    \app\common\middleware\XssFilterMiddleware::class,

    \wise\operationlog\middleware\AuditLog::class,

    \app\common\middleware\VerifyCsrfToken::class,

    \wise\operationlog\middleware\OperationLog::class,

    \app\admin\middleware\MenuStaleSync::class,
];

<?php
return [
    \think\middleware\LoadLangPack::class,

    \wise\api\middleware\CompressionMiddleware::class,

    [\wise\api\middleware\BodySizeLimitMiddleware::class, [10485760]],

    \wise\api\middleware\TraceIdMiddleware::class,

    \wise\api\middleware\ProtocolVersionMiddleware::class,

    [\wise\api\middleware\RateLimitMiddleware::class, [60, 1]],

    \wise\api\middleware\SignVerifyMiddleware::class,

    \wise\api\middleware\ApiLogMiddleware::class,

    \wise\api\middleware\IpWhitelistMiddleware::class,

    \wise\api\middleware\DeprecationMiddleware::class,
];

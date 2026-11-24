<?php

use app\AppService;

return [
    AppService::class,
    \app\common\service\LangPackService::class,
    \app\service\ExtendLoaderService::class,
    \app\admin\Service::class,
    \app\install\Service::class,

];

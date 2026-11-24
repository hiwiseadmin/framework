<?php
return [
    \app\middleware\RequestId::class,
    \app\middleware\SecurityHeaders::class,
    \app\middleware\InitCookieSecure::class,
    think\middleware\AllowCrossDomain::class,
    
    \think\middleware\LoadLangPack::class,
    \think\middleware\SessionInit::class,
    \app\middleware\Environment::class,
    \wise\plugin\middleware\PluginLoader::class,
    \app\middleware\EntryVerify::class,
    
];

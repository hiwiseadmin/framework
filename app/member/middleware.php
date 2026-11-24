<?php
return [
    \think\middleware\LoadLangPack::class,

    ...(class_exists(\plugin\member\middleware\MemberContext::class)
        ? [\plugin\member\middleware\MemberContext::class]
        : []),
];

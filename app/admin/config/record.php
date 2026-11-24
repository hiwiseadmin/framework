<?php

return [
    // 允许记录的请求方法
    'allowedMethods'        => ['GET', 'POST', 'PUT', 'DELETE'],
    // 排除的控制器, 这些控制器的操作将不会被记录，首字母大写驼峰命名
    'excludeControllers'    => ['Dashboard', 'LoginLog', 'OperationLog', 'ApiLog'],
    // 排除的操作
    'excludeActions'        => ['doLogin', 'logout'],
    // 登录日志
    'loginLog'              => true,
    // 操作日志
    'operationLog'          => true,
    // API日志
    'apiLog'                => true,
];

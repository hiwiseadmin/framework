<?php
// +----------------------------------------------------------------------
// | 导航栏设置
// +----------------------------------------------------------------------

return [
    'minibar' => [
        'show_title' => false,   // 是否显示文字标题
        'list' => [
            'lockscreen' => [
                'key' => 'lockscreen',
                'title' => '首页',
                'url' => '/admin/index/index',
                'icon' => 'bi bi-house-door-fill',
            ],
        ],
    ],
];

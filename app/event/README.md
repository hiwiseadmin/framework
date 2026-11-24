# 事件 (Events)

本文件夹用于存放系统事件类。事件是一种解耦机制，允许在其他组件中通过“发布-订阅”模式进行通信。当某个业务动作发生时（如用户注册、订单创建），可以触发一个事件，其他组件（监听器）可以响应并执行相应逻辑，而无需直接耦合。

## 目录结构
event/  
├── UserLogin.php # 用户登录事件  
└── ... # 其他自定义事件  


## 如何接收/监听/订阅事件通知

在插件根目录下的 `listener.php` 文件中，增加事件监听定义。

**示例：`listener.php`**
```php
<?php

return [
    // 事件绑定
    'bind' => [
        // 'UserLogin' => '\app\event\UserLogin',
    ],
    // 事件监听
    'listen' => [
        // 'UserLogin' => 'app\listener\UserLogin',
    ],
    // 事件订阅
    'subscribe' => [
    //    'plugin\demo\event\DemoEvent',
    ],
];
```
将事件监听文件建议统一放在插件根目录的 `listener` 文件夹下，增加事件监听类。
**示例：`listener/UserLogin.php`**
```php
<?php
namespace plugin\demo\listener;

class UserLogin
{
    /**
     * @var \app\model\User $user 注册的用户对象
     */
    public $user;

    /**
     * @var string $ip 注册IP
     */
    public $ip;

    public function __construct($user, string $ip)
    {
        $this->user = $user;
        $this->ip   = $ip;
    }
}

或者采用事件
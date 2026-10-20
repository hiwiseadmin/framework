<?php
// demo 插件 — 子服务提供者注册（boot 链 Step 11）
//
// 返回服务提供者类名数组，框架逐个执行 register() + bootService()；
// 也可返回闭包（签名为 function (\think\App $app): void）。
return [
    // \plugin\demo\Service\ExampleServiceProvider::class,
];

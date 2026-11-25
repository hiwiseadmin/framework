<?php
/* +----------------------------------------------------------------------
 * | 控制台配置
 * +----------------------------------------------------------------------
 *
 */
return [
    'commands' => [
        // 宿主业务命令放这里（app/command/）。
        //
        // 框架级命令（async-task / api:perm-check 等）不在此注册：
        // 统一放 extend/wise/command/，命名空间 wise\command，
        // 由 extend/wise/extend.json 的 commands 节点声明，ExtendLoaderService 负责注册。
    ],
];

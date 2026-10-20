<?php
/**
 * 水管工插件 — wise-game-stage 注册
 *
 * 注册 Provider 和 Validator 实现类。
 * GameStageServiceProvider::boot() 会用 is_subclass_of() 校验。
 */
return [
    'providers' => [
        'pipe_game' => \plugin\pipe_game\provider\PipeStageProvider::class,
    ],
    'validators' => [
        'pipe_game' => \plugin\pipe_game\validator\PipeValidator::class,
    ],
];

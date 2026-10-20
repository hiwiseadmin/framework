<?php
/**
 * 消消乐插件 — wise-game-stage 注册
 */
return [
    'providers' => [
        'match3game' => \plugin\match3game\provider\Match3StageProvider::class,
    ],
    'validators' => [
        'match3game' => \plugin\match3game\validator\Match3Validator::class,
    ],
];

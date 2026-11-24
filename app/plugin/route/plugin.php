<?php
use think\facade\Route;

Route::any('[:plugin]/[:action]', \app\plugin\dispatch\PluginDispatch::class)
    ->pattern(['plugin' => '[\w]+', 'action' => '[\w_]+']);

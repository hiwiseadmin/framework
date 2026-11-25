<?php

use think\facade\Route;


Route::post('login/doLogin', 'Login/doLogin')->middleware([
    \wise\login\middleware\CsrfTokenMiddleware::class,
    \wise\login\middleware\LoginGuardMiddleware::class,
    \wise\login\middleware\LoginResultMiddleware::class,
]);


Route::post('wise_config/renameGroup', 'WiseConfigGroup/renameGroup');
Route::post('wise_config/deleteGroup', 'WiseConfigGroup/deleteGroup');
Route::get('wise_config/availableGroups', 'WiseConfigGroup/availableGroups');

Route::get('wise_config/history', 'WiseConfigHistory/history');
Route::post('wise_config/historyRevert', 'WiseConfigHistory/historyRevert');

Route::get('wise_config/export', 'WiseConfigImport/export');
Route::post('wise_config/copyToNamespace', 'WiseConfigImport/copyToNamespace');
Route::post('wise_config/importConfig', 'WiseConfigImport/importConfig');
Route::post('wise_config/uploadImage', 'WiseConfigImport/uploadImage');

Route::get('plugin/configIndex/name/:name', 'Plugin/configIndex')
    ->pattern(['name' => '[A-Za-z0-9_\-.]+'])
    ->name('plugin_config_index');

Route::post('idler/clearCache', 'Idler/clearCache')->name('admin_cache_clear');

Route::get('icons/download_svg', 'Icons/downloadSvg')->name('icons_download_svg');


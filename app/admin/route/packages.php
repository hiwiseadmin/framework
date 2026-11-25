<?php

use think\facade\Route;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

Route::group(function () {
    RouteRegistrar::applyRoutes(ExtPoints::ROUTE_ADMIN);
});

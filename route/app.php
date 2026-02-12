<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\facade\Route;
// ===== 登录相关（不需要验证）=====
Route::group('admin', function () {
    Route::get('login', 'admin.Login/index');
    Route::post('login/login', 'admin.Login/login');
    Route::get('logout', 'admin.Login/logout');
})->allowCrossDomain();

// ==================== 管理端路由组 ====================
Route::group('admin', function () {
    // 默认首页
    Route::get('/', 'admin.Index/index');

    // 万能路由：先匹配带 action 和参数的，再匹配带 action 的，最后匹配只有 controller 的
    Route::rule(':controller/:action/:id', 'admin.:controller/:action');
    Route::rule(':controller/:action', 'admin.:controller/:action');
    Route::rule(':controller', 'admin.:controller/index');
})->middleware(['AdminAuth']);

// ==================== 供应商端路由组 ====================
Route::group('supplier', function () {
    // 默认首页
    Route::get('/', 'supplier.Index/index');

    // 万能路由：先匹配带 action 的，再匹配只有 controller 的
    Route::rule(':controller/:action', 'supplier.:controller/:action');
    Route::rule(':controller', 'supplier.:controller/index');
})->middleware(['SupplierAuth']);

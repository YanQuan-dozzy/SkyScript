<?php
// +----------------------------------------------------------------------
// | 路由设置
// +----------------------------------------------------------------------
use think\facade\Route;

// 前台
Route::get('/', 'index/index');
Route::get('/index$', 'index/index');
Route::get('/index/about$', 'index/about');

// 转换 API
Route::post('convert/single',  'convert/single');
Route::post('convert/batch',   'convert/batch');
Route::post('convert/folder',  'convert/folder');
Route::get('convert/progress', 'convert/progress');
Route::get('convert/download', 'convert/download');

// 管理员
Route::get('admin/login',    '\\app\\controller\\AdminController/login');
Route::post('admin/doLogin', '\\app\\controller\\AdminController/doLogin');
Route::get('admin/logout',   '\\app\\controller\\AdminController/logout');
Route::get('admin/index',    '\\app\\controller\\AdminController/index');
Route::post('admin/cleanup', '\\app\\controller\\AdminController/cleanup');

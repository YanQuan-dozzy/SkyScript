<?php
// [ 根目录入口转发 ]
// 当 DocumentRoot 指向 www.AuToJs.com（而非 public/）时，
// 本文件作为入口把请求转交给 public/index.php

// 保留原始 URL 供 ThinkPHP 解析
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['PHP_SELF'] = '/index.php' . (isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '');

chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';

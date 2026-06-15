<?php
// 中间件配置
return [
    // 别名
    'alias'    => [
        'rateLimit' => \app\middleware\IpRateLimit::class,
        'adminAuth' => \app\middleware\AdminAuth::class,
    ],
    // 优先级
    'priority' => [],
];

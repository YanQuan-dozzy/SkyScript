<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用地址
    'app_host'         => env('app.host', ''),
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,

    // ============== 项目自定义配置 ==============

    // 上传 token 密钥（用于 download 链接验证）
    'upload_token_key' => 'au2js_2024_secret_key',

    // 管理员密码盐
    'salt'             => 'au2js_2024_salt',

    // 临时文件保留时长（秒），默认 72 小时
    'temp_keep_seconds' => 72 * 3600,

    // 单次批量上限
    'max_batch_files'   => 200,
    'max_zip_size'      => 50 * 1024 * 1024,
    'max_zip_extract'   => 200 * 1024 * 1024,

    // IP 限流（每窗口秒数最大请求数）
    'rate_limit_max'    => 30,
    'rate_limit_window' => 60,
];

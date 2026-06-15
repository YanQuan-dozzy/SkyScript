<?php
declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * 管理员登录验证器
 */
class AdminLoginValidate extends Validate
{
    protected $rule = [
        'username' => 'require|alphaNum|length:3,32',
        'password' => 'require|length:4,64',
        'captcha'  => 'length:0,10',
    ];

    protected $message = [
        'username.require'  => '请输入用户名',
        'username.alphaNum' => '用户名只能是字母和数字',
        'username.length'   => '用户名长度 3-32',
        'password.require'  => '请输入密码',
        'password.length'   => '密码长度 4-64',
    ];
}

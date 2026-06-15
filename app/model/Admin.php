<?php
declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 管理员模型
 * 对应 MySQL 表: atj_admin
 */
class Admin extends Model
{
    use SoftDelete;

    protected $name = 'admin';
    protected $table = 'atj_admin';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';

    /**
     * 密码加密
     */
    public static function encryptPassword(string $pwd): string
    {
        $salt = config('app.salt', 'au2js_salt_2024');
        return md5($salt . $pwd);
    }

    /**
     * 登录验证
     */
    public static function login(string $username, string $password): ?array
    {
        $admin = self::where('username', $username)->find();
        if (!$admin) {
            return null;
        }
        if ($admin->password !== self::encryptPassword($password)) {
            return null;
        }
        if ((int)$admin->status !== 1) {
            return null;
        }

        // 记录最后登录时间 / IP
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->last_login_ip   = request()->ip();
        $admin->save();

        return $admin->toArray();
    }
}

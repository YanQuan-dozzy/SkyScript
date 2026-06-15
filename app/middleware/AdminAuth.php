<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Session;

/**
 * 管理员登录态校验
 */
class AdminAuth
{
    public function handle($request, \Closure $next)
    {
        if (!Session::has('admin')) {
            if ($request->isAjax()) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }
            return redirect((string)url('admin/login'));
        }
        return $next($request);
    }
}

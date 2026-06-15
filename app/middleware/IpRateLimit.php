<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Cache;
use think\Response;

/**
 * IP 限流中间件
 *
 * 默认规则：同一 IP 每 60 秒最多 30 次请求（可由 env / config 调整）
 */
class IpRateLimit
{
    public function handle($request, \Closure $next, int $maxRequests = 30, int $window = 60)
    {
        // 已登录管理员不限流
        if (session('?admin')) {
            return $next($request);
        }

        $ip = $request->ip();
        $key = 'rate_limit:' . md5($ip);

        $count = (int)Cache::get($key, 0);
        if ($count >= $maxRequests) {
            return Response::create([
                'code' => 429,
                'msg'  => '请求过于频繁，请稍后再试',
            ], 'json', 429);
        }

        if ($count === 0) {
            Cache::set($key, 1, $window);
        } else {
            Cache::inc($key, 1);
        }

        return $next($request);
    }
}

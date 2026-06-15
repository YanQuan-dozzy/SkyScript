<?php
declare (strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

/**
 * 首页控制器 - 简洁的蓝白风格首页
 */
class Index extends BaseController
{
    public function index()
    {
        $stats = [
            'today'  => 0,
            'total'  => 0,
        ];
        try {
            $logModel = new \app\model\ConversionLog();
            $stats['today'] = $logModel->whereTime('create_time', 'today')->count();
            $stats['total'] = $logModel->count();
        } catch (\Throwable $e) {
            // 数据库未就绪不阻塞首页
        }
        View::assign('stats', $stats);
        return View::fetch('index');
    }

    public function about()
    {
        return View::fetch('about');
    }
}

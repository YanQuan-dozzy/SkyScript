<?php
declare (strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Admin;
use app\model\ConversionLog;
use app\validate\AdminLoginValidate;
use think\exception\ValidateException;
use think\facade\Session;
use think\facade\View;

/**
 * 管理员后台
 */
class AdminController extends BaseController
{
    public function login()
    {
        if (Session::has('admin')) {
            return redirect((string)url('admin/index'));
        }
        return View::fetch('admin/login');
    }

    public function doLogin()
    {
        $data = $this->request->post();
        try {
            $data = (new AdminLoginValidate())->failException(true)->check($data);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        if (!is_array($data)) {
            $data = $this->request->post();
        }

        $admin = Admin::login($data['username'] ?? '', $data['password'] ?? '');
        if (!$admin) {
            return json(['code' => 401, 'msg' => '用户名或密码错误']);
        }

        Session::set('admin', $admin);
        return json(['code' => 0, 'msg' => '登录成功', 'data' => ['url' => (string)url('admin/index')]]);
    }

    public function logout()
    {
        Session::delete('admin');
        return redirect((string)url('admin/login'));
    }

    public function index()
    {
        $page   = max(1, (int)$this->request->get('page', 1));
        $limit  = 20;
        $status = $this->request->get('status', '');

        $query = ConversionLog::order('id', 'desc');
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($kw = trim((string)$this->request->get('kw', ''))) {
            $query->where('src_name|out_name|user_ip', 'like', "%{$kw}%");
        }
        $list  = $query->paginate(['list_rows' => $limit, 'page' => $page], false);
        $stats = [
            'today'  => ConversionLog::whereTime('create_time', 'today')->count(),
            'total'  => ConversionLog::count(),
            'fail'   => ConversionLog::where('status', 0)->count(),
            'ok'     => ConversionLog::where('status', 1)->count(),
        ];
        View::assign([
            'list'  => $list,
            'stats' => $stats,
            'kw'    => $this->request->get('kw', ''),
            'status'=> $this->request->get('status', ''),
        ]);
        return View::fetch('admin/index');
    }

    public function cleanup()
    {
        // 手动触发清理（也可由定时任务）
        $dirs = [
            app()->getRuntimePath() . 'convert' . DIRECTORY_SEPARATOR . 'upload',
            app()->getRuntimePath() . 'convert' . DIRECTORY_SEPARATOR . 'output',
        ];
        $expired = 72 * 3600;
        $deleted = 0;
        $now = time();
        foreach ($dirs as $root) {
            if (!is_dir($root)) continue;
            $items = scandir($root);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $p = $root . DIRECTORY_SEPARATOR . $item;
                if (is_dir($p) && ($now - filemtime($p)) > $expired) {
                    $this->rmdir($p);
                    $deleted++;
                }
            }
        }
        return json(['code' => 0, 'msg' => "已清理 $deleted 个过期目录"]);
    }

    private function rmdir(string $d): void
    {
        if (!is_dir($d)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($d);
    }
}

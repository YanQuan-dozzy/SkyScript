<?php
declare (strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\TxtToJsService;
use app\model\ConversionLog;
use app\validate\UploadValidate;
use think\exception\ValidateException;
use think\facade\Filesystem;
use think\response\Json;

/**
 * 转换控制器
 *  - 单文件上传：POST /convert/single
 *  - 批量 ZIP：  POST /convert/batch
 *  - 进度查询： GET  /convert/progress?batch_id=xxx
 *  - 下载生成： GET  /convert/download/:batch_id
 */
class Convert extends BaseController
{
    /**
     * 存储配置键
     */
    private const STORAGE_DISK = 'convert';

    /**
     * 单文件上传并转换
     *  支持 .txt（C++ 解析）或 .js（模板互转）
     */
    public function single(): Json
    {
        $file = $this->request->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => '请上传文件']);
        }
        $validate = new UploadValidate();
        try {
            $validate->failException(true)->check(['file' => $file]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }

        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['txt', 'js'], true)) {
            return json(['code' => 400, 'msg' => '单文件模式只支持 .txt / .js']);
        }

        $template   = $this->resolveTemplate();
        $srcTemplate = $this->resolveSrcTemplate();
        $saveMode   = $this->resolveSaveMode();

        $batchId = 'B' . date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
        $dstDir  = $this->storagePath('upload') . $batchId;
        $outDir  = $this->storagePath('output') . $batchId;
        $this->ensureDir($dstDir);
        $this->ensureDir($outDir);

        $randName  = $this->randomName() . '.' . $ext;
        $savePath  = $dstDir . DIRECTORY_SEPARATOR . $randName;
        $file->move($dstDir, $randName);

        $srcAbs = $dstDir . DIRECTORY_SEPARATOR . $randName;
        $bytes  = file_get_contents($srcAbs);

        $started = microtime(true);
        $service = new TxtToJsService();
        $ok      = $service->convertWithTemplate($bytes, $file->getOriginalName(), $template, $srcTemplate);
        $cost    = (int)((microtime(true) - $started) * 1000);

        $srcName = $file->getOriginalName();
        $outName = pathinfo($srcName, PATHINFO_FILENAME) . '.js';
        $logData = [
            'user_ip'    => $this->request->ip(),
            'src_name'   => $srcName,
            'src_size'   => filesize($srcAbs),
            'out_name'   => $outName,
            'batch_id'   => $batchId,
            'mode'       => 'single',
            'error_msg'  => $service->getError(),
            'note_count' => 0,
            'bpm'        => $service->getNoteTime() > 0 ? (int)round(60000 / $service->getNoteTime()) : 0,
            'is_json'    => $service->isJsonScore() ? 1 : 0,
            'cost_ms'    => $cost,
        ];

        if (!$ok) {
            ConversionLog::logFailure($logData);
            return json(['code' => 500, 'msg' => $service->getError() ?: '转换失败']);
        }

        $outAbs = $outDir . DIRECTORY_SEPARATOR . $outName;
        file_put_contents($outAbs, $service->getOutput());
        $logData['out_size'] = filesize($outAbs);

        $token = $this->makeDownloadToken($batchId, $outName);
        ConversionLog::logSuccess($logData);

        // 镜像到可选路径（Web 可访问 / 本地文件夹）
        $mirrors = $this->mirrorFiles([['abs' => $outAbs, 'name' => $outName, 'batch' => $batchId]], $saveMode);

        return json([
            'code'       => 0,
            'msg'        => '转换成功',
            'data'       => [
                'batch_id'    => $batchId,
                'src_name'    => $srcName,
                'src_type'    => $ext,
                'out_name'    => $outName,
                'out_size'    => $logData['out_size'],
                'out_bytes'   => strlen($service->getOutput()),
                'preview'     => mb_substr($service->getOutput(), 0, 1200),
                'preview_full'=> $service->getOutput(),
                'download'    => (string)url('convert/download', ['batch_id' => $batchId, 'name' => $outName, 'token' => $token]),
                'cost_ms'     => $cost,
                'is_json'     => $service->isJsonScore(),
                'bpm'         => $logData['bpm'],
                'template'    => $template,
                'src_template'=> $srcTemplate,
                'save_mode'   => $saveMode,
                'web_files'   => $mirrors['web_files'],
                'local_files' => $mirrors['local_files'],
            ],
        ]);
    }

    /**
     * 批量 ZIP 转换
     */
    public function batch(): Json
    {
        $file = $this->request->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => '请上传文件']);
        }
        try {
            (new UploadValidate())->failException(true)->check(['file' => $file]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }

        $ext = strtolower($file->getOriginalExtension());
        if ($ext !== 'zip') {
            return json(['code' => 400, 'msg' => '批量模式只支持 .zip']);
        }

        $batchId = 'Z' . date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
        $uploadDir  = $this->storagePath('upload') . $batchId;
        $extractDir = $this->storagePath('upload') . $batchId . '_extract';
        $outDir     = $this->storagePath('output') . $batchId;
        $this->ensureDir($uploadDir);
        $this->ensureDir($extractDir);
        $this->ensureDir($outDir);

        $zipName = $this->randomName() . '.zip';
        $file->move($uploadDir, $zipName);
        $zipPath = $uploadDir . DIRECTORY_SEPARATOR . $zipName;

        // 防炸弹检查
        $bomb = $this->checkZipBomb($zipPath);
        if ($bomb !== true) {
            $this->rmdir($extractDir);
            $this->rmdir($uploadDir);
            return json(['code' => 400, 'msg' => 'Zip 防炸弹检测失败：' . $bomb]);
        }

        // 解压
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return json(['code' => 400, 'msg' => '无法打开 zip 文件']);
        }
        $zip->extractTo($extractDir);
        $zip->close();

        // 收集所有 txt / js 源文件
        $sources = $this->scanSources($extractDir);
        if (empty($sources)) {
            return json(['code' => 400, 'msg' => '压缩包内没有 .txt / .js 文件']);
        }
        if (count($sources) > 200) {
            return json(['code' => 400, 'msg' => '单次最多 200 个文件']);
        }

        $template    = $this->resolveTemplate();
        $srcTemplate = $this->resolveSrcTemplate();
        $saveMode    = $this->resolveSaveMode();

        // 进度初始化
        $this->setProgress($batchId, 0, count($sources), 'starting');

        // 转换每一个
        $result   = [
            'success' => [],
            'failed'  => [],
        ];
        $successFiles = [];
        $service  = new TxtToJsService();
        $i        = 0;
        $started  = microtime(true);

        foreach ($sources as $src) {
            $i++;
            $this->setProgress($batchId, $i, count($sources), 'processing');

            $bytes   = @file_get_contents($src);
            $relName = basename($src);
            $ext     = strtolower(pathinfo($relName, PATHINFO_EXTENSION));
            $outName = pathinfo($relName, PATHINFO_FILENAME) . '.js';

            $logData = [
                'user_ip'    => $this->request->ip(),
                'src_name'   => $relName,
                'src_size'   => filesize($src),
                'out_name'   => $outName,
                'batch_id'   => $batchId,
                'mode'       => 'batch',
                'error_msg'  => '',
                'bpm'        => 0,
                'is_json'    => 0,
                'cost_ms'    => 0,
            ];

            $t0 = microtime(true);
            $ok = $bytes !== false && $service->convertWithTemplate($bytes, $relName, $template, $ext === 'js' ? $srcTemplate : null);
            $logData['cost_ms'] = (int)((microtime(true) - $t0) * 1000);

            if ($ok) {
                $outPath = $outDir . DIRECTORY_SEPARATOR . $outName;
                file_put_contents($outPath, $service->getOutput());
                $logData['out_size']   = filesize($outPath);
                $logData['bpm']        = $service->getNoteTime() > 0 ? (int)round(60000 / $service->getNoteTime()) : 0;
                $logData['is_json']    = $service->isJsonScore() ? 1 : 0;
                ConversionLog::logSuccess($logData);
                $result['success'][] = ['src' => $relName, 'out' => $outName];
                $successFiles[] = ['abs' => $outPath, 'name' => $outName, 'batch' => $batchId];
            } else {
                $logData['error_msg'] = $service->getError();
                ConversionLog::logFailure($logData);
                $result['failed'][]  = ['src' => $relName, 'err' => $service->getError()];
            }
        }

        // 打包 zip
        $zipOut = $outDir . '.zip';
        $this->createZip($outDir, $zipOut);
        $this->setProgress($batchId, $i, $i, 'done');

        $cost = (int)((microtime(true) - $started) * 1000);

        $token = $this->makeDownloadToken($batchId, basename($zipOut));

        $successFiles[] = ['abs' => $zipOut, 'name' => basename($zipOut), 'batch' => $batchId];
        $mirrors = $this->mirrorFiles($successFiles, $saveMode);

        return json([
            'code'    => 0,
            'msg'     => '批量转换完成',
            'data'    => [
                'batch_id'    => $batchId,
                'total'       => count($sources),
                'success'     => count($result['success']),
                'failed'      => count($result['failed']),
                'cost_ms'     => $cost,
                'files'       => $result,
                'download'    => (string)url('convert/download', ['batch_id' => $batchId, 'name' => basename($zipOut), 'token' => $token]),
                'template'    => $template,
                'src_template'=> $srcTemplate,
                'save_mode'   => $saveMode,
                'web_files'   => $mirrors['web_files'],
                'local_files' => $mirrors['local_files'],
            ],
        ]);
    }

    /**
     * 文件夹批量转换：用户在前端提交一个绝对路径，后端扫描并转换
     *  POST /convert/folder
     *  入参: folder=绝对路径, template=press/press_new/long_press, src_template=..., save_mode=web_access,local_folder
     */
    public function folder(): Json
    {
        $folder = trim((string)$this->request->post('folder', ''));
        if ($folder === '') {
            return json(['code' => 400, 'msg' => '请提供服务器侧文件夹绝对路径']);
        }
        // Windows 路径分隔符标准化
        $folder = rtrim($folder, "/\\");
        if (!is_dir($folder)) {
            return json(['code' => 400, 'msg' => '文件夹不存在或不可访问：' . $folder]);
        }
        // 安全：禁止访问关键系统目录
        $folderLower = strtolower(str_replace('\\', '/', $folder));
        $forbidden = ['c:/windows', 'c:/program files', 'c:/programdata', '/etc', '/var', '/usr', '/root', '/proc', '/sys'];
        foreach ($forbidden as $deny) {
            if (strpos($folderLower, $deny) === 0) {
                return json(['code' => 400, 'msg' => '禁止访问此目录：' . $folder]);
            }
        }

        $sources = $this->scanSources($folder);
        if (empty($sources)) {
            return json(['code' => 400, 'msg' => '文件夹内没有 .txt / .js 文件']);
        }
        if (count($sources) > 500) {
            return json(['code' => 400, 'msg' => '单次最多 500 个文件']);
        }

        $batchId     = 'F' . date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
        $template    = $this->resolveTemplate();
        $srcTemplate = $this->resolveSrcTemplate();
        $saveMode    = $this->resolveSaveMode();
        $outDir      = $this->storagePath('output') . $batchId;
        $this->ensureDir($outDir);

        $this->setProgress($batchId, 0, count($sources), 'starting');

        $result = ['success' => [], 'failed' => []];
        $successFiles = [];
        $service = new TxtToJsService();
        $started = microtime(true);
        $i = 0;

        foreach ($sources as $src) {
            $i++;
            $this->setProgress($batchId, $i, count($sources), 'processing');

            $bytes   = @file_get_contents($src);
            $relName = basename($src);
            $ext     = strtolower(pathinfo($relName, PATHINFO_EXTENSION));
            $outName = pathinfo($relName, PATHINFO_FILENAME) . '.js';

            $logData = [
                'user_ip'    => $this->request->ip(),
                'src_name'   => $relName,
                'src_size'   => filesize($src),
                'out_name'   => $outName,
                'batch_id'   => $batchId,
                'mode'       => 'folder',
                'error_msg'  => '',
                'bpm'        => 0,
                'is_json'    => 0,
                'cost_ms'    => 0,
            ];

            $t0 = microtime(true);
            $ok = $bytes !== false && $service->convertWithTemplate($bytes, $relName, $template, $ext === 'js' ? $srcTemplate : null);
            $logData['cost_ms'] = (int)((microtime(true) - $t0) * 1000);

            if ($ok) {
                $outPath = $outDir . DIRECTORY_SEPARATOR . $outName;
                file_put_contents($outPath, $service->getOutput());
                $logData['out_size'] = filesize($outPath);
                $logData['bpm']      = $service->getNoteTime() > 0 ? (int)round(60000 / $service->getNoteTime()) : 0;
                $logData['is_json']  = $service->isJsonScore() ? 1 : 0;
                ConversionLog::logSuccess($logData);
                $result['success'][] = ['src' => $relName, 'out' => $outName];
                $successFiles[] = ['abs' => $outPath, 'name' => $outName, 'batch' => $batchId];
            } else {
                $logData['error_msg'] = $service->getError();
                ConversionLog::logFailure($logData);
                $result['failed'][]  = ['src' => $relName, 'err' => $service->getError()];
            }
        }

        // 打包 zip 供用户下载
        $zipOut = $outDir . '.zip';
        $this->createZip($outDir, $zipOut);
        $this->setProgress($batchId, $i, $i, 'done');

        $cost = (int)((microtime(true) - $started) * 1000);
        $token = $this->makeDownloadToken($batchId, basename($zipOut));
        $successFiles[] = ['abs' => $zipOut, 'name' => basename($zipOut), 'batch' => $batchId];
        $mirrors = $this->mirrorFiles($successFiles, $saveMode);

        return json([
            'code'    => 0,
            'msg'     => '文件夹批量转换完成',
            'data'    => [
                'batch_id'     => $batchId,
                'folder'       => $folder,
                'total'        => count($sources),
                'success'      => count($result['success']),
                'failed'       => count($result['failed']),
                'cost_ms'      => $cost,
                'files'        => $result,
                'download'     => (string)url('convert/download', ['batch_id' => $batchId, 'name' => basename($zipOut), 'token' => $token]),
                'template'     => $template,
                'src_template' => $srcTemplate,
                'save_mode'    => $saveMode,
                'web_files'    => $mirrors['web_files'],
                'local_files'  => $mirrors['local_files'],
            ],
        ]);
    }

    /**
     * 解析 save_mode 参数（可多选：web_access / local_folder）
     *  返回值是数组；空数组表示仅走 download 接口
     */
    private function resolveSaveMode(): array
    {
        $post = (array)$this->request->post();
        $raw  = [];
        if (isset($post['save_mode'])) {
            $v = $post['save_mode'];
            if (is_array($v)) {
                $raw = $v;
            } else {
                $raw = [$v];
            }
        }
        // 也支持逗号分隔的字符串
        if (empty($raw) || (count($raw) === 1 && is_string($raw[0]) && strpos($raw[0], ',') !== false)) {
            $str = is_array($raw) ? ($raw[0] ?? '') : (string)$raw;
            $raw = preg_split('/[,\s]+/', $str, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $valid = ['web_access', 'local_folder'];
        $out = [];
        foreach ($raw as $p) {
            if (in_array($p, $valid, true) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * 把生成的文件镜像到 save_mode 指定的路径
     * @param array $files [['abs' => '...', 'name' => '...', 'batch' => '...'], ...]
     * @param array $saveMode
     * @return array ['web_files' => [...], 'local_files' => [...]]
     */
    private function mirrorFiles(array $files, array $saveMode): array
    {
        $webFiles   = [];
        $localFiles = [];
        if (empty($saveMode) || empty($files)) {
            return ['web_files' => $webFiles, 'local_files' => $localFiles];
        }
        $date = date('Y-m-d');
        $wantWeb   = in_array('web_access', $saveMode, true);
        $wantLocal = in_array('local_folder', $saveMode, true);
        foreach ($files as $f) {
            $src = $f['abs'] ?? '';
            $nm  = $f['name'] ?? '';
            $b   = $f['batch'] ?? '';
            if (!$src || !is_file($src) || $nm === '' || $b === '') {
                continue;
            }
            // 用 batch 子目录避免同日多次转换重名
            $sub = $b;
            if ($wantWeb) {
                $webDir = $this->webAccessDir() . $sub . DIRECTORY_SEPARATOR;
                $this->ensureDir($webDir);
                $webDst = $webDir . $nm;
                if (@copy($src, $webDst)) {
                    $webFiles[] = [
                        'name'      => $nm,
                        'path'      => $webDst,
                        'rel'       => 'downloads/' . $date . '/' . $sub . '/' . $nm,
                        'url'       => '/downloads/' . $date . '/' . $sub . '/' . rawurlencode($nm),
                        'size'      => filesize($webDst),
                    ];
                }
            }
            if ($wantLocal) {
                $localDir = $this->localFolderDir() . $sub . DIRECTORY_SEPARATOR;
                $this->ensureDir($localDir);
                $localDst = $localDir . $nm;
                if (@copy($src, $localDst)) {
                    $localFiles[] = [
                        'name' => $nm,
                        'path' => $localDst,
                        'size' => filesize($localDst),
                    ];
                }
            }
        }
        return ['web_files' => $webFiles, 'local_files' => $localFiles];
    }

    /**
     * Web 可访问下载根：项目根 downloads/{date}/
     *  （DocumentRoot = 项目根，所以 /downloads/... 是直接可访问的）
     *  .htaccess 排除 downloads/ 不被 rewrite 到 public/
     */
    private function webAccessDir(): string
    {
        $root = app()->getRootPath() . 'downloads' . DIRECTORY_SEPARATOR
              . date('Y-m-d') . DIRECTORY_SEPARATOR;
        return $root;
    }

    /**
     * 本地固定目录：项目根 converted/{date}/
     *  Apache 不会暴露此目录（.htaccess 已禁止）
     */
    private function localFolderDir(): string
    {
        $root = app()->getRootPath() . 'converted' . DIRECTORY_SEPARATOR
              . date('Y-m-d') . DIRECTORY_SEPARATOR;
        return $root;
    }

    /**
     * 解析 template 参数（press / press_new / long_press），非法值返回 'press'
     */
    private function resolveTemplate(): string
    {
        $tpl = (string)$this->request->param('template', 'press');
        if (!in_array($tpl, TxtToJsService::TEMPLATES, true)) {
            $tpl = 'press';
        }
        return $tpl;
    }

    /**
     * 解析 src_template（仅 .js 源需要，press/press_new/long_press）
     *  默认 'press'
     */
    private function resolveSrcTemplate(): string
    {
        $tpl = (string)$this->request->param('src_template', 'press');
        if (!in_array($tpl, TxtToJsService::TEMPLATES, true)) {
            $tpl = 'press';
        }
        return $tpl;
    }

    /**
     * 批量转换进度（基于 cache + batch_id）
     */
    public function progress(): Json
    {
        $batchId = $this->request->get('batch_id', '');
        if (!$batchId) {
            return json(['code' => 400, 'msg' => 'batch_id required']);
        }
        $info = $this->getProgress($batchId);
        return json(['code' => 0, 'data' => $info ?: ['current' => 0, 'total' => 0, 'status' => 'unknown']]);
    }

    /**
     * 下载文件（token 校验）
     */
    public function download()
    {
        $batchId = $this->request->get('batch_id', '');
        $name    = basename($this->request->get('name', ''));
        $token   = $this->request->get('token', '');

        if (!$batchId || !$name || !$this->checkDownloadToken($token, $batchId, $name)) {
            return json(['code' => 403, 'msg' => 'token 无效']);
        }

        $base = $this->storagePath('output') . $batchId;
        $path = $base . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            $alt = $base . '.zip';
            if ($name === basename($alt) && is_file($alt)) {
                $path = $alt;
            } else {
                return json(['code' => 404, 'msg' => '文件不存在或已被清理']);
            }
        }

        return download($path, $name);
    }

    /* ---------------- 工具方法 ---------------- */

    private function storagePath(string $sub): string
    {
        $root = app()->getRuntimePath() . 'convert' . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }
        return $root;
    }

    private function ensureDir(string $d): void
    {
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
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

    private function randomName(): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    }

    private function makeDownloadToken(string $batchId, string $name): string
    {
        $secret = config('app.upload_token_key', 'au2js_2024_secret');
        return substr(md5($batchId . '|' . $name . '|' . $secret), 0, 16);
    }

    private function checkDownloadToken(string $token, string $batchId, string $name): bool
    {
        return hash_equals($this->makeDownloadToken($batchId, $name), $token);
    }

    /**
     * 防炸弹：限制压缩比 + 解压后总大小
     */
    private function checkZipBomb(string $zipPath): bool|string
    {
        $size = filesize($zipPath);
        if ($size > 50 * 1024 * 1024) {
            return 'zip 源文件超过 50MB';
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '无法打开 zip';
        }

        $total = 0;
        $ratio = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $size = (int)($stat['size'] ?? 0);
            $comp = (int)($stat['compressed_size'] ?? 0);
            $total += $size;
            if ($size > 0) {
                $ratio = max($ratio, $comp > 0 ? $size / max(1, $comp) : 1);
            }
        }
        $zip->close();

        if ($total > 200 * 1024 * 1024) {
            return '解压后总大小超过 200MB';
        }
        if ($ratio > 100) {
            return '压缩比异常（疑似 zip 炸弹）';
        }
        return true;
    }

    /**
     * 扫描目录下所有 .txt 与 .js 源文件
     * @return array<string> 绝对路径列表
     */
    private function scanSources(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if ($ext === 'txt' || $ext === 'js') {
                $out[] = $f->getRealPath();
            }
        }
        sort($out); // 保证顺序稳定
        return $out;
    }

    private function createZip(string $srcDir, string $outZip): void
    {
        $zip = new \ZipArchive();
        $zip->open($outZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getRealPath(), substr($f->getRealPath(), strlen($srcDir) + 1));
            }
        }
        $zip->close();
    }

    private function setProgress(string $batchId, int $current, int $total, string $status): void
    {
        \think\facade\Cache::set('progress:' . $batchId, [
            'current' => $current,
            'total'   => $total,
            'status'  => $status,
            'time'    => time(),
        ], 3600);
    }

    private function getProgress(string $batchId): ?array
    {
        return \think\facade\Cache::get('progress:' . $batchId);
    }
}

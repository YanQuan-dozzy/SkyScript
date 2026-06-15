<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Cache;

/**
 * 清理 72 小时之前的临时上传 / 输出文件
 *
 * 用法：
 *   php think cleanup --hours=72         (清理 72 小时之前的)
 *   php think cleanup --hours=24         (清理 24 小时之前的)
 *   php think cleanup --hours=72 --dry   (只统计，不实际删除)
 */
class Cleanup extends Command
{
    protected function configure(): void
    {
        $this->setName('cleanup')
            ->addOption('hours', null, Option::VALUE_OPTIONAL, '保留多少小时', '72')
            ->addOption('dry',   null, Option::VALUE_NONE,     '只统计不删除')
            ->setDescription('清理过期的临时上传与转换产物');
    }

    protected function execute(Input $input, Output $output): void
    {
        $hours   = (int)$input->getOption('hours');
        $dry     = (bool)$input->getOption('dry');
        $cutoff  = time() - $hours * 3600;

        $output->writeln("Cleanup started: cutoff = " . date('Y-m-d H:i:s', $cutoff) . " (hours={$hours}, dry=" . ($dry ? 'Y' : 'N') . ")");

        $base   = app()->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'convert' . DIRECTORY_SEPARATOR;
        $upload = $base . 'upload' . DIRECTORY_SEPARATOR;
        $output_dir = $base . 'output' . DIRECTORY_SEPARATOR;
        // 公开下载目录（项目根 downloads/）— 也按 72h 清理
        $webDownloads = app()->getRootPath() . 'downloads' . DIRECTORY_SEPARATOR;
        // 本地保存目录（converted/）— 不在自动清理范围内（用户主动产出，需手动管理）

        $deleted = 0;
        $size    = 0;
        foreach ([$upload, $output_dir, $webDownloads] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $p = $f->getRealPath();
                if ($f->isDir()) {
                    // 目录在所有子项删除后再尝试删除
                    if (count(scandir($p)) === 2) {
                        if (!$dry) @rmdir($p);
                    }
                    continue;
                }
                $mtime = $f->getMTime();
                if ($mtime < $cutoff) {
                    $size += $f->getSize();
                    if (!$dry) @unlink($p);
                    $deleted++;
                }
            }
        }

        // 清理 progress 缓存（>= 1 小时未活动的批量任务缓存可清掉）
        if (!$dry && method_exists(Cache::class, 'clear')) {
            // 简单实现：清空所有 batch:* 的key
            $cacheDir = app()->getRuntimePath() . 'cache';
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . DIRECTORY_SEPARATOR . 'batch_*') as $f) {
                    if (filemtime($f) < $cutoff) {
                        @unlink($f);
                    }
                }
            }
        }

        $output->writeln(sprintf("Done. files_deleted=%d, bytes_freed=%d", $deleted, $size));
    }
}

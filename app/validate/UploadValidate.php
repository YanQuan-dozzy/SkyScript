<?php
declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * 上传验证器
 */
class UploadValidate extends Validate
{
    protected $rule = [
        'file'        => 'require|file|fileExt:txt,zip,js|fileSize:10485760',
        'batch_id'    => 'alphaNum|max:32',
    ];

    protected $message = [
        'file.require'  => '请上传文件',
        'file.file'     => '文件无效',
        'file.fileExt'  => '只允许上传 .txt / .js / .zip 文件',
        'file.fileSize' => '文件大小超出限制（最大 10MB）',
        'batch_id'      => '批次号不合法',
    ];
}

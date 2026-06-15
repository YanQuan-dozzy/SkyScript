<?php
declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 转换日志模型
 * 对应 MySQL 表: atj_conversion_log
 */
class ConversionLog extends Model
{
    use SoftDelete;

    protected $name = 'conversion_log';
    protected $table = 'atj_conversion_log';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';

    // 字段类型
    protected $type = [
        'id'           => 'integer',
        'user_ip'      => 'string',
        'src_name'     => 'string',
        'src_size'     => 'integer',
        'out_size'     => 'integer',
        'out_name'     => 'string',
        'batch_id'     => 'string',
        'mode'         => 'string', // single | batch
        'status'       => 'integer', // 1 成功 0 失败
        'error_msg'    => 'string',
        'note_count'   => 'integer',
        'bpm'          => 'integer',
        'is_json'      => 'integer',
        'cost_ms'      => 'integer',
    ];

    /**
     * 写入一条成功记录
     */
    public static function logSuccess(array $data): int
    {
        $data['status']    = 1;
        $data['error_msg'] = '';
        return self::create($data)->id;
    }

    /**
     * 写入一条失败记录
     */
    public static function logFailure(array $data): int
    {
        $data['status'] = 0;
        return self::create($data)->id;
    }
}

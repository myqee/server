<?php
namespace MyQEE\Server;

/**
 * 穿梭服务的任务对象
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
class ShuttleJob
{
    /**
     * 任务ID
     *
     * @var int
     */
    public $id;

    /**
     * 输入内容
     *
     * @var string|mixed
     */
    public $data;

    /**
     * 输出内容
     *
     * @var mixed
     */
    public $result;

    /**
     * 协程ID
     *
     * @var int
     */
    public $coId;

    /**
     * 状态
     *
     * 具体值见 STATUS_* 相关常量
     *
     * @var bool
     */
    public $status = 0;

    /**
     * 错误对象
     *
     * @var \Exception|null
     */
    public $error;

    /**
     * 上下文对象
     *
     * @var \stdClass
     */
    public $context;

    /**
     * 释放对象时的回调函数
     *
     * @var callable|null
     */
    public $onRelease;

    const STATUS_WAITING = 0;   # 等待消费
    const STATUS_SUCCESS = 1;   # 执行成功
    const STATUS_CONSUME = 2;   # 已被消费
    const STATUS_RUNNING = 3;   # 运行中
    const STATUS_ERROR   = 4;   # 有错误
    const STATUS_EXPIRE  = 5;   # 过期
    const STATUS_CANCEL  = 6;   # 取消

    protected static $jobMaxId = 0;

    public function __construct()
    {
        $this->id      = self::$jobMaxId++;
        $this->context = new \stdClass();
    }

    public function __destruct()
    {
        # 清理数据
        if ($this->onRelease && is_callable($this->onRelease))
        {
            # 调用释放对象
            ($this->onRelease)();
        }
    }
}
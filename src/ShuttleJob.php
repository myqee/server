<?php
namespace MyQEE\Server;

/**
 * 穿梭服务的任务对象
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @subpackage Traits
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
class ShuttleJob
{
    /**
     * 输入内容
     *
     * @var string|mixed
     */
    public $input;

    /**
     * 输出内容
     *
     * @var mixed
     */
    public $output;

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
     * 错误序号
     *
     * @var int
     */
    public $errno = 0;

    /**
     * 错误内容
     *
     * @var string
     */
    public $error = '';

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

    const STATUS_WAITING = 0;   # 等待执行
    const STATUS_SUCCESS = 1;   # 执行成功
    const STATUS_RUNNING = 2;   # 运行中
    const STATUS_ERROR   = 3;   # 有错误
    const STATUS_EXPIRE  = 4;   # 过期
    const STATUS_CANCEL  = 5;   # 取消

    public function __construct()
    {
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
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
     * @var int|string
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

    /**
     * 协程切换的ID
     *
     * @var array|null
     */
    protected $coIds;

    const STATUS_WAITING = 0;   # 等待消费
    const STATUS_SUCCESS = 1;   # 执行成功
    const STATUS_ERROR   = 2;   # 有错误
    const STATUS_CONSUME = 3;   # 已被消费
    const STATUS_RUNNING = 4;   # 运行中
    const STATUS_EXPIRE  = 5;   # 过期
    const STATUS_CANCEL  = 6;   # 取消

    public function __construct($jobId = null)
    {
        $this->id      = $jobId;
        $this->context = new \stdClass();
        $this->status  = self::STATUS_WAITING;      # 状态
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

    /**
     * 进行协程切换等待数据返回
     *
     * @return mixed|false
     */
    public function yield()
    {
        if (ShuttleJob::STATUS_WAITING === $this->status || ShuttleJob::STATUS_RUNNING === $this->status)
        {
            # 数据插入成功，还没有被消费处理，协程挂载
            if (null === $this->coIds)
            {
                $this->coIds = [];
            }
            $this->coIds[] = \Swoole\Coroutine::getCid();

            # 协程切换
            \Swoole\Coroutine::yield();
        }

        $this->tryExit();

        return $this->result;
    }

    /**
     * 获取当前协程ID
     *
     * @return array|null
     */
    public function getCoIds()
    {
        return $this->coIds;
    }

    /**
     * 恢复协程
     */
    public function resume()
    {
        if (null !== $this->coIds)
        {
            $coIds       = $this->coIds;
            $this->coIds = null;
            foreach ($coIds as $cid)
            {
                \Swoole\Coroutine::resume($cid);
            }
        }
    }

    /**
     * 抛出结束
     */
    protected function tryExit()
    {
        if ($this->error && $this->error instanceof \Swoole\ExitException)
        {
            # 将结束的信号抛出
            throw $this->error;
        }
    }
}
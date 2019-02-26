<?php
namespace MyQEE\Server;

use \Swoole\Coroutine as Co;

/**
 * 穿梭服务
 *
 * 提高对复杂数据流处理业务的编程体验，降低编程人员对业务处理程序理解难度
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @subpackage Traits
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
class Shuttle
{
    /**
     * 最后的错误
     *
     * @var string
     */
    public $lastError;

    /**
     * 最后的错误号
     *
     * @var int
     */
    public $lastErrNo;

    /**
     * 输入协程调用方法
     *
     * @var callable
     */
    protected $input;

    /**
     * 输出协程调用方法
     *
     * @var callable
     */
    protected $output;

    /**
     * 处理列队
     *
     * @var Co\Channel
     */
    protected $queue;

    protected $queueSize;

    /**
     * 任务处理对象
     * @var \stdClass
     */
    protected $task;

    /**
     * 是否开启状态
     *
     * @var bool
     */
    protected $isOpen = false;

    /**
     * Shuttle constructor.
     *
     * @param callable|null $input 输入协程调用方法，在入队列前处理数据
     * @param callable $output 输出协程调用方法，在队列消费时处理数据
     * @param int $queueSize 列队数
     */
    public function __construct($input, callable $output, $queueSize = 100)
    {
        $this->input     = $input && is_callable($input) ? $input : null;
        $this->output    = $output;
        $this->queueSize = $queueSize;
    }

    public function __destruct()
    {
        if ($this->queue)
        {
            $this->stop(true);
        }
    }

    /**
     * 获得一个Shuttle服务
     *
     * @param callable|null $input 输入协程调用方法，在入队列前处理数据
     * @param callable $output 输出协程调用方法，在队列消费时处理数据
     * @param int $queueSize 列队数
     * @return Shuttle
     */
    public static function factory($input, callable $output, $queueSize = 100)
    {
        return new static($input, $output, $queueSize);
    }

    /**
     * 插入一个待处理任务数据
     *
     * 将会进行协程切换直到数据返回
     *
     * $timeout 设置超时时间，在任务处理通道已满的情况下，push会挂起当前协程
     * 在约定的时间内，如果没有任何消费者消费数据，将发生超时，底层会恢复当前协程，push调用立即返回false，写入失败
     *
     * $data可以是任意类型的PHP变量，包括匿名函数和资源
     * 为避免产生歧义，请勿向通道中写入空数据，如0、false、空字符串、null， false 内容不允许传入
     *
     * @param mixed $data
     * @param float $timeout 此参数在swoole 4.2.12或更高版本可用
     * @return ShuttleJob
     */
    public function go($data, $timeout = -1)
    {
        $job         = new ShuttleJob();
        $job->input  = $data;                           # 传的数据
        $job->output = null;                            # 执行的结果返回
        $job->coId   = null;                            # 挂起的协程ID
        $job->status = ShuttleJob::STATUS_WAITING;      # 状态

        if (false === $this->isOpen)
        {
            $job->output = false;
            $job->status = ShuttleJob::STATUS_CANCEL;
            return $job;
        }

        # 执行输出
        if ($this->input)
        {
            $rs = ($this->input)($job);
            if (false === $rs)
            {
                $job->output = false;
                $job->status = ShuttleJob::STATUS_ERROR;
                return $job;
            }
        }

        $rs = $this->queue->push($job, $timeout);
        if (false === $rs)
        {
            $job->status = ShuttleJob::STATUS_EXPIRE;
            $job->output = false;
            return $job;
        }

        if (ShuttleJob::STATUS_WAITING === $job->status || ShuttleJob::STATUS_RUNNING === $job->status)
        {
            # 数据插入成功，还没有被消费处理，协程挂载
            $job->coId = Co::getCid();
            Co::yield();
        }
        return $job;
    }

    /**
     * 启动穿梭服务
     *
     * 返回 false 有如下情况：
     *
     * * 如果已经有启动的queue（可以先 $this->stop(true) 再重新 start()）
     * * 创建协程失败
     *
     * @return bool
     */
    public function start()
    {
        if ($this->queue)return false;

        $task = $this->createTask();
        if ($task->coId)
        {
            $this->task   = $task;
            $this->queue  = $task->queue;
            $this->isOpen = true;
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 创建一个任务对象
     *
     * @return \stdClass
     */
    protected function createTask()
    {
        $task        = new \stdClass();
        $task->run   = true;
        $task->queue = new Co\Channel($this->queueSize);
        $call        = $this->output;
        $task->coId  = Co::create(function() use ($task, $call) {
            Server::$instance->debug(static::class .' start');
            while (true)
            {
                if (false === $task->run)
                {
                    # 如果任务被标记成 false 则退出
                    Server::$instance->debug(static::class .' stop');
                    break;
                }

                /**
                 * @var ShuttleJob $job
                 */
                $job = $task->queue->pop();
                if (false === $job)
                {
                    # 通道消息关闭
                    Server::$instance->debug(static::class .' close');
                    break;
                }
                $job->status = ShuttleJob::STATUS_RUNNING;
                try
                {
                    if (false === $call($job))
                    {
                        $job->output = false;
                        $job->status = ShuttleJob::STATUS_ERROR;
                    }
                    else
                    {
                        $job->status = ShuttleJob::STATUS_SUCCESS;
                    }
                }
                catch (\Exception $e)
                {
                    $job->status = ShuttleJob::STATUS_ERROR;
                    $job->error  = $e->getMessage();
                    $job->errno  = $e->getCode();
                    $job->output = false;
                    Server::$instance->trace($e);
                }
                $this->lastError = $job->error;
                $this->lastErrNo = $job->errno;

                if ($job->coId)
                {
                    # 有协程id，恢复协程
                    Co::resume($job->coId);
                }
            }
            $task->coId = null;
        });

        return $task;
    }

    /**
     * 销毁这个穿梭服务
     *
     * @param bool $discardTodoJob 丢弃队列中未处理的数据
     */
    public function stop($discardTodoJob = false)
    {
        $queue = $this->queue;
        $task  = $this->task;

        $this->isOpen = false;      # 标记为关闭
        $this->queue  = null;       # 移除队列
        $this->task   = null;       # 移除任务对象

        if ($discardTodoJob)
        {
            $task->run = false;  # 给任务标记为停止
            $allCid    = [];

            # 读取所有未处理的job
            $this->lastError = 'Shuttle stop';
            $this->lastErrNo = -1;
            while ($queue->length())
            {
                /**
                 * @var ShuttleJob $job
                 */
                $job         = $queue->pop();
                $job->status = ShuttleJob::STATUS_CANCEL;
                $job->coId   = null;
                $job->output = false;
                $job->error  = $this->lastError;
                $job->errno  = $this->lastErrNo;

                if ($job->coId)
                {
                    # 如果有挂起的协程
                    $allCid[] = $job->coId;
                }
                unset($job);
            }
            # 关闭队列
            $queue->close();

            # 恢复所有挂起的协程
            foreach ($allCid as $cid)
            {
                Co::resume($cid);
            }
        }
        else
        {
            # 发送一个 false 到列队最后，收到后会退出
            $queue->push(false);
        }
    }

    /**
     * 暂停服务
     *
     * 暂停后，服务队列将不接受任何新的数据加入
     */
    public function pause()
    {
        $this->isOpen = false;
    }

    /**
     * 打开服务
     *
     * 重新打服务，如果服务没有启动则自动启动服务
     *
     * @return bool
     */
    public function open()
    {
        if (!$this->queue)return $this->start();
        $this->isOpen = true;
        return true;
    }

    /**
     * 重启服务
     *
     * @param bool $discardTodoJob 丢弃队列中未处理的数据
     * @return bool
     */
    public function restart($discardTodoJob = false)
    {
        $this->stop($discardTodoJob);
        return $this->start();
    }

    /**
     * 获取任务数
     *
     * @return int
     */
    public function jobCount()
    {
        return $this->queue->length();
    }

    /**
     * 获取通道错误码
     *
     * @return int
     */
    public function getErrCode()
    {
        return $this->queue->errCode;
    }
}
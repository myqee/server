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
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
class Shuttle
{
    /**
     * 消费数据调用方法
     *
     * @var callable
     */
    protected $consumer;

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
     * 并行模式
     *
     * @var bool
     */
    protected $parallelMode = false;

    /**
     * 并行模式时排队已满时丢入的队列
     *
     * @var Co\Channel|null
     */
    protected $parallelQueue;

    /**
     * 暂停时丢入的队列
     *
     * @var Co\Channel|null
     */
    protected $pauseQueue;

    /**
     * 正在执行的任务列队数
     *
     * @var int
     */
    protected $runningCount = 0;

    /**
     * 是否开启状态
     *
     * @var bool
     */
    protected $isOpen = false;

    /**
     * Shuttle constructor.
     *
     * @param callable $consumer 队列消费处理函数
     * @param int      $todoSize 待处理数
     */
    public function __construct(callable $consumer, $todoSize = 100)
    {
        $this->consumer  = $consumer;
        $this->queueSize = $todoSize;
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
     * @param callable $consumer  队列消费处理函数
     * @param int      $queueSize 待处理数
     * @return Shuttle
     */
    public static function factory(callable $consumer, $queueSize = 100)
    {
        return new static($consumer, $queueSize);
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
        $job->data   = $data;                           # 传的数据
        $job->result = null;                            # 执行的结果返回
        $job->coId   = null;                            # 挂起的协程ID
        $job->status = ShuttleJob::STATUS_WAITING;      # 状态

        if (false === $this->isOpen)
        {
            # 放入暂停队列
            $queue = $this->pauseQueue;
        }
        elseif ($this->parallelMode && $this->runningCount >= $this->queueSize)
        {
            # 如果是并行模式且运行数超过当前任务数，则加入另外一个排队队列
            $queue = $this->parallelQueue;
        }
        else
        {
            $queue = $this->queue;
        }

        $rs = $queue->push($job, $timeout);     # 入队，协程挂起
        if (false === $rs)
        {
            $job->status = ShuttleJob::STATUS_EXPIRE;
            $job->result = false;
            $job->error  = new \Exception('timeout', $queue->errCode);
            return $job;
        }
        unset($queue);

        if (ShuttleJob::STATUS_WAITING === $job->status || ShuttleJob::STATUS_RUNNING === $job->status)
        {
            # 数据插入成功，还没有被消费处理，协程挂载
            $job->coId = Co::getCid();
            Co::yield();
        }

        # 并行模式直接在当前协程里运行任务
        if ($this->parallelMode)
        {
            $this->runJob($job);

            # 将并行排队的任务加入队列
            if ($this->parallelQueue->length())
            {
                $this->switchQueue($this->parallelQueue, $this->queue);
            }
        }

        if ($job->error && $job->error instanceof \Swoole\ExitException)
        {
            # 将结束的信号抛出
            throw $job->error;
        }

        return $job;
    }

    /**
     * 将一个排队的任务入队到另外一个队列
     */
    protected function switchQueue(Co\Channel $from, Co\Channel $to)
    {
        /**
         * @var ShuttleJob $job
         */
        $job = $from->pop(-1);
        if (!is_object($job))return;

        if (false === $to->push($job, -1))
        {
            # 入队失败
            $job->status = ShuttleJob::STATUS_EXPIRE;
            $job->result = false;
            $job->error  = new \Exception('Into queue failed', $to->errCode);
            if ($job->coId)
            {
                Co::resume($job->coId);
            }
        }
    }

    /**
     * 开启并行模式
     *
     * 必须在start()之前设置
     *
     * 开启并行模式后和原来的处理逻辑会不一样，协程待处理数仍旧是 todoSize 设置值，但是所有的job都是用并行协程处理的
     * 他们将失去列队原有会遵循的顺序关系
     *
     * 适用于需要限流但每个job并无先后顺序的功能
     *
     * @param bool $isEnable
     * @return bool
     */
    public function enableParallel($isEnable = true)
    {
        if ($this->queue)return false;

        $this->parallelMode = $isEnable;
        if ($isEnable)
        {
            $this->parallelQueue = new Co\Channel(1);
        }
        elseif ($this->parallelQueue)
        {
            $this->parallelQueue->close();
            $this->parallelQueue = null;
        }
        return true;
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
        $task->coId  = Co::create(function() use ($task) {
            /**
             * @var ShuttleJob $job
             */
            Server::$instance->debug(static::class .' start');
            while (true)
            {
                if (false === $task->run)
                {
                    # 如果任务被标记成 false 则退出
                    Server::$instance->debug(static::class .' stop');
                    break;
                }
                $job = $task->queue->pop();
                if (false === $job)
                {
                    # 通道消息关闭
                    Server::$instance->debug(static::class .' close');
                    break;
                }

                if (!$this->parallelMode)
                {
                    # 非并行模式直接调用，这样必须等到此协程块运行结束才会读写下一个job，顺序是可以保证的
                    $this->runJob($job);
                }

                if ($job->coId)
                {
                    # 有协程id，恢复协程
                    Co::resume($job->coId);
                }
                elseif ($job->status === ShuttleJob::STATUS_WAITING)
                {
                    # 这种情况下通常发生在并行模式下第一个进队列，协程还未开始切换，标记成消费完毕避免job的协程在此之后挂起
                    $job->status = ShuttleJob::STATUS_CONSUME;
                }
            }
            $task->coId = null;
        });

        return $task;
    }

    /**
     * 执行一个协程任务
     *
     * @param ShuttleJob $job
     */
    protected function runJob(ShuttleJob $job)
    {
        $this->runningCount++;
        $job->status = ShuttleJob::STATUS_RUNNING;
        try
        {
            $rs = call_user_func($this->consumer, $job);
            if (false === $rs)
            {
                $job->result = false;
                $job->status = ShuttleJob::STATUS_ERROR;
            }
            else
            {
                $job->status = ShuttleJob::STATUS_SUCCESS;
                if (null !== $rs)$job->result = $rs;
            }
        }
        catch (\Exception $e)
        {
            $job->error  = $e;
            $job->status = ShuttleJob::STATUS_ERROR;
            $job->result = false;
            Server::$instance->trace($e);
        }
        $this->runningCount--;
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

        if ($this->parallelQueue)
        {
            $this->parallelQueue->close();
            $this->parallelQueue = null;
        }

        if ($discardTodoJob)
        {
            $task->run = false;  # 给任务标记为停止
            $allCid    = [];
            $err       = new \Exception('Shuttle stop', -1);

            # 读取所有未处理的job
            while ($queue->length())
            {
                /**
                 * @var ShuttleJob $job
                 */
                $job         = $queue->pop();
                $job->status = ShuttleJob::STATUS_CANCEL;
                $job->coId   = null;
                $job->result = false;
                $job->error  = $err;

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
     *
     * @return bool
     */
    public function pause()
    {
        if (!$this->queue || !$this->isOpen)return false;

        $queue = new Co\Channel(1);
        $queue->push('');   # 塞入一个空的内容使其它要塞入的排队等候
        $this->pauseQueue = $queue;
        $this->isOpen = false;

        return true;
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
        if (!$this->queue || !$this->task)return $this->start();

        # 处理暂停期间排队等候的任务
        if ($this->pauseQueue)
        {
            while ($this->pauseQueue->length())
            {
                if ($this->parallelMode && $this->runningCount >= $this->queueSize)
                {
                    # 丢到排队的里面
                    $this->switchQueue($this->pauseQueue, $this->parallelQueue);
                }
                else
                {
                    $this->switchQueue($this->pauseQueue, $this->queue);
                }
            }
            $this->pauseQueue->close();
            $this->pauseQueue = null;
        }

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
     * 获取待处理和处理中任务数
     *
     * @return int
     */
    public function runningCount()
    {
        return $this->runningCount;
    }
}
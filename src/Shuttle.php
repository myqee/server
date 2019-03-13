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
     * 最后的错误
     *
     * @var null|\Exception
     */
    public $lastError = null;

    /**
     * 最后的错误
     *
     * @var null|int
     */
    public $lastErrNo = null;

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

    /**
     * 任务数
     *
     * @var int
     */
    protected $todoSize = 100;

    /**
     * 任务处理对象
     *
     * @var \stdClass
     */
    protected $task;

    /**
     * 运行模式
     *
     * @var bool
     */
    protected $mode = self::MODE_QUEUE;

    /**
     * 容器模式时排队已满时丢入的队列
     *
     * @var Co\Channel|null
     */
    protected $poolTodoChannel;

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

    const MODE_QUEUE = 0;       // 队列模式，将保证先后顺序执行
    const MODE_POOL  = 1;       // 容器模式，将并行执行并最大保证 todoSize 个在执行

    /**
     * Shuttle constructor.
     *
     * @param callable $consumer 队列消费处理函数
     * @param int      $todoSize 待处理数
     */
    public function __construct(callable $consumer, $todoSize = 100)
    {
        $this->consumer = $consumer;
        $this->todoSize = $todoSize;
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
     * @return static
     */
    public static function factory(callable $consumer, $queueSize = 100)
    {
        return new static($consumer, $queueSize);
    }

    /**
     * 协程切换获取返回
     *
     * 将会进行协程切换直到数据返回
     *
     * ```php
     * $rs1 = $shuttle->yield('test1');
     * echo $rs1;
     * $rs2 = $shuttle->yield($rs1);        // 获取$rs1后传入再获得$rs2
     * ```
     *
     * $timeout 设置超时时间，在任务处理通道已满的情况下，push会挂起当前协程
     * 在约定的时间内，如果没有任何消费者消费数据，将发生超时，底层会恢复当前协程，push调用立即返回false，写入失败
     *
     * $data可以是任意类型的PHP变量，包括匿名函数和资源
     *
     * @param mixed $data
     * @param float $timeout
     * @return mixed|false
     */
    public function yield($data, $timeout = -1)
    {
        $job = $this->go($data, $timeout);
        return $job->yield();
    }

    /**
     * 执行一个处理任务
     *
     * ```php
     * // $shuttle1,2,3 分别是不同序列的穿梭服务，也可以是开启 enableParallel(true) 的穿梭服务，这样就可以并行处理
     * $job1 = $shuttle1->go('test1');
     * $job2 = $shuttle2->go('test2');  // 如果$job1有协程切换动作，则不等$job1返回立即执行$job2
     * $job3 = $shuttle3->go($job2);    // 传入 $job2 对象则相当于如下：
     *                                     $rs2  = $shuttle2->yield('test2');
     *                                     $job3 = $shuttle3->go($rs2);
     *
     * // 3个协程同时执行，通过job的 yield() 获取内容
     * var_dump($job1->yield(), $job2->yield(), $job2->yield());
     * ```
     *
     * $timeout 设置超时时间，在任务处理通道已满的情况下，push会挂起当前协程
     * 在约定的时间内，如果没有任何消费者消费数据，将发生超时，底层会恢复当前协程，push调用立即返回false，写入失败
     *
     * $data可以是任意类型的PHP变量，包括匿名函数和资源
     *
     * @param mixed|ShuttleJob $data 任务数据，也可以传一个ShuttleJob对象
     * @param float $timeout 此参数在swoole 4.2.12或更高版本可用
     * @return ShuttleJob
     */
    public function go($data, $timeout = -1)
    {
        /**
         * @var ShuttleJob $job
         */
        if (is_object($data) && $data instanceof ShuttleJob)
        {
            $data = $data->yield();
        }

        $job       = new ShuttleJob();
        $job->data = $data;

        $this->push($job, $timeout);

        return $job;
    }

    /**
     * 将一个任务加入队列
     *
     * @param ShuttleJob $job
     * @param float $timeout
     */
    public function push(ShuttleJob $job, $timeout = -1)
    {
        if (false === $this->isOpen)
        {
            # 放入暂停队列
            $queue = $this->pauseQueue;
        }
        elseif ($this->mode === self::MODE_POOL && $this->runningCount >= $this->todoSize)
        {
            # 如果是并行模式且运行数超过当前任务数，则加入另外一个排队队列
            $queue = $this->poolTodoChannel;
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
        }
        unset($queue);
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
            $job->resume();
        }
    }

    /**
     * 设置处理模式
     *
     * 必须在start()之前设置
     *
     * 开启并行模式后和原来的处理逻辑会不一样，协程待处理数仍旧是 todoSize 设置值，但是所有的job都是用并行协程处理的
     * 他们将失去列队原有会遵循的顺序关系
     *
     * 适用于统一管理的协程服务或需要限流但每个job并无先后顺序的功能
     *
     * @param bool $mode
     * @return bool
     */
    public function setMode($mode = self::MODE_POOL)
    {
        if ($this->queue)return false;

        $this->mode = $mode;
        if ($mode === self::MODE_POOL)
        {
            $this->poolTodoChannel = new Co\Channel(1);
        }
        elseif ($this->poolTodoChannel)
        {
            $this->poolTodoChannel->close();
            $this->poolTodoChannel = null;
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
        $task->queue = new Co\Channel($this->todoSize);
        $task->coId  = Co::create(function() use ($task) {
            /**
             * @var ShuttleJob $job
             */
            switch ($this->mode)
            {
                case self::MODE_POOL:
                    # 容器模式
                    while (true)
                    {
                        if (false === $task->run)break;                         # 如果任务被标记成 false 则退出
                        if (false === ($job = $task->queue->pop()))break;       # 通道消息关闭

                        // 创建一个新的协程块来执行
                        $fun = function() use ($job)
                        {
                            $this->runJob($job);

                            # 将并行排队的任务加入队列
                            if ($this->poolTodoChannel->length())
                            {
                                $this->switchQueue($this->poolTodoChannel, $this->queue);
                            }
                        };
                        if (false === Co::create($fun))$fun();

                        # 恢复协程
                        $job->resume();
                    }
                    break;

                case self::MODE_QUEUE;
                default:
                    # 队列模式
                    while (true)
                    {
                        if (false === $task->run)break;                         # 如果任务被标记成 false 则退出
                        if (false === ($job = $task->queue->pop()))break;       # 通道消息关闭

                        # 必须等到此协程块运行结束才会读下一个job，可以保证顺序
                        $this->runJob($job);

                        # 恢复协程
                        $job->resume();
                    }
                    break;
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
            $this->lastError = null;
            $this->lastErrNo = null;
        }
        catch (\Exception $e)
        {
            $job->error      = $e;
            $job->status     = ShuttleJob::STATUS_ERROR;
            $job->result     = false;
            $this->lastError = $e;
            $this->lastErrNo = $e->getCode();
            Server::$instance->trace($e);
        }
        $this->runningCount--;
    }

    /**
     * 停止这个穿梭服务
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

        if ($this->poolTodoChannel)
        {
            $this->poolTodoChannel->close();
            $this->poolTodoChannel = null;
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
                $job->result = false;
                $job->error  = $err;
                $coIds       = $job->getCoIds();
                if (null !== $coIds)
                {
                    # 如果有挂起的协程
                    $allCid = array_merge($allCid, $coIds);
                }
                unset($job, $coId);
            }
            # 关闭队列
            $queue->close();

            $this->lastErrNo = -1;
            $this->lastError = $err;

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
                if ($this->mode === self::MODE_POOL && $this->runningCount >= $this->todoSize)
                {
                    # 丢到排队的里面
                    $this->switchQueue($this->pauseQueue, $this->poolTodoChannel);
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
     * 是否开启
     *
     * @return bool
     */
    public function isOpen()
    {
        return $this->isOpen;
    }

    /**
     * 是否启动
     *
     * @return bool
     */
    public function isStart()
    {
        return $this->queue ? true : false;
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
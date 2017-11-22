<?php

namespace MyQEE\Server\Coroutine;

use MyQEE\Server\Server;

/**
 * 协程调度器
 *
 * @package MyQEE\Server\Coroutine
 */
abstract class Scheduler
{
    /**
     * 协程异步处理器
     *
     * @var null|int
     */
    protected static $tick;

    /**
     * @var \SplObjectStorage
     */
    protected static $rootList;

    /**
     * 协程列表长度
     *
     * @var int
     */
    protected static $queueCount;

    /**
     * 异步协程最后执行时间
     *
     * @var null
     */
    protected static $lastRunTime;
    
    /**
     * 创建一个并行运行的协程
     *
     * @param \Generator|array $genA
     * @param \Generator $genB
     * @param \Generator|null $genC
     * @param ...
     * @return \Generator
     * @throws \Exception
     */
    public static function parallel($genA, $genB = null, $genC = null)
    {
        if (is_array($genA))
        {
            $list = $genA;
        }
        else
        {
            $list = func_get_args();
        }

        foreach ($list as $i => & $item)
        {
            if ($item instanceof \Closure)
            {
                $item = $item();
            }

            if (false === ($item instanceof \Generator))
            {
                throw new \Exception("Uncaught TypeError: Argument {$i}passed to parallelCoroutine() must be an instance of Generator.");
            }

            $item = new Task($item);
        }
        unset($item);

        /**
         * @var Task $task
         */
        $running = $list;
        while (true)
        {
            $done = [];
            foreach ($running as $key => $task)
            {
                if (true === self::runOneStep($task))
                {
                    $done[] = $key;
                }
            }

            foreach ($done as $key)
            {
                unset($running[$key]);
            }

            if (count($running) === 0)
            {
                break;
            }

            yield;
        }

        $rs = [];
        foreach ($list as $task)
        {
            $rs[] = $task->sendValue;
        }

        yield $rs;
    }

    public static function schedule(Task $task)
    {
        $value = $task->coroutine->current();
        if (null !== $value)
        {
            if (($signal = self::handleSysCall($task,    $value)) !== null)return $signal;
            if (($signal = self::handleCoroutine($task,  $value)) !== null)return $signal;
            if (($signal = self::handleAsyncJob($task,   $value)) !== null)return $signal;
        }

        if (($signal = self::handleYieldValue($task, $value)) !== null)return $signal;
        if (($signal = self::handleTaskStack($task,  $value)) !== null)return $signal;
        if (($signal = self::checkTaskDone($task,    $value)) !== null)return $signal;

        return Signal::TASK_DONE;
    }

    /**
     * 执行1步
     *
     * 返回执行完毕后的状态，执行完成则返回 true
     *
     * @param Task $task
     * @return int|bool
     */
    public static function runOneStep(Task $task)
    {
        try
        {
            switch ($task->status)
            {
                case Signal::TASK_DONE:
                    return true;

                case Signal::TASK_WAIT;
                    if (isset($task->asyncEndTime) && microtime(true) > $task->asyncEndTime)
                    {
                        # 超时，将原来的 coroutine 替换成一个新的
                        $task->coroutine = (function()
                        {
                            yield false;
                        })();
                        $task->status = self::schedule($task);

                        if ($task->status === Signal::TASK_DONE)
                        {
                            return true;
                        }
                        else
                        {
                            return $task->status;
                        }
                    }

                    # 等待异步回调状态
                    return $task->status;

                case Signal::TASK_KILLED;
                    $task->status = Signal::TASK_DONE;
                    return true;

                default:
                    $task->status = self::schedule($task);

                    if ($task->status === Signal::TASK_KILLED)
                    {
                        $task->status = Signal::TASK_DONE;
                        return true;
                    }

                    if ($task->status === Signal::TASK_DONE)
                    {
                        return true;
                    }
                    break;
            }
        }
        catch (\Throwable $t)
        {
            Scheduler::throw($task, $t);
        }
        catch (\Exception $e)
        {
            Scheduler::throw($task, $e);
        }

        return $task->status;
    }

    /**
     * 运行协程任务
     *
     * @param Task $task
     * @return int|bool
     */
    public static function run(Task $task)
    {
        while (true)
        {
            if (true === self::runOneStep($task))
            {
                break;
            }
        }

        return $task->status;
    }

    public static function throw(Task $task, $e, $isFirstCall = false, $isAsync = false)
    {
        if (self::isTaskInvalid($task, $e))
        {
            return;
        }

        if ($task->stack->isEmpty())
        {
            $task->coroutine->throw($e);
            return;
        }

        try
        {
            if ($isFirstCall)
            {
                $coroutine = $task->coroutine;
            }
            else
            {
                $coroutine = $task->stack->pop();
            }
            $task->coroutine = $coroutine;
            $coroutine->throw($e);

            if ($isAsync)
            {
                self::run($task);
            }
        }
        catch (\Throwable $t)
        {
            self::throw($task, $t, false, $isAsync);
        }
        catch (\Exception $e)
        {
            self::throw($task, $e, false, $isAsync);
        }
    }

    /**
     * 处理系统调用
     *
     * @param Task $task
     * @param mixed $value
     * @return mixed|null
     */
    protected static function handleSysCall(Task $task, $value)
    {
        if (!($value instanceof SysCall) && !is_subclass_of($value, SysCall::class))
        {
            return null;
        }

        //echo $task->taskId . "| SYSCALL\n";

        # 走系统调用 实际上因为 __invoke 走的是 $value($task);
        $signal = call_user_func($value, $task);

        if (Signal::isSignal($signal))
        {
            return $signal;
        }

        return null;
    }

    /**
     * 处理子协程
     *
     * @param Task $task
     * @param mixed $value
     * @return mixed|null
     */
    protected static function handleCoroutine(Task $task, $value)
    {
        if (!($value instanceof \Generator))
        {
            return null;
        }

        //echo $task->taskId . "| COROUTINE\n";
        // 当前的协程入栈
        $task->stack->push($task->coroutine);

        //将新的协程设为当前的协程
        $task->coroutine = $value;

        return Signal::TASK_CONTINUE;
    }

    /**
     * 处理异步请求
     *
     * @param Task $task
     * @param mixed $value
     * @return mixed|null
     */
    protected static function handleAsyncJob(Task $task, $value)
    {
        if (!($value instanceof Async))
        {
            return null;
        }

        /** @var $value Async */
        $value->execute(function($response, $exception) use ($task)
        {
            return self::asyncCallback($task, $response, $exception);
        }, $task);

        return Signal::TASK_WAIT;
    }

    /**
     * 执行一个异步回调
     *
     * @param Task $task
     * @param      $response
     * @param null $exception
     * @return mixed
     */
    protected static function asyncCallback($task, $response, $exception = null)
    {
        if (self::isTaskInvalid($task, $exception))
        {
            return $task->sendValue;
        }

        # 兼容PHP7 & PHP5
        if ($exception instanceof \Throwable || $exception instanceof \Exception)
        {
            self::throw($task, $exception, true, true);
        }
        else
        {
            # 发送数据
            $task->send($response);
            $task->status = self::schedule($task);
        }

        return $task->sendValue;
    }

    /**
     * 处理协程栈
     *
     * @param Task $task
     * @param mixed $value
     * @return mixed|null
     */
    protected static function handleTaskStack(Task $task, $value)
    {
        //能够跑到这里说明当前协程已经跑完了 valid()==false了 需要看下栈里是否还有以前的协程
        if ($task->stack->isEmpty())
        {
            return null;
        }

        //echo $task->taskId . "| TASKSTACK\n";
        //出栈 设置为当前运行的协程
        $coroutine       = $task->stack->pop();
        $task->coroutine = $coroutine;

        //这个 sendValue 可能是从刚跑完的协程那里得到的 把它当做send值传给老协程 让他继续跑
        $value = $task->sendValue;
        $task->send($value);

        return Signal::TASK_CONTINUE;
    }

    /**
     * 处理普通的yield值
     *
     * @param Task $task
     * @param mixed $value
     * @return mixed|null
     */
    protected static function handleYieldValue(Task $task, $value)
    {
        if (!$task->coroutine->valid())
        {
            return null;
        }

        //echo $task->taskId . "| YIELD VALUE {$value}\n";
        //如果协程后面没有yield了 这里发出send以后valid就变成false了 并且current变成NULL
        $task->send($value);

        return Signal::TASK_CONTINUE;
    }

    /**
     * 判断是否执行完毕
     *
     * @param Task $task
     * @param mixed $value
     * @return mixed|null
     */
    protected static function checkTaskDone(Task $task, $value)
    {
        if ($task->coroutine->valid())
        {
            return null;
        }
        //echo $task->taskId . "| CHECKDONE\n";

        return Signal::TASK_DONE;
    }

    protected static function isTaskInvalid(Task $task, $t)
    {
        if ($task->status === Signal::TASK_KILLED || $task->status === Signal::TASK_DONE)
        {
            // 兼容PHP7 & PHP5
            if ($t instanceof \Throwable || $t instanceof \Exception)
            {
                Server::$instance->warn($t->getMessage());
            }
            return true;
        }

        return false;
    }

    /**
     * 增加一个协程调度器
     *
     * @param \Generator $gen
     * @return Task
     */
    public static function addCoroutineScheduler(\Generator $gen)
    {
        if (null === self::$tick)
        {
            self::initCoroutineWatch();
        }

        $task = new Task($gen);
        self::$rootList->attach($task);
        self::$queueCount++;

        return $task;
    }

    protected static function initCoroutineWatch()
    {
        Server::$instance->debug("Worker#". Server::$instance->server->worker_id ." [Coroutine] add new coroutine async time tick.");
        if (null === self::$rootList)
        {
            self::$rootList   = new \SplObjectStorage();
            self::$queueCount = 0;
        }

        // 加入一个定时器
        self::$tick = swoole_timer_tick(1, function()
        {
            if (0 === self::$queueCount)return;

            $begin = microtime(true);
            while (true)
            {
                $done  = [];
                while(self::$rootList->valid())
                {
                    /**
                     * @var Task $task
                     */
                    $task = self::$rootList->current();

                    if (true === self::runOneStep($task))
                    {
                        # 不能用直接 detach() 因为这样指针会重置
                        $done[] = $task;
                    }

                    self::$rootList->next();
                }

                # 移除
                foreach ($done as $task)
                {
                    self::$rootList->detach($task);
                }

                # 重置
                self::$rootList->rewind();

                self::$lastRunTime = microtime(true);

                if (false === self::$rootList->valid() || self::$lastRunTime - $begin > 0.0009)
                {
                    break;
                }
            }

            unset($task, $done);

            # 移除临时赋值
            self::$queueCount = self::$rootList->count();
        });

        # 增加一个移除的定时器
        swoole_timer_tick(15000, function($timerId)
        {
            if (0 === self::$queueCount && microtime(true) - self::$lastRunTime > 10)
            {
                swoole_timer_clear(self::$tick);
                swoole_timer_clear($timerId);

                self::$tick     = null;
                self::$rootList = null;

                Server::$instance->debug("Worker#". Server::$instance->server->worker_id .' [Coroutine] remove coroutine async time tick.');
            }
        });
    }

    /**
     * 进程结束，执行所有协程
     */
    public static function shutdown()
    {
        while (true)
        {
            $done = [];
            while (self::$rootList->valid())
            {
                /**
                 * @var Task $task
                 */
                $task = self::$rootList->current();

                if (true === self::runOneStep($task))
                {
                    # 不能用直接 detach() 因为这样指针会重置
                    $done[] = $task;
                }
                elseif ($task->status === Signal::TASK_WAIT)
                {
                    # 标记成过期
                    $task->asyncEndTime = time();
                }

                self::$rootList->next();
            }

            # 移除
            foreach ($done as $task)
            {
                self::$rootList->detach($task);
            }

            # 重置
            self::$rootList->rewind();
            self::$queueCount = self::$rootList->count();

            if (0 === self::$queueCount)return;
        }
    }

    /**
     * 获取当前列队数
     *
     * @return int
     */
    public static function queueCount()
    {
        return self::$queueCount;
    }
}

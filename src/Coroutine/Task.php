<?php
namespace MyQEE\Server\Coroutine;

/**
 * 协程任务对象
 *
 * @package MyQEE\Server\Coroutine
 */
class Task
{
    /**
     * 迭代器
     *
     * @var \Generator
     */
    public $coroutine;

    /**
     * 发送的数据
     *
     * @var mixed
     */
    public $sendValue;

    /**
     * 状态
     *
     * @var int
     */
    public $status = 0;

    /**
     * 协程堆栈
     *
     * @var \SplStack
     */
    public $stack;

    /**
     * 上下文对象
     *
     * @var \stdClass|mixed
     */
    public $context;

    /**
     * 异步调用超时时间
     *
     * @var int
     */
    public $asyncEndTime;

    public function __construct(\Generator $coroutine, $context = null)
    {
        $this->init($coroutine, $context);
    }

    public function init(\Generator $coroutine, $context = null)
    {
        $this->coroutine = $coroutine;
        $this->stack     = new \SplStack();
        $this->context   = $context;
    }

    /**
     * 获取返回内容
     *
     * @return mixed
     */
    public function getResult()
    {
        if ($this->status === Signal::TASK_DONE)
        {
            return $this->sendValue;
        }
        else
        {
            return false;
        }
    }

    /**
     * 执行并获取最终结果
     *
     * 此方法为同步阻塞模式，不可以调度异步任务
     *
     * @return mixed
     */
    public function runAndGetResult()
    {
        Scheduler::run($this);

        return $this->sendValue;
    }

    /**
     * 通过 yield 获取最后返回内容
     *
     * 将返回一个 Generator 对象，所以请使用 `$rs = yield $task->rs();` 这样的方法获取
     *
     * @return \Generator
     */
    public function rs()
    {
        while (true)
        {
            if ($this->status === Signal::TASK_DONE)
            {
                yield $this->sendValue;
                break;
            }
            yield;
        }
    }

    /**
     * 发送数据
     *
     * @param $value
     * @return mixed|null
     */
    public function send($value)
    {
        try
        {
            $this->sendValue = $value;

            return $this->coroutine->send($value);
        }
        catch (\Exception $e){Scheduler::throw($this, $e);}
        catch (\Throwable $t){Scheduler::throw($this, $t);}

        return null;
    }

    /**
     * 是否完成
     *
     * @return bool
     */
    public function isDone()
    {
        return $this->status === Signal::TASK_DONE;
    }

    /**
     * 在协程方法里获取上下文对象(协程方式获取)
     *
     * ```php
     * $context = yield Task::getCurrentContext();
     * ```
     *
     * @return \stdClass
     */
    public static function getCurrentContext()
    {
        yield new SysCall(function(Task $task) {
            return $task->context;
        });
    }

    /**
     * 在协程方法里获取当前Task对象
     *
     * ```php
     * $context = yield Task::getCurrentTask();
     * ```
     *
     * @return Task
     */
    public static function getCurrentTask()
    {
        yield new SysCall(function(Task $task) {
            return $task;
        });
    }
}
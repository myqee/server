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

    public function __construct(\Generator $coroutine)
    {
        $this->init($coroutine);
    }

    public function init(\Generator $coroutine)
    {
        $this->coroutine = $coroutine;
        $this->stack     = new \SplStack();
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

    public function send($value)
    {
        try
        {
            $this->sendValue = $value;

            return $this->coroutine->send($value);
        }
        catch (\Throwable $t)
        {
            Scheduler::throw($this, $t);
        }
        catch (\Exception $e)
        {
            Scheduler::throw($this, $e);
        }

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
}
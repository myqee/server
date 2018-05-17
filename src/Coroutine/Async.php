<?php

namespace MyQEE\Server\Coroutine;

class Async
{
    protected $endTime;

    protected $callback;

    /**
     * Async constructor.
     */
    public function __construct($timeout = 0)
    {
        if ($timeout > 0)
        {
            $this->setTimeout($timeout);
        }
    }

    /**
     * 设置异步等待超时时间
     *
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        if ($timeout > 0)
        {
            $this->endTime = microtime(true) + $timeout;
        }
        else
        {
            $this->endTime = null;
        }
    }

    /**
     * 注册异步任务
     *
     * @param callable $callback
     * @param Task     $task
     */
    public function execute(callable $callback, Task $task)
    {
        $this->callback = $callback;

        if ($this->endTime > 0)
        {
            # 设一个超时的时间戳标志
            $task->asyncEndTime = $this->endTime;
        }
    }

    /**
     * 触发执行
     *
     * @param mixed $response 返回内容
     * @param \Exception|\Throwable|null $exception 异常
     * @return bool|mixed
     */
    public function call($response, $exception = null)
    {
        if (null === $this->callback)return false;
        if (null !== $this->endTime && microtime(true) > $this->endTime)return false;

        return call_user_func($this->callback, $response, $exception);
    }
}
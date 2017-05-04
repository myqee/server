<?php

namespace MyQEE\Server\RPC;

/**
 * RPC回调成功时返回的高级对象
 *
 * @package MyQEE\Server\RPC
 */
class Result
{
    public $data = null;

    /**
     * 回调事件
     *
     * @var array
     */
    protected $on = [];

    /**
     * 设置一个回调事件
     *
     * 支持 success, done, error
     *
     * @param $event
     * @param $callback
     * @return bool
     */
    public function on($event, $callback)
    {
        $event = strtolower($event);
        if (!in_array($event, ['success', 'complete', 'error']))return false;

        $this->on[$event] = $callback;

        return true;
    }

    /**
     * 触发一个事件执行
     *
     * @param      $event
     * @param null $arg1
     * @param null $arg2
     * @return mixed|null
     */
    public function trigger($event)
    {
        if (isset($this->on[$event]))
        {
            $call = $this->on[$event];
            return call_user_func($call);
        }
        else
        {
            return null;
        }
    }
}
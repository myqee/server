<?php
namespace MyQEE\Server\Coroutine;


class Signal
{
    protected $signal;

    const TASK_SLEEP    = 1;
    const TASK_AWAKE    = 2;
    const TASK_CONTINUE = 3;
    const TASK_KILLED   = 4;
    const TASK_RUNNING  = 5;
    const TASK_WAIT     = 6;
    const TASK_DONE     = 7;

    public function __construct($signal)
    {
        $this->signal = $signal;
    }

    /**
     * 判断返回的对象是否一个信号
     *
     * @param $signal
     * @return bool
     */
    public static function isSignal($signal)
    {
        if (!$signal || !is_object($signal) || false === ($signal instanceof Signal))
        {
            return false;
        }

        /**
         * @var Signal $signal
         */
        if (!is_int($signal->signal))
        {
            return false;
        }

        if ($signal->signal < 1)
        {
            return false;
        }

        if ($signal->signal > 7)
        {
            return false;
        }

        return true;
    }
}
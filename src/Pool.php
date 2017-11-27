<?php
namespace MyQEE\Server;

/**
 * 对象池、连接池
 *
 * @package MyQEE\Server
 */
class Pool
{
    /**
     * 列队的闲置数
     *
     * @var int
     */
    public $idleNum;

    /**
     * @var \SplQueue
     */
    protected $queue;

    /**
     * 初始化对象方法
     *
     * @var \Closure
     */
    protected $initCallback;

    /**
     * 创建方法
     *
     * @var \Closure
     */
    protected $createNewCallback;

    /**
     * 重置方法
     *
     * @var \Closure
     */
    protected $resetCallback;

    /**
     * 默认列队闲置数
     */
    const DEFAULT_IDLE_NUM = 10;

    /**
     * 对象池、连接池
     *
     * @param \Closure $createNewCallback 创建一个新的对象回调方法，无参数，返回创角的对象等
     * @param \Closure $resetCallback     重置对象的回调方法
     * @param \Closure $initCallback      初始化对象的回调方法，用于设定一些初始变量、参数
     * @param \Closure $discardCallback   丢弃对象的回调方法，用于完全销毁对象时关闭连接、文件指针等
     * @param int      $preNum            预加载数
     * @param int      $idleNum           闲置数
     */
    public function __construct(\Closure $createNewCallback,
                                $resetCallback = null,
                                $initCallback = null,
                                $discardCallback = null,
                                $idleNum = null)
    {
        $this->createNewCallback = $createNewCallback;
        $this->resetCallback     = self::getCallback($resetCallback);
        $this->initCallback      = self::getCallback($initCallback, 'init');
        $this->discardCallback   = self::getCallback($discardCallback);
        $this->idleNum           = $idleNum ?: static::DEFAULT_IDLE_NUM;
        $this->queue             = new \SplQueue();
    }

    /**
     * 获取一个对象
     *
     * @return mixed
     */
    public function get($arg1 = null, $arg2 = null)
    {
        if ($this->queue->valid())
        {
            $obj = ($this->queue->shift());

            # 初始化对象
            ($this->initCallback)($obj, func_get_args());
        }
        else
        {
            # 创建一个对象
            $obj = call_user_func_array($this->createNewCallback, func_get_args());
        }

        return $obj;
    }

    /**
     * 归还使用过的对象
     *
     * 超过队列数的对象将自动丢弃
     *
     * @param $value
     */
    public function giveBack($value)
    {

        if ($this->queue->count() < $this->idleNum)
        {
            # 回调重置方法
            ($this->resetCallback)($value);

            # 入队列
            $this->queue->enqueue($value);
        }
        else
        {
            # 回调销毁方法
            ($this->discardCallback)($value);
        }
    }

    protected static function getCallback($callback, $type = null)
    {
        if (null === $callback)
        {
            return function(){};
        }

        if (is_string($callback))
        {
            if ('init' === $type)
            {
                $fun = function($obj, $arguments) use ($callback)
                {
                    call_user_func_array([$obj, $callback], $arguments);
                };
            }
            else
            {
                $fun = function($obj) use ($callback)
                {
                    $obj->$callback();
                };
            }
        }
        elseif (is_callable($callback))
        {
            $fun = $callback;
        }
        else
        {
            $fun = function(){};
        }

        return $fun;
    }
}
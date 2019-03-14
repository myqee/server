<?php
namespace MyQEE\Server;

/**
 * 连接池、对象池对象
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */

class Pool
{
    /**
     * 容器
     *
     * @var \Swoole\Coroutine\Channel
     */
    protected $pool;

    /**
     * 空闲保留数
     *
     * @var int
     */
    protected $spaceSize;

    /**
     * 容量
     *
     * @var int
     */
    protected $poolSize;

    /**
     * 清理数据时间间隔，单位毫秒
     *
     * @var int
     */
    protected $cleanTickInterval = 600 * 1000;

    /**
     * 清理数据定时器
     *
     * @var int
     */
    protected $tickForClean;

    /**
     * ping连接定时器
     *
     * @var int
     */
    protected $tickForPing;

    /**
     * 创建对象的方法
     *
     * @var callable
     */
    protected $createObjectFunc;

    /**
     * 销毁对象的方法
     *
     * @var callable
     */
    protected $destroyObjectFunc;

    /**
     * 归还对象的方法
     *
     * @var callable
     */
    protected $givebackObjectFunc;

    /**
     * 获取对象时的回调方法
     *
     * @var callable
     */
    protected $getObjectFunc;

    /**
     * ping连接方法
     *
     * @var callable
     */
    protected $pingConnFunc;

    /**
     * 在使用中的数
     *
     * @var int
     */
    protected $using = 0;

    /**
     * 连接池、对象池
     *
     * @param int $poolSize 总容量
     * @param int $spaceSize 空闲数量，系统每10分钟清理一次，会释放掉池子里多余的对象
     */
    public function __construct($poolSize = 100, $spaceSize = 10)
    {
        $this->pool          = new \Swoole\Coroutine\Channel($poolSize);
        $this->spaceSize     = $spaceSize;
        $this->poolSize      = $poolSize;

        $this->destroyObjectFunc = function($object) {
            if (method_exists($object, 'close'))
            {
                $object->close();
            }
        };

        $this->givebackObjectFunc = function($object) {
            if (method_exists($object, 'free'))
            {
                $object->free();
            }
        };

        # 增加清理数据定时器
        $this->setTickCleanInterval($this->cleanTickInterval);
    }

    public function __destruct()
    {
        if ($this->tickForClean)
        {
            \Swoole\Timer::clear($this->tickForClean);
            $this->tickForClean = null;
        }

        $func = $this->destroyObjectFunc;
        while ($this->pool->length())
        {
            if (false === ($conn = $this->pool->pop()))continue;

            Util\Co::go(function() use ($func, $conn)
            {
                $func($conn);
            });
        }

        $this->pool->close();
    }

    /**
     * 入队一个连接
     *
     * @param \Swoole\Coroutine\MySQL $conn
     */
    public function put($conn)
    {
        if (!$conn)return;

        if ($this->using > 0)
        {
            $this->using--;
        }
        else
        {
            # 有可能加入一个新的连接进来
            $this->using = 0;
        }

        if ($this->pool->isFull())
        {
            $func = $this->destroyObjectFunc;
        }
        elseif ($this->givebackObjectFunc)
        {
            $func = $this->givebackObjectFunc;
        }
        else
        {
            # 直接归还
            $this->pool->push($conn);
            return;
        }

        Util\Co::go(function() use ($func, $conn)
        {
            if (false !== $func($conn))
            {
                $this->pool->push($conn);
            }
        });
    }

    /**
     * 获取连接池对象
     *
     * @return false|mixed
     */
    public function get()
    {
        if ($this->pool->isEmpty() && $this->using < $this->poolSize && $this->createObjectFunc)
        {
            # 没有连接了，创建一个新连接
            $object = call_user_func($this->createObjectFunc);
            $new    = true;

            # 创建失败
            if (!$object)return $object;
        }
        else
        {
            $object = $this->pool->pop();
            $new  = false;
        }

        $func = $this->getObjectFunc;
        if ($func && false === call_user_func($func, $object, $new))return $this->get();        # 如果返回 false 则重新拿

        $this->using++;

        return $object;
    }

    /**
     * 是否满
     *
     * @return bool
     */
    public function isFull()
    {
        return $this->pool->isFull();
    }

    /**
     * 是否为空
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->pool->isEmpty();
    }

    /**
     * 使用中的数量
     *
     * @return int
     */
    public function using()
    {
        return $this->using;
    }

    /**
     * 空闲连接、对象数
     *
     * 不包括已使用的
     *
     * @return int
     */
    public function length()
    {
        return $this->pool->length();
    }

    /**
     * 设置一个创建连接的方法
     *
     * 回调方法无参数，返回实例化后的对象，false 则表示终止操作
     *
     * @param callable|null $func 回调方法，null则移除
     * @return $this
     */
    public function setCreateObjectFunc($func)
    {
        $this->createObjectFunc = is_callable($func) ? $func : null;
        return $this;
    }

    /**
     * 设置一个关闭连接的方法
     *
     * 回调方法第1个参数是对象，返回 false 则表示终止操作
     *
     * @param callable|null $func 回调方法，null则移除
     * @return $this
     */
    public function setDestroyObjectFunc($func)
    {
        $this->destroyObjectFunc = is_callable($func) ? $func : null;
        return $this;
    }

    /**
     * 设置一个获取连接的方法
     *
     * 回调方法第1个参数是对象，第2个参数是是否新创建的，返回 false 则表示终止操作
     *
     * @param callable|null $func 回调方法，null则移除
     * @return $this
     */
    public function setGetObjectFunc($func)
    {
        $this->getObjectFunc = is_callable($func) ? $func : null;
        return $this;
    }

    /**
     * 设置归还连接的方法
     *
     * 如果连接池已满此时归还连接则系统会直接调用关闭连接的方法而不是这个方法，不设置则直接入队列
     *
     * 回调方法第1个参数是对象，返回 false 则表示终止操作
     *
     * @param callable|null $func 回调方法，null则移除
     * @return $this
     */
    public function setGivebackObjectFunc($func)
    {
        $this->givebackObjectFunc = is_callable($func) ? $func : null;
        return $this;
    }

    /**
     * 设置一个ping连接的方法
     *
     * 回调方法第1个参数是对象，返回 false 则表示连接失败会从连接池里移除
     *
     * @param callable|null $func 回调方法，null则移除
     * @param int $interval 间隔时间，单位毫秒，默认10分钟
     * @return $this
     */
    public function setPingConnFunc($func, $interval = 600000)
    {
        $this->pingConnFunc = is_callable($func) ? $func : null;

        if ($interval < $this->cleanTickInterval)
        {
            # 移除旧定时器
            if ($this->tickForPing)
            {
                \Swoole\Timer::clear($this->tickForPing);
                $this->tickForPing = null;
            }

            # 增加一个定时器
            if ($this->pingConnFunc)$this->tickForPing = \Swoole\Timer::tick($interval, [$this, 'checkAllConn']);
        }

        return $this;
    }

    /**
     * 设置清理数据定时间隔
     *
     * @param int $msec 间隔
     */
    public function setTickCleanInterval($msec)
    {
        if ($this->tickForClean)
        {
            \Swoole\Timer::clear($this->tickForClean);
            $this->tickForClean = null;
        }

        $this->cleanTickInterval = $msec;
        $this->tickForClean      = \Swoole\Timer::tick($msec, function ()
        {
            # 清理空闲连接
            while ($this->pool->length() > $this->spaceSize)
            {
                if (false === ($conn = $this->pool->pop()))break;

                # 将多余的连接移除
                call_user_func($this->destroyObjectFunc, $conn);
            }

            # ping的定时器小于清理数据的时效则不增加ping定时器
            if ($this->pingConnFunc && !$this->tickForPing)
            {
                $this->checkAllConn();
            }
        });
    }

    /**
     * 检查所有连接（使用pingConnFunc设置的方法）
     */
    public function checkAllConn()
    {
        if ($this->pingConnFunc && !$this->pool->isEmpty())
        {
            # 重新构造一个新的容器
            $pool       = $this->pool;
            $ping       = $this->pingConnFunc;
            $this->pool = new \Swoole\Coroutine\Channel($this->poolSize);

            # 在旧的容器里遍历
            while (!$pool->isEmpty())
            {
                $object = $pool->pop();
                if (!$object)continue;

                if (false === call_user_func($ping, $object))continue;

                if ($this->pool->isFull())
                {
                    if ($this->destroyObjectFunc)
                    {
                        # 销毁对象
                        call_user_func($this->destroyObjectFunc, $object);
                    }
                }
                else
                {
                    # 归还进新容器里
                    $this->pool->push($object);
                }
            }
            $pool->close();
            unset($pool);
        }
    }
}
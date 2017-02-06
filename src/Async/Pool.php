<?php
namespace MyQEE\Server\Async;

/**
 * 通用的连接池框架
 */
abstract class Pool
{
    /**
     * 连接池的尺寸，最大连接数
     *
     * @var int $poolSize
     */
    protected $poolSize;
    /**
     * idle connection
     *
     * @var array $resourcePool
     */
    protected $resourcePool = [];

    protected $resourceNum = 0;

    protected $failureCount = 0;

    /**
     * @var \SplQueue
     */
    protected $idlePool;
    /**
     * @var \SplQueue
     */
    protected $taskQueue;

    protected $createFunction;

    /**
     * @var array
     */
    protected $config;

    /**
     * 默认端口
     *
     * @var int
     */
    const DEFAULT_PORT = 0;

    /**
     * 默认连接池数量
     *
     * @var int
     */
    const DEFAULT_POOL_SIZE = 10;

    /**
     *
     * @param array|string $config 支持字符串 `127.0.0.1:3306` 或数组 `['host' => '127.0.0.1', 'port' => 3306]`
     * @param int $poolSize 不设置则使用默认值
     * @throws \Exception
     */
    public function __construct($config, $poolSize = null)
    {
        if (is_string($config))
        {
            $tmp = explode(':', $config);
            $config = [
                'host' => $tmp[0],
                'port' => isset($tmp[1]) ?: static::DEFAULT_PORT,
            ];
        }

        if (empty($config['host']))
        {
            throw new \Exception("require host option.");
        }

        if (empty($config['port']))
        {
            $config = static::DEFAULT_PORT;
        }

        $this->poolSize  = $poolSize ?: static::DEFAULT_POOL_SIZE;
        $this->taskQueue = new \SplQueue();
        $this->idlePool  = new \SplQueue();
        $this->config    = $config;

        $this->create([$this, 'connect']);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * 加入到连接池中
     *
     * @param $resource
     */
    function join($resource)
    {
        # 保存到空闲连接池中
        $this->resourcePool[spl_object_hash($resource)] = $resource;
        
        $this->release($resource);
    }

    /**
     * 失败计数
     */
    function failure()
    {
        $this->resourceNum--;
        $this->failureCount++;
    }

    /**
     * @param $callback
     */
    function create($callback)
    {
        $this->createFunction = $callback;
    }

    /**
     * 修改连接池尺寸
     *
     * @param $newSize
     */
    function setPoolSize($newSize)
    {
        $this->poolSize = $newSize;
    }

    /**
     * 移除资源
     *
     * @param $resource
     * @return bool
     */
    function remove($resource)
    {
        $rid = spl_object_hash($resource);
        if (!isset($this->resourcePool[$rid]))
        {
            return false;
        }
        # 从resourcePool中删除
        unset($this->resourcePool[$rid]);
        $this->resourceNum--;

        return true;
    }

    /**
     * 请求资源
     *
     * @param callable $callback
     * @return bool
     */
    public function request(callable $callback)
    {
        # 入队列
        $this->taskQueue->enqueue($callback);

        if (count($this->idlePool) > 0)
        {
            # 有可用资源
            $this->doTask();

            return true;
        } 
        elseif (count($this->resourcePool) < $this->poolSize and $this->resourceNum < $this->poolSize)
        {
            # 没有可用的资源, 创建新的连接
            call_user_func($this->createFunction);
            $this->resourceNum++;

            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 释放资源
     *
     * @param $resource
     */
    public function release($resource)
    {
        $this->idlePool->enqueue($resource);
        # 有任务要做
        if (count($this->taskQueue) > 0)
        {
            $this->doTask();
        }
    }

    public function isFree()
    {
        return 0 == $this->taskQueue->count() && $this->idlePool->count() == count($this->resourcePool);
    }

    protected function doTask()
    {
        $resource = null;

        # 从空闲队列中取出可用的资源
        while (count($this->idlePool) > 0)
        {
            $_resource = $this->idlePool->dequeue();
            $rid       = spl_object_hash($_resource);

            # 资源已经不可用了，连接已关闭
            if (!isset($this->resourcePool[$rid]))
            {
                continue;
            }
            else
            {
                # 找到可用连接
                $resource = $_resource;
                break;
            }
        }

        # 没有可用连接，继续等待
        if (!$resource)
        {
            if (count($this->resourcePool) == 0)
            {
                call_user_func($this->createFunction);
                $this->resourceNum++;
            }

            return;
        }
        $callback = $this->taskQueue->dequeue();
        call_user_func($callback, $resource);
    }

    /**
     * @return array
     */
    function getConfig()
    {
        return $this->config;
    }

    abstract protected function connect();

    abstract protected function close();
}
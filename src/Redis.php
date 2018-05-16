<?php
namespace MyQEE\Server;

/**
 * 轻量级Redis对象
 *
 * @package MyQEE\Server
 */
class Redis
{
    /**
     * @var \Redis
     */
    protected $_redis;
    protected $_host;
    protected $_port;
    protected $_timeout;
    protected $_retry;
    protected $_cluster;
    protected $_options = [];
    protected $_lastActivityTime = 0;

    protected static $_instance = [];

    protected static $_cleanConnectTimeTick = null;

    /**
     * 连接活动超时时间
     *
     * 超过这个时间将自动被清理掉
     *
     * @var int
     */
    public static $activityTimeout = 30;

    /**
     * 兼容支持 RedisCluster 和 Redis 参数
     *
     * ```php
     * // 集群方式 (见 RedisCluster 类)
     * $redisCluster = new Redis(null, ['10.1.1.11:6379', '10.1.1.12:6379']);
     *
     * // 单机方法
     * $redis = new Redis();
     * $redis->connect('10.1.1.11');
     * ```
     *
     * @param null $name
     * @param null $seeds
     * @param null $timeout
     * @param null $readTimeout
     * @param bool $persistent
     */
    public function __construct($name = null, $seeds = null, $timeout = null, $readTimeout = null, $persistent = false)
    {
        if (is_array($seeds))
        {
            # 集群
            $this->_redis   = new \RedisCluster($name, $seeds, $timeout, $readTimeout, $persistent);
            $this->_cluster = [$name, $seeds, $timeout, $readTimeout, $persistent];
        }
        else
        {
            $this->_redis = new \Redis();
        }

        $this->_resetOpt();
    }

    /**
     * 获取一个实例化对象
     *
     * ```php
     * $redis1 = Redis::instance();
     * $redis2 = Redis::instance();
     * $redis3 = Redis::instance('test');
     * $redis4 = Redis::instance('10.1.1.10:6379');
     * var_dump($redis1 === $redis2);      // true
     * var_dump($redis1 === $redis3);      // false
     *
     * # 设置前缀 test_
     * $redis = Redis::instance('redis://127.0.0.1:6379/test_');
     *
     * # 集群
     * $redis = Redis::instance('127.0.0.1:6379,127.0.0.1:6380');
     *
     * # 高级设置
     * 在 Server 的 config 中设置
     *
     *   redis:
     *     test:                 # 设置一个 test 的key
     *       host: 127.0.0.1
     *       port: 6379
     *       timeout: 0.5        # 连接超时
     *       retryInterval: 100  # 以毫秒为单位重试间隔
     *     cluster:
     *       host:
     *         - 127.0.0.1:6379
     *         - 127.0.0.1:6378
     *       timeout: 0.5        # 连接超时
     *
     * 然后直接通过以下方式获取对象：
     * $redis = Redis::instance('test');
     * $redis = Redis::instance('cluster');   # 连接集群
     *
     * ```
     *
     * @param string $config
     * @return static
     */
    public static function instance($config = 'default')
    {
        $key = strtolower(static::class.'.'.$config);

        if (!isset(self::$_instance[$key]))
        {
            $flag = null;
            parse:
            if (false !== strpos($config, ','))
            {
                $conf = [
                    'host' => explode(',', $config),
                ];
            }
            else
            {
                if (false !== strpos($config, ':'))
                {
                    $tmp = explode(':', $config);
                    $conf = [
                        'host' => $tmp[0],
                        'port' => (int)$tmp[1] ?: 6379,
                    ];
                }
                elseif (substr($config, 0, 8) === 'redis://')
                {
                    $conf = parse_url($config);
                    if (!$conf)
                    {
                        throw new \Exception("Redis解析配置 $config 错误");
                    }
                }
                elseif ($flag === null)
                {
                    if (!isset(Server::$instance->config['redis'][$config]))
                    {
                        throw new \Exception("Redis配置 $config 不存在");
                    }

                    $conf = Server::$instance->config['redis'][$config];

                    if (is_string($conf))
                    {
                        # 字符串类型的再解析一次
                        $flag   = true;
                        $config = $conf;
                        $conf   = null;
                        goto parse;
                    }
                }
                elseif (is_string($config))
                {
                    $conf = [
                        'host' => $config,
                        'port' => 6379,
                    ];
                }
                else
                {
                    throw new \Exception("Redis配置 $config 设置错误");
                }
            }

            if (is_array($conf['host']))
            {
                # 集群模式
                $conf += [
                    'name'        => null,
                    'timeout'     => null,
                    'readTimeout' => null,
                    'persistent'  => false,
                ];
                $redis = new static($conf['name'], $conf['host'], $conf['timeout'], $conf['readTimeout'], $conf['persistent']);
            }
            else
            {
                # 单机模式
                $conf += [
                    'name'          => null,
                    'port'          => 6379,
                    'timeout'       => 0,
                    'retryInterval' => 0,
                ];
                $redis = new static();
                $redis->connect($conf['host'], $conf['port'], $conf['timeout'], $conf['retryInterval']);
            }

            self::$_instance[$key] = $redis;

            if (!self::$_cleanConnectTimeTick)
            {
                # 增加一个清理连接的对象
                self::$_cleanConnectTimeTick = Server::$instance->tick(1000 * 60, function($tick)
                {
                    $time = microtime(true);
                    foreach (self::$_instance as $k => $redis)
                    {
                        /**
                         * @var Redis $redis
                         */
                        if (($useTime = $time - $redis->_lastActivityTime) > self::$activityTimeout)
                        {
                            # 10秒没有工作, 自动关闭连接
                            $redis->close();
                            Server::$instance->debug("Redis {$redis->_host}:{$redis->_port} 已经超过 {$useTime}s 没有操作，已自动关闭连接");
                            unset(self::$_instance[$k]);
                        }
                    }

                    if (empty(self::$_instance))
                    {
                        if (Server::$instance->clearTick($tick))
                        {
                            self::$_cleanConnectTimeTick = null;
                            Server::$instance->debug("自动移除了用于清理长时间不使用的Redis定时器");
                        }
                    }
                });
                Server::$instance->debug("自动增加了一个用于清理长时间不使用的Redis定时器");
            }
        }

        return self::$_instance[$key];
    }

    /**
     * @param string $host
     * @param int    $port
     * @param float  $timeout
     * @param int    $retryInterval
     * @return bool
     */
    public function connect($host, $port = 6379, $timeout = 0.0, $retryInterval = 0)
    {
        # 集群模式不可以单独再连接
        if (null !== $this->_cluster)
        {
            return false;
        }

        $this->_host    = $host;
        $this->_port    = $port;
        $this->_timeout = $timeout;
        $this->_retry   = $retryInterval;

        $rs = $this->_redis->connect($host, $port, $timeout, $retryInterval);
        if ($rs)
        {
            $this->_lastActivityTime = microtime(true);
            $this->_resetOpt();
        }
        return $rs;
    }

    public function close()
    {
        $this->_redis->close();
    }

    /**
     * @param string $key
     * @return bool|string
     * @throws \RedisException
     */
    public function get($key)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->get($key);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis get $key, 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->get($key);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @return bool|string
     * @throws \RedisException
     */
    public function delete($key1, $key2 = null, $key3 = null, $key4 = null)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->delete(is_array($key1) ? $key1 : func_get_args());
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis delete ". implode(', ', is_array($key1) ? $key1 : func_get_args()) .", 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->delete(is_array($key1) ? $key1 : func_get_args());
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @param string $value
     * @param int    $timeout
     * @return bool
     * @throws \RedisException
     */
    public function set($key, $value, $timeout = 0)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->set($key, $value, $timeout);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis set $key, $value, $timeout 时间过长，耗时: {$useTime}s");
            }
            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->set($key, $value, $timeout);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @param string $hashKey
     * @param int    $value
     * @return int
     * @throws \RedisException
     */
    public function hIncrBy($key, $hashKey, $value = 1)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->hIncrBy($key, $hashKey, $value);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis hIncrBy $key, $hashKey, $value 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->hIncrBy($key, $hashKey, $value);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @param string $hashKey
     * @return string
     * @throws \RedisException
     */
    public function hGet($key, $hashKey)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->hGet($key, $hashKey);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis hGet $key, $hashKey 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->hGet($key, $hashKey);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param $key
     * @return array
     * @throws \RedisException
     */
    public function hGetAll($key)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->hGetAll($key);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis hGetAll $key 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->hGetAll($key);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @param string $hashKey
     * @param string $value
     * @return int
     * @throws \RedisException
     */
    public function hSet($key, $hashKey, $value)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->hSet($key, $hashKey, $value);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis hSet $key, $hashKey, $value 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->hSet($key, $hashKey, $value);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @param int    $ttl
     * @return bool
     * @throws \RedisException
     */
    public function expire($key, $ttl)
    {
        $this->_lastActivityTime = microtime(true);
        try
        {
            $rs = $this->_redis->expire($key, $ttl);
            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis expire $key, $ttl 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                return $this->_redis->expire($key, $ttl);
            }
            else
            {
                throw $e;
            }
        }
    }

    /**
     * @param string $name
     * @param string $value
     * @return bool
     * @throws \RedisException
     */
    public function setOption($name, $value)
    {
        $this->_options[$name] = $value;

        return $this->_redis->setOption($name, $value);
    }

    protected function _reConnect()
    {
        if (null !== $this->_cluster)
        {
            try
            {
                list($name, $seeds, $timeout, $readTimeout, $persistent) = $this->_cluster;

                $redis        = new \RedisCluster($name, $seeds, $timeout, $readTimeout, $persistent);
                $this->_redis = $redis;
                $this->_resetOpt();
                $this->_lastActivityTime = microtime(true);
                return true;
            }
            catch (\RedisException $e)
            {
                Server::$instance->warn('连接 Redis 失败 :'. implode(', ', $seeds) .'. err: '. $e->getMessage());
                return false;
            }
        }
        else
        {
            $rs = $this->_redis->connect($this->_host, $this->_port, $this->_timeout, $this->_retry);
            if ($rs)
            {
                $this->_resetOpt();
                $this->_lastActivityTime = microtime(true);
            }
            else
            {
                Server::$instance->warn("连接 Redis 失败 : {$this->_host}:{$this->_port}");
            }
        }
    }

    public function __get($name)
    {
        return $this->_redis->$name;
    }

    public function __call($name, $arguments)
    {
        $this->_lastActivityTime = microtime(true);

        try
        {
            switch (count($arguments))
            {
                case 0:
                    $rs = $this->_redis->$name();
                    break;

                case 1:
                    $rs = $this->_redis->$name($arguments[0]);
                    break;

                case 2:
                    $rs = $this->_redis->$name($arguments[0], $arguments[1]);
                    break;

                case 3:
                    $rs = $this->_redis->$name($arguments[0], $arguments[1], $arguments[2]);
                    break;

                default:
                    $rs = call_user_func_array([$this->_redis, $name], $arguments);
                    break;
            }

            if (($useTime = microtime(true) - $this->_lastActivityTime) > 1)
            {
                Server::$instance->warn("Redis $name ". explode(', ', $arguments) ." 时间过长，耗时: {$useTime}s");
            }

            return $rs;
        }
        catch (\RedisException $e)
        {
            if ($this->_reConnect())
            {
                switch (count($arguments))
                {
                    case 0:
                        return $this->_redis->$name();

                    case 1:
                        return $this->_redis->$name($arguments[0]);

                    case 2:
                        return $this->_redis->$name($arguments[0], $arguments[1]);

                    case 3:
                        return $this->_redis->$name($arguments[0], $arguments[1], $arguments[2]);

                    default:
                        return call_user_func_array([$this->_redis, $name], $arguments);
                }
            }
            else
            {
                throw $e;
            }
        }
    }

    public function ping()
    {
        return $this->_redis->ping();
    }

    protected function _resetOpt()
    {
        if ($this->_options)foreach ($this->_options as $k => $v)
        {
            $this->_redis->setOption($k, $v);
        }
    }
}
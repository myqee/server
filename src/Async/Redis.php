<?php
namespace MyQEE\Server\Async;

class Redis extends Pool
{
    const DEFAULT_PORT = 6379;

    protected function connect()
    {
        $redis = new \Swoole\Redis();
        $redis->on('close', function($redis)
        {
            $this->remove($redis);
        });

        return $redis->connect($this->config['host'], $this->config['port'], function($redis, $result)
        {
            if ($result)
            {
                $this->join($redis);
            }
            else
            {
                $this->failure();
                trigger_error("connect to redis server[{$this->config['host']}:{$this->config['port']}] failed. Error: {$redis->errMsg}[{$redis->errCode}].");
            }
        });
    }

    /**
     * 关闭连接池
     */
    public function close()
    {
        foreach ($this->resourcePool as $conn)
        {
            /**
             * @var $conn \Swoole\Redis
             */
            $conn->close();
        }
    }

    function __call($call, $params)
    {
        return $this->request(function(\Swoole\Redis $redis) use ($call, $params)
        {
            call_user_func_array([$redis, $call], $params);
            //必须要释放资源，否则无法被其他重复利用
            $this->release($redis);
        });
    }
}
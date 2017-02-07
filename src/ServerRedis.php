<?php
namespace MyQEE\Server;

use \Swoole\Redis\Server as RedisServer;

/**
 * 服务器对象
 *
 * 主端口可同时支持 WebSocket, Http 协议, 并可以额外监听TCP新端口
 *
 * @package MyQEE\Server
 */
class ServerRedis extends Server
{
    public function bind()
    {
        parent::bind();

        # Redis
        if (self::$serverType === 4)
        {
            foreach (get_class_methods($this) as $method)
            {
                if (substr($method, 0, 5) === 'bind_')
                {
                    $act = substr($method, 5);

                    # 批量绑定
                    self::$server->setHandler($act, [$this, $method]);
                }
            }
        }
    }

    public function bind_GET($fd, $data)
    {
        if (count($data) == 0)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'GET' command");
        }

        $rs = self::$worker->get($data[0]);

        if (empty($rs))
        {
            return RedisServer::format(RedisServer::NIL);
        }
        elseif (is_int($rs))
        {
            return RedisServer::format(RedisServer::INT, $rs);
        }
        else
        {
            return RedisServer::format(RedisServer::STRING, $rs);
        }
    }

    public function bind_SET($fd, $data)
    {
        if (count($data) < 2)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'SET' command");
        }
        $key   = $data[0];
        $value = $data[1];
        $rs    = self::$worker->set($key, $value);

        if ($rs)
        {
            return RedisServer::format(RedisServer::STATUS, 'OK');
        }
        else
        {
            return RedisServer::format(RedisServer::ERROR, "ERR set fail");
        }
    }

    public function bind_sAdd($fd, $data)
    {
        if (count($data) < 2)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'sAdd' command");
        }

        $key   = array_shift($data);
        $count = self::$worker->sAdd($key, $data);

        if (false === $count)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR get sMembers");
        }

        return RedisServer::format(RedisServer::INT, $count);
    }

    public function bind_sMembers($fd, $data)
    {
        if (count($data) < 1)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'sMembers' command");
        }

        $rs = self::$worker->sMembers($data[0]);

        if (false === $rs)
        {
            return RedisServer::format(RedisServer::ERROR, " sMembers fail");
        }

        if (!$rs)
        {
            return RedisServer::format(RedisServer::NIL);
        }

        return RedisServer::format(RedisServer::SET, $rs);
    }

    public function bind_hSet($fd, $data)
    {
        if (count($data) < 3)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'hSet' command");
        }
        $rs = self::$worker->hSet($data[0], $data[1], $data[2]);

        if (false === $rs)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR hSet fail");
        }

        return RedisServer::format(RedisServer::INT, $rs);
    }

    public function bind_hGet($fd, $data)
    {
        if (count($data) < 2)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'hSet' command");
        }
        $rs = self::$worker->hGet($data[0], $data[1]);

        if (false === $rs)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR hSet fail");
        }

        if (empty($rs))
        {
            return RedisServer::format(RedisServer::NIL);
        }
        elseif (is_int($rs))
        {
            return RedisServer::format(RedisServer::INT, $rs);
        }
        else
        {
            return RedisServer::format(RedisServer::STRING, $rs);
        }
    }

    public function bind_hGetAll($fd, $data)
    {
        if (count($data) < 1)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR wrong number of arguments for 'hGetAll' command");
        }

        $rs = self::$worker->hGetAll($data[0]);

        if (false === $rs)
        {
            return RedisServer::format(RedisServer::ERROR, "ERR hGetAll fail");
        }

        return RedisServer::format(RedisServer::MAP, $rs);
    }
}

<?php
namespace MyQEE\Server;

use \Swoole\Redis\Server as SRS;

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
        if ($this->serverType === 4)
        {
            foreach (get_class_methods($this) as $method)
            {
                if (substr($method, 0, 5) === 'bind_')
                {
                    $act = substr($method, 5);

                    # 批量绑定
                    $this->server->setHandler($act, [$this, $method]);
                }
            }
        }
    }

    public function bind_GET($fd, $data)
    {
        if (count($data) == 0)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'GET' command");
        }

        $rs = $this->worker->get($data[0]);

        if (empty($rs))
        {
            return SRS::format(SRS::NIL);
        }
        elseif (is_int($rs))
        {
            return SRS::format(SRS::INT, $rs);
        }
        else
        {
            return SRS::format(SRS::STRING, $rs);
        }
    }

    public function bind_SET($fd, $data)
    {
        if (count($data) < 2)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'SET' command");
        }
        $key   = $data[0];
        $value = $data[1];
        $rs    = $this->worker->set($key, $value);

        if ($rs)
        {
            return SRS::format(SRS::STATUS, 'OK');
        }
        else
        {
            return SRS::format(SRS::ERROR, "ERR set fail");
        }
    }

    public function bind_sAdd($fd, $data)
    {
        if (count($data) < 2)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'sAdd' command");
        }

        $key   = array_shift($data);
        $count = $this->worker->sAdd($key, $data);

        if (false === $count)
        {
            return SRS::format(SRS::ERROR, "ERR get sMembers");
        }

        return SRS::format(SRS::INT, $count);
    }

    public function bind_sMembers($fd, $data)
    {
        if (count($data) < 1)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'sMembers' command");
        }

        $rs = $this->worker->sMembers($data[0]);

        if (false === $rs)
        {
            return SRS::format(SRS::ERROR, " sMembers fail");
        }

        if (!$rs)
        {
            return SRS::format(SRS::NIL);
        }

        return SRS::format(SRS::SET, $rs);
    }

    public function bind_hSet($fd, $data)
    {
        if (count($data) < 3)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'hSet' command");
        }
        $rs = $this->worker->hSet($data[0], $data[1], $data[2]);

        if (false === $rs)
        {
            return SRS::format(SRS::ERROR, "ERR hSet fail");
        }

        return SRS::format(SRS::INT, $rs);
    }

    public function bind_hGet($fd, $data)
    {
        if (count($data) < 2)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'hSet' command");
        }
        $rs = $this->worker->hGet($data[0], $data[1]);

        if (false === $rs)
        {
            return SRS::format(SRS::ERROR, "ERR hSet fail");
        }

        if (empty($rs))
        {
            return SRS::format(SRS::NIL);
        }
        elseif (is_int($rs))
        {
            return SRS::format(SRS::INT, $rs);
        }
        else
        {
            return SRS::format(SRS::STRING, $rs);
        }
    }

    public function bind_hGetAll($fd, $data)
    {
        if (count($data) < 1)
        {
            return SRS::format(SRS::ERROR, "ERR wrong number of arguments for 'hGetAll' command");
        }

        $rs = $this->worker->hGetAll($data[0]);

        if (false === $rs)
        {
            return SRS::format(SRS::ERROR, "ERR hGetAll fail");
        }

        return SRS::format(SRS::MAP, $rs);
    }
}

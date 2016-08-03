<?php
namespace MyQEE\Server\Register;

use MyQEE\Server\RPC;
use MyQEE\Server\Server;
use MyQEE\Server\WorkerTCP;
use MyQEE\Server\Clusters\Host;

/**
 * 注册服务器进程对象
 *
 * @package MyQEE\Server\Register
 */
class WorkerMain extends WorkerTCP
{
    /**
     * 最大服务器数
     *
     * @var int
     */
    public $maxCount = 0;

    /**
     * 是否自增编号
     *
     * @var bool
     */
    public $isIncrementID = false;

    public function onReceive($server, $fd, $fromId, $data)
    {
        $data = trim($data);
        if ($data === '')return;

        /**
         * @var \Swoole\Server $server
         */
        $tmp = @msgpack_unpack($data);

        if (is_object($tmp) && $tmp instanceof \stdClass)
        {
            # 解密数据
            $tmp = RPC::decryption($tmp);

            if (false === $tmp)
            {
                # 数据错误
                $server->send($fd, '{"status":"error","type":"decryption"}'."\r\n");
                $server->close($fd, $fromId);
                Server::debug("register server decryption error, data: ". substr($data, 0, 256) . (strlen($data) > 256 ? '...' :''));

                return;
            }

            $data = $tmp;
            unset($tmp);

            if ($data instanceof Host)
            {
                $this->registerHost($fd, $data, $fromId);
                return;
            }
            else
            {
                $server->send($fd, '{"status":"error","type":"unknown object"}'."\r\n");
                $server->close($fd, $fromId);
            }
        }
        else
        {
            Server::debug("get error msgpack data, data: ". substr($data, 0, 256) . (strlen($data) > 256 ? '...' :''));
            return;
        }
    }

    public function onClose($server, $fd, $fromId)
    {
        $host = Host::getHostByFd($fd);

        if ($host)
        {
            # 已经有连接上的服务器
            Server::info("host#{$host->id}({$host->ip}:{$host->port}) close connection.");
            $host->remove();

            # 移除服务
            $this->notifyRemoveServer($host->id);
        }
    }

    /**
     * 发送数据
     *
     * @param $fd
     * @param $data
     * @return bool
     */
    protected function send($fd, $data)
    {
        return $this->server->send($fd, RPC::encrypt($data));
    }

    /**
     * 注册服务器
     *
     * @param \Swoole\Server $server
     * @param $fd
     * @param $data
     * @return bool
     */
    protected function registerHost($fd, Host $data, $fromId)
    {
        if (!$data->port)
        {
            $this->server->send($fd, '{"status":"error","message":"miss port"}');
            $this->server->close($fd, $fromId);
            return false;
        }

        if (!$data->ip)
        {
            # 没设置则根据连接时的IP来设置
            $data->ip = $this->server->connection_info($fd)['remote_ip'];
        }

        if (is_numeric($data->id) && $data->id >= 0)
        {
            $hostId = (int)$data->id;

            if (($old = Host::get($hostId)) && !$old->removed)
            {
                # 指定的服务器ID已经存在, 则不让注册
                $this->server->send($fd, '{"status":"error","message":"already exists"}');
                $this->server->close($fd, $fromId);
                Server::debug("register server error, server {$hostId} already exists.");

                return false;
            }
        }
        else
        {
            # 自动分配ID
            if (false === ($hostId = Host::getNewHostId()))
            {
                $this->server->send($fd, '{"status":"error","message":"can not assignment id"}');
                $this->server->close($fd, $fromId);

                return false;
            }
        }

        # 更新服务器ID
        $data->id    = $hostId;
        $data->key   = self::random(32);
        $data->fd    = $fd;
        # 返回参数
        $rs          = new \stdClass();
        $rs->type    = 'reg.ok';            // 数据类型
        $rs->id      =  $hostId;            // 唯一ID
        $rs->key     = $data->key;          // 通讯密钥
        $rs->ip      = $data->ip;           // IP
        $rs->hosts   = Host::getAll();      // 已经连上的服务器列表

        # 保存数据
        Host::$table->set($this->id, $data->asArray());
        Host::$fdToIdTable->set($fd, ['id' => $data->id]);

        # 发送
        if ($this->send($fd, $rs))
        {
            Server::info("register a new host#{$data->id}: {$data->ip}:{$data->port}");

            $this->notifyAddServer($data);
        }

        return true;
    }

    /**
     * 通知所有服务器移除一个Server
     *
     * @param $hostId
     */
    protected function notifyRemoveServer($hostId)
    {
        $data       = new \stdClass();
        $data->type = 'remove';
        $data->id   = $hostId;

        foreach (Host::$table as $item)
        {
            $this->send($item['fd'], $data);
        }
    }

    /**
     * 通知所有服务器移除一个Server
     *
     * @param Host $host
     */
    protected function notifyAddServer(Host $host)
    {
        $data         = new \stdClass();
        $data->type   = 'add';
        $data->server = $host;

        foreach (Host::$table as $item)
        {
            # 刚刚添加的不需要通知
            if ($item['id'] === $data->server->id)continue;

            $this->send($host->fd, $data);
        }
    }

    /**
     * 返回一个随机字符串
     *
     * @param int $length
     * @return string
     */
    protected static function random($length)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pool = str_split($pool, 1);
        $max  = count($pool) - 1;
        $str  = '';

        for($i = 0; $i < $length; $i++)
        {
            $str .= $pool[mt_rand(0, $max)];
        }

        if ($length > 1)
        {
            if (ctype_alpha($str))
            {
                $str[mt_rand(0, $length - 1)] = chr(mt_rand(48, 57));
            }
            elseif (ctype_digit($str))
            {
                $str[mt_rand(0, $length - 1)] = chr(mt_rand(65, 90));
            }
        }

        return $str;
    }
}
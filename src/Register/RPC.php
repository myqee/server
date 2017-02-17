<?php
namespace MyQEE\Server\Register;

use MyQEE\Server\Clusters\Host;
use MyQEE\Server\RPC\Result;
use MyQEE\Server\Server;

/**
 * 暴露给客户端用的方法, 参数
 */
class RPC extends \MyQEE\Server\RPC
{
    public static $RPC_KEY = __FILE__;

    /**
     * 注册一个新服务器
     *
     * @param Host $host
     * @return bool|\stdClass|Result
     */
    public function reg(Host $host)
    {
        if (!$host->port)
        {
            $this->closeClient('host miss port');
            return false;
        }

        if (!$host->group)
        {
            $host->group = 'default';
        }

        # 获取连接信息
        $connection = $this->connectionInfo();

        # 没有获取到连接信息
        if (!$connection)return false;

        if (!$host->ip)
        {
            # 没设置则根据连接时的IP来设置
            $host->ip = $connection['remote_ip'];
        }

        if (is_numeric($host->id) && $host->id >= 0)
        {
            $hostId = (int)$host->id;

            if (($old = Host::get($hostId, $host->group)) && !$old->removed)
            {
                # 指定的服务器ID已经存在, 则不让注册
                $this->closeClient('already exists');
                Server::$instance->debug("register server error, server {$hostId} already exists.");

                return false;
            }
        }
        else
        {
            # 自动分配ID
            $hostId = false;

            # 在移除的服务器列表中找
            foreach (Host::$table as $k => $v)
            {
                if ($v['removed'])
                {
                    if ($v['ip'] === $host->ip && $v['port'] == $host->port)
                    {
                        $hostId = $v['id'];
                        break;
                    }
                }
            }

            if (false === $hostId && false === ($hostId = Host::getNewHostId($host->group)))
            {
                $this->closeClient('can not assignment id');

                return false;
            }
        }

        # 更新服务器ID
        $host->id     = $hostId;
        $host->key    = $host->encrypt ? self::random(32) : '';
        $host->fd     = $connection['fd'];
        $host->fromId = $connection['from_id'];

        # 返回参数
        $rs        = new \stdClass();
        $rs->id    = $hostId;             // 唯一ID
        $rs->key   = $host->key;          // 通讯密钥
        $rs->ip    = $host->ip;           // IP
        $rs->hosts = Host::getAll();      // 已经连上的服务器列表

        # 保存数据
        $host->save();
        Host::$fdToIdTable->set($host->fd, ['id' => $host->id, 'group' => $host->group]);

        # 返回一个高级对象
        $rpcRs       = new Result();
        $rpcRs->data = $rs;
        $rpcRs->on('success', function() use ($host)
        {
            Server::$instance->info("register a new host#{$host->id}: {$host->ip}:{$host->port}");

            $class = get_class($this);

            # 通知所有 RPC 客户端增加服务器
            foreach (Host::$table as $item)
            {
                # 已被标志为移除的
                if ($item['removed'] > 0)continue;

                # 刚刚添加的不需要通知
                if ($item['group'] === $host->group && $item['id'] === $host->id)continue;

                $rpc   = new $class($item['fd'], $item['from_id']);
                /**
                 * @var RPC $rpc
                 */
                $rpc->trigger('server.add', $host);
            }
        });

        return $rpcRs;
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
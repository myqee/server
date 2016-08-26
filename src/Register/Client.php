<?php

namespace MyQEE\Server\Register;

use MyQEE\Server\Server;
use MyQEE\Server\Clusters;
use MyQEE\Server\Clusters\Host;

/**
 * 注册服务器客户端
 *
 * @package MyQEE\Server\Register
 */
class Client
{
    /**
     * 当前服务器对象
     *
     * @var Host
     */
    public static $host;

    /**
     * @var RPC|\MyQEE\Server\RPC\Client
     */
    protected static $rpc;

    /**
     * 注册服务器
     *
     * MyQEE\Server\RPC\Client::init();
     */
    public static function init($group, $id = -1, $isTask = false)
    {
        self::$host            = new Host();
        self::$host->group     = $isTask ? "$group.task": $group;
        self::$host->id        = $id;
        self::$host->workerNum = $isTask ? Server::$config['swoole']['task_worker_num'] : Server::$server->setting['worker_num'];
        self::$host->port      = $isTask ? Server::$config['clusters']['task_port'] : Server::$config['clusters']['port'];
        self::$host->encrypt   = Server::$config['clusters']['encrypt'] ? true : false;

        if (Server::$config['clusters']['ip'] && Server::$config['clusters']['ip'] !== '0.0.0.0')
        {
            self::$host->ip = Server::$config['clusters']['ip'];
        }

        $rpc       = RPC::Client();
        self::$rpc = $rpc;

        # 定义回调方法
        $rpc->on('connect',       [static::class, 'onConnect']);
        $rpc->on('server.add',    [static::class, 'onServerAdd']);
        $rpc->on('server.remove', [static::class, 'onServerRemove']);

        $rpc->connect(Server::$config['clusters']['register']['ip'], Server::$config['clusters']['register']['port']);
    }


    /**
     * 连接上服务器回调
     */
    public static function onConnect()
    {
        try
        {
            /**
             * 注册服务器
             */
            $rs = self::$rpc->Reg(self::$host);

            if ($rs)
            {
                # 返回成功
                self::$host->key = $rs->key;
                self::$host->ip  = $rs->ip;
                self::$host->id  = $rs->id;

                if (is_array($rs->hosts) && $rs->hosts)foreach ($rs->hosts as $host)
                {
                    /**
                     * @var Host $host
                     */
                    $host->save();
                }

                # 保存数据, 其它 worker 进程就可以使用了
                self::$host->save();

                # 更新时间
                Host::$lastChangeTime->set(time());

                \MyQEE\Server\Server::$instance->info('register clusters host group: '. self::$host->group .'#'. self::$host->id .'(' . self::$host->ip . ':' . self::$host->port . ') success.');
            }
            else
            {
                throw new \Exception('result error.');
            }
        }
        catch (\Exception $e)
        {
            \MyQEE\Server\Server::$instance->warn('register clusters host('. self::$host->ip . ':' . self::$host->port .') fail. '. $e->getMessage());
        }
    }

    /**
     * 添加一个HOST
     *
     * @param Host $host
     */
    public static function onServerAdd($host)
    {
        Host::$lastChangeTime->set(time());

        $host->save();
    }

    /**
     * 移除一个HOST
     *
     * @param int $key
     */
    public static function onServerRemove($group, $id)
    {
        # 移除信息
        Host::$lastChangeTime->set(time());

        Host::$table->del("{$group}_{$id}");
    }
}
<?php

namespace MyQEE\Server\Register;

use MyQEE\Server\Server;
use MyQEE\Server\RPC;
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
    protected $host;

    /**
     * 连接客户端
     *
     * @var \Swoole\Client
     */
    protected $client;

    public function __construct()
    {
        $this->host            = new Host();
        $this->host->id        = isset(Server::$config['clusters']['id']) && Server::$config['clusters']['id'] >= 0 ? (int)Server::$config['clusters']['id'] : -1;
        $this->host->workerNum = Server::$instance->server->setting['worker_num'];
        $this->host->taskNum   = Server::$config['swoole']['task_worker_num'];
        $this->host->port      = Server::$config['clusters']['port'];
        $this->host->taskPort  = Server::$config['clusters']['task_port'];
        $this->host->encrypt   = Server::$config['clusters']['encrypt'] ? true : false;

        if (Server::$config['clusters']['ip'])
        {
            $this->host->ip = Server::$config['clusters']['ip'];
        }
    }

    /**
     * 注册服务器
     */
    public function init()
    {
        # 开启一个异步客户端
        $this->client = new \Swoole\Client(SWOOLE_TCP, SWOOLE_SOCK_ASYNC);

        $this->client->on('receive', [$this, 'onReceive']);
        $this->client->on('error',   [$this, 'onError']);
        $this->client->on('connect', [$this, 'onConnect']);
        $this->client->on('close',   [$this, 'onClose']);

        $this->client->connect(Server::$config['clusters']['register']['ip'], Server::$config['clusters']['register']['port']);

        # 发心跳包
        swoole_timer_tick(10000, function()
        {
            if ($this->client)
            {
                static $i = 0;
                $i++;

                if ($i === 100)
                {
                    $i = 0;
                    $this->client->send("\0\r\n");
                }
                else
                {
                    $this->client->send("\0");
                }
            }
        });
    }

    /**
     * 收到消息回调
     *
     * @param \Swoole\Client $cli
     * @param $data
     */
    public function onReceive($cli, $data)
    {
        if ($data[0] === '{')
        {
            # json 格式
            $tmp = json_decode($data, true);
            if ($tmp)
            {
                if ($tmp['status'] === 'error')
                {
                    Server::warn('register server error: '. $tmp['type']);
                    return;
                }
                else
                {
                    # 不应该会出现的情况
                    Server::warn('unknown status. data: '. $data);
                    return;
                }
            }
            else
            {
                Server::warn('register server error, can not parse data: '. $data);
                return;
            }
        }

        $tmp = RPC::decryption(@msgpack_unpack($data));

        if (!$tmp)
        {
            Server::warn('decryption data fail. data: ' . $data);
            return;
        }

        switch ($tmp->type)
        {
            case 'reg.ok':
                # 注册成功
                $this->host->id  = $tmp->id;
                $this->host->ip  = $tmp->ip;
                $this->host->key = $tmp->key;

                Server::info('register host success.');

                # 更新内存表设置
                $this->host->save();

                # 将服务器上返回的服务器列表加入到内容表中
                if ($tmp->hosts && is_array($tmp->hosts))foreach ($tmp->hosts as $host)
                {
                    /**
                     * @var Host $host
                     */
                    $host->save();
                }
                break;

            case 'remove':
                # 移除一个服务器
                Host::$table->del($tmp->id);
                break;

            case 'add':
                # 添加一个服务器
                $tmp->server->save();
                break;
        }
    }

    /**
     * 到注册服务器里同步服务器列表
     */
    protected function syncServerList()
    {

    }

    /**
     * @param \Swoole\Client $cli
     */
    public function onConnect($cli)
    {
        $cli->send(RPC::encrypt($this->host) ."\r\n");
    }

    /**
     * @param \Swoole\Client $cli
     */
    public function onClose($cli)
    {
        $this->client = null;

        swoole_timer_after(3000, function()
        {
            # 断开后重新连接
            $this->init();
        });
    }

    /**
     * @param \Swoole\Client $cli
     */
    public function onError($cli)
    {
        $this->client = null;

        Server::warn("register host error: " . swoole_strerror($cli->errCode) .' - '. Server::$config['clusters']['register']['ip'] .':'. Server::$config['clusters']['register']['port']);

        swoole_timer_after(3000, function()
        {
            $this->init();
        });
    }
}
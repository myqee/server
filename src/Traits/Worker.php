<?php
namespace MyQEE\Server\Traits;

use \MyQEE\Server\Server;

trait Worker
{
    /**
     * 当前进程的唯一ID
     *
     * @var int
     */
    public $id;

    /**
     * 当前进程的服务器ID
     *
     * @var int
     */
    public $serverId;

    /**
     * @var \Swoole\Server|\Swoole\Http\Server|\Swoole\Websocket\Server
     */
    public $server;

    /**
     * 服务器设置
     *
     * @var array
     */
    public $setting = [];

    /**
     * 当前进程启动时间
     *
     * @var int
     */
    protected static $startTime;

    /**
     * 服务器名
     *
     * @var string
     */
    public static $serverName;

    /**
     * WorkerBase constructor.
     *
     * @param \Swoole\Server $server
     */
    public function __construct($server, $name, $workerId)
    {
        self::$startTime  = time();
        $this->server     = $server;
        $this->name       = $name;
        $this->id         = $workerId;
        self::$serverName = Server::$config['server']['host'] . ':' . Server::$config['server']['port'];

        if ($this instanceof \MyQEE\Server\WorkerTask)
        {
            # 任务进程，有一个 taskId
            $this->taskId = $workerId - $server->setting['worker_num'];
        }

        $this->setting = Server::$config['hosts'][$this->name];

    }

    /**
     * 向任意 worker 进程或者 task 进程发送消息
     *
     * 不可以向自己投递，它支持服务器集群下向任意集群去投递数据
     *
     * @param        $data
     * @param int    $workerId
     * @param int    $serverId
     * @param string $serverGroup
     * @return bool
     */
    public function sendMessage($data, $workerId, $serverId = -1, $serverGroup = null)
    {
        if ($serverId < 0 || Server::$clustersType === 0 || ($this->serverId === $serverId && null === $serverGroup))
        {
            # 没有指定服务器ID 或者 本服务器 或 非集群模式

            if ($workerId === $this->id)
            {
                # 自己调自己
                $this->onPipeMessage($this->server, $this->id, $data, $serverId);

                return true;
            }
            else if ($this->name !== Server::$mainHostKey || !is_string($data))
            {
                $obj = new \stdClass();
                $obj->_sys = true;
                $obj->name = $this->name;
                $obj->sid  = Server::$serverId;
                $obj->data = $data;
                $data      = serialize($obj);
            }

            return $this->server->sendMessage($data, $workerId);
        }
        else
        {
            $client = \MyQEE\Server\Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)return false;

            return $client->sendData('msg', $data, $this->name);
        }
    }

    /**
     * 向所有 worker 进程发送数据
     *
     * 有任何失败将会抛出错误
     *
     * @todo 暂时不支持给集群里其它服务器所有进程发送消息
     * @param     $data
     * @param int $workerType 进程类型 0: 全部进程， 1: 仅仅 worker 进程, 2: 进程 task 进程
     * @return bool
     * @throws \Exception
     */
    public function sendMessageToAllWorker($data, $workerType = 0)
    {
        $i         = 0;
        $workerNum = $this->server->setting['worker_num'] + $this->server->setting['task_worker_num'];

        switch ($workerType)
        {
            case 1:
                $workerNum = $this->server->setting['worker_num'];
                break;
            case 2:
                $i = $this->server->setting['worker_num'];
                break;
            case 0:
            default:
                break;
        }

        while ($i < $workerNum)
        {
            if (!$this->sendMessage($data, $i))
            {
                throw new \Exception('worker id:' . $i . ' is send message fail!');
            }

            $i++;
        }

        return true;
    }

    /**
     * 退出程序是回调
     */
    public function onStop()
    {
        self::debug("Worker{$this->name}#{$this->id} Stop, pid: {$this->server->worker_pid}");
    }

    /**
     * 进程启动后执行 (空方法, 可自行扩展)
     *
     */
    public function onStart()
    {
        self::debug("Worker{$this->name}#{$this->id} Start, pid: {$this->server->worker_pid}");
    }

    /**
     * 接受到任意进程的调用(空方法)
     *
     * @param \Swoole\Server $server
     * @param $fromWorkerId
     * @param $message
     * @return void
     */
    public function onPipeMessage($server, $fromWorkerId, $message, $fromServerId = -1)
    {

    }


    /**
     * 输出自定义log
     *
     * @param string $label
     * @param string|array $info
     * @param string $type
     * @param string $color
     */
    public function log($label, array $data = null, $type = 'other', $color = '[36m')
    {
        Server::$instance->log($label, $data, $type, $color);
    }

    /**
     * 错误信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function warn($labelOrData, array $data = null)
    {
        Server::$instance->log($labelOrData, $data, 'warn', '[31m');
    }

    /**
     * 输出信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function info($labelOrData, array $data = null)
    {
        Server::$instance->log($labelOrData, $data, 'info', '[33m');
    }

    /**
     * 调试信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function debug($labelOrData, array $data = null)
    {
        Server::$instance->log($labelOrData, $data, 'debug', '[34m');
    }

    /**
     * 跟踪信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function trace($labelOrData, array $data = null)
    {
        Server::$instance->log($labelOrData, $data, 'trace', '[35m');
    }
}
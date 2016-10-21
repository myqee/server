<?php
namespace MyQEE\Server;

class WorkerTask
{
    /**
     * 当前进程的唯一ID
     *
     * @var int
     */
    public $id;

    /**
     * 任务序号, 从0开始
     *
     * @var int
     */
    public $taskId;

    /**
     * 当前进程的服务器ID
     *
     * @var int
     */
    public $serverId;

    /**
     * @var \Swoole\Server
     */
    protected $server;

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
    protected static $serverName;

    /**
     * WorkerBase constructor.
     *
     * @param \Swoole\Server $server
     */
    public function __construct($server)
    {
        $this->server     = $server;
        self::$startTime  = time();
        self::$serverName = Server::$config['server']['host'] . ':' . Server::$config['server']['port'];
    }

    /**
     * 给投递者返回信息
     *
     * @param $rs
     */
    public function finish($rs)
    {
        if (Server::$clustersType < 2)
        {
            # 没有指定服务器ID 或者 非集群模式
            $this->server->finish($rs);
        }
        else
        {

        }
    }

    /**
     * 对象启动(空方法)
     */
    public function onStart()
    {

    }

    /**
     * 退出程序是回调
     */
    public function onStop()
    {
        self::debug("Task#{$this->taskId} Stop, pid: {$this->server->worker_pid}");
    }

    /**
     * 收到任务后回调(空方法)
     *
     * @param \Swoole\Server $server
     * @param int $taskId
     * @param int $fromId
     * @param $data
     * @param int $fromServerId -1 则表示从自己服务器调用
     * @return mixed
     */
    public function onTask($server, $taskId, $fromId, $data, $fromServerId = -1)
    {

    }

    /**
     * 接受到任意进程的调用(空方法)
     *
     * @param \Swoole\Server $server
     * @param $fromWorkerId
     * @param $message
     * @return null
     */
    public function onPipeMessage($server, $fromWorkerId, $message, $fromServerId = -1)
    {

    }

    /**
     * 向任意 worker 进程或者 task 进程发送消息
     *
     * 和 swoole 不同的是, 它支持服务器集群下向任意集群去投递数据
     *
     * @param     $data
     * @param int $workerId
     * @param int $serverId
     * @return bool
     */
    public function sendMessage($data, $workerId, $serverId = -1)
    {
        if ($serverId === -1)
        {
            return $this->server->sendMessage($data, $workerId);
        }
        else
        {
            $client = Clusters\Client::getClient($serverId, $workerId, true);
            if (!$client)return false;

            return $client->sendData('msg', $data, 'Task');
        }
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
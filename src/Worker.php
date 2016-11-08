<?php
namespace MyQEE\Server;

class Worker
{
    /**
     * 工作进程服务对象的name, 主端口为 Main
     *
     * @var string
     */
    public $name = 'Main';

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
     * 主端口工作进程对象
     *
     * 在自定义对象里有次对象
     *
     * @var \WorkerMain|WorkerTCP|WorkerUDP|WorkerWebSocket
     */
    public $worker;

    /**
     * @var \Swoole\Server|\Swoole\Http\Server|\Swoole\Websocket\Server
     */
    protected $server;

    /**
     * 当前进程启动时间
     *
     * @var int
     */
    protected static $startTime;

    /**
     * 当前时间
     *
     * @var int
     */
    protected static $time;

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
    public function __construct($server)
    {
        $this->server     = $server;
        self::$time       = time();
        self::$startTime  = self::$time;
        self::$serverName = Server::$config['server']['host'] . ':' . Server::$config['server']['port'];
    }

    /**
     * 进程启动后执行 (空方法, 可自行扩展)
     *
     */
    public function onStart()
    {

    }

    /**
     * 退出程序是回调
     */
    public function onStop()
    {
        self::debug("Worker#{$this->id} Stop, pid: {$this->server->worker_pid}");
    }

    /**
     * 接受到任意进程的调用
     *
     * @param \Swoole\Server $server
     * @param int   $fromWorkerId
     * @param mixed $message
     * @param int   $serverId
     * @return mixed
     */
    public function onPipeMessage($server, $fromWorkerId, $message, $serverId = -1)
    {

    }

    /**
     * @param $server
     * @param $taskId
     * @param $data
     */
    public function onFinish($server, $taskId, $data)
    {

    }

    /**
     * 连接服务器回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    public function onConnect($server, $fd, $fromId)
    {

    }

    /**
     * 关闭连接回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    public function onClose($server, $fd, $fromId)
    {

    }

    /**
     * 投递任务
     *
     * 和 swoole 不同的是, 它支持服务器集群下向任意集群去投递数据
     *
     * @param          $data
     * @param int      $workerId
     * @param \Closure $callback
     * @param int      $serverId 默认 -1 则优先本地投递
     * @param string   $serverGroup
     * @return bool|int
     */
    public function task($data, $workerId = -1, $callback = null, $serverId = -1, $serverGroup = null)
    {
        if (Server::$clustersType < 2)
        {
            # 非高级集群模式
            return $this->server->task($data, $workerId, $callback);
        }
        else
        {
            # 高级集群模式
            $client = Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)
            {
                $this->debug('get task client error');
                return false;
            }

            return $client->sendData('task', $data, $this->name, $callback);
        }
    }

    /**
     * 阻塞的投递信息
     *
     * @param mixed  $taskData
     * @param float  $timeout
     * @param int    $workerId
     * @param int    $serverId
     * @param string $serverGroup
     * @return mixed
     */
    public function taskWait($taskData, $timeout = 0.5, $workerId = -1, $serverId = -1, $serverGroup = null)
    {
        if (Server::$clustersType < 2)
        {
            # 非高级集群模式
            return $this->server->taskwait($taskData, $timeout, $workerId);
        }
        else
        {
            $client = Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)return false;

            return $client->taskWait($taskData, $timeout, $this->name);
        }
    }

    /**
     * 向任意 worker 进程或者 task 进程发送消息
     *
     * 和 swoole 不同的是, 它支持服务器集群下向任意集群去投递数据
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
            if ($this->name !== 'Main')
            {
                $obj = new \stdClass();
                $obj->_sys = true;
                $obj->name = $this->name;
                $obj->sid  = Server::$serverId;
                $obj->data = $data;
                $data      = $obj;
            }

            return $this->server->sendMessage($data, $workerId);
        }
        else
        {
            $client = Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)return false;

            return $client->sendData('msg', $data, $this->name);
        }
    }

    /**
     * 增加一个优化执行时间间隔的定时器
     *
     * 如果你有一个定时器任务会在每个进程上运行, 但是又不希望所有的定时器在同一刹那执行, 那么用这个方法非常适合, 它可以根据进程数将定时器执行的时间分散开.
     *
     * 例如你启动了10个worker进程, 定时器是间隔10秒执行1次, 那么正常情况下, 这10个进程会在同1秒执行, 在下一个10秒又同时执行...
     *
     * 而通过本方法添加的定时器是这样执行的:
     *
     * 进程1会在 00, 10, 20, 30, 40, 50秒执行,
     * 进程2会在 01, 11, 21, 31, 41, 51秒执行,
     * ....
     * 进程9会在 09, 19, 29, 39, 49, 59秒执行.
     *
     * 每个进程运行的间隔仍旧是10秒钟, 但是它不会和其它进程在同一时间执行
     *
     * @param int $interval 时间间隔, 单位: 毫秒
     * @param string|array|\Closure $callback 回调函数
     * @param mixed|null $params
     */
    protected function timeTick($interval, $callback, $params = null)
    {
        $aTime  = intval($interval * $this->id / $this->server->setting['worker_num']);
        $mTime  = intval(microtime(1) * 1000);
        $aTime += $interval * ceil($mTime / $interval) - $mTime;

        # 增加一个延迟执行的定时器
        swoole_timer_after($aTime, function() use ($interval, $callback, $params)
        {
            # 添加定时器
            swoole_timer_tick($interval, $callback, $params);
        });
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
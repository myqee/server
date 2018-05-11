<?php
namespace MyQEE\Server;

class Worker
{
    use Traits\Worker;

    /**
     * 工作进程服务对象的name, 同 $config['hosts'] 里对应的key，在初始化后会自动更新
     *
     * @var string
     */
    public $name = '';

    /**
     * 服务器设置
     *
     * @var array
     */
    public $setting = [];

    /**
     * 在 onStart() 前系统调用初始化 event 事件
     */
    public function initEvent()
    {
        $this->event->bindSysEvent('finish',  ['$server', '$taskId', '$data'], [$this, 'onFinish']);
        $this->event->bindSysEvent('connect', ['$server', '$fd', '$fromId'],   [$this, 'onConnect']);
        $this->event->bindSysEvent('close',   ['$server', '$fd', '$fromId'],   [$this, 'onClose']);
    }

    /**
     * @param $server
     * @param $taskId
     * @param $data
     * @return null|\Generator
     */
    public function onFinish($server, $taskId, $data)
    {
        return null;
    }

    /**
     * 连接服务器回调
     *
     * @param \Swoole\Server $server
     * @param $fd
     * @param $fromId
     * @return null|\Generator
     */
    public function onConnect($server, $fd, $fromId)
    {
        return null;
    }

    /**
     * 关闭连接回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     * @return null|\Generator
     */
    public function onClose($server, $fd, $fromId)
    {
        return null;
    }

    /**
     * 投递任务
     *
     * 它支持服务器集群下向任意集群去投递数据
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
        if (static::$Server->clustersType < 2)
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
        if (static::$Server->clustersType < 2)
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
        if ($aTime > 0)
        {
            swoole_timer_after($aTime, function() use ($interval, $callback, $params)
            {
                # 添加定时器
                swoole_timer_tick($interval, $callback, $params);
            });
        }
        else
        {
            swoole_timer_tick($interval, $callback, $params);
        }
    }
}
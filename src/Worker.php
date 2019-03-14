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
     * 获取实例化对象
     *
     * @param null $name
     * @return Worker\SchemeRedis|Worker\SchemeTCP|Worker\SchemeUDP|Worker\SchemeWebSocket|\WorkerMain|null|mixed
     */
    public static function instance($name = null)
    {
        if (!$name)
        {
            return Server::$instance->worker;
        }
        elseif (isset(Server::$instance->workers[$name]))
        {
            return Server::$instance->workers[$name];
        }
        else
        {
            return null;
        }
    }

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
     * @return bool|int
     */
    public function task($data, $workerId = -1, $callback = null)
    {
        return $this->server->task($data, $workerId, $callback);
    }

    /**
     * 阻塞的投递信息
     *
     * @param mixed  $taskData
     * @param float  $timeout
     * @param int    $workerId
     * @return mixed
     */
    public function taskWait($taskData, $timeout = 0.5, $workerId = -1)
    {
        return $this->server->taskwait($taskData, $timeout, $workerId);
    }

    /**
     * 并发执行Task并进行协程调度
     *
     * 最大并发任务不得超过1024
     *
     * @param array $tasks $tasks任务列表，必须为数组,底层会遍历数组，将每个元素作为task投递到Task进程池
     * @param float $timeout 超时时间，默认为0.5秒，当规定的时间内任务没有全部完成，立即中止并返回结果
     */
    public function taskCo(array $tasks, float $timeout = 0.5)
    {
        return $this->server->taskCo($tasks, $timeout);
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
            \Swoole\Timer::after($aTime, function() use ($interval, $callback, $params)
            {
                # 添加定时器
                \Swoole\Timer::tick($interval, $callback, $params);
            });
        }
        else
        {
            \Swoole\Timer::tick($interval, $callback, $params);
        }
    }
}
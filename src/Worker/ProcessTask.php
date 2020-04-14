<?php
namespace MyQEE\Server\Worker;

class ProcessTask {
    use \MyQEE\Server\Traits\Worker;

    /**
     * 任务进程名，一般不用改
     *
     * @var string
     */
    public $name = '_Task';

    /**
     * 任务序号, 从0开始
     *
     * @var int
     */
    public $taskId;

    /**
     * 获取实例化对象
     *
     * @return ProcessTask|\WorkerTask|null|mixed
     */
    public static function instance() {
        return \MyQEE\Server\Server::$instance->workerTask;
    }

    /**
     * 给投递者返回信息
     *
     * ! swoole4.0后，请使用 onTask 中的 `$task` 对象的finish方法，例如：`$task->finish($rs)`
     *
     * @param $rs
     * @deprecated 请使用 `$task->finish($rs)`
     */
    public function finish($rs) {
        $this->server->finish($rs);
    }

    public function initEvent() {
        $this->event->bindSysEvent('task', ['$server', '$taskId', '$fromId', '$data'], [$this, 'onTask']);
    }

    /**
     * 收到任务后回调(空方法)
     *
     * @param \Swoole\Server $server
     * @param \Swoole\Server\Task $task
     */
    public function onTask($server, $task) {}

    /**
     * 增加一个优化执行时间间隔的定时器
     *
     * 如果你有一个定时器任务会在每个进程上运行, 但是又不希望所有的定时器在同一刹那执行, 那么用这个方法非常适合, 它可以根据进程数将定时器执行的时间分散开.
     *
     * 例如你启动了10个taskWorker进程, 定时器是间隔10秒执行1次, 那么正常情况下, 这10个进程会在同1秒执行, 在下一个10秒又同时执行...
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
    protected function timeTick($interval, $callback, $params = null) {
        $aTime  = intval($interval * $this->taskId / $this->server->setting['task_worker_num']);
        $mTime  = intval(microtime(1) * 1000);
        $aTime += $interval * ceil($mTime / $interval) - $mTime;

        # 增加一个延迟执行的定时器
        if ($aTime > 0) {
            \Swoole\Timer::after($aTime, function() use ($interval, $callback, $params) {
                # 添加定时器
                \Swoole\Timer::tick($interval, $callback, $params);
            });
        }
        else {
            \Swoole\Timer::tick($interval, $callback, $params);
        }
    }
}
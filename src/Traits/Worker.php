<?php
namespace MyQEE\Server\Traits;

trait Worker
{
    use Log;

    /**
     * 当前进程的唯一ID
     *
     * @var int
     */
    public $id;

    /**
     * @var \Swoole\Server|\Swoole\Http\Server|\Swoole\Websocket\Server
     */
    public $server;

    /**
     * 事件对象
     *
     * @var \MyQEE\Server\Event
     */
    public $event;

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
     * 当前服务对象（不是 Swoole\Server 对象）
     *
     * @var \MyQEE\Server\Server|\Server
     */
    public static $Server;

    /**
     * WorkerBase constructor.
     */
    public function __construct($arguments)
    {
        static::$startTime = time();

        foreach ($arguments as $k => $v)
        {
            $this->$k = $v;
        }

        static::$Server = \MyQEE\Server\Server::$instance;

        if (null === $this->server)
        {
            $this->server = static::$Server->server;
        }

        static::$serverName =& static::$Server->serverName;
        $this->id           =& $this->server->worker_id;
        $this->event        = new \MyQEE\Server\Event();

        # 设置依赖
        $this->event->injectorSet('$worker', $this);
        $this->event->injectorSet('$server', $this->server);

        # 绑定默认系统事件
        $this->event->bindSysEvent('pipeMessage', ['$server', '$fromWorkerId', '$message'], [$this, 'onPipeMessage']);
        $this->event->bindSysEvent('exit',        [$this, 'onExit']);
        $this->event->bindSysEvent('stop',        [$this, 'onStop']);
    }

    /**
     * 向任意 worker 进程或者 task 进程发送消息
     *
     * 不可以向自己投递，它支持服务器集群下向任意集群去投递数据
     *
     * @param        $data
     * @param int    $workerId
     * @return bool
     */
    public function sendMessage($data, $workerId)
    {
        if ($workerId === $this->id)
        {
            # 自己调自己
            \Swoole\Timer::after(1, function() use ($data)
            {
                $this->onPipeMessage($this->server, $this->id, $data);
            });

            return true;
        }

        $setting      = $this->server->setting;
        $allWorkerNum = $setting['worker_num'] + $setting['task_worker_num'];
        if ($workerId < $allWorkerNum)
        {
            $isMain = $this === static::$Server->worker;
            if (false === $isMain || !is_string($data))
            {
                $data = \MyQEE\Server\Message::createSystemMessageString($data, true === $isMain ? '' : $this->name);
            }

            return $this->server->sendMessage($data, $workerId);
        }
        else
        {
            # 往自定义进程里发
            $process = static::$Server->getCustomWorkerProcessByWorkId($workerId);
            if (null !== $process)
            {
                /**
                 * @var \Swoole\Process $process
                 */
                $data = \MyQEE\Server\Message::createSystemMessageString($data, '', $this->id);

                return $process->write($data) == strlen($data);
            }
            else
            {
                return false;
            }
        }
    }

    /**
     * 通过进程Key给指定自定义子进程发送信息
     *
     * 支持任意长度，超过 8k 长度的的数据将被分包发送

     * @param mixed  $data
     * @param string $workerName
     * @return bool
     */
    public function sendMessageToCustomWorker($data, $workerName)
    {
        $process = static::$Server->getCustomWorkerProcess($workerName);
        if (null !== $process)
        {
            $data = \MyQEE\Server\Message::createSystemMessageString($data, '', $this->id);
            $len  = strlen($data);

            if ($len <= 8192)
            {
                return $process->write($data) === $len;
            }
            else
            {
                # 数据分包
                $blocks = str_split($data, 8100);
                $id     = intval(microtime(true) * 1000000) * 1000 + $this->id;
                $index  = 0;
                $count  = count($blocks);
                foreach ($blocks as $block)
                {
                    $finish = ++$index === $count;
                    $tmp = "%\2" . pack('nCJ', strlen($block), $finish, $id). $block;
                    $rs  = $process->write($tmp) === strlen($tmp);
                    if (false === $rs)
                    {
                        return false;
                    }
                }
                return true;
            }
        }
        else
        {
            return false;
        }
    }

    /**
     * 向所有 worker 进程发送数据
     *
     * 有任何失败将会抛出错误
     *
     *  Message::SEND_MESSAGE_TYPE_WORKER  - 所有worker进程
     *  Message::SEND_MESSAGE_TYPE_TASK    - 所有task进程
     *  Message::SEND_MESSAGE_TYPE_CUSTOM  - 所有custom进程
     *  Message::SEND_MESSAGE_TYPE_ALL     - 所有进程
     *
     * ```
     *  $this->sendMessageToAllWorker('test', Worker::SEND_MESSAGE_TYPE_WORKER);
     *  $this->sendMessageToAllWorker('test', Worker::SEND_MESSAGE_TYPE_WORKER || Worker::SEND_MESSAGE_TYPE_CUSTOM);
     * ```
     *
     * @param string $data
     * @param int $workerType 进程类型 不传: 全部进程， 1: 仅仅 worker 进程, 2: 仅仅 task 进程, 4: 仅仅 custom 进程
     * @return bool
     * @throws \Exception
     */
    public function sendMessageToAllWorker($data, $workerType = null)
    {
        if (!$workerType)$workerType = \MyQEE\Server\Message::SEND_MESSAGE_TYPE_ALL;

        $setting   = \MyQEE\Server\Server::$instance->server->setting;
        $workerNum = $setting['worker_num'];
        $taskNum   = $setting['task_worker_num'];

        if (($workerType & \MyQEE\Server\Message::SEND_MESSAGE_TYPE_WORKER) == \MyQEE\Server\Message::SEND_MESSAGE_TYPE_WORKER)
        {
            $i = 0;
            while ($i < $workerNum)
            {
                if (!$this->sendMessage($data, $i))
                {
                    throw new \Exception('worker id:' . $i . ' send message fail!');
                }

                $i++;
            }
        }

        if (($workerType & \MyQEE\Server\Message::SEND_MESSAGE_TYPE_TASK) == \MyQEE\Server\Message::SEND_MESSAGE_TYPE_TASK)
        {
            $i = $workerNum;
            while ($i < $workerNum + $taskNum)
            {
                if (!$this->sendMessage($data, $i))
                {
                    throw new \Exception('worker id:' . $i . '(task) send message fail!');
                }

                $i++;
            }
        }

        if (($workerType & \MyQEE\Server\Message::SEND_MESSAGE_TYPE_CUSTOM) == \MyQEE\Server\Message::SEND_MESSAGE_TYPE_CUSTOM)
        {
            $i = $workerNum + $taskNum;
            foreach (\MyQEE\Server\Server::$instance->getCustomWorkerProcess() as $key => $process)
            {
                if ($i == $this->id)
                {
                    # 当前进程
                    \Swoole\Timer::after(1, function() use ($data)
                    {
                        $this->onPipeMessage($this->server, $this->id, $data);
                    });
                }
                else
                {
                    /**
                     * @var \Swoole\Process $process
                     */
                    if ($process->pipe)
                    {
                        $this->sendMessageToCustomWorker($data, $key);
                    }
                }
                $i++;
            }
        }

        return true;
    }

    /**
     * 旧进程退出前回调
     */
    public function onExit()
    {
    }

    /**
     * 退出程序时回调
     */
    public function onStop()
    {
    }

    /**
     * 进程启动后执行 (空方法, 可自行扩展)
     *
     */
    public function onStart()
    {
    }

    /**
     * 接受到任意进程的调用(空方法)
     *
     * 如果 $fromWorkerId > worker + task 数则表明是从一个自定义子进程里发过来的
     *
     * @param \Swoole\Server $server
     * @param int $fromWorkerId
     * @param $message
     */
    public function onPipeMessage($server, $fromWorkerId, $message)
    {
        return null;
    }

    /**
     * 获取当前服务器对象
     *
     * @return \MyQEE\Server\Server
     */
    public function getServer()
    {
        return static::$Server;
    }
}
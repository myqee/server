<?php
namespace MyQEE\Server\Traits;

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
     * @var \MyQEE\Server\Server
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

        $this->serverId     =& static::$Server->serverId;
        static::$serverName =& static::$Server->serverName;
        $this->id           =& $this->server->worker_id;
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
        if (is_object($data) && $data instanceof \MyQEE\Server\Message)
        {
            return $data->send($workerId, $serverId, $serverGroup);
        }

        if ($serverId < 0 || static::$Server->clustersType === 0 || ($this->serverId === $serverId && null === $serverGroup))
        {
            # 没有指定服务器ID 或者 本服务器 或 非集群模式

            if ($workerId === $this->id)
            {
                # 自己调自己
                swoole_timer_after(1, function() use ($data, $serverId)
                {
                    $this->onPipeMessage($this->server, $this->id, $data, $serverId);
                });

                return true;
            }

            $setting      = $this->server->setting;
            $allWorkerNum = $setting['worker_num'] + $setting['task_worker_num'];
            if ($workerId < $allWorkerNum)
            {
                if ($this !== static::$Server->worker || !is_string($data))
                {
                    $obj          = new \stdClass();
                    $obj->__sys__ = true;
                    $obj->name    = $this->name;
                    $obj->sid     = static::$Server->serverId;
                    $obj->data    = $data;
                    $data         = serialize($obj);
                }
                return $this->server->sendMessage($data, $workerId);
            }
            else
            {
                # 往自定义进程里发
                return $this->sendMessageToCustomWorkerById($data, $workerId);
            }
        }
        else
        {
            $client = \MyQEE\Server\Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)return false;

            return $client->sendData('msg', $data, $this->name);
        }
    }

    /**
     * 通过进程ID给指定自定义子进程发送信息
     *
     * 注意，第一个自定义子进程的workerId不是0，而是 worker_num + task_worker_num
     *
     * @param mixed $data
     * @param int $workerId
     * @return bool
     */
    public function sendMessageToCustomWorkerById($data, $workerId)
    {
        $customProcess = static::$Server->getCustomWorkerProcessByWorkId($workerId);
        if (null !== $customProcess)
        {
            /**
             * @var \Swoole\Process $customProcess
             */
            if ($this !== static::$Server->worker || !is_string($data))
            {
                $obj          = new \stdClass();
                $obj->__sys__ = true;
                $obj->fid     = $this->id;
                $obj->data    = $data;
                $data         = serialize($obj);
            }
            return $customProcess->write($data) == strlen($data);
        }
        else
        {
            return false;
        }
    }

    /**
     * 通过进程Key给指定自定义子进程发送信息
     *
     * @param $data
     * @param $key
     * @return bool
     */
    public function sendMessageToCustomWorkerByKey($data, $key)
    {
        $process = static::$Server->getCustomWorkerProcess($key);
        if (null !== $process)
        {
            /**
             * @var \Swoole\Process $customProcess
             */
            if ($this !== static::$Server->worker || !is_string($data))
            {
                $obj          = new \stdClass();
                $obj->__sys__ = true;
                $obj->fid     = $this->id;
                $obj->data    = $data;
                $data         = serialize($obj);
            }
            return $customProcess->write($data) == strlen($data);
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
     * @todo 暂时不支持给集群里其它服务器所有进程发送消息
     * @param     $data
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
            if (!is_string($data))
            {
                $obj          = new \stdClass();
                $obj->__sys__ = true;
                $obj->data    = $data;
                $obj->fid     = $this->id;
                $dataStr      = serialize($obj);
            }
            else
            {
                $dataStr = $data;
            }
            $i = $workerNum + $taskNum;
            foreach (\MyQEE\Server\Server::$instance->getCustomWorkerProcess() as $process)
            {
                if ($i == $this->id)
                {
                    # 当前进程
                    swoole_timer_after(1, function() use ($data)
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
                        $process->write($dataStr);
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
    public function onWorkerExit()
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
        static::$Server->log($label, $data, $type, $color);
    }

    /**
     * 错误信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function warn($labelOrData, array $data = null)
    {
        static::$Server->log($labelOrData, $data, 'warn', '[31m');
    }

    /**
     * 输出信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function info($labelOrData, array $data = null)
    {
        static::$Server->log($labelOrData, $data, 'info', '[33m');
    }

    /**
     * 调试信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function debug($labelOrData, array $data = null)
    {
        static::$Server->log($labelOrData, $data, 'debug', '[34m');
    }

    /**
     * 跟踪信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    protected function trace($labelOrData, array $data = null)
    {
        static::$Server->log($labelOrData, $data, 'trace', '[35m');
    }
}
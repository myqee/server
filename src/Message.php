<?php
namespace MyQEE\Server;

/**
 * 进程间通讯消息对象
 *
 * 使用方法1：自己写一个类继承这个对象，并实现 onPipeMessage，然后实例化这个对象，给这个对象设置参数就可以使用了
 * 例：
 *
 * ```php
 * class Test extents Message
 * {
 *     public $id;
 *     public $value;
 *
 *     public function onPipeMessage($server, $fromWorkerId, $fromServerId = -1)
 *     {
 *         // your code
 *     }
 * }
 *
 * $obj        = new Test();
 * $obj->id    = 123;
 * $obj->value = 'abc';
 * $obj->send(1);
 * ```
 *
 * 使用方法2：设置一个回调参数：
 *
 * ```php
 * use \MyQEE\Server\Message;
 * $obj = Message::create('MyClass::test');        // 将会在不同的进程里回调 MyClass::test($server, $fromWorkerId, $obj, $fromServerId);
 * $obj->send(1);
 * ```
 *
 * 注意：callback 必须是一个可以回调的的字符串（不可以是是闭包函数）
 *
 * @package MyQEE\Server
 */
class Message
{
    /**
     * 此参数用在 Worker::sendMessageToAllWorker() 方法的第2个参数里
     */
    const SEND_MESSAGE_TYPE_ALL    = 2047;  # 所有进程
    const SEND_MESSAGE_TYPE_WORKER = 1;     # 所有worker
    const SEND_MESSAGE_TYPE_TASK   = 2;     # 所有task
    const SEND_MESSAGE_TYPE_CUSTOM = 4;     # 所有custom

    /**
     * 任意进程接受到时调用(空方法)
     *
     * @param \Swoole\Server $server
     * @param $fromWorkerId
     * @param $message
     * @return void
     */
    public function onPipeMessage($server, $fromWorkerId, $fromServerId = -1)
    {
        if (isset($this->callback) && is_callable($this->callback))
        {
            call_user_func($this->callback, $server, $fromWorkerId, $this, $fromServerId);
        }
    }

    /**
     * 发送消息
     *
     * @param      $workerId
     * @param int  $serverId
     * @param null $serverGroup
     * @return bool
     */
    public function send($workerId, $serverId = -1, $serverGroup = null)
    {
        $server = Server::$instance;
        if ($serverId < 0 || $server->clustersType === 0 || ($server->serverId === $serverId && null === $serverGroup))
        {
            # 没有指定服务器ID 或者 本服务器 或 非集群模式

            if ($workerId === $server->server->worker_id)
            {
                # 自己调自己
                $this->onPipeMessage($server->server, $server->server->worker_id, $serverId);

                return true;
            }
            else
            {
                $data = $this->getString();
            }

            $setting      = Server::$instance->server->setting;
            $allWorkerNum = $setting['worker_num'] + $setting['task_worker_num'];
            if ($workerId < $allWorkerNum)
            {
                return $server->server->sendMessage($data, $workerId);
            }
            else
            {
                # 往自定义进程里发
                $customId      = $workerId - $allWorkerNum;
                $customProcess = array_values(Server::$instance->getCustomWorkerProcess());
                if (isset($customProcess[$customId]) && $customProcess[$customId]->pipe)
                {
                    /**
                     * @var array $customProcess
                     */
                    return $customProcess[$customId]->write($data) == strlen($data);
                }
                else
                {
                    return false;
                }
            }
        }
        else
        {
            $client = \MyQEE\Server\Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)return false;

            return $client->sendData('msg', $this, null);
        }
    }

    /**
     * 获取数据
     *
     * @return string
     */
    protected function getString($args = null)
    {
        $obj          = new \stdClass();
        $obj->__sys__ = true;
        $obj->sid     = Server::$instance->serverId;
        $obj->data    = $this;
        if ($args)foreach ($args as $k => $v)
        {
            $obj->$k = $v;
        }
        $data = serialize($obj);

        return $data;
    }

    /**
     * 向所有 worker 进程发送数据
     *
     * 有任何失败将会抛出错误
     *
     * ```
     *  Message::SEND_MESSAGE_TYPE_WORKER  - 所有worker进程
     *  Message::SEND_MESSAGE_TYPE_TASK    - 所有task进程
     *  Message::SEND_MESSAGE_TYPE_CUSTOM  - 所有custom进程
     *  Message::SEND_MESSAGE_TYPE_ALL     - 所有进程
     * ```
     *
     * 例：
     *
     * ```php
     *  $this->sendMessageToAllWorker('test', Message::SEND_MESSAGE_TYPE_WORKER);
     *  $this->sendMessageToAllWorker('test', Message::SEND_MESSAGE_TYPE_WORKER | Message::SEND_MESSAGE_TYPE_TASK);  # 所有worker和task进程
     * ```
     *
     * @todo 暂时不支持给集群里其它服务器所有进程发送消息
     * @param int $workerType 进程类型 默认: 全部进程， 1: 仅仅 worker 进程, 2: 仅仅 task 进程, 4: 仅仅 custom 进程，支持位或
     * @return bool
     * @throws \Exception
     */
    public function sendMessageToAllWorker($workerType = Message::SEND_MESSAGE_TYPE_ALL)
    {
        $setting   = Server::$instance->server->setting;
        $workerNum = $setting['worker_num'];
        $taskNum   = $setting['task_worker_num'];

        if (($workerType & self::SEND_MESSAGE_TYPE_WORKER) == self::SEND_MESSAGE_TYPE_WORKER)
        {
            $i = 0;
            while ($i < $workerNum)
            {
                if (!$this->send($i))
                {
                    throw new \Exception('worker id:' . $i . ' send message fail!');
                }

                $i++;
            }
        }
        if (($workerType & self::SEND_MESSAGE_TYPE_TASK) == self::SEND_MESSAGE_TYPE_TASK)
        {
            $i = $workerNum;
            while ($i < $workerNum + $taskNum)
            {
                if (!$this->send($i))
                {
                    throw new \Exception('worker id:' . $i . '(task) send message fail!');
                }

                $i++;
            }
        }
        if (($workerType & self::SEND_MESSAGE_TYPE_CUSTOM) == self::SEND_MESSAGE_TYPE_CUSTOM)
        {
            $args = [
                'fid' => Server::$instance->server->worker_id
            ];
            $str  = $this->getString($args);
            foreach (\MyQEE\Server\Server::$instance->getCustomWorkerProcess() as $process)
            {
                /**
                 * @var \Swoole\Process $process
                 */
                if ($process->pipe)
                {
                    $process->write($str);
                }
            }
        }

        return true;
    }

    /**
     * 创建一个新的回调消息
     *
     * @param $callback
     * @return Message
     * @throws \Exception
     */
    public static function create($callback)
    {
        if (!is_string($callback) || !is_callable($callback))
        {
            throw new \Exception('给 Message 设置了一个错误的回调方法, 必须是可回调的字符串');
        }

        $obj = new Message();
        $obj->callback = $callback;
        return $obj;
    }
}
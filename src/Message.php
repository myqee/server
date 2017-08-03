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
    const SEND_MESSAGE_TYPE_ALL    = 0;     # 所有进程
    const SEND_MESSAGE_TYPE_WORKER = 1;     # 所有worker
    const SEND_MESSAGE_TYPE_TASK   = 2;     # 所有task
    const SEND_MESSAGE_TYPE_CUSTOM = 3;     # 所有custom

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
                $obj          = new \stdClass();
                $obj->__sys__ = true;
                $obj->sid     = $server->serverId;
                $obj->data    = $this;
                $data         = serialize($obj);
            }

            return $server->server->sendMessage($data, $workerId);
        }
        else
        {
            $client = \MyQEE\Server\Clusters\Client::getClient($serverGroup, $serverId, $workerId, true);
            if (!$client)return false;

            return $client->sendData('msg', $this, null);
        }
    }

    /**
     * 向所有 worker 进程发送数据
     *
     * 有任何失败将会抛出错误
     *
     * ```
     *  Message::SEND_MESSAGE_TYPE_WORKER  - 所有worker进程
     *  Message::SEND_MESSAGE_TYPE_TASK    - 所有task进程
     *  Message::SEND_MESSAGE_TYPE_ALL     - 所有进程
     * ```
     *
     * 例：
     *
     * ```php
     *  $this->sendMessageToAllWorker('test', Message::SEND_MESSAGE_TYPE_WORKER);
     * ```
     *
     * @todo 暂时不支持给集群里其它服务器所有进程发送消息
     * @param int $workerType 进程类型 0: 全部进程， 1: 仅仅 worker 进程, 2: 进程 task 进程
     * @return bool
     * @throws \Exception
     */
    public function sendMessageToAllWorker($workerType = 0)
    {
        $setting   = Server::$instance->server->setting;
        $i         = 0;
        $workerNum = $setting['worker_num'] + $setting['task_worker_num'];

        switch ($workerType)
        {
            case self::SEND_MESSAGE_TYPE_WORKER:
                $workerNum = $setting['worker_num'];
                break;

            case self::SEND_MESSAGE_TYPE_TASK:
                $i = $setting['worker_num'];
                break;

            case self::SEND_MESSAGE_TYPE_ALL:
            default:
                break;
        }

        while ($i < $workerNum)
        {
            if (!$this->send($i))
            {
                throw new \Exception('worker id:' . $i . ' send message fail!');
            }

            $i++;
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
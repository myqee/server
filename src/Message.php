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

    const COMPRESS_LEVEL = 9;      # 数据压缩等级
    const FLAT_SERIALIZE = 1;      # 序列化
    const FLAT_MESSAGE   = 2;      # Message消息体
    const FLAT_COMPRESS  = 4;      # 压缩
    const FLAT_SERVER_ID = 8;      # 是否含服务器ID
    const FLAT_WORKER_ID = 16;     # 是否含WorkerID

    /**
     * 消息序号
     *
     * @var int
     */
    protected static $MESSAGE_NO = 0;

    /**
     * 任意进程接受到时调用(空方法)
     *
     * @param \Swoole\Server $server
     * @param $fromWorkerId
     * @param $message
     * @return mixed
     */
    public function onPipeMessage($server, $fromWorkerId, $fromServerId = -1)
    {
        if (isset($this->callback) && is_callable($this->callback))
        {
            return call_user_func($this->callback, $server, $fromWorkerId, $this, $fromServerId);
        }

        return null;
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
                $server->server->after(1, function()
                {
                    $this->onPipeMessage(Server::$instance->server, Server::$instance->server->worker_id);
                });
                return true;
            }

            $setting      = Server::$instance->server->setting;
            $allWorkerNum = $setting['worker_num'] + $setting['task_worker_num'];
            if ($workerId < $allWorkerNum)
            {
                return $server->server->sendMessage($this->getString(), $workerId);
            }
            else
            {
                # 往自定义进程里发
                $customProcess = Server::$instance->getCustomWorkerProcessByWorkId($workerId);
                if (null !== $customProcess)
                {
                    /**
                     * @var \Swoole\Process $customProcess
                     */
                    $data = $this->getString(true);

                    return $customProcess->write($data) == strlen($data);
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
    protected function getString($addWorkerId = false)
    {
        return self::createSystemMessageString($this, '', $addWorkerId ? Server::$instance->server->worker_id : null);
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
            $myId = Server::$instance->server->worker_id;
            $data = $this->getString(true);
            foreach (\MyQEE\Server\Server::$instance->getCustomWorkerProcess() as $process)
            {
                /**
                 * @var \Swoole\Process|mixed $process
                 */
                if ($process->worker_id == $myId)
                {
                    # 当前进程
                    swoole_timer_after(1, function() use ($myId)
                    {
                        $this->onPipeMessage(Server::$instance->server, $myId);
                    });
                }
                elseif ($process->pipe)
                {
                    $process->write($data);
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
     */
    public static function create($callback)
    {
        if (!is_string($callback) || !is_callable($callback))
        {
            self::throwError('给 Message 设置了一个错误的回调方法, 必须是可回调的字符串');
        }

        $obj = new Message();
        $obj->callback = $callback;
        return $obj;
    }

    /**
     * 获取一个 sendMessage() 可用的字符串
     *
     * @param mixed  $data
     * @param string $fromWorkerName 进程id，不传则默认
     * @param int    $fromWorkerId   传的workerId
     * @return string
     */
    public static function createSystemMessageString($data, $fromWorkerName = '', $fromWorkerId = null)
    {
        if (is_string($data))
        {
            $dataLen = strlen($data);
            $flag    = 0;
        }
        else
        {
            $flag = self::FLAT_SERIALIZE;
            if (is_object($data) && $data instanceof Message)
            {
                $flag |= self::FLAT_MESSAGE;
            }

            $data    = serialize($data);
            $dataLen = strlen($data);
        }

        $workerLen = strlen($fromWorkerName);
        $allLen    = 6 + $workerLen;

        if ($dataLen > 65000 && true === static::isSupportCompress())
        {
            $data    = static::compress($data, static::COMPRESS_LEVEL);
            $dataLen = strlen($data);
            $flag    = $flag | self::FLAT_COMPRESS;
        }
        $allLen += $dataLen;

        if (Server::$instance->serverId >= 0)
        {
            $flag        = $flag | self::FLAT_SERVER_ID;
            $serverIdStr = pack('L', Server::$instance->serverId);
            $allLen     += 4;
        }
        else
        {
            $serverIdStr = '';
        }

        if ($fromWorkerId !== null)
        {
            $flag        = $flag | self::FLAT_WORKER_ID;
            $workerIdStr = pack('S', $fromWorkerId);
            $allLen     += 2;
        }
        else
        {
            $workerIdStr = '';
        }

        # flag          标记位      1字节
        # allLen        所有的长度   2字节
        # workerLen     进程名称长度 1字节
        # $workerName   进程名称
        # $workerId     进程序号
        # $serverIdStr  服务器编号ID
        return "%\1". pack('CSC', $flag, $allLen, $workerLen) . $fromWorkerName . $serverIdStr . $workerIdStr . $data;
    }

    /**
     * 解析经过 createSystemMessageString() 处理后的内容
     *
     * ```php
     * // 编码
     * $msg = Message::createSystemMessageString(['aa', 'bb']);
     * // 解码
     * list($isMessage, $workerName, $serverId, $workerId) = Message::parseSystemMessage($msg);
     * var_dump($msg);
     * ```
     *
     * @param $message
     * @return array [$isMessage, $workerName, $serverId, $workerId]
     */
    public static function parseSystemMessage(& $message)
    {
        if (!is_string($message))
        {
            return [false, null, -1, null];
        }

        if (substr($message, 0, 2) !== "%\1")
        {
            return [false, null, -1, null];
        }
        $headerLen = 6;
        $data      = @unpack('a2tmp/Cflag/Slen/CworkerLen', substr($message, 0, $headerLen));
        if (false === $data || 4 !== count($data))return [false, null, -1, null];

        if ($data['len'] !== strlen($message))
        {
            return [false, null, -1, null];
        }
        $flag      = $data['flag'];
        $workerLen = $data['workerLen'];

        if ($workerLen > 0)
        {
            $workerName = substr($message, $headerLen, $workerLen);
        }
        else
        {
            $workerName = null;
        }
        $msgPos = $workerLen + $headerLen;

        # 服务器ID
        if (($flag & self::FLAT_SERVER_ID) === self::FLAT_SERVER_ID)
        {
            $tmp      = unpack('L', substr($message, $msgPos, 4));
            $serverId = $tmp[1] ?: -1;
            $msgPos  += 4;
        }
        else
        {
            $serverId = -1;
        }

        # 进程ID
        if (($flag & self::FLAT_WORKER_ID) === self::FLAT_WORKER_ID)
        {
            $tmp      = unpack('S', substr($message, $msgPos, 2));
            $workerId = $tmp[1] ?: -1;
            $msgPos  += 2;
        }
        else
        {
            $workerId = -1;
        }

        # 读取最终数据
        $message = substr($message, $msgPos);

        # 处理压缩
        if (($flag & self::FLAT_COMPRESS) === self::FLAT_COMPRESS)
        {
            $tmp = static::unCompress($message);
            if (false === $tmp)
            {
                $tmp = '';
                Server::$instance->warn("解压缩 Message 数据失败， 内容: ". self::hexString($message));
            }
            $message = $tmp;
        }

        # 处理序列化数据
        if (($flag & self::FLAT_SERIALIZE) === self::FLAT_SERIALIZE)
        {
            $tmp = @unserialize($message);
            if (false === $tmp)
            {
                $tmp = '';
                Server::$instance->warn("反序列化 Message 数据失败， 内容: ". self::hexString($message));
            }
            $message = $tmp;
        }

        # 判断是否 Message 消息体
        if (($flag & self::FLAT_MESSAGE) === self::FLAT_MESSAGE)
        {
            $isMessage = true;
        }
        else
        {
            $isMessage = false;
        }

        return [$isMessage, $workerName, $serverId, $workerId];
    }

    /**
     * 是否支持压缩
     *
     * @return bool|null
     */
    protected static function isSupportCompress()
    {
        static $lz4 = null;
        if (null === $lz4)
        {
            $lz4 = function_exists('\\lz4_compress');
        }

        return $lz4;
    }

    /**
     * 压缩数据
     *
     * @param $data
     * @param $level
     * @return string|false
     */
    protected static function compress($data, $level)
    {
        return lz4_compress($data, $level);
    }

    /**
     * 解压数据
     *
     * @param $data
     * @return string|false
     */
    protected static function unCompress($data)
    {
        return @lz4_uncompress($data);
    }


    /**
     * 获取 bin2hex 的字符
     *
     * @param $data
     * @return string
     */
    protected static function hexString($data)
    {
        $str = '';
        for($i = 0; $i < strlen($data); $i++)
        {
            $str .= bin2hex($data[$i]) ." ";
        }

        return $str;
    }

    /**
     * 抛出错误
     *
     * @param string $msg
     * @param int $code
     * @throws \Exception
     */
    protected function throwError($msg, $code = 0)
    {
        throw new \Exception($msg, $code);
    }
}
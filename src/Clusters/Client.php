<?php

namespace MyQEE\Server\Clusters;

use MyQEE\Server\Server;

class Client
{
    /**
     * 服务器序号
     *
     * @var int
     */
    protected $serverId;

    /**
     * 所在分组
     *
     * @var string
     */
    protected $group;

    /**
     * 进程序号
     *
     * 从0开始, task进程也是从0开始
     *
     * @var int
     */
    protected $workerId;

    /**
     * 服务器通讯IP
     *
     * @var string
     */
    protected $ip;

    /**
     * 服务器通讯端口
     *
     * @var int
     */
    protected $port;

    /**
     * 服务器通讯密钥
     *
     * @var string
     */
    protected $key;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * 最后一个任务的投递ID
     *
     * @var int
     */
    protected $lastTaskId = 0;

    /**
     * 未读完的数据
     *
     * @var string
     */
    protected $buffer = '';

    protected $taskCallbackList = [];

    /**
     * 所有 Client 实例化对象
     *
     * @var array
     */
    protected static $instances = [];

    /**
     * 获取对象
     *
     * @param $serverId
     * @param $workerId
     * @return Client|false
     */
    public static function getClient($serverGroup, $serverId, $workerId, $isTask = false)
    {
        if (!$serverGroup)
        {
            # 服务器分组
            $serverGroup = Server::$instance->config['clusters']['group'] ?: 'default';
        }
        if ($isTask)
        {
            $serverGroup .= '.task';
        }

        if (-1 === $serverId)
        {
            # 随机服务器ID
            $host = Host::getRandHostData($serverGroup);
            if ($host)
            {
                $serverId = $host['id'];
            }
        }
        else
        {
            # 随机ID
            $host = Host::$table->get("{$serverGroup}_{$serverId}");
        }

        if (!$host)return false;

        if (-1 === $workerId)
        {
            $workerId = mt_rand(0, $host['worker_num'] - 1);
        }

        # 生成一个KEY
        $key = "{$serverGroup}_{$serverId}_{$workerId}";

        if (!isset(self::$instances[$key]))
        {
            if (!isset($host))
            {
                $host = Host::$table->get($serverId);
                if (!$host)return false;
            }

            # 检查任务ID是否超出序号返回
            if ($workerId - $host['worker_num'] > 1)return false;

            /**
             * @var Client $client
             */
            $class            = static::class;
            $client           = new $class();
            $client->serverId = $serverId;
            $client->workerId = $workerId;
            $client->key      = $host['key'];
            $client->ip       = $host['ip'];
            $client->port     = $host['port'];
            $rs               = $client->connect();

            if (!$rs)
            {
                # 没有连接上去
                return false;
            }

            self::$instances[$key] = $client;
        }

        return self::$instances[$key];
    }

    /**
     *
     */
    public function __construct()
    {
    }

    function __destruct()
    {
        $this->close();
    }

    /**
     * 发送数据
     *
     * @param string $type 类型: task | msg
     * @param mixed  $data
     * @param string $workerName 当前进程对应的名称
     * @param \Closure $callback 需要回调的信息, $type = task 时支持
     * @return bool
     */
    public function sendData($type, $data, $workerName, $callback = null)
    {
        $resource = $this->resource();
        if (!$resource)
        {
            return false;
        }

        $id         = Host::$taskIdAtomic->add();
        $obj        = new \stdClass();
        $obj->type  = $type;
        $obj->id    = $id;
        $obj->sid   = $this->serverId;
        $obj->wid   = $this->workerId;
        $obj->wname = $workerName;
        $obj->data  = $data;

        if ($obj->id > 4000000000)
        {
            # 重置序号
            Host::$taskIdAtomic->set(1);
            $obj->id = 1;
        }

        if ($callback && $type === 'task')
        {
            # 设置一个回调
            $this->taskCallbackList[$id] = $callback;
        }

        $str  = ($this->key ? \MyQEE\Server\RPC\Server::encrypt($obj, $this->key) : msgpack_pack($obj)) . \MyQEE\Server\RPC\Server::$EOF;
        $len  = strlen($str);
        $wLen = @fwrite($resource, $str);

        if ($len !== $wLen)
        {
            # 发送失败
            $this->close();
            return false;
        }
        else
        {
            $this->lastTaskId = $obj->id;
            return true;
        }
    }

    /**
     * 投递任务并等待服务器返回
     *
     * @param mixed  $data 数据
     * @param float  $timeout 超时时间
     * @param string $workerName 当前进程对应的名称
     * @return mixed
     */
    public function taskWait($data, $timeout = 0.5, $workerName)
    {
        if ($this->sendData('taskWait', $data, $workerName))
        {
            # 发送成功开始读取数据
            $taskId   = $this->lastTaskId;
            $time     = microtime(1);
            $buffer   =& $this->buffer;
            $eof      = \MyQEE\Server\RPC\Server::$EOF;
            $eofLen   = - strlen($eof);

            while (true)
            {
                # 读取数据
                $rs = fread($this->socket, 4096);
                if ($rs === '')
                {
                    if (microtime(1) - $time > $timeout)
                    {
                        # 超时返回
                        return false;
                    }
                    usleep(10000);
                    continue;
                }

                if (substr($rs, $eofLen) === $eof)
                {
                    foreach (explode($eof, rtrim($buffer . $rs)) as $item)
                    {
                        $rs = $this->callbackByString($item, true);

                        if ($rs)
                        {
                            if ($rs->id === $taskId)
                            {
                                # 这个是当前任务返回的数据
                                $currentResult = $rs;
                            }
                            else
                            {
                                # 之前的任务回调
                                $this->callbackFinish($rs->id, $rs->data, $rs->wname);
                            }
                        }
                    }
                    $buffer = '';
                }
                elseif (strpos($rs, $eof))
                {
                    # 未封闭的数据
                    if ($buffer)$rs = $buffer . $rs;
                    $arr = explode($eof, $rs);
                    $num = count($arr) - 1;

                    if ($num > 0)for ($i = 0; $i < $num; $i++)
                    {
                        $rs = $this->callbackByString($arr[$i], true);

                        if ($rs)
                        {
                            if ($rs->id === $taskId)
                            {
                                # 这个是当前任务返回的数据
                                $currentResult = $rs;
                            }
                            else
                            {
                                # 执行回调功能
                                $this->callbackFinish($rs->id, $rs->data, $rs->wname);
                            }
                        }
                    }
                    $buffer = $arr[$num];
                }

                if (isset($currentResult))return $currentResult->data;

                # 更新超时时间
                $time = microtime(1);
            }

            return false;
        }
        else
        {
            return false;
        }
    }

    /**
     * 连接任务服务器
     *
     * @param null $ip
     * @param null $port
     * @return bool
     */
    protected function connect()
    {
        if (!$this->ip)return false;

        if (!$this->port)return false;

        if ($this->socket)
        {
            # 关闭连接
            $this->close();
        }

        $socket = @stream_socket_client("tcp://$this->ip:$this->port", $errno, $errstr, 0.3, STREAM_CLIENT_CONNECT);

        if ($errno)
        {
            Server::$instance->warn("Connect tcp://$this->ip:$this->port error, $errstr");
            return false;
        }
        stream_set_timeout($socket, 0, 10);

        # 任务进程没有异步功能, 直接返回
        if (Server::$instance->server->taskworker)return true;

        $eof    = \MyQEE\Server\RPC\Server::$EOF;
        $eofLen = - strlen($eof);

        # 加入到事件循环里
        $rs = swoole_event_add($socket, function($socket) use ($eof, $eofLen)
        {
            $buffer =& $this->buffer;
            $rs     = fread($socket, 1);
            if ($rs === '')
            {
                # 如果有事件响应但是读取了个空字符, 说明服务器已经断开了连接, 调用 close 方法移除对象
                $this->close();
                return;
            }
            $buffer .= $rs;

            while (true)
            {
                $rs = fread($socket, 4096);
                if ($rs === '')
                {
                    break;
                }

                if (substr($rs, $eofLen) === $eof)
                {
                    foreach (explode($eof, rtrim($buffer . $rs)) as $item)
                    {
                        $this->callbackByString($item);
                    }
                    $buffer = '';
                }
                elseif (strpos($rs, $eof))
                {
                    if ($buffer)$rs = $buffer . $rs;
                    $arr = explode($eof, $rs);
                    $num = count($arr) - 1;
                    if ($num > 0)for ($i = 0; $i < $num; $i++)
                    {
                        $this->callbackByString($arr[$i]);
                    }
                    $buffer = $arr[$num];
                }
            }
        });

        if ($rs)
        {
            # 绑定
            $obj       = new \stdClass();
            $obj->bind = true;
            $obj->id   = $this->workerId;
            $str       = msgpack_pack($obj) . $eof;
            $len       = strlen($str);

            if ($len === fwrite($socket, $str, $len))
            {
                $this->socket = $socket;

                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            return false;
        }
    }

    protected function close()
    {
        if ($this->socket)
        {
            if (!Server::$instance->server->taskworker)
            {
                swoole_event_del($this->socket);
            }

            @fclose($this->socket);
            $this->socket = null;
        }

        # 重置任务列表
        $this->taskCallbackList = [];
    }

    /**
     * 获取连接客户端
     *
     * @return resource|false
     */
    protected function resource()
    {
        if ($this->socket)return $this->socket;

        $rs = $this->connect();
        if (!$rs)
        {
            return false;
        }

        return $this->socket ?: false;
    }


    protected function callbackByString($str, $rs = false)
    {
        if ($obj = @msgpack_unpack($str))
        {
            unset($str);
            if ($this->key)
            {
                $obj = \MyQEE\Server\RPC\Server::decryption($obj, $this->key);
            }

            if (!$obj)
            {
                Server::$instance->warn('decryption task result data error');
            }

            if ($rs)
            {
                return $obj;
            }
            else
            {
                $this->callbackFinish($obj->id, $obj->data, $obj->wname);
            }
        }

        return null;
    }

    protected function callbackFinish($taskId, $data, $workerName)
    {
        if (isset($this->taskCallbackList[$taskId]))
        {
            # 自定义回调
            $callback = $this->taskCallbackList[$taskId];
            unset($this->taskCallbackList[$taskId]);
            $callback(Server::$instance->server, $taskId, $data);
        }
        elseif (isset(Server::$instance->workers[$workerName]))
        {
            # 执行回调
            /**
             * @var \MyQEE\Server\Worker $worker
             */
            $worker = Server::$instance->workers[$workerName];
            $worker->onFinish(Server::$instance->server, $taskId, $data);
        }
        else
        {
            Server::$instance->worker->onFinish(Server::$instance->server, $taskId, $data);
        }
    }
}
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
     * @var string
     */
    protected $lastTaskId;

    /**
     * 未读完的数据
     *
     * @var string
     */
    protected $buffer = '';

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
            $serverGroup = Server::$config['clusters']['group'] ?: 'default';
        }

        if (-1 === $serverId)
        {
            # 随机服务器ID
        }

        if (-1 === $workerId)
        {
            # 随机ID
            $host = Host::$table->get("{$serverGroup}_{$serverId}");
            if (!$host)return false;

            if ($isTask)
            {
                $workerId = mt_rand(0, $host['task_num'] - 1);
            }
            else
            {
                $workerId = mt_rand(0, $host['worker_num'] - 1);
            }
        }

        # 生成一个KEY
        $key = "{$serverGroup}_{$serverId}_{$workerId}_" . ($isTask ? 'task' : 'worker');

        if (!isset(self::$instances[$key]))
        {
            if (!isset($host))
            {
                $host = Host::$table->get($serverId);
                if (!$host)return false;
            }

            # 检查任务ID是否超出序号返回
            if ($isTask)
            {
                if ($workerId - $host['task_num'] > 1)return false;
            }
            else
            {
                if ($workerId - $host['worker_num'] > 1)return false;
            }

            /**
             * @var Client $client
             */
            $class            = static::class;
            $client           = new $class();
            $client->serverId = $serverId;
            $client->workerId = $workerId;
            $client->key      = $host['key'];
            $client->ip       = $host['ip'];
            $client->port     = $isTask ? $host['task_port'] : $host['port'];
            $rs               = $client->connect();

            if (!$rs)
            {
                # 没有连接上去
                return false;
            }

            self::$instances[$key] = $client;
        }

        return self::$instances[$serverId];
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
     * @return bool
     */
    public function sendData($type, $data, $workerName)
    {
        $resource = $this->resource();
        if (!$resource)
        {
            return false;
        }

        $obj        = new \stdClass();
        $obj->type  = $type;
        $obj->id    = md5(microtime(1));
        $obj->wid   = $this->workerId;
        $obj->wname = $workerName;
        $obj->data  = $data;
        $str        = ($this->key ? \MyQEE\Server\RPC\Server::encrypt($obj, $this->key) : msgpack_pack($obj)) . "\r\n";
        $rs         = fwrite($resource, $str) === strlen($str);

        if (false === $rs)
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
     * @param        $data
     * @param float  $timeout
     * @param int    $workerId
     * @param string $workerName 当前进程对应的名称
     * @return mixed
     */
    public function taskWait($data, $timeout = 0.5, $workerName)
    {
        if ($this->sendData('taskWait', $data, $workerName))
        {
            # 发送成功开始读取数据
            $resource = $this->resource();
            $taskId   = $this->lastTaskId;
            $time     = microtime(1);
            $buffer   =& $this->buffer;

            while (true)
            {
                # 读取数据
                $rs = fread($resource, 4096);
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

                if (substr($rs, -2) === "\r\n")
                {
                    foreach (explode("\r\n", rtrim($buffer . $rs)) as $item)
                    {
                        $rs = $this->callbackByString($item, true);

                        if ($rs)
                        {
                            if ($rs->id === $taskId)
                            {
                                # 这个是当前任务返回的数据
                                $currentResult = $rs->data;
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
                elseif (strpos($rs, "\r\n"))
                {
                    # 未封闭的数据
                    if ($buffer)$rs = $buffer . $rs;
                    $arr = explode("\r\n", $rs);
                    $num = count($arr) - 1;

                    if ($num > 0)for ($i = 0; $i < $num; $i++)
                    {
                        $rs = $this->callbackByString($arr[$i], true);

                        if ($rs)
                        {
                            if ($rs->id === $taskId)
                            {
                                # 这个是当前任务返回的数据
                                $currentResult = $rs->data;
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

                if (isset($currentResult))return $currentResult;

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

        $socket = stream_socket_client("tcp://$this->ip:$this->port", $errno, $errstr, 0.3, STREAM_CLIENT_CONNECT);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec'=> 600, 'usec'=> 0]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec'=> 0,   'usec'=> 50]);

        if (!$socket)return false;

        # 任务进程没有异步功能, 直接返回
        if (Server::$server->taskworker)return true;

        # 加入到事件循环里
        $rs = swoole_event_add($socket, function($socket)
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

                if (substr($rs, -2) === "\r\n")
                {
                    foreach (explode("\r\n", rtrim($buffer . $rs)) as $item)
                    {
                        $this->callbackByString($item);
                    }
                    $buffer = '';
                }
                elseif (strpos($rs, "\r\n"))
                {
                    if ($buffer)$rs = $buffer . $rs;
                    $arr = explode("\r\n", $rs);
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
            fwrite($socket, msgpack_pack($obj) . "\r\n");
            $this->socket = $socket;

            return true;
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
            if (!Server::$server->taskworker)
            {
                swoole_event_del($this->socket);
            }

            @fclose($this->socket);
            $this->socket = null;
        }
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
        if ($workerName === 'Main')
        {
            # 执行回调
            Server::$worker->onFinish(Server::$server, $taskId, $data);
        }
        elseif (isset(Server::$workers[$workerName]))
        {
            # 执行回调
            /**
             * @var \MyQEE\Server\Worker $worker
             */
            $worker = Server::$workers[$workerName];
            $worker->onFinish(Server::$server, $taskId, $data);
        }
        else
        {
            Server::$instance->warn("task callback unknown worker type: $workerName");
        }
    }
}
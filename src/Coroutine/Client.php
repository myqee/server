<?php
namespace MyQEE\Server\Coroutine;

use MyQEE\Server\Server;

/**
 * 协程网络连接客户端
 *
 * 支持 Task 进程
 *
 * @package MyQEE\Server\Coroutine
 */
class Client
{
    public $errCode;

    /**
     * @var \MyQEE\Server\Coroutine\Task
     */
    protected $coroutine;

    /**
     * @var \Swoole\Client
     */
    protected $client;

    protected $recvQueue;

    /**
     * 一个异步调用对象
     *
     * @var Async
     */
    protected $recvEvent;

    protected $isConnected = null;

    /**
     * 最后连接参数
     *
     * @var array
     */
    protected $connectOption = [];

    /**
     * 是否异步客户端
     *
     * @var bool
     */
    protected $isAsyncClient;

    const MAX_RECEIVE_QUEUE = 10000;

    public function __construct($sockType = SWOOLE_SOCK_TCP, $key = null)
    {
        $this->recvQueue = new \SplQueue();

        if (true === Server::$instance->server->taskworker)
        {
            # 任务进程，不支持异步IO
            $this->createInSyncWorker($sockType, $key);
            $this->isAsyncClient = false;
        }
        else
        {
            $this->createInAsyncWorker($sockType, $key);
            $this->isAsyncClient = true;
        }
    }

    public function __destruct()
    {
        if ($this->client->isConnected())
        {
            $this->client->close();
        }
    }

    /**
     * 在同步进程中创建一个同步连接
     *
     * @param $sockType
     * @param $key
     */
    protected function createInSyncWorker($sockType, $key)
    {
        $this->client = new \Swoole\Client($sockType, SWOOLE_SOCK_SYNC, $key);
    }

    /**
     * 在异步进程中创建一个异步连接
     *
     * @param $sockType
     * @param $key
     */
    protected function createInAsyncWorker($sockType, $key)
    {
        $this->client = new \Swoole\Client($sockType, SWOOLE_SOCK_ASYNC, $key);

        $this->client->on('receive', function($server, $data)
        {
            if (null !== $this->recvEvent)
            {
                # 有一个异步监听对象，直接调用回调
                $rs = $this->recvEvent->call($data);
                unset($this->recvEvent);

                if (false !== $rs)
                {
                    # 回调成功
                    return;
                }
            }

            $this->recvQueue->enqueue($data);

            # 自动出队列
            if ($this->recvQueue->count() > static::MAX_RECEIVE_QUEUE)
            {
                $this->recvQueue->dequeue();
            }
        });

        $this->client->on('connect', function($cli)
        {
            $this->isConnected = true;
        });

        $this->client->on('error', function($cli)
        {
            $this->isConnected = false;
            $this->errCode     = $this->client->errCode;
            if ($this->client->isConnected())
            {
                $this->client->close();
            }
        });

        $this->client->on('close', function($cli)
        {
            $this->isConnected = false;
            if ($this->client->isConnected())
            {
                $this->client->close();
            }
        });
    }

    /**
     * 是否连接成功
     *
     * @return bool
     */
    public function isConnected()
    {
        return $this->client->isConnected();
    }

    /**
     * 发送数据
     *
     * @param $data
     * @return int|bool
     */
    public function send($data)
    {
        if (true !== $this->isConnected)
        {
            return false;
        }

        return $this->client->send($data);
    }

    /**
     * 关闭连接
     *
     * @return bool
     */
    public function close()
    {
        $rs                = $this->client->close();
        $this->isConnected = null;
        $this->recvQueue   = new \SplQueue();

        return $rs;
    }

    /**
     * 获取内容
     *
     * @param int $length 读取长度
     * @param int|float $timeout 超时时间，0则不限制
     * @return \Generator
     */
    public function recv($length = 65535, $timeout = 5)
    {
        $time = microtime(true);

        if (true === $this->isAsyncClient)
        {
            while (true)
            {
                if (true !== $this->isConnected)
                {
                    # 已经连接失败，直接返回 false
                    yield false;
                    break;
                }

                if (0 === $this->recvQueue->count())
                {
                    # 继续等待
                    if ($timeout > 0 && microtime(true) - $time > $timeout)
                    {
                        # 超时
                        yield '';
                        break;
                    }
                    elseif (null === $this->recvEvent)
                    {
                        # 返回一个异步等待对象
                        $this->recvEvent = new Async();
                        if ($timeout > 0)
                        {
                            # 设置超时
                            $this->recvEvent->setTimeout($timeout);
                        }

                        yield $this->recvEvent;
                        break;
                    }
                    else
                    {
                        # 跳出等待
                        yield null;
                        continue;
                    }
                }

                # 返回当前数据
                yield $this->recvQueue->dequeue();
                break;
            }
        }
        else
        {
            $socket = $this->client->getSocket();
            while (true)
            {
                $rs = @fread($socket, $length);
                if ($rs)
                {
                    yield $rs;
                    break;
                }
                elseif (microtime(true) - $time > $timeout)
                {
                    yield '';
                    break;
                }
                else
                {
                    yield;
                }
            }
        }
    }

    /**
     * 连接
     *
     * @param       $host
     * @param       $port
     * @param float $timeout
     * @return \Generator
     */
    public function connect($host, $port, $timeout = 0.1)
    {
        if (true === $this->isAsyncClient)
        {
            $this->connectOption = func_get_args();
            yield $this->client->connect($host, $port, $timeout);

            while (true)
            {
                if (null !== $this->isConnected)
                {
                    yield $this->isConnected;
                    break;
                }
                else
                {
                    yield;
                }
            }
        }
        else
        {
            $this->isConnected = $this->client->connect($host, $port, $timeout);

            $socket = $this->client->getSocket();
            stream_set_timeout($socket, 0, 1);
            stream_set_blocking($socket, 0);

            yield $this->isConnected;
        }
    }

    /**
     * 重新连接
     *
     * @return \Generator
     */
    public function reconnect()
    {
        if (true === $this->isConnected)
        {
            $this->close();
        }

        yield call_user_func_array([$this, 'connect'], $this->connectOption);
    }
}
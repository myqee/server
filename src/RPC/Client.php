<?php

namespace MyQEE\Server\RPC;

use MyQEE\Server\RPC;

/**
 * RPC服务端
 *
 * @package MyQEE\Server\RPC
 */
class Client
{
    /**
     * 客户端
     *
     * @var \Swoole\Client
     */
    private $__client;

    private $__ip;

    private $__port;

    /**
     * @var string
     */
    private $__rpc;

    /**
     * 被服务器关闭
     *
     * @var bool
     */
    private $__closeByServer = false;

    private $__data = [];

    /**
     * 回调列表
     *
     * @var array
     */
    private $__events = [];

    /**
     * Client constructor.
     */
    public function __construct($rpc)
    {
        $this->__rpc = "\\" . trim($rpc, '\\');
    }

    public function trigger($event, $arg1 = null, $arg2 = null)
    {
        throw new \Exception('function trigger can only call in rpc server');
    }

    function __get($name)
    {
        if (isset($this->__data[$name]) || array_key_exists($name, $this->__data))
        {
            return $this->__data[$name];
        }

        # 内部变量不允许调用
        if ($name[0] === '_')throw new \Exception("do not allow get $name");

        $rs = $this->__send('get', $name);
        if ($rs)
        {
            $this->__data[$name] = $name;
            $this->__data        = array_merge($this->__data, $rs);
        }
        else
        {
            throw new \Exception('rpc get data error');
        }

        return $this->__data[$name];
    }

    function __set($name, $value)
    {
        if ($name[0] === '_')throw new \Exception("do not allow set $name");

        return $this->__send('set', $name, $value);
    }

    function __call($name, $arguments)
    {
        if ($name[0] === '_')throw new \Exception("do not allow call function $name");

        return $this->__send('fun', $name, $arguments);
    }

    /**
     * @param      $type
     * @param      $name
     * @param null $v
     * @return mixed
     */
    protected function __send($type, $name, $args = null)
    {
        if (!$this->__client)return false;

        $obj       = new \stdClass();
        $obj->id   = microtime(1);
        $obj->type = $type;
        $obj->name = $name;

        if ($args)
        {
            $obj->args = $args;
        }

        /**
         * @var RPC $rpc
         */
        $rpc  = $this->__rpc;
        $key  = $rpc::_getRpcKey();
        $str  = Server::encrypt($obj, $key, $this->__rpc) . Server::$EOF;
        $len  = strlen($str);
        $sock = fopen("php://fd/{$this->__client->sock}", 'w');
        $wLen = fwrite($sock, $str, $len);

        if ($wLen !== $len)
        {
            return false;
        }

        $rs = fread($sock, 1048576);
        fclose($sock);

        if ($rs[0] === '{')
        {
            $rs = @json_decode($rs);
        }
        else
        {
            $rs = msgpack_unpack($rs);
            if ($key)
            {
                $rs = Server::decryption($rs, $key);
            }
        }

        if (is_object($rs) && $rs instanceof \stdClass)
        {
            switch ($rs->type)
            {
                case 'error':
                    # 系统返回了一个错误
                    throw new \Exception($rs->msg, $rs->code);
                    break;

                case 'close':
                    $this->__closeByServer = true;
                    throw new \Exception($rs->msg, $rs->code);
                    break;
            }
        }

        return $rs;
    }

    public function on($event, $callback)
    {
        if ($this->__client)throw new \Exception('can not add event after connect rpc server');

        $event                  = strtolower($event);
        $this->__events[$event] = $callback;
    }

    /**
     * 连接一个RPC服务器
     *
     * @param $ip
     * @param $port
     * @return bool
     */
    public function connect($ip, $port)
    {
        if ($this->__client)
        {
            @$this->__client->close();
            $this->__client = null;
        }

        /**
         * @var RPC $rpc
         */
        $this->__ip            = $ip;
        $this->__port          = $port;
        $this->__closeByServer = false;
        $rpc                   = $this->__rpc;
        $key                   = $rpc::_getRpcKey();

        $client = new \Swoole\Client(SWOOLE_TCP, SWOOLE_SOCK_ASYNC);

        $client->on('receive', function($client, $data) use ($key)
        {
            $arr = explode(Server::$EOF, $data);

            foreach ($arr as $item)
            {
                if ($item === '')continue;

                $tmp = @msgpack_unpack($item);
                if ($key)
                {
                    $tmp = Server::decryption($tmp, $key);

                    if (!$tmp)
                    {
                        \MyQEE\Server\Server::$instance->warn('rpc decryption data fail. data: ' . $item);

                        continue;
                    }
                }

                switch ($tmp->type)
                {
                    case 'on':
                        $event = $tmp->event;
                        if (isset($this->__events[$event]))
                        {
                            # 回调执行
                            call_user_func_array($this->__events[$event], $tmp->args);
                        }
                        else
                        {
                            \MyQEE\Server\Server::$instance->warn("unknown rpc {$this->__rpc} event: {$event}");
                        }
                        break;

                    case 'close':
                        $this->__closeByServer = true;
                        $this->__client = null;
                        break;

                    default:
                        \MyQEE\Server\Server::$instance->warn("unknown rpc type {$tmp->type}");
                        break;
                }
            }
        });

        $client->on('connect', function($client)
        {
            if (isset($this->__events['connect']))
            {
                # 回调自定义的事件
                call_user_func($this->__events['connect'], $client);
            }
        });

        $client->on('close', function($client)
        {
            $this->__client = null;
            \MyQEE\Server\Server::$instance->warn("rpc connection closed, $this->__ip:$this->__port.");

            if (!$this->isClosedByServer())
            {
                # 不是被服务器强制关闭的则自动重新连接
                $this->reconnect();
            }

            if (isset($this->__events['close']))
            {
                # 回调自定义的事件
                call_user_func($this->__events['close'], $client);
            }
        });

        $client->on('error', function($client)
        {
            $this->__client = null;
            \MyQEE\Server\Server::$instance->warn("rpc connection($this->__ip:$this->__port) error: ". socket_strerror($client->errCode));

            # 遇到错误则自动重连
            swoole_timer_after(3000, function()
            {
                $this->reconnect();
            });

            if (isset($this->__events['error']))
            {
                # 回调自定义的事件
                call_user_func($this->__events['error'], $client);
            }
        });

        $this->__client = $client;

        # 发心跳包
        swoole_timer_tick(1000 * 60 * 5, function()
        {
            if ($this->__client && $this->__client->isConnected())
            {
                $this->__client->send("\0". Server::$EOF);
            }
        });

        $this->__client->connect($ip, $port);

        return true;
    }

    public function reconnect()
    {
        $this->connect($this->__ip, $this->__port);
    }

    /**
     * 关闭服务
     */
    public function close()
    {
        if ($this->__client)
        {
            @$this->__client->close();
            $this->__client = null;
        }
    }

    /**
     * 是否被服务器强制关闭连接的
     *
     * @return bool
     */
    public function isClosedByServer()
    {
        return $this->__closeByServer;
    }
}
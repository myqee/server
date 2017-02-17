<?php
namespace MyQEE\Server\RPC;

use MyQEE\Server\RPC;
use MyQEE\Server\WorkerTCP;

/**
 * RPC端口服务器对象
 *
 * @package MyQEE\Server\RPC
 */
class Server extends WorkerTCP
{
    /**
     * 默认监听设置
     *
     * @var array
     */
    protected $config = [];

    /**
     * 分包协议结尾符
     *
     * 数字 3338 使用 msgpack 编码后会产生一个 \r\n, 所以切不可只用 \r\n 做分隔符
     *
     * @var string
     */
    public static $EOF = "\r\n\r\n";

    /**
     * RPC 对象名
     *
     * @var string
     */
    public static $defaultRPC = '\\RPC';

    /**
     * 不允许调用的方法, 全小写, 下划线(_)开头的的方法已经默认不允许了
     *
     * @var array
     */
    public static $forbiddenAction = ['trigger', 'client', 'isclosedbyserver', 'factory'];

    /**
     * 监听一个端口
     *
     * @param $ip
     * @param $port
     */
    public function listen($ip, $port, $type = SWOOLE_SOCK_TCP)
    {
        $port = \MyQEE\Server\Server::$instance->server->listen($ip, $port, $type);

        $config = [
            'open_eof_check' => true,
            'open_eof_split' => true,
            'package_eof'    => static::$EOF,
        ];

        if ($this->config)
        {
            $config   = array_merge($config, $this->config);
            static::$EOF = $config['package_eof'];
        }

        $port->set($config);
        $port->on('Connect', [$this, 'onConnect']);
        $port->on('Close',   [$this, 'onClose']);
        $port->on('Receive', [$this, 'onReceive']);
    }

    /**
     * 接受到任意进程的调用
     *
     * @param \Swoole\Server $server
     * @param int   $fromWorkerId
     * @param mixed $message
     * @return mixed
     */
    public function onPipeMessage($server, $fromWorkerId, $message, $serverId = -1)
    {
        if (is_object($message) && $message instanceof \stdClass)
        {
            switch ($message->type)
            {
                case 'call':
                    $this->call($message->fd, $message->fromId, $message->data);
                    break;
            }
        }
    }

    /**
     * 收到信息
     *
     * @param \Swoole\Server $server
     * @param $fd
     * @param $fromId
     * @param $data
     */
    public function onReceive($server, $fd, $fromId, $data)
    {
        $data = trim($data);
        if ($data === '')return;

        /**
         * @var \Swoole\Server $server
         */
        $tmp = @msgpack_unpack($data);

        if (is_object($tmp) && $tmp instanceof \stdClass)
        {
            $rpc = static::$defaultRPC;
            if ($tmp->rpc)
            {
                if (preg_match('#^[\\\\0-9a-z]+$#i', $tmp->rpc))
                {
                    $rpc = $tmp->rpc;
                }
            }

            /**
             * @var RPC $rpc
             */
            $key = $rpc::_getRpcKey();

            if ($key)
            {
                # 解密数据
                $tmp = self::decryption($tmp, $key, $rpc);

                if (false === $tmp)
                {
                    # 数据错误
                    $server->send($fd, '{"type":"close","code":0,"msg":"decryption fail."}' . static::$EOF);
                    $server->close($fd, $fromId);

                    \MyQEE\Server\Server::$instance->debug("register server decryption error, data: " . substr($data, 0, 256) . (strlen($data) > 256 ? '...' : ''));

                    return;
                }
            }

            $data = $tmp;
            unset($tmp);

            if ('bind' === $data->type)
            {
                if ($this->server->setting['dispatch_mode'] === 5 && is_int($data->id))
                {
                    # 支持 worker 绑定
                    $this->server->bind($fd, $data->id);
                }

                return;
            }

            $data->rpc = $rpc;

            if (in_array($this->server->setting['dispatch_mode'], [1, 3]) && ($workerNum = $this->server->setting['worker_num']) > 1)
            {
                # 服务器是轮循或抢占模式, 每次请求的可能不是同一个 worker
                $workerId = $fd % $workerNum;

                if ($workerId !== $this->server->worker_id)
                {
                    # 发送给对应的进程去处理
                    $obj         = new \stdClass();
                    $obj->type   = 'call';
                    $obj->fd     = $fd;
                    $obj->fromId = $fromId;
                    $obj->data   = $data;

                    $this->sendMessage($data, $workerId);
                    return;
                }
            }

            # 执行RPC调用
            $this->call($fd, $fromId, $data);
        }
        else
        {
            \MyQEE\Server\Server::$instance->warn("rpc get error msgpack data: ". substr($data, 0, 256) . (strlen($data) > 256 ? '...' :''));
            $this->server->close($fd, $fromId);
            return;
        }
    }

    protected function call($fd, $fromId, \stdClass $data)
    {
        $rpc = $data->rpc ?: static::$defaultRPC;

        try
        {
            if (isset(RPC::$__instance[$rpc][$fd]))
            {
                # 复用已经存在的对象
                $class = RPC::$__instance[$rpc][$fd];
            }
            else
            {
                $class = new $rpc($fd, $fromId);
                if (!($class instanceof RPC))
                {
                    throw new \Exception("class $rpc not allow call by rpc");
                }
            }

            $name  = trim((string)$data->name);
            $args  = $data->args;

            if ($name[0] === '_')throw new \Exception("$name not allowed to call");

            switch ($data->type)
            {
                case 'fun':
                    if (in_array(strtolower($name), static::$forbiddenAction))throw new \Exception("$name not allowed to call");

                    $rs = call_user_func_array([$class, $name], $args ?: []);

                    break;

                case 'set':
                    $class->$name = $args;
                    $rs = true;
                    break;

                case 'get':
                    $rs = $class->$name;
                    break;

                default:
                    throw new \Exception("undefined type $data->type");
            }

            if ($class->isClosedByServer())
            {
                # 已经在程序里被关闭连接了
                return;
            }
        }
        catch (\Exception $e)
        {
            $rs       = new \stdClass();
            $rs->type = 'error';
            $rs->code = $e->getCode();
            $rs->msg  = $e->getMessage();
        }

        if (is_object($rs) && $rs instanceof Result)
        {
            $string = $rs->data;
            $call   = true;
        }
        else
        {
            $string = $rs;
            $call   = false;
        }

        /**
         * @var RPC $rpc
         */
        $key    = $rpc::_getRpcKey();
        $string = $key ? self::encrypt($string, $key) : msgpack_pack($string);
        $status = $this->server->send($fd, $string, $fromId);

        if ($call)
        {
            /**
             * @var Result $rs
             */
            if (false === $status)
            {
                $rs->trigger('error');
            }
            else
            {
                $rs->trigger('success');
            }

            $rs->trigger('complete');
        }
    }

    /**
     * 加密数据
     *
     * @param mixed $data
     * @return string
     */
    public static function encrypt($data, $key = null, $rpc = null)
    {
        $rs = new \stdClass();
        if ($rpc && $rpc !== static::$defaultRPC)
        {
            $rs->rpc = $rpc;
        }

        $string   = msgpack_pack($data);
        $rs->type = is_object($data) ? ($data instanceof \stdClass || isset($data->type) ? $data->type : get_class($data)) : 'e';
        $rs->data = self::rc4($string, false, $key);
        $rs->hash = md5($string . $key. $rs->rpc);

        return msgpack_pack($rs);
    }


    /**
     * 解密数据
     *
     * @param $data
     * @return false|mixed
     */
    public static function decryption($data, $key = null, $rpc = null)
    {
        if (!($data instanceof \stdClass))return false;

        if ($rpc === static::$defaultRPC)
        {
            $rpc = null;
        }

        if (!$data->data || !($tmp = self::rc4($data->data, true, $key)) || $data->hash !== md5($tmp.$key.$rpc))
        {
            # 数据错误
            return false;
        }

        return msgpack_unpack($tmp);
    }


    /**
     * 优化版rc4加密解密
     *
     * @param string $string
     * @param string $isDecode
     * @param string $key
     * @param number $expiry
     * @return string
     */
    protected static function rc4($string, $isDecode = true, $key = null, $expiry = 0)
    {
        $cKeyLength = 4;
        $key        = md5($key ?: __DIR__);
        $keya       = md5(substr($key, 0, 16));
        $keyb       = md5(substr($key, 16, 16));
        $keyc       = $cKeyLength ? ($isDecode === true ? substr($string, 0, $cKeyLength) : substr(md5(microtime()), -$cKeyLength)) : '';
        $cryptkey   = $keya . md5($keya . $keyc);
        $key_length = strlen($cryptkey);

        $string        = $isDecode == true ? base64_decode(substr($string, $cKeyLength)) : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string.$keyb), 0, 16) . $string;
        $string_length = strlen($string);
        $result        = '';
        $box           = range(0, 255);
        $randKey       = [];

        for($i = 0; $i <= 255; $i++)
        {
            $randKey[$i] = ord($cryptkey[$i % $key_length]);
        }

        for($j = $i = 0; $i < 256; $i++)
        {
            $j       = ($j + $box[$i] + $randKey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        for($a = $j = $i = 0; $i < $string_length; $i++)
        {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if($isDecode == true)
        {
            if((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16))
            {
                return substr($result, 26);
            }
            else
            {
                return '';
            }
        }
        else
        {
            return $keyc . base64_encode($result);
        }
    }
}
<?php
namespace MyQEE\Server;

/**
 * RPC 基础类
 *
 * 在客户端使用 `\RPC::Client()` 来获取对象, 不要使用 `new RPC()` 方式来构造对象
 *
 * 用于提供暴露出来的方法, 对象参数
 *
 * @package MyQEE\Server
 */
class RPC
{
    /**
     * 当前连接FD
     *
     * @var int
     */
    private $__fd = null;

    /**
     * 当前连接所在线程ID
     *
     * @var int
     */
    private $__fromId = 0;

    private $__isClosed = false;

    /**
     * 此RPC通讯密钥
     *
     * @var string
     */
    public static $RPC_KEY = __FILE__;

    public static $__instance = [];

    /**
     * @param     $fd
     * @param int $fromId
     */
    public function __construct($fd, $fromId = 0)
    {
        $this->__fd     = $fd;
        $this->__fromId = $fromId;
    }

    /**
     * @param      $fd
     * @param null $fromId
     * @return static
     */
    public static function factory($fd, $fromId = null)
    {
        $class = static::class;
        $obj   = new $class($fd, $fromId);
        return $obj;
    }

    /**
     * 设置此对象为可复用
     *
     * 系统会为每个客户端创建一个对象RPC的对象, 调用完毕后销毁, 设置后只要客户端没有断开则不会销毁
     *
     * 可以使用 `$this->release()` 释放对象
     *
     * @return $this
     */
    protected function enableReuse()
    {
        $rpc                                 = get_class($this);
        self::$__instance[$rpc][$this->__fd] = $this;

        return $this;
    }

    /**
     * 取消可复用
     */
    protected function disableReuse()
    {
        $rpc = get_class($this);

        if (!isset(self::$__instance[$rpc]))return;

        unset(self::$__instance[$rpc][$this->__fd]);
        if (!self::$__instance[$rpc])
        {
            unset(self::$__instance[$rpc]);
        }
    }

    /**
     * 服务器上触发客户端一个事件
     *
     * @param      $event
     * @param null $arg1
     * @param null $arg2
     * @return bool
     */
    public function trigger($event, $arg1 = null, $arg2 = null)
    {
        if (null === $this->__fd)return false;

        $args = func_get_args();
        array_shift($args);

        $obj        = new \stdClass();
        $obj->type  = 'on';
        $obj->event = $event;
        $obj->args  = $args;
        $string     = RPC\Server::encrypt($obj, static::_getRpcKey()) . RPC\Server::$EOF;

        return Server::$instance->server->send($this->__fd, $string, $this->__fromId);
    }

    /**
     * 返回当前RPC连接的信息
     *
     * 额外提供 fd 和 from_id
     *
     * @return array|bool
     */
    protected function connectionInfo()
    {
        $rs = Server::$instance->server->connection_info($this->__fd);
        if (!$rs)return false;

        $rs['fd']      = $this->__fd;
        $rs['from_id'] = $this->__fromId;

        return $rs;
    }

    /**
     * 关闭当前RPC客户端连接
     */
    protected function closeClient($msg = null)
    {
        $data = [
            'type' => 'close',
            'msg'  => $msg,
            'code' => 0,
        ];
        $this->__isClosed = true;
        Server::$instance->server->send($this->__fd, json_encode($data, JSON_UNESCAPED_UNICODE) . RPC\Server::$EOF, $this->__fromId);
        Server::$instance->server->close($this->__fd, $this->__fromId);
        $this->disableReuse();
    }

    /**
     * 是否被服务器强制关闭连接的
     *
     * @return bool
     */
    public function isClosedByServer()
    {
        return $this->__isClosed;
    }

    /**
     * 返回PRC调用的客户端, 客户端使用
     *
     * @return RPC\Client
     */
    public static function Client()
    {
        return new RPC\Client(static::class);
    }

    /**
     * 返回当前RPC的通讯密钥
     *
     * @return string
     */
    public static function _getRpcKey()
    {
        return static::$RPC_KEY;
    }
}
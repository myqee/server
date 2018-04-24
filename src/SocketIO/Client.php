<?php
namespace MyQEE\Server\SocketIO;

use \MyQEE\Server\Server;

/**
 * SocketIO 的客户端对象
 *
 * 每个连接成功的都将分配一个这样的对象
 *
 * @package MyQEE\Server\SocketIO
 */
class Client
{
    /**
     * @var int
     */
    public $fd;

    /**
     * 最后活跃时间，会在收到 ping 信息后自动更新
     *
     * @var int
     */
    public $lastPingTime = 0;

    /**
     * 房间列表
     *
     * @var array
     */
    protected $rooms = [];

    /**
     * 所有客户端列表
     *
     * @var array
     */
    public static $instances = [];

    /**
     * 所有房间列表
     *
     * @var array|\Ds\Map
     */
    public static $ALL_ROOMS = [];

    /**
     * 是否支持 DS 对象，系统会自动初始化
     *
     * @var bool
     */
    protected static $SUPPORT_DS = null;


    /**
     * SocketIOClient constructor.
     *
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function __construct($fd)
    {
        $this->fd = $fd;
        $this->lastPingTime = time();
    }

    /**
     * 获取一个缓存的客户端对象
     *
     * @param $fd
     * @return static
     */
    public static function instance($fd)
    {
        $class = static::class;

        if (!isset(self::$instances[$class]))
        {
            self::$instances[$class] = true === self::$SUPPORT_DS ? new \Ds\Map() : [];
        }

        $list =& self::$instances[$class];

        if (!isset($list[$fd]))
        {
            $list[$fd] = new $class($fd);
        }

        return $list[$fd];
    }

    /**
     * 推送数据
     *
     * @param \Swoole\Http\Response $response
     * @param $name
     * @param mixed $data1
     * @param mixed $data2
     */
    public function emit($event, $data1 = null, $data2 = null)
    {
        Server::$instance->server->push($this->fd, '42'. json_encode(func_get_args()));
    }

    /**
     * 发送一个消息
     *
     * @param null $data1
     * @param null $data2
     */
    public function send($data1 = null, $data2 = null)
    {
        call_user_func_array([$this, 'emit'], array_merge(['message'], func_get_args()));
    }

    /**
     * 获取所有房间
     *
     * @return array
     */
    public function getRooms()
    {
        return $this->rooms;
    }

    /**
     * 加入一个房间
     *
     * @param $room
     * @return $this
     */
    public function joinRoom($room)
    {
        $this->rooms[$room] = $room;

        if (!isset(self::$ALL_ROOMS[$room]))
        {
            self::$ALL_ROOMS[$room] = true === self::$SUPPORT_DS ? new \Ds\Map() : [];
        }

        self::$ALL_ROOMS[$room][$this->fd] = $this->fd;

        return $this;
    }

    /**
     * 离开房间
     *
     * @param string|array $room
     * @return $this
     */
    public function leaveRoom($room)
    {
        if (is_array($room))
        {
            foreach ($room as $r)
            {
                $this->leaveRoom($r);
            }
            return $this;
        }

        unset($this->rooms[$room]);
        if (isset(self::$ALL_ROOMS[$room][$this->fd]))
        {
            unset(self::$ALL_ROOMS[$room][$this->fd]);
            if (count(self::$ALL_ROOMS[$room]) == 0)
            {
                unset(self::$ALL_ROOMS[$room]);
            }
        }

        return $this;
    }

    /**
     * 关闭连接
     */
    public function close()
    {
        $server = Server::$instance->server;
        $server->push($this->fd, '1');
        $server->close($this->fd);

        $this->remove();
    }

    /**
     * 移除对象
     */
    public function remove()
    {
        if ($this->rooms)
        {
            $this->leaveRoom($this->rooms);
        }

        $class = static::class;
        unset(self::$instances[$class][$this->fd]);
    }

    /**
     * 连接客户端是否存在
     *
     * @param $fd
     * @return bool
     */
    public static function exist($fd)
    {
        $class = static::class;
        return isset(self::$instances[$class][$fd]);
    }

    /**
     * 初始化
     */
    public static function init()
    {
        self::$SUPPORT_DS = class_exists('\\Ds\\Map', false);
        if (true === self::$SUPPORT_DS)
        {
            self::$ALL_ROOMS = new \Ds\Map();
        }
    }
}
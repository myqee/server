<?php
namespace MyQEE\Server\SocketIO;

/**
 * @package MyQEE\Server\SocketIO
 */
class Server extends \MyQEE\Server\Server
{
    /**
     * @var \Swoole\Table
     */
    public $socketsIOClientRooms;

    /**
     * @var \Swoole\Table
     */
    public $socketsIORooms;

    /**
     * 最大房间数
     *
     * @var int
     */
    protected static $maxRoomNum = 64;

    /**
     * 最大客户端占用房间数
     *
     * 假设有10个客户端每个客户端都占用3个房间，那么这个总占用数为30
     *
     * @var int
     */
    protected static $maxRoomClientNum  = 1048576;

    protected function checkConfig()
    {
        parent::checkConfig();

        $this->socketsIORooms = new \Swoole\Table(self::$maxRoomNum);
        $this->socketsIORooms->column('name', \SWOOLE\Table::TYPE_STRING, 128);     # 房间名称
        $this->socketsIORooms->column('count', \SWOOLE\Table::TYPE_INT, 4);         # 房间客户端数
        $this->socketsIORooms->create();

        $this->socketsIOClientRooms = new \Swoole\Table(self::$maxRoomClientNum);
        $this->socketsIOClientRooms->column('roomId', \SWOOLE\Table::TYPE_INT, 4);  # 房间ID
        $this->socketsIOClientRooms->create();
    }
}

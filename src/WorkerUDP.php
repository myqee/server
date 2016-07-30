<?php
namespace MyQEE\Server;

abstract class WorkerUDP extends Worker
{
    /**
     * UDP下收到数据回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    abstract public function onPacket($server, $data, $clientInfo);
}
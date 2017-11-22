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
     * @return null|\Generator
     */
    abstract public function onPacket($server, $data, $clientInfo);
}
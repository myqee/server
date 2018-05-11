<?php
namespace MyQEE\Server;

abstract class WorkerUDP extends Worker
{
    /**
     * UDP下收到数据回调
     *
     * @param \Swoole\Server $server
     * @param string $data
     * @param array $client 客户端信息， 包括 address/port/server_socket 3项数据
     * @return null|\Generator
     */
    abstract public function onPacket($server, $data, $client);

    public function initEvent()
    {
        parent::initEvent();
        $this->event->bindSysEvent('packet', ['$server', '$data', '$client'], [$this, 'onPacket']);
    }
}
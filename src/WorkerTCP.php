<?php
namespace MyQEE\Server;

abstract class WorkerTCP extends Worker
{
    /**
     * @param \Swoole\Server $server
     * @param $fd
     * @param $fromId
     * @param $data
     * @return null|\Generator
     */
    abstract public function onReceive($server, $fd, $fromId, $data);

    public function initEvent()
    {
        parent::initEvent();

        $this->event->bindSysEvent('receive', ['$server', '$fd', '$fromId', '$data'], [$this, 'onReceive']);
    }
}
<?php
namespace MyQEE\Server;

abstract class WorkerWebSocket extends WorkerHttp
{
    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\WebSocket\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    abstract public function onMessage($server, $frame);

    /**
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     *
     * @param \Swoole\Websocket\Server $svr
     * @param \Swoole\Http\Request $req
     */
    public function onOpen($svr, $req)
    {

    }
}
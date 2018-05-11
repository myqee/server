<?php
namespace MyQEE\Server;

abstract class WorkerWebSocket extends WorkerHttp
{
    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\Server|\Swoole\WebSocket\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     * @return null|\Generator
     */
    abstract public function onMessage($server, $frame);

    /**
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     *
     * @param \Swoole\Websocket\Server $server
     * @param \Swoole\Http\Request $request
     * @return null|\Generator
     */
    public function onOpen($server, $request)
    {

    }

    /**
     * WebSocket建立连接后进行握手
     *
     * 默认不启用，需要设置在对应 hosts 里设置 shake: true 才会生效
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return null|\Generator
     */
    public function onHandShake($request, $response)
    {

    }

    /**
     * 在 onStart() 前系统调用初始化 event 事件
     */
    public function initEvent()
    {
        parent::initEvent();
        $this->event->bindSysEvent('message',   ['$server',  '$frame'],    [$this, 'onMessage']);
        $this->event->bindSysEvent('handShake', ['$request', '$response'], [$this, 'onHandShake']);
        $this->event->bindSysEvent('open',      ['$server',  '$request'],  [$this, 'onOpen']);
    }
}
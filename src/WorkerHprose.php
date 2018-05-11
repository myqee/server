<?php
namespace MyQEE\Server;

/**
 * Hprose服务器端支持类
 *
 * 依赖 `hprose/hprose`, `hprose/hprose-swoole`
 *
 * @see http://hprose.com/
 * @see https://github.com/hprose/hprose-php/wiki
 * @package MyQEE\Server
 */
class WorkerHprose extends Worker
{
    /**
     * @var \Hprose\Swoole\WebSocket\Service|\Hprose\Swoole\Socket\Service
     */
    public $hproseService;

    /**
     * 协议类型
     *
     * 0 - TCP,UNIX
     * 1 - HTTP
     * 2 - WebSocket
     *
     * @var int
     */
    protected $hproseType = 0;
    protected $buffers    = [];
    protected $onReceives = [];

    public function __construct($arguments)
    {
        parent::__construct($arguments);

        $this->initService();
    }

    /**
     * 该事件在调用执行前触发
     *
     * @param string $name
     * @param array $args
     * @param string $byref
     * @param \stdClass $context
     */
    public function onBeforeInvoke($name, &$args, $byref, \stdClass $context)
    {

    }

    /**
     * 该事件在调用执行后触发
     *
     * @param string $name
     * @param array $args
     * @param string $byref
     * @param mixed $result
     * @param \stdClass $context
     */
    public function onAfterInvoke($name, &$args, $byref, &$result, \stdClass $context)
    {

    }

    /**
     * 该事件在服务端发生错误时触发
     *
     * @param string $error
     * @param \stdClass $context
     */
    public function onSendError(& $error, \stdClass $context)
    {

    }

    /**
     * 该事件在服务器发送 HTTP 头时触发
     *
     * @param \stdClass $context
     */
    public function onSendHeader(\stdClass $context)
    {

    }

    /**
     * 接受一个新请求回调
     *
     * 可扩展，这个事件仅 Http\WebSocket 服务器支持
     *
     * @param $context
     * @return mixed
     */
    public function onAccept($context)
    {

    }

    public function initEvent()
    {
        parent::initEvent();

        $this->event->bindSysEvent('request', ['$request', '$response'],              [$this, 'onRequest']);
        $this->event->bindSysEvent('receive', ['$server', '$fd', '$fromId', '$data'], [$this, 'onReceive']);
        $this->event->bindSysEvent('message', ['$server',  '$frame'],                 [$this, 'onMessage']);
        $this->event->bindSysEvent('open',    ['$server',  '$request'],               [$this, 'onOpen']);
    }

    /**
     * 初始化服务
     */
    public function initService()
    {
        if (!class_exists('\\Hprose\\Swoole\\WebSocket\\Service'))
        {
            if ($this->id === 0)
            {
                $this->warn("必须安装 Hprose Swoole 模块, 安装方法: composer require hprose/hprose:dev-master hprose/hprose-swoole:dev-master");

                swoole_timer_after(10, function()
                {
                    $this->server->shutdown();
                });
            }

            return;
        }

        switch ($this->setting['type'])
        {
            case 'ws':
            case 'wss':
                $this->hproseType    = 2;
                $this->hproseService = new \Hprose\Swoole\WebSocket\Service();
                break;

            case 'http':
            case 'https':
                $this->hproseType    = 1;
                $this->hproseService = new \Hprose\Swoole\Http\Service();
                break;

            case 'tcp':
            case 'tcp4':
            case 'tcp6':
            case 'ssl':
            case 'sslv2':
            case 'sslv3':
            case 'tls':
            case 'unix':
                $this->hproseType    = 0;
                $this->hproseService = new \Hprose\Swoole\Socket\Service();
                break;

            default:
                $this->warn("Can't support this scheme: {$this->setting['type']}");
                break;
        }

        # 绑定回调
        $this->hproseService->onSendHeader   = [$this, 'onSendHeader'];
        $this->hproseService->onBeforeInvoke = [$this, 'onBeforeInvoke'];
        $this->hproseService->onAfterInvoke  = [$this, 'onAfterInvoke'];
        $this->hproseService->onSendError    = [$this, 'onSendError'];
    }

    /**
     * @param \Swoole\Server $server
     * @param \Swoole\Http\Request $request
     * @return mixed
     */
    public function onOpen($server, $request)
    {
        $fd = $request->fd;
        if (isset($this->buffers[$fd]))
        {
            unset($this->buffers[$fd]);
        }
        try
        {
            $context           = new \stdClass();
            $context->server   = $server;
            $context->request  = $request;
            $context->fd       = $fd;
            $context->userdata = new \stdClass();

            return $this->onAccept($context);
        }
        catch (\Exception $e) { $server->close($fd); }
        catch (\Throwable $e) { $server->close($fd); }
    }

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return null|\Generator
     */
    public function onRequest($request, $response)
    {

    }

    /**
     * 收到数据处理
     *
     * 只在 tcp 方式下处理数据，一般不需要扩展
     *
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     * @param mixed
     */
    public function onReceive($server, $fd, $fromId, $data)
    {
        if (0 === $this->hproseType)
        {
            if (isset($this->onReceives[$fd]))
            {
                $onReceive = $this->onReceives[$fd];

                return $onReceive($server, $fd, $fromId, $data);
            }
            else
            {
                $server->close($fd, true);
            }
        }
    }

    /**
     * 在连接上去时处理
     *
     * 只支持 tcp 的协议
     *
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     * @return mixed
     */
    public function onConnect($server, $fd, $fromId)
    {
        if (0 === $this->hproseType)
        {
            try
            {
                $this->onReceives[$fd] = $this->hproseService->getOnReceive();
                $context               = new \stdClass();
                $context->server       = $server;
                $context->socket       = $fd;
                $context->fd           = $fd;
                $context->fromid       = $fromId;
                $context->userdata     = new \stdClass();

                return $this->onAccept($context);
            }
            catch (\Exception $e) { $server->close($fd); }
            catch (\Throwable $e) { $server->close($fd); }
        }
    }

    /**
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     * @return mixed
     */
    public function onClose($server, $fd, $fromId)
    {
        if (isset($this->buffers[$fd]))
        {
            unset($this->buffers[$fd]);
        }

        if (isset($this->onReceives[$fd]))
        {
            unset($this->onReceives[$fd]);
        }
    }

    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\Server|\Swoole\WebSocket\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     * @return mixed
     */
    public function onMessage($server, $frame)
    {
        $fd = $frame->fd;
        if (isset($this->buffers[$fd]))
        {
            if ($frame->finish)
            {
                $data = $this->buffers[$fd] . $frame->data;
                unset($this->buffers[$fd]);
                $this->hproseService->onMessage($server, $fd, $data);
            }
            else
            {
                $this->buffers[$fd] .= $frame->data;
            }
        }
        else
        {
            if ($frame->finish)
            {
                $this->hproseService->onMessage($server, $fd, $frame->data);
            }
            else
            {
                $this->buffers[$fd] = $frame->data;
            }
        }
    }
}
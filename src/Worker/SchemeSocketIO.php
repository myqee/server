<?php
namespace MyQEE\Server\Worker;

use MyQEE\Server\Message;
use MyQEE\Server\SocketIO;

class SchemeSocketIO extends SchemeWebSocket
{
    /**
     * 支持的来源
     *
     * @var array|string
     */
    public $origins = '*';

    /**
     * ping 间隔, 单位毫秒
     *
     * @var int
     */
    protected $pingInterval = 25000;

    /**
     * ping超时时间, 单位毫秒
     *
     * @var int
     */
    protected $pingTimeout = 60000;

    /**
     * 客户端对象
     *
     * @var string
     */
    protected $clientClassName = '\\SocketIOClient';

    /**
     * socket.io 路径
     *
     * @var string
     */
    protected $socketIOPath = '/socket.io';

    /**
     * 输出 socket.io.js 的内容
     *
     * @var null
     */
    protected $socketJsContent = null;

    /**
     * socket.io.js 版本
     *
     * @var string
     */
    protected $socketVersion = '1.7.4';

    /**
     * 是否开启混合模式
     *
     * API之外是否支持普通的 http
     * 如果开启 $this->useAction 或 $this->useAssets 则此参数默认 true
     *
     * * false - 则是纯API模式
     * * true  - 则是优先判断是否API路径，是的话使用api，不是API前缀的路径则使用page模式，适合页面和API混合在一起的场景
     *
     * @var bool
     */
    public $mixedMode = false;

    /**
     * 进程数据分配模式
     *
     * 1 : 随机
     * 2 : 固定
     * 3 : 可分配
     *
     * @var int
     */
    protected $ipcMode = 2;

    public function __construct($arguments)
    {
        parent::__construct($arguments);

        if (isset($this->setting['origins']))
        {
            $this->origins = (array)$this->setting['origins'];
        }

        if (is_array($this->origins))
        {
            $arr = [];
            foreach ($this->origins as $o)
            {
                $arr[$o] = $o;
            }
            $this->origins = $arr;
        }

        if (isset($this->setting['pingInterval']))
        {
            $this->pingInterval = (int)$this->setting['pingInterval'];
        }

        if (isset($this->setting['pingTimeout']))
        {
            $this->pingTimeout = (int)$this->setting['pingTimeout'];
        }

        if (isset($this->setting['socketVersion']))
        {
            $this->socketVersion = $this->setting['socketVersion'];
        }

        if (isset($this->setting['socketIOPath']))
        {
            $this->socketIOPath = '/'. trim($this->setting['socketIOPath'], '/');
        }

        if ($this->useAction || $this->useAssets || (isset($this->setting['mixedMode']) && true == $this->setting['mixedMode']))
        {
            # 开启混合模式
            $this->mixedMode = true;
        }

        if (isset($this->setting['clientClassName']))
        {
            $this->clientClassName = trim($this->setting['clientClassName'], '\\');

            if ($this->id == 0 && false === class_exists($this->clientClassName))
            {
                # 检查类名是否在
                swoole_timer_after(200, function()
                {
                    $this->warn("定义的 SocketIO 客户端对象 {$this->clientClassName} 不存在，请检查 clientClassName 配置");
                    $this->server->shutdown();
                });
            }
        }
        else
        {
            if (false === class_exists($this->clientClassName))
            {
                $def = '\\MyQEE\\Server\\SocketIO\\Client';
                class_alias($def, $this->clientClassName);

                if ($this->id == 0)
                {
                    $this->info("SocketIO 客户端对象 {$this->clientClassName} 不存在，已使用默认对象 {$def} 作为别名替代");
                }
            }
        }
        //if ($this->id == 0)
        //{
        //    if (false === (self::$Server instanceof ServerSocketIO))
        //    {
        //        $this->warn("你试图启动一个 SocketIO 服务器，它所依赖的的服务器对象需要继承到 " . ServerSocketIO::class . ' 才可以启动，当前服务器：'. get_class(self::$Server). ' 没有继承到此对象，所以不能启动。');
        //        $this->server->shutdown();
        //        return;
        //    }
        //}

        if (isset($this->server->setting['dispatch_mode']) && !in_array($this->server->setting['dispatch_mode'], [2, 4]))
        {
            # see https://wiki.swoole.com/wiki/page/277.html
            switch ($this->server->setting['dispatch_mode'])
            {
                case 1:
                case 3:
                    # 轮循、抢占模式
                    $this->ipcMode = 1;
                    if ($this->id == 0)
                    {
                        $d = $this->server->setting['dispatch_mode'];
                        $this->info("当前服务器的 swoole.dispatch_mode 配置是 {$d}, 收到的信息会". ($d = 1 ? '轮循' : '随机') ."分配进程，将禁用广播功能，推荐设置成 2");
                    }

                    # 这种模式下因为不能够保证客户端断开时能否准确调用 onClose 方法，所以增加一个清理数据的方法
                    //$this->timeTick(1000 * 60, function()
                    //{
                    //    $time         = time();
                    //    $pingInterval = $this->pingInterval / 1000 * 3;
                    //    $removeList   = [];
                    //    foreach (SocketIOClient::$instances as $class => $list)
                    //    {
                    //        foreach ($list as $fd => $client)
                    //        {
                    //            if ($time - $client->lastPingTime > $pingInterval)
                    //            {
                    //                # 将好久没有 ping 数据的客户端移除
                    //                $removeList[] = $client;
                    //            }
                    //        }
                    //    }
                    //
                    //    foreach ($removeList as $client)
                    //    {
                    //        /**
                    //         * @var SocketIOClient $client
                    //         */
                    //        $client->remove();
                    //    }
                    //});
                    break;

                case 5:
                    # UID分配
                    $this->ipcMode = 3;
                    break;
            }
        }

        # 添加一个事件
        $this->event->before('request', ['$request', '$response'], [$this, 'onBeforeRequest']);

        SocketIO\Client::init();
    }

    /**
     * 发送广播
     *
     * @param string|array $room 不设置则向所有房间投递
     * @param string $event
     * @param mixed $data1
     * @param mixed $data2
     * @throws \Exception
     */
    public function broadcast($room, $event, $data1 = null, $data2 = null)
    {
        # 向所有进程投递数据
        if (1 === $this->ipcMode)
        {
            throw new \Exception("当前服务器模式不支持广播信息");
        }

        $msg       = Message::create(static::class. '::doBroadcast');
        $msg->data = func_get_args();
        $msg->sendMessageToAllWorker(Message::SEND_MESSAGE_TYPE_WORKER);

        # 将在所有进程里执行 self::doBroadcast();
    }

    /**
     * SocketIO 连接时可以设定的回调方法
     *
     * @param SocketIO\Client $client
     */
    public function onConnection($client)
    {
        //$client->emit('news', 'test');
    }

    public function onOpen($server, $request)
    {
        if (!isset($request->get['sid']))
        {
            $server->close($request->fd);
            return;
        }
    }

    public function onClose($server, $fd, $fromId)
    {
        /**
         * @var $class SocketIO\Client
         */
        $class = $this->clientClassName;
        if ($class::exist($fd))
        {
            $this->getClientByFd($fd)->remove();
        }
    }

    /**
     * 在 onRequest 前执行的事件
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return bool
     */
    public function onBeforeRequest($request, $response)
    {
        switch (rtrim($request->server['request_uri'], '/'))
        {
            case $this->socketIOPath:
                $response->header('Access-Control-Allow-Credentials', 'true');
                $response->header('Access-Control-Allow-Origin', isset($request->header['origin']) ? $request->header['origin'] : '*');
                $response->header('Content-Type', 'application/octet-stream');
                $response->header('Connection', 'keep-alive');

                if (isset($request->get['sid']))
                {
                    $sid = $request->get['sid'];

                    $response->cookie('io', $sid, 0, '/', '', false, true);

                    if ($request->server['request_method'] == 'POST')
                    {
                        # step 3
                        $response->end('ok');
                    }
                    else
                    {
                        # step 2
                        $response->end(self::httpRspFormat(0, 4) . self::httpRspFormat('', 6));
                    }
                }
                else
                {
                    # step 1
                    if (isset($request->header['origin']) && false === $this->checkOrigin($request->header['origin']))
                    {
                        $response->status(400);
                        $response->end('{"code":null}');
                        break;
                    }

                    $sid  = self::random();
                    $data = [
                        'sid'          => $sid,
                        "upgrades"     => ["websocket"],
                        "pingInterval" => $this->pingInterval,
                        "pingTimeout"  => $this->pingTimeout,
                    ];
                    $response->cookie('io', $sid, 0, '/', '', false, true);
                    $response->end(self::httpRspFormat(json_encode($data), 0));
                }
                break;

            case '/socket.io.min.js':
                # 提供一个静态文件的输出地址
                $response->header('Content-Type', 'application/javascript; charset=utf-8');
                $response->header('Access-Control-Allow-Origin', isset($request->header['origin']) ? $request->header['origin'] : '*');
                $response->header('Cache-Control', 'public, max-age=30672000');
                $response->end($this->getSocketJsContent());

                break;

            case '/favicon.ico':
                $this->faviconIcon($response);
                break;

            default:
                # 继续执行后面的绑定事件
                return true;
                break;
        }

        # 阻止后面所有的 event 执行
        return false;
    }

    public function onMessage($server, $frame)
    {
        # open    0
        # close   1
        # ping    2
        # pong    3
        # message 4
        # upgrade 5
        # noop    6

        switch (substr($frame->data, 0, 1))
        {
            case '0':
                # open
                break;

            case '1':
                # close
                $this->server->close($frame->fd);
                break;

            case '2':
                # ping
                $server->push($frame->fd, 3 . substr($frame->data, 1));

                $this->getClientByFd($frame->fd)->lastPingTime = time();

                break;

            case '3':
                # pone
                break;

            case '4':
                # message
                preg_match('#^(\d+)(.*)$#', $frame->data, $m);

                $data = @json_decode($m[2], true);
                if (strlen($m[1]) > 2)
                {
                    $type = substr($m[1], 0, 2);
                    $num  = substr($m[1], 2);
                }
                else
                {
                    $type = $m[1];
                    $num  = false;
                }

                switch ($type)
                {
                    case '42':
                        # emit
                        # emit callback 模式, 编号是这样: 420, 421, 423 .... 42101, 40102, 42103, ...
                        $emit = array_shift($data);
                        $m    = 'on'. str_replace(' ', '_', $emit);

                        $client = $this->getClientByFd($frame->fd);
                        if (method_exists($client, $m))
                        {
                            try
                            {
                                $rs = call_user_func_array([$client, $m], $data);
                            }
                            catch (\Exception $e)
                            {
                                $this->warn("SocketIO on emit error: ". $e->getMessage());
                                $rs = [];
                            }
                        }
                        else
                        {
                            $rs = [];
                            $this->debug("SocketIO unknown event {$m}");
                        }

                        if (false !== $num)
                        {
                            # emit callback
                            $server->push($frame->fd, "43{$num}". json_encode((array)$rs));
                        }
                        break;

                    case '41':
                        # close
                        $this->server->close($frame->fd);
                        break;
                }
                break;

            case '5':
                # upgrade
                if ($this->ipcMode === 3)
                {
                    $this->bindClient($frame->fd);
                }

                return $this->onConnection($this->getClientByFd($frame->fd));

            case '6':
                # noop

                break;
            default:
                return $this->onMessageDefault($server, $frame);
        }

        return null;
    }

    /**
     * WebSocket 获取消息其它的回调
     *
     * 可以进行扩展
     *
     * @param \Swoole\Server|\Swoole\WebSocket\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessageDefault($server, $frame)
    {
        $this->warn("unknown frame: ". print_r($frame, true));

        return null;
    }

    /**
     * 当 swoole 的 dispatch_mode 参数设置成 5 时，通过此方法来绑定投递进程
     *
     * @see https://wiki.swoole.com/wiki/page/277.html
     * @param $fd
     */
    protected function bindClient($fd)
    {
        $this->server->bind($fd, $this->id);
    }

    /**
     * 检查来源是否符合条件
     *
     * @param $origin
     * @return bool
     */
    protected function checkOrigin($origin)
    {
        if ($this->origins == '*')return true;

        $url = parse_url($origin);
        if ($url)
        {
            $host = $url['host'];
            if (isset($this->origins[$host]))return true;

            $host = explode('.', $host);
        }
        else
        {
            return false;
        }

        while (true)
        {
            # *.test.com
            $host[0] = '*';
            if (isset($this->origins[implode('.', $host)]))return true;

            # abc.test.com
            array_shift($host);
            if (isset($this->origins[implode('.', $host)]))return true;

            if (!$host)break;
        }

        return false;
    }

    /**
     * 支持 http 的获取 socket js 文件内容
     *
     * @return string
     */
    protected function getSocketJsContent()
    {
        # 给个缓存
        if (null === $this->socketJsContent)
        {
            $this->socketJsContent = file_get_contents("https://cdnjs.cloudflare.com/ajax/libs/socket.io/{$this->socketVersion}/socket.io.min.js");
        }

        return $this->socketJsContent;
    }

    /**
     * 输出网站图标
     *
     * @param \Swoole\Http\Response $response
     */
    protected function faviconIcon($response)
    {
        $response->status(404);
        $response->end();
    }

    /**
     * 获取客户端对象
     *
     * @param $fd
     * @return SocketIO\Client|\SocketIOClient
     */
    protected function getClientByFd($fd)
    {
        /**
         * @var SocketIO\Client $class
         */
        $class = $this->clientClassName;

        if (1 === $this->ipcMode)
        {
            # 随机、轮询模式，直接返回一个新对象
            return new $class($fd);
        }

        return $class::instance($fd);
    }

    /**
     * 收到从别的进程投递来需要处理的方法
     *
     * @param \Swoole\WebSocket\Server $server
     * @param int $fromWorkerId
     * @param mixed $message
     */
    public static function doBroadcast($server, $fromWorkerId, $message)
    {
        $data  = $message->data;
        $room  = array_shift($data);            # 房间参数
        $data  = '42'. json_encode($data);      # 需要投递的数据内容

        if (is_array($room))
        {
            # 多个房间投递
            $send = [];
            foreach ($room as $r)
            {
                if (!isset(SocketIO\Client::$ALL_ROOMS[$r]))
                {
                    continue;
                }

                foreach (SocketIO\Client::$ALL_ROOMS[$r] as $fd)
                {
                    if (!isset($send[$fd]))
                    {
                        $server->push($fd, $data);
                        $send[$fd] = 1;
                    }
                }
            }
        }
        elseif (null === $room)
        {
            # 全部投递
            foreach (SocketIO\Client::$instances as $fd)
            {
                $server->push($fd, $data);
            }
        }
        elseif (isset(SocketIO\Client::$ALL_ROOMS[$room]))
        {
            foreach (SocketIO\Client::$ALL_ROOMS[$room] as $fd)
            {
                $server->push($fd, $data);
            }
        }
    }

    protected static function random($length = 20)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pool = str_split($pool, 1);
        $max  = count($pool) - 1;

        $str = '';
        for($i = 0; $i < $length; $i++)
        {
            // Select a random character from the pool and add it to the string
            $str .= $pool[mt_rand(0, $max)];
        }

        if (ctype_alpha($str))
        {
            // Add a random digit
            $str[mt_rand(0, $length - 1)] = chr(mt_rand(48, 57));
        }
        elseif (ctype_digit($str))
        {
            // Add a random letter
            $str[mt_rand(0, $length - 1)] = chr(mt_rand(65, 90));
        }

        return $str;
    }

    protected static function httpRspFormat($data, $code = 4)
    {
        $data = $code . $data;
        $len  = (string)strlen($data);

        $buffer = chr(0);
        for ($i = 0; $i < strlen($len); $i++)
        {
            $buffer .= chr($len[$i]);
        }
        $buffer .= chr(255);

        return $buffer . $data;
    }
}
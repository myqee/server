<?php
namespace MyQEE\Server;

/**
 * 服务器对象
 *
 * 主端口可同时支持 WebSocket, Http 协议, 并可以额外监听TCP新端口
 *
 * @package MyQEE\Server
 */
class Server
{
    /**
     * 当前服务器在集群里的序号
     *
     * @var int
     */
    public $serverId = -1;

    /**
     * @var \Swoole\WebSocket\Server
     */
    public $server;

    /**
     * @var \Swoole\Http\Request
     */
    public $request;

    /**
     * @var \Swoole\Http\Response
     */
    public $response;

    /**
     * 当前任务进程对象
     *
     * @var \WorkerTask
     */
    public $workerTask;

    /**
     * 当前进程对象
     *
     * @var \WorkerMain|WorkerWebSocket|WorkerTCP|WorkerUDP
     */
    public $worker;

    /**
     * 全局配置表
     *
     * @var \Swoole\Table
     */
    public $globalConfig;

    /**
     * 服务器模式
     *
     * * 0 - 主端口自定义协议
     * * 1 - 主端口只支持 Http
     * * 2 - 主端口只支持 WebSocket
     * * 3 - 主端口同时支持 WebSocket, Http
     *
     * @var int
     */
    protected $serverType = 0;

    /**
     * 服务器启动模式
     *
     * @see http://wiki.swoole.com/wiki/page/14.html
     *
     * @var int
     */
    protected $serverMode = 3;

    /**
     * 服务器连接模式
     *
     * @var int
     */
    protected $serverSockType = 1;

    /**
     * 集群模式
     *
     * * 0 - 单机模式
     * * 1 - 集群模式
     *
     * @var int
     */
    protected $clustersMode = 0;

    /**
     * 当前配置
     *
     * @var array
     */
    public static $config = [];

    /**
     * 所有工作进程对象
     *
     * @var array
     */
    public static $workers = [];

    /**
     * 集群模式
     *
     * 0 - 无集群, 1 - 简单模式, 2 - 高级模式
     *
     * @var int
     */
    public static $clustersType = 0;

    /**
     * 日志输出设置
     *
     * @var int
     */
    protected static $logPath = [];

    public function __construct($configFile = 'server.yaml')
    {
        if (!defined('SWOOLE_VERSION'))
        {
            self::warn("必须安装 swoole 插件, see http://www.swoole.com/");
            exit;
        }

        if (version_compare(SWOOLE_VERSION, '1.8.0', '<'))
        {
            self::warn("swoole插件必须>=1.8版本");
            exit;
        }

        if (!class_exists('\\Swoole\\Server', false))
        {
            # 载入兼容对象文件
            include (__DIR__ .'/../other/Compatible.php');
            self::info("你没有开启 swoole 的命名空间模式, 请修改 ini 文件增加 swoole.use_namespace = true 参数. \n操作方式: 先执行 php --ini 看 swoole 的扩展配置在哪个文件, 然后编辑对应文件加入即可, 如果没有则加入 php.ini 里");
        }

        if (is_array($configFile))
        {
            $config = $configFile;
        }
        else
        {
            if (!function_exists('\\yaml_parse_file'))
            {
                self::warn('必须安装 yaml 插件');
                exit;
            }
            # 读取配置
            $config = yaml_parse_file($configFile);
        }

        if (!$config)
        {
            self::warn("配置解析失败");
            exit;
        }

        self::$config = $config;

        $this->checkConfig();

        # 设置参数
        if (isset(self::$config['php']['error_reporting']))
        {
            error_reporting(self::$config['php']['error_reporting']);
        }

        if (isset(self::$config['php']['timezone']))
        {
            date_default_timezone_set(self::$config['php']['timezone']);
        }

        if (self::$config['clusters']['mode'] !== 'none' && !function_exists('\\msgpack_pack'))
        {
            self::warn('开启集群模式必须安装 msgpack 插件');
            exit;
        }

        if (!self::$config['server']['socket_block'])
        {
            # 设置不阻塞
            swoole_async_set(['socket_dontwait' => 1]);
        }

        self::info("======= Swoole Config ========\n". json_encode(self::$config['swoole'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 启动服务
     */
    public function start()
    {
        if (self::$config['clusters']['mode'] === 'advanced')
        {
            # 高级集群模式
            $this->startWithClusters();
        }
        else
        {
            $this->startWorkerServer();
        }
    }

    /**
     * 高级集群模式启动
     *
     * @return bool
     */
    private function startWithClusters()
    {
        $longOpts = [
            'worker',        // --worker
            'task',          // --task
        ];
        $options = getopt('', $longOpts);

        if (isset($options['worker']))
        {
            # 仅仅用作 worker 服务器
            $this->startWorkerServer();
        }
        elseif (isset($options['task']))
        {
            # 仅仅用作 Task 服务器
            $this->startTaskServer();
        }
        else
        {
            # 二者都有, 开启一个子进程单独启动task相关服务
            $process = new \Swoole\Process(function($worker)
            {
                /**
                 * @var \Swoole\Process $worker
                 */
                $this->startTaskServer();
            });

            $process->start();

            # 任务进程会通过独立服务启动, 所以这里强制设置成0
            self::$config['swoole']['task_worker_num']  = 0;
            self::$config['swoole']['task_max_request'] = 0;

            $this->startWorkerServer();
        }

        return true;
    }

    protected function startWorkerServer()
    {
        switch($this->serverType)
        {
            case 1:
                # 主端口同时支持 WebSocket 和 Http 协议
                $class = '\\Swoole\\WebSocket\\Server';
                break;

            case 2:
                # 主端口为自定义端口
                $class = '\\Swoole\\Server';
                break;

            case 0:
            default:
                # 主端口仅 Http 协议
                $class = '\\Swoole\\Http\\Server';
                break;
        }

        # 创建一个服务
        $this->server = new $class(self::$config['server']['host'], self::$config['server']['port'], $this->serverMode, self::$config['server']['sock_type']);

        # 设置配置
        $this->server->set(self::$config['swoole']);

        $this->bind();

        if (self::$config['sockets'])
        {
            $this->initSockets();
        }

        $this->server->start();
    }

    /**
     * 单独启动task服务器
     */
    protected function startTaskServer()
    {
        $config = [
            'dispatch_mode'      => 5,
            'worker_num'         => self::$config['swoole']['task_worker_num'],
            'max_request'        => self::$config['swoole']['task_max_request'],
            'task_worker_num'    => 0,
            'package_max_length' => 5000000,
            'task_tmpdir'        => self::$config['swoole']['task_tmpdir'],
            'buffer_output_size' => self::$config['swoole']['buffer_output_size'],
            'open_eof_check'     => true,
            'open_eof_split'     => true,
            'package_eof'        => "\r\n",
        ];

        $this->server = new \Swoole\Server(self::$config['clusters']['host'], self::$config['clusters']['task_port'], SWOOLE_BASE);
        $this->server->set($config);

        $this->server->on('WorkerStart', function($server, $taskId)
        {
            global $argv;

            if ($taskId === 0 && !class_exists('\\WorkerTask'))
            {
                # 停止服务
                self::warn('任务进程 WorkerTask 类不存在');
                $this->server->shutdown();
                return;
            }

            # 进程序号
            $workerId = $taskId + self::$config['swoole']['worker_num'];

            # 内存限制
            ini_set('memory_limit', self::$config['server']['task_worker_memory_limit'] ?: '4G');

            self::setProcessName("php ". implode(' ', $argv) ." [task#$taskId]");

            $this->workerTask         = new \WorkerTask($server);
            $this->workerTask->id     = $workerId;
            $this->workerTask->taskId = $taskId;
            $this->workerTask->onWorkerStart();
        });

        $this->server->on('Receive', function($server, $fd, $fromId, $data)
        {
            /**
             * @var \Swoole\Server $server
             */
            $data = msgpack_unpack($data);

            if (is_object($data))
            {
                if ($data instanceof \stdClass)
                {
                    if ($data->bind)
                    {
                        # 绑定进程ID
                        $server->bind($fd, $data->id);
                        return;
                    }
                }
            }

            $this->workerTask->onTask($server, $server->worker_id, $fromId, $data);
        });

        $this->server->start();
    }

    /**
     * 绑定事件
     */
    protected function bind()
    {
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('WorkerStart',  [$this, 'onWorkerStart']);
        $this->server->on('WorkerStop',   [$this, 'onWorkerStop']);
        $this->server->on('PipeMessage',  [$this, 'onPipeMessage']);
        $this->server->on('Start',        [$this, 'onStart']);
        $this->server->on('Finish',       [$this, 'onFinish']);
        $this->server->on('Task',         [$this, 'onTask']);
        $this->server->on('Packet',       [$this, 'onPacket']);
        $this->server->on('Close',        [$this, 'onClose']);
        $this->server->on('Connect',      [$this, 'onConnect']);

        # 其它自定义回调函数
        foreach (['Shutdown', 'Timer', 'ManagerStop'] as $type)
        {
            $fun = "on$type";
            if (method_exists($this, $fun))
            {
                $this->server->on($type, [$this, $fun]);
            }
        }

        # 自定义协议
        if ($this->serverType === 0)
        {
            $this->server->on('Receive', [$this, 'onReceive']);
        }

        # HTTP
        if ($this->serverType === 1 || $this->serverType === 3)
        {
            $this->server->on('Request', [$this, 'onRequest']);
        }

        # WebSocket
        if ($this->serverType === 2 || $this->serverType === 3)
        {
            $this->server->on('Message', [$this, 'onMessage']);

            if (method_exists($this, 'onHandShake'))
            {
                $this->server->on('HandShake', [$this, 'onHandShake']);
            }
            else
            {
                $this->server->on('Open', [$this, 'onOpen']);
            }
        }
    }

    /**
     * 添加的自定义端口服务
     */
    protected function initSockets()
    {
        foreach (self::$config['sockets'] as $key => $setting)
        {
            if (in_array(strtolower($key), ['main', 'task', 'api', 'manager']))
            {
                self::warn("自定义端口服务关键字不允许是 Main, Task, API, Manager, 已忽略配置, 请修改配置 sockets.{$key}.");
                continue;
            }

            foreach ((array)$setting['link'] as $st)
            {
                $opt    = self::parseSockUri($st);
                $listen = $this->server->listen($opt->host, $opt->port, $opt->type);

                if (!isset(self::$workers[$key]))
                {
                    self::$workers[$key] = $key;
                }

                # 设置参数
                $listen->set(self::getSockConf($key));

                # 设置回调
                self::setListenCallback($key, $listen, $opt);
            }
        }
    }

    /**
     * 进程启动
     *
     * @param \Swoole\Server $server
     * @param $workerId
     */
    public function onWorkerStart($server, $workerId)
    {
        global $argv;

        if($server->taskworker)
        {
            # 任务序号
            $taskId = $workerId - $server->setting['worker_num'];

            if ($taskId === 0 && !class_exists('\\WorkerTask'))
            {
                # 停止服务
                self::warn('任务进程 WorkerTask 类不存在');
                $this->server->shutdown();
            }

            # 内存限制
            ini_set('memory_limit', self::$config['server']['task_worker_memory_limit'] ?: '4G');

            self::setProcessName("php ". implode(' ', $argv) ." [task#$taskId]");

            $this->workerTask         = new \WorkerTask($server);
            $this->workerTask->id     = $workerId;
            $this->workerTask->taskId = $taskId;
            $this->workerTask->onWorkerStart();
        }
        else
        {
            if ($workerId === 0 && !class_exists('\\WorkerMain'))
            {
                # 停止服务
                self::warn('工作进程 WorkerMain 类不存在');
                $this->server->shutdown();
                return;
            }

            ini_set('memory_limit', self::$config['server']['worker_memory_limit'] ?: '2G');

            self::setProcessName("php ". implode(' ', $argv) ." [worker#$workerId]");

            $this->worker          = new \WorkerMain($server);
            $this->worker->key     = 'Main';
            $this->worker->id      = $workerId;
            self::$workers['Main'] = $this->worker;

            # 调用初始化方法
            $this->worker->onWorkerStart();

            # 加载自定义端口对象
            foreach (array_keys(self::$workers) as $key)
            {
                if ($key === 'Main')continue;

                /**
                 * @var Worker $class
                 */
                $className = "\\Worker{$key}";

                if (!class_exists($className))
                {
                    if (in_array($key, ['API', 'Manager']))
                    {
                        # 使用系统自带的对象
                        $className = "\\MyQEE\\Server\\Worker{$key}";
                    }
                    else
                    {
                        unset(self::$workers[$key]);
                        self::warn("$className 不存在, 已忽略对应监听");
                        continue;
                    }
                }

                # 构造对象
                $class = new $className($server);

                switch ($key)
                {
                    case 'API':
                        if ($class instanceof WorkerAPI)
                        {
                            /**
                             * @var WorkerAPI $class
                             */
                            $class->prefix       = self::$config['server']['http']['api_prefix'] ?: '/api/';
                            $class->prefixLength = strlen($class->prefix);
                        }
                        else
                        {
                            unset(self::$workers[$key]);
                            self::warn("忽略$className 服务, 必须继承 \\MyQEE\\Server\\WorkerAPI 类");
                            continue 2;
                        }

                        break;
                    case 'Manager':
                        if ($class instanceof WorkerManager)
                        {
                            /**
                             * @var WorkerManager $class
                             */
                            $class->prefix       = self::$config['server']['http']['manager_prefix'] ?: '/admin/';
                            $class->prefixLength = strlen($class->prefix);
                        }
                        else
                        {
                            unset(self::$workers[$key]);
                            self::warn("忽略 $className 服务, 必须继承 \\MyQEE\\Server\\WorkerManager 类");
                            continue 2;
                        }
                        break;

                    default:
                        if (!($class instanceof Worker))
                        {
                            unset(self::$workers[$key]);
                            self::warn("忽略 {$key} 多协议服务, 对象 $className 必须继承 \\MyQEE\\Server 的 WorkerTCP 或 WorkerUDP 或 WorkerHttp 或 WorkerWebSocket");
                            continue 2;
                        }
                        break;
                }

                $class->key          = $key;
                $class->id           = $workerId;
                $class->worker       = $this->worker;
                self::$workers[$key] = $class;

                # 调用初始化方法
                $class->onWorkerStart();
            }
        }
    }

    /**
     * @param \Swoole\Server $server
     * @param $workerId
     */
    public function onWorkerStop($server, $workerId)
    {
        if($server->taskworker)
        {
            $this->workerTask->onWorkerStop();
        }
        else
        {
            $this->worker->onWorkerStop();

            foreach (self::$workers as $worker)
            {
                /**
                 * @var Worker $worker
                 */
                $worker->onWorkerStop();
            }
        }
    }

    /**
     * @param \Swoole\Server $server
     * @param $fd
     * @param $fromId
     * @param $data
     */
    public function onReceive($server, $fd, $fromId, $data)
    {
        $this->worker->onReceive($server, $fd, $fromId, $data);
    }

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest($request, $response)
    {
        # 发送一个头信息
        $response->header('Server', self::$config['server']['name'] ?: 'MQSRV');

        if (isset(self::$workers['API']))
        {
            /**
             * @var WorkerAPI $worker
             */
            $worker = self::$workers['API'];

            if ($worker->isApi($request))
            {
                $worker->onRequest($request, $response);
                return;
            }
        }

        if (isset(self::$workers['Manager']))
        {
            /**
             * @var WorkerManager $worker
             */
            $worker = self::$workers['Manager'];

            if ($worker->isManager($request))
            {
                $worker->onRequest($request, $response);
                return;
            }
        }

        $this->worker->onRequest($request, $response);
    }

    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage($server, $frame)
    {
        $this->worker->onMessage($server, $frame);
    }

    /**
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     *
     * @param \Swoole\Websocket\Server $svr
     * @param \Swoole\Http\Request $req
     */
    public function onOpen($svr, $req)
    {
        $this->worker->onOpen($svr, $req);
    }

    /**
     * 连接服务器回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    public function onConnect($server, $fd, $fromId)
    {
        $this->worker->onConnect($server, $fd, $fromId);
    }

    /**
     * 关闭连接回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    public function onClose($server, $fd, $fromId)
    {
        $this->worker->onClose($server, $fd, $fromId);
    }

    /**
     * UDP下收到数据回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    public function onPacket($server, $data, $clientInfo)
    {
        $this->worker->onPacket($server, $data, $clientInfo);
    }

    /**
     * @param \Swoole\Server $server
     * @param $fromWorkerId
     * @param $message
     * @return null
     */
    public function onPipeMessage($server, $fromWorkerId, $message)
    {
        if ($server->taskworker)
        {
            return $this->workerTask->onPipeMessage($server, $fromWorkerId, $message, $this->serverId);
        }
        else
        {
            return $this->worker->onPipeMessage($server, $fromWorkerId, $message, $this->serverId);
        }
    }

    /**
     * @param \Swoole\Server $server
     * @param $taskId
     * @param $data
     * @return mixed
     */
    public function onFinish($server, $taskId, $data)
    {
        return $this->worker->onFinish($server, $taskId, $data, $this->serverId);
    }

    /**
     * @param \Swoole\Server $server
     * @param $taskId
     * @param $fromId
     * @param $data
     * @return mixed
     */
    public function onTask($server, $taskId, $fromId, $data)
    {
        return $this->workerTask->onTask($server, $taskId, $fromId, $data);
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onStart($server)
    {
        if ($this->serverType === 1 || $this->serverType === 3)
        {
            self::info("Http server: http://". self::$config['server']['host'] .":". self::$config['server']['port'] ."/");
        }

        if ($this->serverType === 2 || $this->serverType === 3)
        {
            self::info("webSocket server: wss://". self::$config['server']['host'] .":". self::$config['server']['port'] ."/");
        }
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onManagerStart($server)
    {
        global $argv;
        self::setProcessName("php ". implode(' ', $argv) ." [manager]");

        self::debug('manager start');
    }

    /**
     * 输出自定义log
     *
     * @param        $log
     * @param string $type
     * @param string $color
     */
    public static function log($log, $type = 'other', $color = '[36m')
    {
        if (!isset(self::$logPath[$type]))return;

        list($f, $t) = explode(' ', microtime());
        $f   = substr($f, 1, 4);
        $beg = "\x1b{$color}";
        $end = "\x1b[39m";
        $str = $beg .'['. date("Y-m-d H:i:s", $t) . "{$f}][{$type}]{$end} - " . $log . "\n";

        if (is_string(self::$logPath[$type]))
        {
            # 写文件
            @file_put_contents(self::$logPath[$type], $str, FILE_APPEND);
        }
        else
        {
            # 直接输出
            echo $str;
        }
    }

    /**
     * 错误信息
     *
     * @param $info
     */
    public static function warn($info)
    {
        self::log($info, 'warn', '[31m');
    }

    /**
     * 输出信息
     *
     * @param $info
     */
    public static function info($info)
    {
        self::log($info, 'info', '[33m');
    }

    /**
     * 调试信息
     *
     * @param $info
     */
    public static function debug($info)
    {
        self::log($info, 'debug', '[34m');
    }

    /**
     * 跟踪信息
     *
     * @param $info
     */
    public static function trace($info)
    {
        self::log($info, 'trace', '[35m');
    }

    protected function checkConfig()
    {
        # 设置启动模式
        if (self::$config['server']['http']['use'] && self::$config['server']['http']['websocket'])
        {
            $this->serverType = 3;
        }
        elseif (self::$config['server']['http']['use'])
        {
            $this->serverType = 1;
        }
        elseif (self::$config['server']['http']['websocket'])
        {
            $this->serverType = 2;
        }
        else
        {
            $this->serverType = 0;
        }

        if ($this->serverType === 3 || $this->serverType === 1)
        {
            # 开启API
            if (self::$config['server']['http']['api'])
            {
                self::$workers['API'] = 'API';
            }

            # 开启管理
            if (self::$config['server']['http']['manager'])
            {
                self::$workers['Manager'] = 'Manager';
            }
        }

        # 设置log等级
        if (self::$config['server']['log']['level'])foreach (self::$config['server']['log']['level'] as $type)
        {
            if (self::$config['server']['log']['path'])
            {
                self::$logPath[$type] = str_replace('$type', $type, self::$config['server']['log']['path']);
            }
            else
            {
                self::$logPath[$type] = true;
            }
        }

        # 设置 swoole 的log输出路径
        if (!isset(self::$config['swoole']['log_file']) && self::$config['server']['log']['path'])
        {
            self::$config['swoole']['log_file'] = str_replace('$type', 'swoole', self::$config['server']['log']['path']);
        }

        # 无集群模式
        if (!self::$config['clusters']['mode'])
        {
            self::$config['clusters']['mode'] = 'none';
        }

        switch (self::$config['clusters']['mode'])
        {
            case 'simple':
                self::$clustersType = 1;
                break;

            case 'advanced':
                self::$clustersType = 2;
                break;
        }
     }


    /**
     * 设置进程的名称
     *
     * @param $name
     */
    public static function setProcessName($name)
    {
        if (function_exists('\cli_set_process_title'))
        {
            @cli_set_process_title($name);
        }
        else
        {
            if (function_exists('\swoole_set_process_name'))
            {
                @swoole_set_process_name($name);
            }
            else
            {
                trigger_error(__METHOD__ .' failed. require cli_set_process_title or swoole_set_process_name.');
            }
        }
    }

    /**
     * 解析Sock的URI
     *
     * @param $uri
     * @return \stdClass
     * @throws \Exception
     */
    protected static function parseSockUri($uri)
    {
        $result = new \stdClass();
        $p      = parse_url($uri);

        if ($p)
        {
            switch (strtolower($p['scheme']))
            {
                case 'tcp':
                case 'tcp4':
                case 'ssl':
                case 'sslv2':
                case 'sslv3':
                case 'tls':
                    $result->type = SWOOLE_SOCK_TCP;
                    $result->host = $p['host'];
                    $result->port = $p['port'];
                    break;
                case 'tcp6':
                    $result->type = SWOOLE_SOCK_TCP6;
                    $result->host = $p['host'];
                    $result->port = $p['port'];
                    break;
                case 'unix':
                    $result->type = SWOOLE_UNIX_STREAM;
                    $result->host = $p['path'];
                    $result->port = 0;
                    break;
                default:
                    throw new \Exception("Can't support this scheme: {$p['scheme']}");
            }
        }
        else
        {
            throw new \Exception("Can't parse this uri: " . $uri);
        }

        return $result;
    }

    /**
     * 获取自定义监听的配置
     *
     * @param $key
     * @return array
     */
    protected static function getSockConf($key)
    {
        return self::$config['sockets'][$key]['conf'] ?: [
            'open_eof_check' => true,
            'open_eof_split' => true,
            'package_eof'    => "\n",
        ];
    }

    /**
     * 设置自定义端口监听的回调
     *
     * @param string  $key
     * @param \Swoole\Server\Port $listen
     * @param \stdClass $opt
     */
    protected static function setListenCallback($key, $listen, \stdClass $opt)
    {
        # 设置回调
        $listen->on('Receive', function($server, $fd, $fromId, $data) use ($key)
        {
            if (isset(self::$workers[$key]))
            {
                self::$workers[$key]->onReceive($server, $fd, $fromId, $data);
            }
        });

        switch ($opt->type)
        {
            case SWOOLE_SOCK_TCP:
            case SWOOLE_SOCK_TCP6:
                $listen->on('Connect', function($server, $fd, $fromId) use ($key)
                {
                    if (isset(self::$workers[$key]))
                    {
                        self::$workers[$key]->onConnect($server, $fd, $fromId);
                    }
                });

                $listen->on('Close', function($server, $fd, $fromId) use ($key)
                {
                    if (isset(self::$workers[$key]))
                    {
                        self::$workers[$key]->onClose($server, $fd, $fromId);
                    }
                });

                break;
            case SWOOLE_UNIX_STREAM:

                $listen->on('Packet', function($server, $data, $clientInfo) use ($key)
                {
                    if (isset(self::$workers[$key]))
                    {
                        self::$workers[$key]->onPacket($server, $data, $clientInfo);
                    }
                });
                break;
        }
    }
}

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
     * 服务器ID
     *
     * @var int
     */
    public static $serverId = -1;

    /**
     * 所有的配置
     *
     * @var array
     */
    public static $config = [];

    /**
     * 配置文件路径
     *
     * @var null
     */
    public static $configFile = null;

    /**
     * @var \Swoole\Server
     */
    public static $server;

    /**
     * 当前任务进程对象
     *
     * @var \WorkerTask|WorkerTask
     */
    public static $workerTask;

    /**
     * 当前进程对象
     *
     * @var \WorkerMain|WorkerWebSocket|WorkerTCP|WorkerUDP
     */
    public static $worker;

    /**
     * 服务器启动模式
     *
     * SWOOLE_BASE 或 SWOOLE_PROCESS
     *
     * @see http://wiki.swoole.com/wiki/page/14.html
     * @var int
     */
    public static $serverMode = 3;

    /**
     * 集群模式
     *
     * 0 - 无集群, 1 - 简单模式, 2 - 高级模式
     *
     * @var int
     */
    public static $clustersType = 0;

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
    public static $serverType = 0;

    /**
     * 所有工作进程对象
     *
     * @var array
     */
    public static $workers = [];

    /**
     * 主进程的PID
     *
     * @var int
     */
    public static $pid = 0;

    /**
     * 当前服务器实例化对象
     *
     * @var Server
     */
    public static $instance;

    /**
     * 用户对象命名空间
     *
     * @var string
     */
    public static $namespace = '\\';

    /**
     * 日志输出设置
     *
     * @var int
     */
    public static $logPath = [
        'warn' => true
    ];


    public function __construct($configFile = 'server.yaml')
    {
        $this->checkSystem();

        self::$instance = $this;

        if ($configFile)
        {
            if (is_array($configFile))
            {
                self::$config = $configFile;
            }
            else
            {
                if (!function_exists('\\yaml_parse_file'))
                {
                    self::warn('必须安装 yaml 插件');
                    exit;
                }

                if (is_file($configFile))
                {
                    self::$configFile = realpath($configFile);

                    # 读取配置
                    self::$config = yaml_parse_file($configFile);
                }
                else
                {
                    self::warn("指定的配置文件: $configFile 不存在");
                    exit;
                }
            }
        }

        if (!self::$config)
        {
            self::warn("配置解析失败");
            exit;
        }

        # 主进程的PID
        self::$pid = getmypid();
    }

    protected function checkSystem()
    {
        if (self::$instance)
        {
            throw new \Exception('只允许实例化一个 \\MyQEE\\Server\\Server 对象');
        }

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
            include(__DIR__ . '/../other/Compatible.php');
            $this->info("你没有开启 swoole 的命名空间模式, 请修改 ini 文件增加 swoole.use_namespace = true 参数. \n操作方式: 先执行 php --ini 看 swoole 的扩展配置在哪个文件, 然后编辑对应文件加入即可, 如果没有则加入 php.ini 里");
        }
    }

    protected function init()
    {
        # 设置参数
        if (isset(self::$config['php']['error_reporting']))
        {
            error_reporting(self::$config['php']['error_reporting']);
        }

        if (isset(self::$config['php']['timezone']))
        {
            date_default_timezone_set(self::$config['php']['timezone']);
        }

        if (isset($config['server']['unixsock_buffer_size']) && $config['server']['unixsock_buffer_size'] > 1000)
        {
            # 修改进程间通信的UnixSocket缓存区尺寸
            ini_set('swoole.unixsock_buffer_size', $config['server']['unixsock_buffer_size']);
        }

        if (self::$config['clusters']['mode'] !== 'none')
        {
            if (!function_exists('\\msgpack_pack'))
            {
                self::warn('开启集群模式必须安装 msgpack 插件');
                exit;
            }
        }

        if (!self::$config['server']['socket_block'])
        {
            # 设置不阻塞
            swoole_async_set(['socket_dontwait' => 1]);
        }

        $this->info("======= Swoole Config ========\n". json_encode(self::$config['swoole'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (self::$clustersType > 0)
        {
            # 集群模式下初始化 Host 设置
            Clusters\Host::init(self::$config['clusters']['register']['is_register']);
        }
    }

    /**
     * 在启动前执行, 可以扩展本方法
     */
    public function onBeforeStart()
    {

    }

    /**
     * 启动服务
     */
    public function start()
    {
        $this->checkConfig();
        $this->init();

        $this->onBeforeStart();

        if (self::$clustersType === 2 && self::$config['swoole']['task_worker_num'] > 0)
        {
            # 高级集群模式
            $this->startWithAdvancedClusters();
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
    private function startWithAdvancedClusters()
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
            $config                     = self::$config['swoole'];
            $config['task_worker_num']  = 0;
            $config['task_max_request'] = 0;

            $this->startWorkerServer($config);
        }

        return true;
    }

    protected function startWorkerServer($config = null)
    {
        switch(self::$serverType)
        {
            case 3:
            case 2:
                # 主端口同时支持 WebSocket 和 Http 协议
                $className = '\\Swoole\\WebSocket\\Server';
                break;

            case 1:
                # 主端口仅 Http 协议
                $className = '\\Swoole\\Http\\Server';
                break;

            case 0:
            default:
                # 主端口为自定义端口
                $className = '\\Swoole\\Server';
                break;
        }

        # 创建一个服务
        self::$server = new $className(self::$config['server']['host'], self::$config['server']['port'], self::$serverMode, self::$config['server']['sock_type']);

        # 设置配置
        self::$server->set($config ?: self::$config['swoole']);

        $this->bind();

        if (self::$config['sockets'])
        {
            $this->initSockets();
        }

        if (self::$clustersType > 0)
        {
            if (self::$config['clusters']['register']['is_register'])
            {
                # 启动注册服务器
                $worker       = new Register\WorkerMain(self::$server);
                $worker->name = 'RegisterServer';
                $worker->listen(self::$config['clusters']['register']['ip'], self::$config['clusters']['register']['port']);

                # 放在Worker对象里
                self::$workers['RegisterServer'] = $worker;
            }
        }

        self::$server->start();
    }

    /**
     * 启动task服务器
     */
    public function startTaskServer()
    {
        # 初始化任务服务器
        $server = new Clusters\TaskServer();

        $server->start(self::$config['clusters']['host'] ?: '0.0.0.0', self::$config['clusters']['task_port']);
    }

    /**
     * 绑定事件
     */
    protected function bind()
    {
        self::$server->on('ManagerStart', [$this, 'onManagerStart']);
        self::$server->on('WorkerStart',  [$this, 'onWorkerStart']);
        self::$server->on('WorkerStop',   [$this, 'onWorkerStop']);
        self::$server->on('PipeMessage',  [$this, 'onPipeMessage']);
        self::$server->on('Start',        [$this, 'onStart']);
        self::$server->on('Finish',       [$this, 'onFinish']);
        self::$server->on('Task',         [$this, 'onTask']);
        self::$server->on('Packet',       [$this, 'onPacket']);
        self::$server->on('Close',        [$this, 'onClose']);
        self::$server->on('Connect',      [$this, 'onConnect']);

        # 其它自定义回调函数
        foreach (['Shutdown', 'Timer', 'ManagerStop'] as $type)
        {
            $fun = "on$type";
            if (method_exists($this, $fun))
            {
                self::$server->on($type, [$this, $fun]);
            }
        }

        # 自定义协议
        if (self::$serverType === 0)
        {
            self::$server->on('Receive', [$this, 'onReceive']);
        }

        # HTTP
        if (self::$serverType === 1 || self::$serverType === 3)
        {
            self::$server->on('Request', [$this, 'onRequest']);
        }

        # WebSocket
        if (self::$serverType === 2 || self::$serverType === 3)
        {
            self::$server->on('Message', [$this, 'onMessage']);

            if (method_exists($this, 'onHandShake'))
            {
                self::$server->on('HandShake', [$this, 'onHandShake']);
            }
            else
            {
                self::$server->on('Open', [$this, 'onOpen']);
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
            if (in_array(strtolower($key), ['main', 'task', 'api', 'manager', 'registerserver']))
            {
                self::warn("自定义端口服务关键字不允许是 Main, Task, API, Manager, RegisterServer, 已忽略配置, 请修改配置 sockets.{$key}.");
                continue;
            }

            foreach ((array)$setting['link'] as $st)
            {
                $opt    = $this->parseSockUri($st);
                $listen = self::$server->listen($opt->host, $opt->port, $opt->type);

                if (!isset(self::$workers[$key]))
                {
                    self::$workers[$key] = $key;
                }

                # 设置参数
                $listen->set($this->getSockConf($key));

                # 设置回调
                $this->setListenCallback($key, $listen, $opt);

                $this->info("add listen: $st");
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
            $taskId    = $workerId - $server->setting['worker_num'];
            $className = self::$namespace. 'WorkerTask';

            if (!class_exists($className))
            {
                # 停止服务
                if ($taskId === 0)
                {
                    self::warn("任务进程 $className 类不存在");
                }
                $className = '\\MyQEE\\Server\\WorkerTask';
            }

            # 内存限制
            ini_set('memory_limit', self::$config['server']['task_worker_memory_limit'] ?: '4G');

            static::setProcessName("php ". implode(' ', $argv) ." [task#$taskId]");

            self::$workerTask         = new $className($server);
            self::$workerTask->id     = $workerId;
            self::$workerTask->taskId = $workerId - $server->setting['worker_num'];

            self::$workerTask->onStart();
        }
        else
        {
            if ($workerId === 0 && self::$clustersType > 0)
            {
                # 集群模式, 第一个进程执行, 连接注册服务器
                $id = isset(Server::$config['clusters']['id']) && Server::$config['clusters']['id'] >= 0 ? (int)Server::$config['clusters']['id'] : -1;
                Register\Client::init(Server::$config['clusters']['group'] ?: 'default', $id, false);
            }

            $className = self::$namespace. 'WorkerMain';

            if (!class_exists($className))
            {
                if ($workerId === 0)
                {
                    # 停止服务
                    self::warn("工作进程 $className 类不存在");
                }
                $className = '\\MyQEE\\Server\\Worker';
            }
            ini_set('memory_limit', self::$config['server']['worker_memory_limit'] ?: '2G');

            static::setProcessName("php ". implode(' ', $argv) ." [worker#$workerId]");

            self::$worker          = new $className($server);
            self::$worker->name    = 'Main';
            self::$workers['Main'] = self::$worker;

            # 加载自定义端口对象
            foreach (array_keys(self::$workers) as $name)
            {
                if ($name === 'Main')continue;

                /**
                 * @var Worker $class
                 */
                $className = self::$namespace. "Worker{$name}";

                if (!class_exists($className))
                {
                    if (in_array($name, ['API', 'Manager']))
                    {
                        # 使用系统自带的对象
                        $className = "\\MyQEE\\Server\\Worker{$name}";
                    }
                    else
                    {
                        unset(self::$workers[$name]);
                        if (self::$server->worker_id === 0)
                        {
                            self::warn("$className 不存在, 已忽略对应监听");
                        }
                        continue;
                    }
                }

                # 构造对象
                $class = new $className($server);

                switch ($name)
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
                            unset(self::$workers[$name]);
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
                            unset(self::$workers[$name]);
                            self::warn("忽略 $className 服务, 必须继承 \\MyQEE\\Server\\WorkerManager 类");
                            continue 2;
                        }
                        break;

                    default:
                        if (!($class instanceof Worker))
                        {
                            unset(self::$workers[$name]);
                            self::warn("忽略 {$name} 多协议服务, 对象 $className 必须继承 \\MyQEE\\Server 的 WorkerTCP 或 WorkerUDP 或 WorkerHttp 或 WorkerWebSocket");
                            continue 2;
                        }
                        break;
                }

                $class->name          = $name;
                $class->worker        = self::$worker;
                self::$workers[$name] = $class;
            }

            foreach (self::$workers as $class)
            {
                # 设置工作ID
                $class->id = $workerId;

                # 调用初始化方法
                $class->onStart();
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
            self::$workerTask->onStop();
        }
        else
        {
            self::$worker->onStop();

            foreach (self::$workers as $worker)
            {
                /**
                 * @var Worker $worker
                 */
                $worker->onStop();
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
        self::$worker->onReceive($server, $fd, $fromId, $data);
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

        self::$worker->onRequest($request, $response);
    }

    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage($server, $frame)
    {
        self::$worker->onMessage($server, $frame);
    }

    /**
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     *
     * @param \Swoole\Websocket\Server $svr
     * @param \Swoole\Http\Request $req
     */
    public function onOpen($svr, $req)
    {
        self::$worker->onOpen($svr, $req);
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
        self::$worker->onConnect($server, $fd, $fromId);
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
        self::$worker->onClose($server, $fd, $fromId);
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
        self::$worker->onPacket($server, $data, $clientInfo);
    }

    /**
     * @param \Swoole\Server $server
     * @param $fromWorkerId
     * @param $message
     * @return null
     */
    public function onPipeMessage($server, $fromWorkerId, $message)
    {
        if (is_object($message) && $message instanceof \stdClass && $message->_sys === true)
        {
            $name     = $message->name;
            $serverId = $message->sid;
            $message  = $message->data;
        }
        else
        {
            $serverId = self::$serverId;
            $name     = null;
        }

        if ($server->taskworker)
        {
            # 调用 task 进程
            self::$workerTask->onPipeMessage($server, $fromWorkerId, $message, $serverId);
        }
        else
        {
            if ($name && isset(self::$workers[$name]))
            {
                # 调用对应的 worker 对象
                self::$workers[$name]->onPipeMessage($server, $fromWorkerId, $message, $serverId);
            }
            else
            {
                self::$worker->onPipeMessage($server, $fromWorkerId, $message, $serverId);
            }
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
        self::$worker->onFinish($server, $taskId, $data);
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
        if (is_object($data) && $data instanceof \stdClass && $data->_sys === true)
        {
            $serverId = $data->sid;
            $data     = $data->data;
        }
        else
        {
            $serverId = self::$serverId;
        }

        return self::$workerTask->onTask($server, $taskId, $fromId, $data, $serverId);
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onStart($server)
    {
        if (self::$serverType === 0)
        {
            $this->info("Server: ". (in_array(self::$config['server']['sock_type'], [1, 3]) ? 'tcp' : 'udp') ."://". self::$config['server']['host'] .":". self::$config['server']['port'] ."/");
        }

        if (self::$serverType === 1 || self::$serverType === 3)
        {
            $this->info("Http Server: http://". (self::$config['server']['host'] === '0.0.0.0' ? '127.0.0.1' : self::$config['server']['host']) .":". self::$config['server']['port'] ."/");
        }

        if (self::$serverType === 2 || self::$serverType === 3)
        {
            $this->info("webSocket Server: wss://". self::$config['server']['host'] .":". self::$config['server']['port'] ."/");
        }
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onManagerStart($server)
    {
        global $argv;
        self::setProcessName("php ". implode(' ', $argv) ." [manager]");

        $this->debug('manager start');
    }

    /**
     * 输出自定义log
     *
     * @param string $label
     * @param string|array $info
     * @param string $type
     * @param string $color
     */
    public function log($label, array $data = null, $type = 'other', $color = '[36m')
    {
        if (!isset(self::$logPath[$type]))return;

        if (null === $data)
        {
            $data  = $label;
            $label = null;
        }

        list($f, $t) = explode(' ', microtime());
        $f   = substr($f, 1, 4);
        $beg = "\x1b{$color}";
        $end = "\x1b[39m";
        $str = $beg .'['. date("Y-m-d H:i:s", $t) . "{$f}][{$type}]{$end} - " . ($label ? "\x1b]37m $label $end - " : '') . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE): $data) . "\n";

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
     * @param string|array $labelOrData
     * @param array        $data
     */
    public function warn($labelOrData, array $data = null)
    {
        $this->log($labelOrData, $data, 'warn', '[31m');
    }

    /**
     * 输出信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    public function info($labelOrData, array $data = null)
    {
        $this->log($labelOrData, $data, 'info', '[33m');
    }

    /**
     * 调试信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    public function debug($labelOrData, array $data = null)
    {
        $this->log($labelOrData, $data, 'debug', '[34m');
    }

    /**
     * 跟踪信息
     *
     * @param string|array $labelOrData
     * @param array        $data
     */
    public function trace($labelOrData, array $data = null)
    {
        $this->log($labelOrData, $data, 'trace', '[35m');
    }

    protected function checkConfig()
    {
        if (isset(self::$config['server']['mode']) && self::$config['server']['mode'] === 'base')
        {
            # 用 BASE 模式启动
            self::$serverMode = SWOOLE_BASE;
        }

        self::$config['server']['sock_type'] = (int)self::$config['server']['sock_type'];
        if (self::$config['server']['sock_type'] < 1 || self::$config['server']['sock_type'] > 6)
        {
            self::$config['server']['sock_type'] = 1;
        }

        # 设置启动模式
        if (isset(self::$config['server']['http']['use']))
        {
            if (self::$config['server']['http']['use'] && isset(self::$config['server']['http']['websocket']) && self::$config['server']['http']['websocket'])
            {
                self::$serverType = 3;
            }
            elseif (self::$config['server']['http']['use'])
            {
                self::$serverType = 1;
            }
            elseif (isset(self::$config['server']['http']['websocket']) && self::$config['server']['http']['websocket'])
            {
                self::$serverType = 2;
            }
        }

        if (self::$serverType === 3 || self::$serverType === 1)
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

        global $argv;
        if (in_array('-vvv', $argv))
        {
            self::$config['server']['log']['level'][] = 'info';
            self::$config['server']['log']['level'][] = 'debug';
            self::$config['server']['log']['level'][] = 'trace';
            error_reporting(E_ALL ^ E_NOTICE);
        }
        elseif (in_array('-vv', $argv) || isset($option['debug']))
        {
            self::$config['server']['log']['level'][] = 'info';
            self::$config['server']['log']['level'][] = 'debug';
        }
        elseif (in_array('-v', $argv))
        {
            self::$config['server']['log']['level'][] = 'info';
        }

        if (isset(self::$config['server']['log']['level']))
        {
            self::$config['server']['log']['level'] = array_unique((array)self::$config['server']['log']['level']);
        }

        # 设置log等级
        foreach (self::$config['server']['log']['level'] as $type)
        {
            if (isset(self::$config['server']['log']['path']) && self::$config['server']['log']['path'])
            {
                self::$logPath[$type] = str_replace('$type', $type, self::$config['server']['log']['path']);
                if (!is_writable(self::$logPath[$type]))
                {
                    echo "给定的log文件不可写: " . self::$logPath[$type] ."\n";
                }
            }
            else
            {
                self::$logPath[$type] = true;
            }
        }

        # 设置 swoole 的log输出路径
        if (isset(self::$config['swoole']['log_file']) && self::$config['server']['log']['path'])
        {
            self::$config['swoole']['log_file'] = str_replace('$type', 'swoole', self::$config['server']['log']['path']);
        }

        # 无集群模式
        if (!isset(self::$config['clusters']['mode']) || !self::$config['clusters']['mode'])
        {
            self::$config['clusters']['mode'] = 'none';
        }

        switch (self::$config['clusters']['mode'])
        {
            case 'simple':
            case 'task':
                self::$clustersType = 1;
                break;

            case 'advanced':
                self::$clustersType = 2;
                break;
        }

        # 集群服务器
        if (self::$clustersType > 0)
        {
            if (!isset(self::$config['clusters']['register']) || !is_array(self::$config['clusters']['register']))
            {
                self::warn('集群模式开启但是缺少 clusters.register 参数');
                exit;
            }

            if (!isset(self::$config['clusters']['register']['ip']))
            {
                self::warn('集群模式开启但是缺少 clusters.register.ip 参数');
                exit;
            }

            # 注册服务器端口
            if (!isset(self::$config['clusters']['register']['port']))
            {
                self::$config['clusters']['register']['port'] = 1310;
            }

            # 集群间通讯端口
            if (!isset(self::$config['clusters']['port']))
            {
                self::$config['clusters']['port'] = 1311;
            }

            # 高级模式下任务进程端口
            if (self::$clustersType === 2 && !isset(self::$config['clusters']['task_port']))
            {
                self::$config['clusters']['task_port'] = 1312;
            }

            if (isset(Server::$config['clusters']['register']['key']) && Server::$config['clusters']['register']['key'])
            {
                # 设置集群注册服务器密码
                Register\RPC::$RPC_KEY = Server::$config['clusters']['register']['key'];
            }
        }

        # 缓存目录
        if (isset(self::$config['swoole']['task_tmpdir']))
        {
            if (!is_dir(self::$config['swoole']['task_tmpdir']))
            {
                if (self::$config['swoole']['task_tmpdir'] !== '/dev/shm/')
                {
                    self::warn('定义的 swoole.task_tmpdir 的目录 '.self::$config['swoole']['task_tmpdir'].' 不存在, 已改到 /tmp/ 目录');
                }
                self::$config['swoole']['task_tmpdir'] = '/tmp/';
            }
        }

        # 对象的命名空间
        if (isset(self::$config['server']['namespace']) && self::$config['server']['namespace'])
        {
            $ns = trim(self::$config['server']['namespace'], '\\');
            if ($ns)
            {
                self::$namespace = "\\{$ns}\\";
            }
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
    protected function parseSockUri($uri)
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
    protected function getSockConf($key)
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
    protected function setListenCallback($key, $listen, \stdClass $opt)
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

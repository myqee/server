<?php
namespace MyQEE\Server;

define('VERSION', '4.0');

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
     * @var \Swoole\Server|\Swoole\WebSocket\Server|\Swoole\Redis\Server
     */
    public static $server;

    /**
     * 当前服务器名
     *
     * @var string
     */
    public static $serverName;

    /**
     * 当前任务进程对象
     *
     * @var \WorkerTask|WorkerTask
     */
    public static $workerTask;

    /**
     * 当前进程对象
     *
     * @var \WorkerMain|WorkerWebSocket|WorkerTCP|WorkerUDP|WorkerRedis
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
     * * 4 - 主端口为 Redis 服务，Redis 和 Http、WebSocket 不可同时使用
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
     * 日志输出设置
     *
     * @var int
     */
    public static $logPath = [
        'warn' => true
    ];

    /**
     * 主服务器的 Host key
     *
     * @var null
     */
    public static $mainHostKey = null;

    /**
     * 主服务器配置
     *
     * @var array
     */
    public static $mainHost = [];

    /**
     * 所有 Http 和 ws 服务列表
     *
     * @var array
     */
    protected static $hostsHttpAndWs = [];


    public function __construct($configFile = 'server.yal')
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
                    $this->warn('必须安装 yaml 插件');
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
                    $this->warn("指定的配置文件: $configFile 不存在");
                    exit;
                }
            }
        }

        if (!self::$config)
        {
            $this->warn("配置解析失败");
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

        if(!isset($_SERVER['_']))
        {
            $this->warn("必须命令行启动本服务");
            exit;
        }

        if (!defined('SWOOLE_VERSION'))
        {
            $this->warn("必须安装 swoole 插件, see http://www.swoole.com/");
            exit;
        }

        if (version_compare(SWOOLE_VERSION, '1.8.0', '<'))
        {
            $this->warn("swoole插件必须>=1.8版本");
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

        if (!isset($config['unixsock_buffer_size']) || $config['unixsock_buffer_size'] > 1000)
        {
            # 修改进程间通信的UnixSocket缓存区尺寸
            ini_set('swoole.unixsock_buffer_size', $config['unixsock_buffer_size'] ?: 104857600);
        }

        if (self::$config['clusters']['mode'] !== 'none')
        {
            if (!function_exists('\\msgpack_pack'))
            {
                $this->warn('开启集群模式必须安装 msgpack 插件');
                exit;
            }
        }

        if (!self::$config['socket_block'])
        {
            # 设置不阻塞
            swoole_async_set(['socket_dontwait' => 1]);
        }

        # 启动的任务进程数
        if (isset(self::$config['task']['number']) && self::$config['task']['number'])
        {
            self::$config['swoole']['task_worker_num'] = self::$config['task']['number'];
        }
        elseif (!isset(self::$config['swoole']['task_worker_num']))
        {
            # 不启用 task 进程
            self::$config['swoole']['task_worker_num'] = 0;
        }

        # 任务进程最大请求数后会重启worker
        if (isset(self::$config['task']['task_max_request']))
        {
            self::$config['swoole']['task_max_request'] = (int)self::$config['task']['task_max_request'];
        }

        $this->info("======= Swoole Config ========\n". str_replace('\\/', '/', json_encode(self::$config['swoole'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));

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

            case 4:
                # Redis 协议
                $className = '\\Swoole\\Redis\\Server';
                break;

            case 0:
            default:
                # 主端口为自定义端口
                $className = '\\Swoole\\Server';
                break;
        }

        $opt          = self::parseSockUri(self::$mainHost['listen'][0]);
        self::$server = new $className($opt->host, $opt->port, self::$serverMode, $opt->type);

        # 设置配置
        self::$server->set($config ?: self::$config['swoole']);

        # 有多个端口叠加绑定
        if (($count = count(self::$mainHost['listen'])) > 1)
        {
            for($i = 1; $i < $count; $i++)
            {
                $opt = self::parseSockUri(self::$mainHost['listen'][$i]);
                self::$server->listen($opt->host, $opt->port, $opt->type);
            }
        }
        # 清理变量
        unset($count, $opt, $i, $className, $config);

        $this->bind();

        $this->initHosts();


        if (self::$clustersType > 0)
        {
            if (self::$config['clusters']['register']['is_register'])
            {
                # 启动注册服务器
                $worker = new Register\WorkerMain(self::$server, '_RegisterServer');
                $worker->listen(self::$config['clusters']['register']['ip'], self::$config['clusters']['register']['port']);

                # 放在Worker对象里
                self::$workers[$worker->name] = $worker;
            }
        }

        # 启动服务
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

            if (self::$mainHost['handShake'])
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
    protected function initHosts()
    {
        foreach (self::$config['hosts'] as $key => $setting)
        {
            if ($key === self::$mainHostKey)continue;

            foreach ((array)$setting['listen'] as $st)
            {
                $opt    = $this->parseSockUri($st);
                $listen = self::$server->listen($opt->host, $opt->port, $opt->type);
                if (false === $listen)
                {
                    $this->warn('创建服务失败：' .$opt->host .':'. $opt->port);
                    exit;
                }

                self::$workers[$key] = $key;

                if (isset($setting['conf']) && $setting['conf'])
                {
                    $listen->set($setting['conf']);
                }

                # 设置回调
                $this->setListenCallback($key, $listen, $opt);

                $this->info("Listen: $st");
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
            $className = isset(self::$config['task']['class']) && self::$config['task']['class'] ? '\\'. trim(self::$config['task']['class'], '\\') : '\\WorkerTask';

            if (!class_exists($className))
            {
                # 停止服务
                if ($taskId === 0)
                {
                    $this->warn("任务进程 $className 类不存在");
                }
                $className = '\\MyQEE\\Server\\WorkerTask';
            }

            # 内存限制
            ini_set('memory_limit', self::$config['server']['task_worker_memory_limit'] ?: '4G');

            static::setProcessName("php ". implode(' ', $argv) ." [task#$taskId]");

            self::$workerTask       = new $className($server, '_Task');
            # 放一个在 $workers 里
            self::$workers['_Task'] = self::$workerTask;

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

            ini_set('memory_limit', self::$config['server']['worker_memory_limit'] ?: '2G');
            static::setProcessName("php ". implode(' ', $argv) ." [worker#$workerId]");

            foreach (self::$config['hosts'] as $k => $v)
            {
                $className = '\\'. trim($v['class'], '\\');

                if (!class_exists($className))
                {
                    if ($workerId === 0)
                    {
                        # 停止服务
                        $this->warn("Host: {$k} 工作进程 $className 类不存在(". current($v['listen']) .")");
                    }
                    $className = '\\MyQEE\\Server\\Worker';
                }

                /**
                 * @var $worker Worker
                 */
                $worker            = new $className($server, $k);
                self::$workers[$k] = $worker;

                if ($worker instanceof WorkerAPI)
                {
                    $worker->prefix       = isset($v['prefix']) && $v['prefix'] ? $v['prefix'] : '/api/';
                    $worker->prefixLength = strlen($worker->prefix);
                }
                elseif ($worker instanceof WorkerManager)
                {
                    $worker->prefix       = isset($v['prefix']) && $v['prefix'] ? $v['prefix'] : '/api/';
                    $worker->prefixLength = strlen($worker->prefix);
                }
            }
            self::$worker = self::$workers[self::$mainHostKey];

            foreach (self::$workers as $worker)
            {
                # 调用初始化方法
                $worker->onStart();
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
        $response->header('Server', self::$mainHost['name'] ?: 'MQSRV');

        self::fixMultiPostData($request);
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
     * WebSocket建立连接后进行握手
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onHandShake($request, $response)
    {
        self::$worker->onHandShake($request, $response);
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
     * @return void
     */
    public function onPipeMessage($server, $fromWorkerId, $message)
    {
        # 支持对象方式
        switch (substr($message, 0, 2))
        {
            case 'O:':
            case 'a:':
                if (false !== ($tmp = @unserialize($message)))
                {
                    $message = $tmp;
                }
                break;
        }

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
     * @return void
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
            $this->info('Server: '. current(self::$mainHost['listen']) .'/');
        }

        if (self::$serverType === 1 || self::$serverType === 3)
        {
            $this->info('Http Server: '. current(self::$mainHost['listen']) .'/');
        }

        if (self::$serverType === 2 || self::$serverType === 3)
        {
            $this->info('WebSocket Server: '. current(self::$mainHost['listen']) .'/');
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
        $str = $beg .'['. date("Y-m-d H:i:s", $t) . "{$f}][{$type}]{$end} - " . ($label ? "\x1b[37m$label$end - " : '') . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE): $data) . "\n";

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
        global $argv, $argc;

        if (PHP_SAPI === 'cgi-fcgi')
        {
            # CGI模式在 GET 参数里
            $argv     = array_keys($_GET);
            $argc     = count($argv);
            $_GET     = [];
            $_REQUEST = [];
            $_SERVER['argv'] = $argv;
            $_SERVER['argc'] = $argc;
        }

        if (in_array('-vvv', $argv))
        {
            self::$config['log']['level'][] = 'info';
            self::$config['log']['level'][] = 'debug';
            self::$config['log']['level'][] = 'trace';
            error_reporting(E_ALL ^ E_NOTICE);
        }
        elseif (in_array('-vv', $argv) || isset($option['debug']))
        {
            self::$config['log']['level'][] = 'info';
            self::$config['log']['level'][] = 'debug';
        }
        elseif (in_array('-v', $argv))
        {
            self::$config['log']['level'][] = 'info';
        }

        if (isset(self::$config['server']['log']['level']))
        {
            self::$config['log']['level'] = array_unique((array)self::$config['log']['level']);
        }

        # 设置log等级
        if (!isset(self::$config['log']['level']))
        {
            self::$config['log'] = [
                'level' => ['warn'],
                'size'  =>  10240000,
            ];
        }
        foreach (self::$config['log']['level'] as $key)
        {
            if (isset(self::$config['log']['path']) && self::$config['log']['path'])
            {
                self::$logPath[$key] = str_replace('$type', $key, self::$config['log']['path']);
                if (is_file(self::$logPath[$key]) && !is_writable(self::$logPath[$key]))
                {
                    echo "给定的log文件不可写: " . self::$logPath[$key] ."\n";
                }
            }
            else
            {
                self::$logPath[$key] = true;
            }
        }

        if (!isset(self::$config['swoole']) || !is_array(self::$config['swoole']))
        {
            self::$config['swoole'] = [];
        }

        if (!isset(self::$config['server']))
        {
            self::$config['server'] = [];
        }

        # 默认配置
        self::$config['server'] += [
            'worker_num'               => 16,
            'mode'                     => 'process',
            'unixsock_buffer_size'     => '104857600',
            'worker_memory_limit'      => '2G',
            'task_worker_memory_limit' => '4G',
            'socket_block'             => 0,
        ];

        if (isset(self::$config['server']['mode']) && self::$config['server']['mode'] === 'base')
        {
            # 用 BASE 模式启动
            self::$serverMode = SWOOLE_BASE;
        }

        if (isset(self::$config['server']['worker_num']))
        {
            self::$config['swoole']['worker_num'] = intval(self::$config['server']['worker_num']);
        }
        else if (!isset(self::$config['swoole']['worker_num']))
        {
            self::$config['swoole']['worker_num'] = function_exists('\\swoole_cpu_num') ? \swoole_cpu_num() : 8;
        }

        # 设置 swoole 的log输出路径
        if (isset(self::$config['swoole']['log_file']) && self::$config['log']['path'])
        {
            self::$config['swoole']['log_file'] = str_replace('$type', 'swoole', self::$config['log']['path']);
        }

        # 设置日志等级
        if (!isset(self::$config['swoole']['log_level']))
        {
            if (in_array('debug', self::$config['log']['level']))
            {
                self::$config['swoole']['log_level'] = 0;
            }
            elseif (in_array('trace', self::$config['log']['level']))
            {
                self::$config['swoole']['log_level'] = 1;
            }
            elseif (in_array('info', self::$config['log']['level']))
            {
                self::$config['swoole']['log_level'] = 2;
            }
            else
            {
                self::$config['swoole']['log_level'] = 4;
            }
        }

        if (!isset(self::$config['hosts']) || !self::$config['hosts'] || !is_array(self::$config['hosts']))
        {
            $this->warn('缺少 hosts 配置参数');
            exit;
        }

        $mainHost = null;
        foreach (self::$config['hosts'] as $key => & $hostConfig)
        {
            if (!isset($hostConfig['class']))
            {
                $hostConfig['class'] = "\\Worker{$key}";
            }
            elseif (substr($hostConfig['class'], 0) !== '\\')
            {
                $hostConfig['class'] = "\\". $hostConfig['class'];
            }

            if (!isset($hostConfig['listen']))
            {
                $hostConfig['listen'] = [];
            }
            elseif (!is_array($hostConfig['listen']))
            {
                $hostConfig['listen'] = [$hostConfig['listen']];
            }

            if (!isset($hostConfig['type']) || !$hostConfig['type'])
            {
                if ($hostConfig['listen'])
                {
                    $tmp = self::parseSockUri($hostConfig['listen'][0]);
                    $hostConfig['type'] = $tmp->scheme ? : 'tcp';
                }
                else
                {
                    $hostConfig['type'] = 'tcp';
                }
            }

            if (isset($hostConfig['host']) && $hostConfig['port'])
            {
                array_unshift($hostConfig['listen'], "{$hostConfig['type']}://{$hostConfig['host']}:{$hostConfig['port']}");
            }
            elseif (!isset($hostConfig['listen']) || !is_array($hostConfig['listen']) || !$hostConfig['listen'])
            {
                $this->warn('hosts “'. $key .'”配置错误，必须 host, port 或 listen 参数.');
                exit;
            }

            if (self::$serverType < 3)
            {
                switch ($hostConfig['type'])
                {
                    case 'ws':
                    case 'wss':
                        # 使用 onHandShake 回调 see http://wiki.swoole.com/wiki/page/409.html
                        $hostConfig['handShake'] = isset($hostConfig['handShake']) && $hostConfig['handShake'] ? true : false;

                        if (self::$serverType === 1)
                        {
                            # 已经有 http 服务了
                            self::$serverType = 3;
                        }
                        else
                        {
                            self::$serverType = 2;
                        }
                        self::$hostsHttpAndWs[$key] = $hostConfig;
                        if (null === $mainHost)
                        {
                            $mainHost = [$key, $hostConfig];
                        }

                        break;

                    case 'http':
                    case 'https':
                    case 'manager':
                    case 'api':
                        if (self::$serverType === 2)
                        {
                            # 已经有 webSocket 服务了
                            self::$serverType = 3;
                        }
                        else
                        {
                            self::$serverType = 1;
                        }

                        self::$hostsHttpAndWs[$key] = $hostConfig;
                        break;
                    case 'redis':
                        # Redis 服务器
                        if (!($this instanceof ServerRedis))
                        {
                            $this->warn('启动 Redis 服务器必须使用或扩展到 MyQEE\\Server\\ServerRedis 类，当前“'. get_class($this) .'”不支持');
                            exit;
                        }

                        self::$serverType = 4;
                        $mainHost         = [$key, $hostConfig];
                        break;

                    case 'upload':
                        # 上传服务器
                        if (!isset($hostConfig['conf']) || !is_array($hostConfig['conf']))
                        {
                            $hostConfig['conf'] = [];
                        }

                        # 设定参数
                        $hostConfig['conf'] = [
                            'open_eof_check'    => false,
                            'open_length_check' => false,
                        ] + $hostConfig['conf'] + [
                            'upload_tmp_dir'           => is_dir('/tmp/') ? '/tmp/' : sys_get_temp_dir() .'/',
                            'heartbeat_idle_time'      => 180,
                            'heartbeat_check_interval' => 60,
                        ];

                        if (substr($hostConfig['conf']['upload_tmp_dir'], -1) != '/')
                        {
                            $hostConfig['conf']['upload_tmp_dir'] .= '/';
                        }

                        break;

                    default:
                        if (!isset($hostConfig['conf']))
                        {
                            $hostConfig['conf'] = [
                                'open_eof_check' => true,
                                'open_eof_split' => true,
                                'package_eof'    => "\n",
                            ];
                        }
                        break;
                }
            }

            if (!self::$mainHostKey)
            {
                self::$mainHostKey = $key;
                self::$mainHost    = $hostConfig;
            }
        }

        $this->info("======= Hosts Config ========\n". str_replace('\\/', '/', json_encode(self::$config['hosts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));

        if (self::$serverType === 4 && self::$hostsHttpAndWs)
        {
            $this->warn('Redis 服务器和 Http、WebSocket 服务不能同时启用在一个服务里');
            exit;
        }

        if ($mainHost)
        {
            self::$mainHostKey = $mainHost[0];
            self::$mainHost    = $mainHost[1];
        }
        elseif (self::$hostsHttpAndWs)
        {
            reset(self::$hostsHttpAndWs);
            self::$mainHostKey = key(self::$hostsHttpAndWs);
            self::$mainHost    = current(self::$hostsHttpAndWs);
        }

        if (isset(self::$config['server']['name']) && self::$config['server']['name'])
        {
            self::$serverName = self::$config['server']['name'];
        }
        else
        {
            $opt = self::parseSockUri(self::$mainHost['listen'][0]);
            self::$serverName = $opt->host . ':' . $opt->port;
            unset($opt);
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
                $this->warn('集群模式开启但是缺少 clusters.register 参数');
                exit;
            }

            if (!isset(self::$config['clusters']['register']['ip']))
            {
                $this->warn('集群模式开启但是缺少 clusters.register.ip 参数');
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
                $tmpDir = '/tmp/';
                if (!is_dir($tmpDir))
                {
                    $tmpDir = sys_get_temp_dir();
                }

                if (self::$config['swoole']['task_tmpdir'] !== '/dev/shm/')
                {
                    $this->warn('定义的 swoole.task_tmpdir 的目录 '.self::$config['swoole']['task_tmpdir'].' 不存在, 已改到临时目录：'. $tmpDir);
                }
                self::$config['swoole']['task_tmpdir'] = $tmpDir;
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
            switch ($scheme = strtolower($p['scheme']))
            {
                case 'http':
                case 'https':
                case 'ws':
                case 'wss':
                case 'upload':
                case 'api':
                case 'manager':
                case 'tcp':
                case 'tcp4':
                case 'ssl':
                case 'sslv2':
                case 'sslv3':
                case 'tls':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_SOCK_TCP;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    break;

                case 'tcp6':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_SOCK_TCP6;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    break;

                case 'unix':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_UNIX_STREAM;
                    $result->host   = $p['path'];
                    $result->port   = 0;
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
     * 设置自定义端口监听的回调
     *
     * @param string  $key
     * @param \Swoole\Server\Port $listen
     * @param \stdClass $opt
     */
    protected function setListenCallback($key, $listen, \stdClass $opt)
    {
        switch ($opt->scheme)
        {
            case 'http':
            case 'https':
                $listen->on('Request', function($request, $response) use ($key)
                {
                    /**
                     * @var \Swoole\Http\Request $request
                     * @var \Swoole\Http\Response $response
                     */

                    # 发送一个头信息
                    $response->header('Server', self::$config['hosts'][$key]['name'] ?: 'MQSRV');

                    self::fixMultiPostData($request);
                    self::$workers[$key]->onRequest($request, $response);
                });
                break;
            case 'ws':
            case 'wss':

                $listen->on('Message', function($server, $frame) use ($key)
                {
                    self::$workers[$key]->onMessage($server, $frame);
                });

                if (self::$config['hosts'][$key]['handShake'])
                {
                    $listen->on('HandShake', function($request, $response) use ($key)
                    {
                        self::$workers[$key]->onHandShake($request, $response);
                    });
                }
                else
                {
                    $listen->on('Open', function($svr, $req) use ($key)
                    {
                        self::$workers[$key]->onOpen($svr, $req);
                    });
                }

                $listen->on('Close', function($server, $fd, $fromId) use ($key)
                {
                    self::$workers[$key]->onClose($server, $fd, $fromId);
                });

                break;
            default:

                $listen->on('Receive', function($server, $fd, $fromId, $data) use ($key)
                {
                    self::$workers[$key]->onReceive($server, $fd, $fromId, $data);
                });

                switch ($opt->type)
                {
                    case SWOOLE_SOCK_TCP:
                    case SWOOLE_SOCK_TCP6:
                        $listen->on('Connect', function($server, $fd, $fromId) use ($key)
                        {
                            self::$workers[$key]->onConnect($server, $fd, $fromId);
                        });

                        $listen->on('Close', function($server, $fd, $fromId) use ($key)
                        {
                            self::$workers[$key]->onClose($server, $fd, $fromId);
                        });

                        break;
                    case SWOOLE_UNIX_STREAM:

                        $listen->on('Packet', function($server, $data, $clientInfo) use ($key)
                        {
                            self::$workers[$key]->onPacket($server, $data, $clientInfo);
                        });
                        break;
                }
                break;
        }
    }

    /**
     * 修复 swoole_http_server 在 multipart/form-data 模式时不支持 a[]=1&a[]=2 这样的参数的问题
     *
     * @param \Swoole\Http\Request $request
     */
    protected function fixMultiPostData($request)
    {
        /*
        表单：
        <input type="input" name="a[]" />
        <input type="input" name="a[]" />
        <input type="input" name="aa[bb][]" />
        <input type="input" name="aa[bb][]" />
        <input type="input" name="aaa[aa]" />
        <input type="input" name="aaa[bb]" />

        数据：test=a&a[]=1&a[]=2&aa[bb][]=3&aa[bb][]=4&aaa[aa]=5&aaa[bb]=6

        Swoole 会错误的解析成这样的：
        Array
        (
            [test] => a
            [a[]] => Array
                (
                    [0] => 1
                    [1] => 2
                )

            [aa[bb][]] => Array
                (
                    [0] => 3
                    [1] => 4
                )

            [aaa[aa]] => 5
            [aaa[bb]] => 6
        )
         */
        if (!$request->post || $request->header['content-type'] == 'application/x-www-form-urlencoded')
        {
            # application/x-www-form-urlencoded 时可以正确解析
            return;
        }

        $multi = false;
        foreach ($request->post as $key => $v)
        {
            if (strpos($key, ']'))
            {
                $multi = true;
                break;
            }
        }

        if ($multi)
        {
            $str = '';
            foreach ($request->post as $key => $v)
            {
                if (is_array($v))
                {
                    foreach ($v as $item)
                    {
                        $str .= "{$key}=". rawurlencode($item) ."&";
                    }
                }
                else
                {
                    $str .= "{$key}=". rawurlencode($v) ."&";
                }
            }
            $str = rtrim($str, '&');
            parse_str($str, $request->post);
        }
    }
}

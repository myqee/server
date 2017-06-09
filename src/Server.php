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
    public $serverId = -1;

    /**
     * 所有的配置
     *
     * @var array
     */
    public $config = [];

    /**
     * 配置文件路径
     *
     * @var null
     */
    public $configFile = null;

    /**
     * @var \Swoole\Server|\Swoole\WebSocket\Server|\Swoole\Redis\Server
     */
    public $server;

    /**
     * 当前服务器名
     *
     * @var string
     */
    public $serverName;

    /**
     * 当前任务进程对象
     *
     * @var \WorkerTask|WorkerTask
     */
    public $workerTask;

    /**
     * 主进程对象名称
     *
     * @var string
     */
    public $defaultWorkerName = 'Main';

    /**
     * Main进程对象
     *
     * @var \WorkerMain|WorkerWebSocket|WorkerTCP|WorkerUDP|WorkerRedis
     */
    public $worker;

    /**
     * 所有工作进程对象，key同配置 hosts 中参数
     *
     * @var array
     */
    public $workers = [];

    /**
     * 服务器启动模式
     *
     * SWOOLE_BASE 或 SWOOLE_PROCESS
     *
     * @see http://wiki.swoole.com/wiki/page/14.html
     * @var int
     */
    public $serverMode = 3;

    /**
     * 集群模式
     *
     * 0 - 无集群, 1 - 简单模式, 2 - 高级模式
     *
     * @var int
     */
    public $clustersType = 0;

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
    public $serverType = 0;

    /**
     * 主进程的PID
     *
     * @var int
     */
    public $pid = 0;

    /**
     * 日志输出设置
     *
     * @var int
     */
    public $logPath = ['warn' => true];

    /**
     * 启动时间
     *
     * @var int
     */
    public $startTime;

    /**
     * 启动时间，含毫秒
     *
     * @var float
     */
    public $startTimeFloat;

    /**
     * 主服务器的 Host key
     *
     * @var null
     */
    protected $masterHostKey = null;

    /**
     * 主服务器对应的工作进程
     *
     * @var \WorkerMain|WorkerWebSocket|WorkerTCP|WorkerUDP|WorkerRedis
     */
    protected $masterWorker;

    /**
     * 主服务器配置
     *
     * @var array
     */
    protected $masterHost = [];

    /**
     * 所有 Http 和 ws 服务列表
     *
     * @var array
     */
    protected $hostsHttpAndWs = [];

    /**
     * 使用使用 php-cgi 命令启动
     *
     * PHP_SAPI 值：
     *
     *  * php: cli
     *  * php-cgi: cgi-fcgi
     *  * nginx: fpm-fcgi
     *  * apache: apache2handler
     *
     * @var bool
     */
    protected $cgiMode = false;

    /**
     * 当前服务器实例化对象
     *
     * @var static
     */
    public static $instance;

    public function __construct($configFile = 'server.yal')
    {
        $this->checkSystem();

        $this->startTimeFloat = microtime(1);
        $this->startTime      = time();
        self::$instance       = $this;
        $this->cgiMode        = PHP_SAPI === 'cgi-fcgi' ? true : false;

        if ($configFile)
        {
            if (is_array($configFile))
            {
                $this->config = $configFile;
            }
            else
            {
                $yal = false;
                if (!function_exists('\\yaml_parse_file'))
                {
                    if (!($yal = class_exists('\\Symfony\\Component\\Yaml\\Yaml')))
                    {
                        $this->warn('不能启动，需要 yaml 扩展支持，你可以安装 yaml 扩展，也可以通过 composer require symfony/yaml 命令来安装 yaml 的php版本');
                        exit;
                    }
                }

                if (is_file($configFile))
                {
                    $this->configFile = realpath($configFile);

                    # 读取配置
                    if ($yal)
                    {
                        try
                        {
                            $this->config = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($configFile));
                        }
                        catch (\Symfony\Component\Yaml\Exception\ParseException $e)
                        {
                            $this->warn('解析配置失败: '. $e->getMessage());
                            exit;
                        }
                    }
                    else
                    {
                        $this->config = yaml_parse_file($configFile);
                    }
                }
                else
                {
                    $this->warn("指定的配置文件: $configFile 不存在");
                    exit;
                }
            }
        }

        if (!$this->config)
        {
            $this->warn("配置解析失败");
            exit;
        }

        # 主进程的PID
        $this->pid = getmypid();
    }

    protected function checkSystem()
    {
        if (self::$instance)
        {
            throw new \Exception('只允许实例化一个 \\MyQEE\\Server\\Server 对象');
        }

        if(PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi')
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
        if (isset($this->config['php']['error_reporting']))
        {
            error_reporting($this->config['php']['error_reporting']);
        }

        if (isset($this->config['php']['timezone']))
        {
            date_default_timezone_set($this->config['php']['timezone']);
        }

        if (!isset($config['unixsock_buffer_size']) || $config['unixsock_buffer_size'] > 1000)
        {
            # 修改进程间通信的UnixSocket缓存区尺寸
            ini_set('swoole.unixsock_buffer_size', $config['unixsock_buffer_size'] ?: 104857600);
        }

        if ($this->config['clusters']['mode'] !== 'none')
        {
            if (!function_exists('\\msgpack_pack'))
            {
                $this->warn('开启集群模式必须安装 msgpack 插件');
                exit;
            }
        }

        //if (version_compare(SWOOLE_VERSION, '1.9.6', '>='))
        //{
        //    # 默认启用 fast_serialize
        //    # see https://wiki.swoole.com/wiki/page/p-serialize.html
        //    ini_set('swoole.fast_serialize', 'On');
        //}

        if (!$this->config['socket_block'])
        {
            # 设置不阻塞
            swoole_async_set(['socket_dontwait' => 1]);
        }

        # 启动的任务进程数
        if (isset($this->config['task']['number']) && $this->config['task']['number'])
        {
            $this->config['swoole']['task_worker_num'] = $this->config['task']['number'];
        }
        elseif (!isset($this->config['swoole']['task_worker_num']))
        {
            # 不启用 task 进程
            $this->config['swoole']['task_worker_num'] = 0;
        }

        # 任务进程最大请求数后会重启worker
        if (isset($this->config['task']['task_max_request']))
        {
            $this->config['swoole']['task_max_request'] = (int)$this->config['task']['task_max_request'];
        }

        $this->info("======= Swoole Config ========\n". str_replace('\\/', '/', json_encode($this->config['swoole'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));

        if ($this->clustersType > 0)
        {
            # 集群模式下初始化 Host 设置
            Clusters\Host::init($this->config['clusters']['register']['is_register']);
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

        if ($this->clustersType === 2 && $this->config['swoole']['task_worker_num'] > 0)
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
        /**
         * @var int|array $longOpts
         */
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
            $config                     = $this->config['swoole'];
            $config['task_worker_num']  = 0;
            $config['task_max_request'] = 0;

            $this->startWorkerServer($config);
        }

        return true;
    }

    protected function startWorkerServer($config = null)
    {
        switch($this->serverType)
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

        $opt          = self::parseSockUri($this->masterHost['listen'][0]);
        $this->server = new $className($opt->host, $opt->port, $this->serverMode, $opt->type);

        # 设置配置
        $this->server->set($config ?: $this->config['swoole']);

        # 有多个端口叠加绑定
        if (($count = count($this->masterHost['listen'])) > 1)
        {
            for($i = 1; $i < $count; $i++)
            {
                $opt = self::parseSockUri($this->masterHost['listen'][$i]);
                $this->server->listen($opt->host, $opt->port, $opt->type);
            }
        }
        # 清理变量
        unset($count, $opt, $i, $className, $config);

        $this->bind();

        $this->initHosts();


        if ($this->clustersType > 0)
        {
            if ($this->config['clusters']['register']['is_register'])
            {
                # 启动注册服务器
                $worker = new Register\WorkerMain($this->server, '_RegisterServer');
                $worker->listen($this->config['clusters']['register']['ip'], $this->config['clusters']['register']['port']);

                # 放在Worker对象里
                $this->workers[$worker->name] = $worker;
            }
        }

        # 启动服务
        $this->server->start();
    }

    /**
     * 启动task服务器
     */
    public function startTaskServer()
    {
        # 初始化任务服务器
        $server = new Clusters\TaskServer();

        $server->start($this->config['clusters']['host'] ?: '0.0.0.0', $this->config['clusters']['task_port']);
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
        $this->server->on('Shutdown',     [$this, 'onShutdown']);
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
        switch ($this->serverType)
        {
            case 0:
                # 自定义协议
                $this->server->on('Receive', [$this, 'onReceive']);
                break;
            case 1:
            case 2:
            case 3:
                # http and webSocket
                $this->server->on('Request', [$this, 'onRequest']);
                break;
        }

        # webSocket
        if ($this->serverType === 2 || $this->serverType === 3)
        {
            $this->server->on('Message', [$this, 'onMessage']);

            if ($this->masterHost['handShake'])
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
    protected function initHosts()
    {
        foreach ($this->config['hosts'] as $key => $setting)
        {
            if ($key === $this->masterHostKey)continue;

            foreach ((array)$setting['listen'] as $st)
            {
                $opt    = $this->parseSockUri($st);
                $listen = $this->server->listen($opt->host, $opt->port, $opt->type);
                if (false === $listen)
                {
                    $this->warn('创建服务失败：' .$opt->host .':'. $opt->port .', 错误码:' . $this->server->getLastError());
                    exit;
                }

                $this->workers[$key] = $key;

                if (isset($setting['conf']) && $setting['conf'])
                {
                    $listen->set($setting['conf']);
                }

                # 设置回调
                $this->setListenCallback($key, $listen, $opt);

                $this->info("Listen: $st");
            }
        }

        if ($this->config['remote_shell']['open'])
        {
            $shell = $this->workers['_remoteShell'] = new RemoteShell(isset($this->config['remote_shell']['public_key']) ? $this->config['remote_shell']['public_key'] : null);
            $rs    = $shell->listen($this->server, $host = $this->config['remote_shell']['host'] ?: '127.0.0.1', $port = $this->config['remote_shell']['port']?: 9599);
            if ($rs)
            {
                $this->info("Add remote shell tcp://$host:$port success");
            }
            else
            {
                $this->warn("RAdd remote shell tcp://$host:$port fail");
                exit;
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
        if (isset($this->config['swoole']['daemonize']) && $this->config['swoole']['daemonize'] == 1)
        {
            $this->pid = $this->server->master_pid;
        }

        if($server->taskworker)
        {
            # 任务序号
            $taskId    = $workerId - $server->setting['worker_num'];
            $className = isset($this->config['task']['class']) && $this->config['task']['class'] ? '\\'. trim($this->config['task']['class'], '\\') : '\\WorkerTask';

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
            ini_set('memory_limit', $this->config['server']['task_worker_memory_limit'] ?: '4G');

            $this->setProcessTag("task#$taskId");

            $this->workerTask       = new $className($server, '_Task');
            # 放一个在 $workers 里
            $this->workers['_Task'] = $this->workerTask;

            $this->workerTask->onStart();

            self::debug("TaskWorker#{$taskId} Started, pid: {$this->server->worker_pid}");
        }
        else
        {
            if ($workerId === 0 && $this->clustersType > 0)
            {
                # 集群模式, 第一个进程执行, 连接注册服务器
                $id = isset($this->config['clusters']['id']) && $this->config['clusters']['id'] >= 0 ? (int)$this->config['clusters']['id'] : -1;
                Register\Client::init($this->config['clusters']['group'] ?: 'default', $id, false);
            }

            ini_set('memory_limit', $this->config['server']['worker_memory_limit'] ?: '2G');
            $this->setProcessTag("worker#$workerId");

            foreach ($this->config['hosts'] as $k => $v)
            {
                $className = '\\'. trim($v['class'], '\\');

                if (!class_exists($className))
                {
                    $old = $className;
                    if (isset($v['type']))
                    {
                        switch ($v['type'])
                        {
                            case 'api':
                                $className = '\\MyQEE\\Server\\WorkerAPI';
                                break;

                            case 'http':
                            case 'https':
                                $className = '\\MyQEE\\Server\\WorkerHttp';
                                break;

                            case 'upload':
                                $className = '\\MyQEE\\Server\\WorkerHttpRangeUpload';
                                break;

                            case 'manager':
                                $className = '\\MyQEE\\Server\\WorkerManager';
                                break;

                            default:
                                $className = '\\MyQEE\\Server\\Worker';
                                break;
                        }
                    }
                    else
                    {
                        $className = '\\MyQEE\\Server\\Worker';
                    }

                    if ($workerId === 0)
                    {
                        # 停止服务
                        $this->warn("Host: {$k} 工作进程 $old 类不存在(". current($v['listen']) ."), 已使用默认对象 {$className} 代替");
                    }
                }

                /**
                 * @var $worker Worker
                 */
                $worker            = new $className($server, $k);
                $this->workers[$k] = $worker;
            }
            $this->worker       = $this->workers[$this->defaultWorkerName];
            $this->masterWorker = $this->workers[$this->masterHostKey];

            foreach ($this->workers as $worker)
            {
                # 调用初始化方法
                $worker->onStart();
            }

            self::debug("Worker#{$workerId} Started, pid: {$this->server->worker_pid}");
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
            $this->workerTask->onStop();
            self::debug("TaskWorker#". ($workerId - $server->setting['worker_num']) ." Stopped, pid: {$this->server->worker_pid}");
        }
        else
        {
            foreach ($this->workers as $worker)
            {
                /**
                 * @var Worker $worker
                 */
                $worker->onStop();
            }
            self::debug("Worker#{$workerId} Stopped, pid: {$this->server->worker_pid}");
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
        $this->masterWorker->onReceive($server, $fd, $fromId, $data);
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
        $response->header('Server', $this->masterHost['name']);

        self::fixMultiPostData($request);
        $this->masterWorker->onRequest($request, $response);
    }

    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage($server, $frame)
    {
        $this->masterWorker->onMessage($server, $frame);
    }

    /**
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     *
     * @param \Swoole\Websocket\Server $svr
     * @param \Swoole\Http\Request $req
     */
    public function onOpen($svr, $req)
    {
        $this->masterWorker->onOpen($svr, $req);
    }

    /**
     * WebSocket建立连接后进行握手
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onHandShake($request, $response)
    {
        $this->masterWorker->onHandShake($request, $response);
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
        $this->masterWorker->onConnect($server, $fd, $fromId);
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
        $this->masterWorker->onClose($server, $fd, $fromId);
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
        $this->masterWorker->onPacket($server, $data, $clientInfo);
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

        if (is_object($message) && get_class($message) === 'stdClass' && isset($message->__sys__) && $message->__sys__ === true)
        {
            $serverId = isset($message->sid) ? $message->sid : -1;
            $name     = isset($message->name) ? $message->name : null;
            $message  = $message->data;

            # 消息对象, 直接调用
            if (is_object($message) && $message instanceof Message)
            {
                $message->onPipeMessage($server, $fromWorkerId, $serverId);
                return;
            }
        }
        else
        {
            $serverId = $this->serverId;
            $name     = null;
        }

        if ($server->taskworker)
        {
            # 调用 task 进程
            $this->workerTask->onPipeMessage($server, $fromWorkerId, $message, $serverId);
        }
        else
        {
            if ($name && isset($this->workers[$name]))
            {
                # 调用对应的 worker 对象
                $this->workers[$name]->onPipeMessage($server, $fromWorkerId, $message, $serverId);
            }
            else
            {
                $this->worker->onPipeMessage($server, $fromWorkerId, $message, $serverId);
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
        $this->worker->onFinish($server, $taskId, $data);
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
        if (is_object($data) && get_class($data) === 'stdClass' && isset($data->__sys__) && $data->__sys__ === true)
        {
            $serverId = $data->sid;
            $data     = $data->data;
        }
        else
        {
            $serverId = $this->serverId;
        }

        return $this->workerTask->onTask($server, $taskId, $fromId, $data, $serverId);
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onStart($server)
    {
        if ($this->serverType === 0)
        {
            $this->info('Server: '. current($this->masterHost['listen']) .'/');
        }

        if ($this->serverType === 1 || $this->serverType === 3)
        {
            $this->info('Http Server: '. current($this->masterHost['listen']) .'/');
        }

        if ($this->serverType === 2 || $this->serverType === 3)
        {
            $this->info('WebSocket Server: '. current($this->masterHost['listen']) .'/');
        }
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onShutdown($server)
    {

    }

    /**
     * @param \Swoole\Server $server
     */
    public function onManagerStart($server)
    {
        if (isset($this->config['swoole']['daemonize']) && $this->config['swoole']['daemonize'] == 1)
        {
            $this->pid = $this->server->master_pid;
        }

        $this->setProcessTag('manager');
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
        if (!isset($this->logPath[$type]))return;

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

        if (is_string($this->logPath[$type]))
        {
            # 写文件
            @file_put_contents($this->logPath[$type], $str, FILE_APPEND);
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

    /**
     * 给进程设置一个Tag名
     *
     * @param $tag
     */
    public function setProcessTag($tag)
    {
        global $argv;
        $this->setProcessName("php ". implode(' ', $argv) ." [$tag] pid={$this->pid}");
    }

    /**
     * 设置进程的名称
     *
     * @param $name
     */
    public function setProcessName($name)
    {
        if(PHP_OS === 'Darwin')
        {
            # Mac 系统设置不了
            return;
        }

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

    protected function checkConfig()
    {
        global $argv, $argc;

        if ($this->cgiMode)
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
            $this->config['log']['level'][] = 'info';
            $this->config['log']['level'][] = 'debug';
            $this->config['log']['level'][] = 'trace';
            error_reporting(E_ALL ^ E_NOTICE);
        }
        elseif (in_array('-vv', $argv) || isset($option['debug']))
        {
            $this->config['log']['level'][] = 'info';
            $this->config['log']['level'][] = 'debug';
        }
        elseif (in_array('-v', $argv))
        {
            $this->config['log']['level'][] = 'info';
        }

        if (isset($this->config['server']['log']['level']))
        {
            $this->config['log']['level'] = array_unique((array)$this->config['log']['level']);
        }

        # 设置log等级
        if (!isset($this->config['log']['level']))
        {
            $this->config['log'] = [
                'level' => ['warn'],
                'size'  =>  10240000,
            ];
        }
        if ($this->cgiMode && (!isset($this->config['log']['path']) || !$this->config['log']['path']))
        {
            # php-cgi 下强制输出到指定目录
            $this->config['log']['path'] = '/tmp/myqee-cgi.$type.log';
        }

        foreach ($this->config['log']['level'] as $key)
        {
            if (isset($this->config['log']['path']) && $this->config['log']['path'])
            {
                $this->logPath[$key] = str_replace('$type', $key, $this->config['log']['path']);
                if (is_file($this->logPath[$key]) && !is_writable($this->logPath[$key]))
                {
                    echo "给定的log文件不可写: " . $this->logPath[$key] ."\n";
                    exit;
                }
            }
            else
            {
                $this->logPath[$key] = true;
            }
        }

        if (!isset($this->config['swoole']) || !is_array($this->config['swoole']))
        {
            $this->config['swoole'] = [];
        }

        if (!isset($this->config['server']))
        {
            $this->config['server'] = [];
        }

        # 默认配置
        $this->config['server'] += [
            'worker_num'               => 16,
            'mode'                     => 'process',
            'unixsock_buffer_size'     => '104857600',
            'worker_memory_limit'      => '2G',
            'task_worker_memory_limit' => '4G',
            'socket_block'             => 0,
        ];

        if (isset($this->config['server']['mode']) && $this->config['server']['mode'] === 'base')
        {
            # 用 BASE 模式启动
            $this->serverMode = SWOOLE_BASE;
        }

        if (isset($this->config['server']['worker_num']))
        {
            $this->config['swoole']['worker_num'] = intval($this->config['server']['worker_num']);
        }
        else if (!isset($this->config['swoole']['worker_num']))
        {
            $this->config['swoole']['worker_num'] = function_exists('\\swoole_cpu_num') ? \swoole_cpu_num() : 8;
        }

        # 设置 swoole 的log输出路径
        if (!isset($this->config['swoole']['log_file']) && $this->config['log']['path'])
        {
            $this->config['swoole']['log_file'] = str_replace('$type', 'swoole', $this->config['log']['path']);
        }

        # 设置日志等级
        if (!isset($this->config['swoole']['log_level']))
        {
            if (in_array('debug', $this->config['log']['level']))
            {
                $this->config['swoole']['log_level'] = 0;
            }
            elseif (in_array('trace', $this->config['log']['level']))
            {
                $this->config['swoole']['log_level'] = 1;
            }
            elseif (in_array('info', $this->config['log']['level']))
            {
                $this->config['swoole']['log_level'] = 2;
            }
            else
            {
                $this->config['swoole']['log_level'] = 4;
            }
        }

        if (!isset($this->config['hosts']) || !$this->config['hosts'] || !is_array($this->config['hosts']))
        {
            $this->warn('缺少 hosts 配置参数');
            exit;
        }

        # 主对象名称
        $this->defaultWorkerName = key($this->config['hosts']);

        $mainHost = null;
        foreach ($this->config['hosts'] as $key => & $hostConfig)
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

            if ($this->serverType < 3)
            {
                switch ($hostConfig['type'])
                {
                    case 'ws':
                    case 'wss':
                        # 使用 onHandShake 回调 see http://wiki.swoole.com/wiki/page/409.html
                        $hostConfig['handShake'] = isset($hostConfig['handShake']) && $hostConfig['handShake'] ? true : false;

                        if ($this->serverType === 1)
                        {
                            # 已经有 http 服务了
                            $this->serverType = 3;
                        }
                        else
                        {
                            $this->serverType = 2;
                        }
                        $this->hostsHttpAndWs[$key] = $hostConfig;
                        if (null === $mainHost)
                        {
                            $mainHost = [$key, $hostConfig];
                        }

                        break;

                    case 'http':
                    case 'https':
                    case 'manager':
                    case 'api':
                        if ($this->serverType === 2)
                        {
                            # 已经有 webSocket 服务了
                            $this->serverType = 3;
                        }
                        else
                        {
                            $this->serverType = 1;
                        }

                        $this->hostsHttpAndWs[$key] = $hostConfig;
                        break;
                    case 'redis':
                        # Redis 服务器
                        if (!($this instanceof ServerRedis))
                        {
                            $this->warn('启动 Redis 服务器必须使用或扩展到 MyQEE\\Server\\ServerRedis 类，当前“'. get_class($this) .'”不支持');
                            exit;
                        }

                        $this->serverType = 4;
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

            if (!$this->masterHostKey)
            {
                $this->masterHostKey = $key;
                $this->masterHost    = $hostConfig;
            }
        }

        if (isset($this->masterHost['conf']) && $this->masterHost['conf'])
        {
            $this->config['swoole'] = array_merge($this->masterHost['conf'], $this->config['swoole']);
        }

        $this->info("======= Hosts Config ========\n". str_replace('\\/', '/', json_encode($this->config['hosts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));

        if ($this->serverType === 4 && $this->hostsHttpAndWs)
        {
            $this->warn('Redis 服务器和 Http、WebSocket 服务不能同时启用在一个服务里');
            exit;
        }

        if ($mainHost)
        {
            $this->masterHostKey = $mainHost[0];
            $this->masterHost    = $mainHost[1];
        }
        elseif ($this->hostsHttpAndWs)
        {
            reset($this->hostsHttpAndWs);
            $this->masterHostKey = key($this->hostsHttpAndWs);
            $this->masterHost    = current($this->hostsHttpAndWs);
        }

        if ($this->serverType > 0 && $this->serverType < 4)
        {
            if (!isset($this->config['swoole']['open_tcp_nodelay']))
            {
                # 开启后TCP连接发送数据时会关闭Nagle合并算法，立即发往客户端连接, http服务器，可以提升响应速度
                # see https://wiki.swoole.com/wiki/page/316.html
                $this->config['swoole']['open_tcp_nodelay'] = true;
            }

            if (!isset($this->masterHost['name']))
            {
                # 默认 Server 名称
                $this->masterHost['name'] = 'MQSRV';
            }
        }

        if (isset($this->config['server']['name']) && $this->config['server']['name'])
        {
            $this->serverName = $this->config['server']['name'];
        }
        else
        {
            $opt = self::parseSockUri($this->masterHost['listen'][0]);
            $this->serverName = $opt->host . ':' . $opt->port;
            unset($opt);
        }

        # 无集群模式
        if (!isset($this->config['clusters']['mode']) || !$this->config['clusters']['mode'])
        {
            $this->config['clusters']['mode'] = 'none';
        }

        switch ($this->config['clusters']['mode'])
        {
            case 'simple':
            case 'task':
                $this->clustersType = 1;
                break;

            case 'advanced':
                $this->clustersType = 2;
                break;
        }

        # 集群服务器
        if ($this->clustersType > 0)
        {
            if (!isset($this->config['clusters']['register']) || !is_array($this->config['clusters']['register']))
            {
                $this->warn('集群模式开启但是缺少 clusters.register 参数');
                exit;
            }

            if (!isset($this->config['clusters']['register']['ip']))
            {
                $this->warn('集群模式开启但是缺少 clusters.register.ip 参数');
                exit;
            }

            # 注册服务器端口
            if (!isset($this->config['clusters']['register']['port']))
            {
                $this->config['clusters']['register']['port'] = 1310;
            }

            # 集群间通讯端口
            if (!isset($this->config['clusters']['port']))
            {
                $this->config['clusters']['port'] = 1311;
            }

            # 高级模式下任务进程端口
            if ($this->clustersType === 2 && !isset($this->config['clusters']['task_port']))
            {
                $this->config['clusters']['task_port'] = 1312;
            }

            if (isset($this->config['clusters']['register']['key']) && $this->config['clusters']['register']['key'])
            {
                # 设置集群注册服务器密码
                Register\RPC::$RPC_KEY = $this->config['clusters']['register']['key'];
            }
        }

        # 缓存目录
        if (isset($this->config['swoole']['task_tmpdir']))
        {
            if (!is_dir($this->config['swoole']['task_tmpdir']))
            {
                $tmpDir = '/tmp/';
                if (!is_dir($tmpDir))
                {
                    $tmpDir = sys_get_temp_dir();
                }

                if ($this->config['swoole']['task_tmpdir'] !== '/dev/shm/')
                {
                    $this->warn('定义的 swoole.task_tmpdir 的目录 '.$this->config['swoole']['task_tmpdir'].' 不存在, 已改到临时目录：'. $tmpDir);
                }
                $this->config['swoole']['task_tmpdir'] = $tmpDir;
            }
        }

        if (!isset($this->config['remote_shell']))
        {
            $this->config['remote_shell'] = [
                'open' => false,
            ];
        }
        else
        {
            $this->config['remote_shell']['open'] = isset($this->config['remote_shell']['open']) ? (bool)$this->config['remote_shell']['open'] : false;
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
        if (false === $p && substr($uri, 0, 8) == 'unix:///')
        {
            $p = [
                'scheme' => 'unix',
                'path'   => substr($uri, 7),
            ];
        }

        if (false !== $p)
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

                case 'udp':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_SOCK_UDP;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    break;

                case 'udp6':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_SOCK_UDP6;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    break;

                case 'unix':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_UNIX_STREAM;
                    $result->host   = (isset($p['host']) ? '/'.$p['host']:''). $p['path'];
                    $result->port   = 0;
                    break;

                default:
                    throw new \Exception("Can't support this scheme: {$p['scheme']}");
            }
        }
        else
        {
            throw new \Exception("Can't parse this Uri: " . $uri);
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
                    $response->header('Server', $this->config['hosts'][$key]['name'] ?: 'MQSRV');

                    self::fixMultiPostData($request);
                    $this->workers[$key]->onRequest($request, $response);
                });
                break;
            case 'ws':
            case 'wss':

                $listen->on('Message', function($server, $frame) use ($key)
                {
                    $this->workers[$key]->onMessage($server, $frame);
                });

                if ($this->config['hosts'][$key]['handShake'])
                {
                    $listen->on('HandShake', function($request, $response) use ($key)
                    {
                        $this->workers[$key]->onHandShake($request, $response);
                    });
                }
                else
                {
                    $listen->on('Open', function($svr, $req) use ($key)
                    {
                        $this->workers[$key]->onOpen($svr, $req);
                    });
                }

                $listen->on('Close', function($server, $fd, $fromId) use ($key)
                {
                    $this->workers[$key]->onClose($server, $fd, $fromId);
                });

                break;
            default:

                $listen->on('Receive', function($server, $fd, $fromId, $data) use ($key)
                {
                    $this->workers[$key]->onReceive($server, $fd, $fromId, $data);
                });

                switch ($opt->type)
                {
                    case SWOOLE_SOCK_TCP:
                    case SWOOLE_SOCK_TCP6:
                        $listen->on('Connect', function($server, $fd, $fromId) use ($key)
                        {
                            $this->workers[$key]->onConnect($server, $fd, $fromId);
                        });

                        $listen->on('Close', function($server, $fd, $fromId) use ($key)
                        {
                            $this->workers[$key]->onClose($server, $fd, $fromId);
                        });

                        break;
                    case SWOOLE_UNIX_STREAM:

                        $listen->on('Packet', function($server, $data, $clientInfo) use ($key)
                        {
                            $this->workers[$key]->onPacket($server, $data, $clientInfo);
                        });
                        break;
                }
                break;
        }
    }

    /**
     * 修复 swoole_http_server（1.9.6以下版本） 在 multipart/form-data 模式时不支持 a[]=1&a[]=2 这样的参数的问题
     *
     * @param \Swoole\Http\Request $request
     */
    protected function fixMultiPostData($request)
    {
        static $s = null;
        if ($s === null)
        {
            $s = version_compare(SWOOLE_VERSION, '1.9.6', '>=');
        }
        if (true === $s)return;

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
        foreach ($request->post as $key => $s)
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
            foreach ($request->post as $key => $s)
            {
                if (is_array($s))
                {
                    foreach ($s as $item)
                    {
                        $str .= "{$key}=". rawurlencode($item) ."&";
                    }
                }
                else
                {
                    $str .= "{$key}=". rawurlencode($s) ."&";
                }
            }
            $str = rtrim($str, '&');
            parse_str($str, $request->post);
        }
    }
}

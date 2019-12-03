<?php

namespace MyQEE\Server;

define('VERSION', '2.1');


/**
 * 服务器对象
 *
 * 主端口可同时支持 WebSocket, Http 协议, 并可以额外监听TCP新端口
 *
 * @package MyQEE\Server
 */
class Server {
    use Traits\Log;

    /**
     * 全局配置
     *
     * 可以直接通过 `Config::$instance` 获取
     *
     * @var Config
     */
    public $config = [];

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
     * @var \WorkerTask|Worker\ProcessTask|null|mixed
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
     * @var \WorkerMain|Worker\SchemeWebSocket|Worker\SchemeTCP|Worker\SchemeUDP|Worker\SchemeRedis|null|mixed
     */
    public $worker;

    /**
     * 所有工作进程对象，key同配置 hosts 中参数
     *
     * @var array
     */
    public $workers = [];

    /**
     * 所有端口的监听端口对象
     *
     * @var array
     */
    public $portListens = [];

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
     * 事件对象
     *
     * @var Event
     */
    public $event;

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
     * 请求数
     *
     * @var int
     */
    public $counterRequest = 0;

    /**
     * 上次开始统计时的时间
     *
     * @var int
     */
    public $counterRequestBeginTime = 0;

    /**
     * 当前的QPS
     *
     * @var int
     */
    public $counterQPS = 0;

    /**
     * 每个进程的QPS汇总
     *
     * 可通过 `$this->getServerQPS()` 获取服务器总的QPS
     *
     * @var \Swoole\Table
     */
    protected $counterQPSTable;

    /**
     * 主服务器的 Host key
     *
     * @var null
     */
    protected $masterHostKey = null;

    /**
     * 主服务器对应的工作进程
     *
     * @var \WorkerMain|Worker\SchemeWebSocket|Worker\SchemeTCP|Worker\SchemeUDP|Worker\SchemeRedis
     */
    protected $masterWorker;

    /**
     * 主服务器配置
     *
     * @var array
     */
    protected $masterHost = [];

    /**
     * 所有自定义子进程进程
     *
     * @var array
     */
    protected $customWorkerProcessList = [];

    /**
     * 所有自定义子进程序号所对应的Key
     *
     * 序号并不是从0开始，而是从 worker_num + task_worker_num 开始
     *
     * @var array
     */
    protected $customWorkerIdForKey = [];

    /**
     * 自定义子进程共享内存状态
     *
     * @var \Swoole\Table
     */
    protected $customWorkerTable;

    /**
     * 当前进程是否自定义子进程key
     *
     * null 表示非自定义子进程
     *
     * @var bool
     */
    public $customWorkerKey = null;

    /**
     * 自定义子进程Worker对象
     *
     * @var Worker\ProcessCustom
     */
    public $customWorker;

    /**
     * 进程Tag标签
     *
     * 系统会自动设置
     *
     * @var string
     */
    public $processTag = 'manager';

    /**
     * 临时文件目录
     *
     * 默认 /tmp/ 目录，不存在的话使用 sys_get_temp_dir() 返回的目录，带后缀
     *
     * @var string
     */
    public $tmpDir = '/tmp/';

    /**
     * 当前服务器实例化对象
     *
     * @var static
     */
    public static $instance;

    /**
     * @var \Swoole\Atomic
     */
    private $_realMasterPid;

    /**
     * 服务器实例
     *
     * @param string|array|Config $configFile
     */
    public function __construct($configFile = 'server.yal') {
        $this->checkSystem();

        $this->startTimeFloat = microtime(true);
        $this->startTime      = time();
        self::$instance       = $this;

        if (!is_dir($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        }

        # 主进程的PID
        $this->pid = getmypid();

        $config = [];
        if ($configFile) {
            if (is_object($configFile) && $configFile instanceof Config) {
                $this->config = $configFile;
                return;
            }
            elseif (is_array($configFile)) {
                $config = $configFile;
            }
            else {
                if (0 === Util\Text::yamlSupportType()) {
                    exit('不能启动，需要 yaml 扩展支持，你可以安装 yaml 扩展，也可以通过 composer require symfony/yaml 命令来安装 yaml 的php版本');
                }

                if (is_file($configFile)) {
                    $config = Util\Text::yamlParse($configFile);
                    if (false === $config) {
                        exit("解析配置失败, 请检查文件: $configFile");
                    }
                }
                else {
                    exit("指定的配置文件: $configFile 不存在");
                }
            }
        }
        if (!$config) {
            exit("配置解析失败");
        }
        $this->config = Config::create($config);
    }

    /**
     * 检查系统兼容
     */
    protected function checkSystem() {
        if (self::$instance) {
            $e = '\\Exception';
            throw new $e('只允许实例化一个 \\MyQEE\\Server\\Server 对象');
        }

        if (PHP_SAPI !== 'cli') {
            exit("必须命令行启动本服务");
        }

        if (version_compare(PHP_VERSION, '7', '<')) {
            exit("需要PHP7及以上版本，推荐使用最新版本PHP");
        }

        if (!defined('SWOOLE_VERSION')) {
            exit("必须安装 swoole 插件, see http://www.swoole.com/");
        }

        if (version_compare(SWOOLE_VERSION, '4.2.12', '<')) {
            exit("本服务需要Swoole v4.2.12及以上版本，推荐使用最新版本");
        }

        if (!class_exists('\\Swoole\\Server', false)) {
            # 载入兼容对象文件
            exit("你没有开启 swoole 的命名空间模式, 请修改 ini 文件增加 swoole.use_namespace = true 参数. \n操作方式: 先执行 php --ini 看 swoole 的扩展配置在哪个文件, 然后编辑对应文件加入即可, 如果没有则加入 php.ini 里");
        }
    }

    public function init() {
        # 将配置更新到 Server 上
        foreach ($this->config->getServersSetting() as $key => $value) {
            $this->$key = $value;
        }

        $this->config->effectiveConfig();

        if (isset($this->config['serverName']) && $this->config['serverName']) {
            $this->serverName = $this->config['serverName'];
        }

        $this->info("======= Swoole Config ========\n" . str_replace('\\/', '/', json_encode($this->config['swoole'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));

        $size  = bindec(str_pad(1, strlen(decbin($this->config['swoole']['worker_num'] - 1)), 0)) * 2;
        $table = new \Swoole\Table($size);
        $table->column('qps', \SWOOLE\Table::TYPE_INT, 8);
        $table->create();
        $this->counterQPSTable = $table;

        $this->event = new Event();
    }

    /**
     * 在启动前执行, 可以扩展本方法
     */
    public function onBeforeStart() {
    }

    /**
     * 安装服务
     */
    public function setup() {
        # 检查配置
        $this->initConfig();

        # 初始化配置
        $this->init();

        # 创建服务器
        $this->createServer();

        # 绑定事件
        $this->bind();

        # 初始化服务器端口
        $this->initServers();

        # 初始化自定义子进程
        $this->initCustomWorker();

        # 在启动前执行
        $this->onBeforeStart();
    }

    /**
     * 启动服务
     */
    public function start() {
        $this->setup();

        # 启动服务
        $this->server->start();
    }

    public function createServer($config = null) {
        switch ($this->serverType) {
            case 3:
            case 2:
                # 主端口同时支持 WebSocket 和 Http 协议
                $className = \Swoole\WebSocket\Server::class;
                break;

            case 1:
                # 主端口仅 Http 协议
                $className = \Swoole\Http\Server::class;
                break;

            case 4:
                # Redis 协议
                $className = \Swoole\Redis\Server::class;
                break;

            case 0:
            default:
                # 主端口为自定义端口
                $className = \Swoole\Server::class;
                break;
        }

        try {
            $opt          = self::parseSockUri($this->masterHost['listen'][0]);
            $this->server = new $className($opt->host, $opt->port, $this->config['mode'], $opt->type | $opt->ssl);
        }
        catch (\Exception $e) {
            $this->warn("Create Server {$className} error. msg: ". $e->getMessage() .", opt: " . json_encode($opt));
            exit;
        }

        # 设置配置
        $this->server->set($config ?: $this->config['swoole']);

        # 有多个端口叠加绑定
        if (($count = count($this->masterHost['listen'])) > 1) {
            for ($i = 1; $i < $count; $i++) {
                try {
                    $opt = self::parseSockUri($this->masterHost['listen'][$i]);
                    $this->server->listen($opt->host, $opt->port, $opt->type | $opt->ssl);
                }
                catch (\Exception $e) {
                    $this->warn($e->getMessage());
                    exit;
                }
            }
        }
        # 清理变量
        unset($count, $opt, $i, $className, $config);
    }

    /**
     * 绑定事件
     */
    public function bind() {
        $this->server->on('managerStart', [$this, 'onManagerStart']);
        $this->server->on('workerStart',  [$this, 'onWorkerStart']);
        $this->server->on('pipeMessage',  [$this, 'onPipeMessage']);
        $this->server->on('start',        [$this, 'onStart']);
        $this->server->on('shutdown',     [$this, 'onShutdown']);
        $this->server->on('finish',       [$this, 'onFinish']);
        $this->server->on('task',         [$this, 'onTask']);
        $this->server->on('packet',       [$this, 'onPacket']);
        $this->server->on('close',        [$this, 'onClose']);
        $this->server->on('connect',      [$this, 'onConnect']);
        $this->server->on('workerStop',   [$this, 'onWorkerStop']);
        $this->server->on('workerExit',   [$this, 'onWorkerExit']);

        # 其它自定义回调函数
        foreach (['shutdown', 'timer', 'managerStop'] as $type) {
            $fun = "on$type";
            if (method_exists($this, $fun)) {
                $this->server->on($type, [$this, $fun]);
            }
        }

        # 自定义协议
        if ($this->serverType === 0) {
            $this->server->on('receive', [$this, 'onReceive']);
        }
        switch ($this->serverType) {
            case 0:
                # 自定义协议
                $this->server->on('receive', [$this, 'onReceive']);
                break;
            case 1:
            case 2:
            case 3:
                # http and webSocket
                $this->server->on('request', [$this, 'onRequest']);
                break;
        }

        # webSocket
        if ($this->serverType === 2 || $this->serverType === 3) {
            $this->server->on('message', [$this, 'onMessage']);

            if ($this->masterHost['handShake']) {
                $this->server->on('handShake', [$this, 'onHandShake']);
            }
            else {
                $this->server->on('open', [$this, 'onOpen']);
            }
        }
    }

    /**
     * 添加的自定义端口服务
     */
    public function initServers() {
        foreach ($this->config['servers'] as $key => $setting) {
            if ($key === $this->masterHostKey) {
                continue;
            }

            foreach ((array)$setting['listen'] as $st) {
                try {
                    $opt    = $this->parseSockUri($st);
                    $listen = $this->server->listen($opt->host, $opt->port, $opt->type | $opt->ssl);

                    $this->portListens["{$opt->host}:{$opt->port}"] = $listen;
                }
                catch (\Exception $e) {
                    $this->warn($e->getMessage());
                    exit;
                }

                if (false === $listen) {
                    $this->warn('创建服务失败：' . $opt->host . ':' . $opt->port . ', 错误码:' . $this->server->getLastError());
                    exit;
                }

                $this->workers[$key] = $key;

                if (isset($setting['conf']) && $setting['conf']) {
                    $listen->set($setting['conf']);
                }

                # 设置回调
                $this->setListenCallback($key, $listen, $opt);

                $this->info('Listen: ' . preg_replace('#^(upload|api|manager)://#', 'http://', $st));
            }
        }

        if ($this->config['debugger']['open']) {
            if (isset($this->config['debugger']['class']) && $this->config['debugger']['class']) {
                $class = $this->config['debugger']['class'];
            }
            else {
                $class = Debugger::class;
            }
            /**
             * @var Debugger $class
             */
            $shell = $class::instance(isset($this->config['debugger']['public_key']) ? $this->config['debugger']['public_key'] : null);
            $rs    = $shell->listen($this->server, $host = $this->config['debugger']['host'] ?: '127.0.0.1', $port = $this->config['debugger']['port'] ?: 9599);
            if ($rs) {
                $this->info("Add remote shell debugger success. tcp://$host:$port");
            }
            else {
                $this->warn("Add remote shell debugger fail. tcp://$host:$port");
                exit;
            }
        }
    }

    /**
     * 初始化自定义子进程
     */
    public function initCustomWorker() {
        if (isset($this->config['customWorker']) && ($size = count($this->config['customWorker'])) > 0) {
            $size = bindec(str_pad(1, strlen(decbin((int)$size - 1)), 0)) * 2;
            $this->customWorkerTable = new \Swoole\Table($size);
            $this->customWorkerTable->column('pid', \SWOOLE\Table::TYPE_INT, 4);          # 进程ID
            $this->customWorkerTable->column('wid', \SWOOLE\Table::TYPE_INT, 4);          # 进程序号（接task进程后面）
            $this->customWorkerTable->column('startTime', \SWOOLE\Table::TYPE_INT, 4);    # 启动时间
            $this->customWorkerTable->create();

            $i = 0;
            $beginNum = $this->config['swoole']['worker_num'] + (isset($this->config['swoole']['task_worker_num']) ? $this->config['swoole']['task_worker_num'] : 0);
            foreach ($this->config['customWorker'] as $key => $conf) {
                $process = new \Swoole\Process(function($process) use ($key, $conf, $i) {
                    if (isset($this->config['swoole']['daemonize']) && $this->config['swoole']['daemonize'] == 1) {
                        # 如果是 daemonize 需要更新下
                        $this->server->master_pid = $this->pid = $this->_realMasterPid->get();
                    }

                    if (Logger\Lite::$sysLoggerProcessName && Logger\Lite::$sysLoggerProcessName === $key) {
                        # 这是一个系统logger的进程，所以默认关闭重复写入
                        Logger\Lite::$useProcessLoggerSaveFile = false;
                    }

                    /**
                     * @param \Swoole\Process $process
                     */
                    $this->customWorkerKey = $key;
                    Util\Text::clearPhpSystemCache();

                    # 这个里面的代码在启动自定义子进程后才会执行
                    $this->setProcessTag("custom#{$conf['name']}");

                    if (isset($conf['memory_limit']) && $conf['memory_limit']) {
                        # 设置内存限制
                        ini_set('memory_limit', $conf['memory_limit']);
                        $this->info("php ini_set memory_limit {$rs} => {$conf['memory_limit']}");
                    }

                    # 在自定义子进程里默认没有获取到 worker_pid, worker_id，所以要更新下

                    if (!isset($this->server->worker_pid) || 0 === $this->server->worker_pid)$this->server->worker_pid = getmypid();
                    if (!isset($this->server->worker_id) || $this->server->worker_id <= 0)$this->server->worker_id = $i + $this->server->setting['worker_num'] + $this->server->setting['task_worker_num'];

                    $this->customWorkerTable->set($key, [
                        'pid'       => $this->server->worker_pid,
                        'wid'       => $this->server->worker_id,
                        'startTime' => time(),
                    ]);

                    $className = self::getFirstExistsClass($conf['class']);
                    if (false === $className) {
                        $className = Worker\ProcessCustom::class;
                        $this->info("自定义进程 {$conf['class']} 类不存在，已使用默认对象 {$className} 代替");
                    }
                    $arguments = [
                        'server'   => $this->server,
                        'name'     => $key,
                        'process'  => $process,
                        'setting'  => $conf,
                        'customId' => $i,
                    ];
                    $this->customWorker = $worker = new $className($arguments);
                    /**
                     * @var $worker Worker\ProcessCustom
                     */
                    # 监听一个信号 SIGTERM
                    \Swoole\Process::signal(15, function() use ($process, $worker) {
                        $worker->unbindWorker();
                        $worker->event->trigger('exit');
                        \Swoole\Timer::after(10, function() use ($process, $worker) {
                            /**
                             * @var \Swoole\Process $process
                             */
                            $this->debug("收到一个重启 SIGTERM 信号, 将重启pid: ". $this->server->worker_pid);
                            $worker->event->trigger('stop');
                            $process->exit();
                        });
                    });

                    if ($process->pipe) {
                        # 绑定一个读的异步事件
                        swoole_event_add($process->pipe, [$worker, 'readInProcessCallback']);
                    }

                    try {
                        $worker->initEvent();
                    }
                    catch (\Swoole\ExitException $e){}
                    catch (\Exception $e){$this->trace($e);}
                    catch (\Throwable $t){$this->trace($t);}

                    try {
                        $worker->onStart();
                    }
                    catch (\Swoole\ExitException $e){}
                    catch (\Exception $e){$this->trace($e);}
                    catch (\Throwable $t){$this->trace($t);}

                    $this->debug("Custom#{$conf['name']} Started, pid: {$this->server->worker_pid}");

                }, $conf['redirect_stdin_stdout'], $conf['create_pipe']);

                $process->worker_id  = $workerId = $beginNum + $i;
                $process->worker_key = $key;
                $this->customWorkerProcessList[$key]   = $process;
                $this->customWorkerIdForKey[$workerId] = $key;
                $i++;
            }

            foreach ($this->customWorkerProcessList as $process) {
                $this->server->addProcess($process);
            }
        }
    }

    /**
     * 进程启动
     *
     * @param \Swoole\Server $server
     * @param $workerId
     */
    public function onWorkerStart($server, $workerId) {
        Util\Text::clearPhpSystemCache();

        if (isset($this->config['swoole']['daemonize']) && $this->config['swoole']['daemonize'] == 1) {
            $this->pid = $this->server->master_pid;
        }

        if ($server->taskworker) {
            # 任务序号
            $taskId = $workerId - $server->setting['worker_num'];
            $this->setProcessTag("task#$taskId");

            $className = self::getFirstExistsClass(isset($this->config['task']['class']) && $this->config['task']['class'] ? $this->config['task']['class'] : 'WorkerTask');
            if (false === $className) {
                # 停止服务
                if ($taskId === 0) {
                    $this->warn("任务进程 {$this->config['task']['class']} 类不存在");
                }
                $className = Worker\ProcessTask::class;
            }

            if (isset($this->config['task_worker_memory_limit']) && $this->config['task_worker_memory_limit']) {
                # 内存限制
                $rs = ini_set('memory_limit', $this->config['task_worker_memory_limit']);
                $this->info("php ini_set memory_limit {$rs} => {$this->config['task_worker_memory_limit']}");
            }

            $arguments = [
                'server' => $server,
                'name'   => '_Task',
                'taskId' => $taskId,
            ];

            $this->workerTask       = new $className($arguments);
            $this->workers['_Task'] = $this->workerTask;    # 放一个在 $workers 里

            try {
                $this->workerTask->initEvent();
            }
            catch (\Swoole\ExitException $e){}
            catch (\Exception $e){$this->trace($e);}
            catch (\Throwable $t){$this->trace($t);}

            try {
                $this->workerTask->onStart();
            }
            catch (\Swoole\ExitException $e){}
            catch (\Exception $e){$this->trace($e);}
            catch (\Throwable $t){$this->trace($t);}

            $this->debug("TaskWorker#{$taskId} Started, pid: {$this->server->worker_pid}");
        }
        else {
            $this->setProcessTag("worker#$workerId");

            if (isset($this->config['worker_memory_limit']) && $this->config['worker_memory_limit']) {
                $rs = ini_set('memory_limit', $this->config['worker_memory_limit']);
                $this->info("php ini_set memory_limit {$rs} => {$this->config['worker_memory_limit']}");
            }

            foreach ($this->config['servers'] as $k => $v) {
                $className = self::getFirstExistsClass($v['class']);

                if (false === $className) {
                    if (isset($v['type'])) {
                        switch ($v['type']) {
                            case 'api':
                                $className = Worker\SchemeAPI::class;
                                break;

                            case 'http':
                            case 'https':
                                $className = Worker\SchemeHttp::class;
                                break;

                            case 'upload':
                                $className = Worker\SchemeHttpRangeUpload::class;
                                break;

                            case 'manager':
                                $className = Worker\SchemeManager::class;
                                break;

                            default:
                                $className = Worker::class;
                                break;
                        }
                    }
                    else {
                        $className = Worker::class;
                    }

                    if ($workerId === 0) {
                        $old = implode(', ', (array)$v['class']);
                        $this->warn("Host: {$k} 工作进程 $old 类不存在(" . current($v['listen']) . "), 已使用默认对象 {$className} 代替");
                    }
                }

                /**
                 * @var $worker Worker
                 */
                $arguments = [
                    'server'  => $server,
                    'name'    => $k,
                    'setting' => $v,
                ];

                $worker            = new $className($arguments);
                $this->workers[$k] = $worker;
            }
            $this->worker       = $this->workers[$this->defaultWorkerName];
            $this->masterWorker = $this->workers[$this->masterHostKey];

            foreach ($this->workers as $worker) {
                try {
                    $worker->initEvent();
                }
                catch (\Swoole\ExitException $e){}
                catch (\Exception $e){$this->trace($e);}
                catch (\Throwable $t){$this->trace($t);}
            }

            foreach ($this->workers as $worker) {
                try {
                    $worker->onStart();
                }
                catch (\Swoole\ExitException $e){}
                catch (\Exception $e){$this->trace($e);}
                catch (\Throwable $t){$this->trace($t);}
            }

            $this->counterRequestBeginTime = microtime(true);

            # 统计QPS
            \Swoole\Timer::tick(mt_rand(5000, 8000), function() {
                $now                           = microtime(true);
                $this->counterQPS              = ceil($this->counterRequest / ($now - $this->counterRequestBeginTime));
                $this->counterRequestBeginTime = $now;
                $this->counterQPSTable->set($this->server->worker_id, ['qps' => $this->counterQPS]);
            });

            $this->debug("Worker#{$workerId} Started, pid: {$this->server->worker_pid}");
        }

        # 触发绑定在系统的 onWorkerStart 事件
        $this->event->emit('workerStart', [$server, $workerId]);
    }

    /**
     * @see https://wiki.swoole.com/wiki/page/775.html
     * @param \Swoole\Server $server
     * @param $workerId
     */
    public function onWorkerExit($server, $workerId) {
        try {
            static $time = null;
            if ($server->taskworker) {
                $this->workerTask->event->emit('exit');
            }
            else {
                foreach ($this->workers as $worker) {
                    /**
                     * @var Worker $worker
                     */
                    $worker->event->emit('exit');
                }
            }

            $this->event->emit('workerExit', [$server, $workerId]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}


        if (null === $time || microtime(true) - $time > 60) {
            $time = microtime(true);
            $this->debug("worker process exit, pid: {$this->server->worker_pid}");
        }
    }

    /**
     * 进程停止时调用的事件
     *
     * @param \Swoole\Server $server
     * @param $workerId
     */
    public function onWorkerStop($server, $workerId) {
        try {
            if ($server->taskworker) {
                $this->workerTask->event->emit('stop');
            }
            else {
                foreach ($this->workers as $worker) {
                    /**
                     * @var Worker $worker
                     */
                    $worker->event->emit('stop');
                }
            }
            $this->event->emit('workerStop', [$server, $workerId]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}

        $this->debug("worker process stopped, pid: {$this->server->worker_pid}");
    }

    /**
     * 收到tcp数据后回调方法
     *
     * @param \Swoole\Server $server
     * @param $fd
     * @param $fromId
     * @param $data
     */
    public function onReceive($server, $fd, $fromId, $data) {
        $this->counterRequest++;

        try {
            $event = $this->masterWorker->event;
            if ($event->excludeSysEventExists('receive')) {
                $event->emit('receive', [$server, $fd, $fromId, $data]);
                return;
            }
            $this->masterWorker->onReceive($server, $fd, $fromId, $data);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest($request, $response) {
        $this->counterRequest++;

        try {
            # 发送一个头信息
            $response->header('Server', $this->masterHost['name']);

            $event = $this->masterWorker->event;
            if ($event->excludeSysEventExists('request')) {
                $event->emit('request', [$request, $response]);
                return;
            }

            # 检查域名是否匹配
            if (false === $this->masterWorker->onCheckDomain($request->header['host'])) {
                $response->status(403);
                $response->end('forbidden domain');
                return;
            }

            $this->masterWorker->onRequest($request, $response);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * WebSocket 获取消息回调
     *
     * @param \Swoole\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage($server, $frame) {
        $this->counterRequest++;

        try {
            $event = $this->masterWorker->event;
            if ($event->excludeSysEventExists('message')) {
                # 使用事件处理
                $event->emit('message', [$server, $frame]);
                return;
            }

            $this->masterWorker->onMessage($server, $frame);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     *
     * @param \Swoole\Websocket\Server $server
     * @param \Swoole\Http\Request $request
     */
    public function onOpen($server, $request) {
        try {
            $this->masterWorker->event->emit('open', [$server, $request]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}

    }

    /**
     * WebSocket建立连接后进行握手
     *
     * @param \Swoole\Http\Request  $request
     * @param \Swoole\Http\Response $response
     */
    public function onHandShake($request, $response) {
        try {
            $this->masterWorker->event->emit('handShake', [$request, $response]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * 连接服务器回调
     *
     * @param $server
     * @param $fd
     * @param $fromId
     */
    public function onConnect($server, $fd, $fromId) {
        try {
            $this->masterWorker->event->emit('connect', [$server, $fd, $fromId]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * 关闭连接回调
     *
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     */
    public function onClose($server, $fd, $fromId) {
        try {
            $this->masterWorker->event->emit('close', [$server, $fd, $fromId]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * UDP下收到数据回调
     *
     * @param \Swoole\Server $server
     * @param int $fd
     * @param array $client  客户端信息, 包括 address/port/server_socket 3项数据
     */
    public function onPacket($server, $data, $client) {
        $this->counterRequest++;

        try {
            $event = $this->masterWorker->event;
            if ($event->excludeSysEventExists('packet')) {
                # 使用事件处理
                $event->emit('packet', [$server, $data, $client]);
                return;
            }

            $this->masterWorker->onPacket($server, $data, $client);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * 收到来自其它进程的消息
     *
     * @param \Swoole\Server $server
     * @param int $fromId
     * @param string $message
     */
    public function onPipeMessage($server, $fromId, $message) {
        try {
            # 支持对象方式
            list($isMessage, $workerName) = Message::parseSystemMessage($message);

            $rs = null;
            if (true === $isMessage) {
                /**
                 * @var Message $message
                 */
                $message->onPipeMessage($server, $fromId);
                return;
            }

            if ($server->taskworker) {
                # 调用 task 进程
                $event = $this->workerTask->event;
                if ($event->excludeSysEventExists('pipeMessage')) {
                    # 使用事件处理
                    $event->emit('pipeMessage', [$server, $fromId, $message]);
                    return;
                }

                $this->workerTask->onPipeMessage($server, $fromId, $message);
            }
            else {
                /**
                 * @var Event $event
                 */
                if ($workerName && isset($this->workers[$workerName])) {
                    # 调用对应的 worker 对象
                    $event = $this->workers[$workerName]->event;
                    if ($event->excludeSysEventExists('pipeMessage')) {
                        # 使用事件处理
                        $event->emit('pipeMessage', [$server, $fromId, $message]);
                        return;
                    }

                    $this->workers[$workerName]->onPipeMessage($server, $fromId, $message);
                }
                elseif ($this->worker) {
                    $event = $this->worker->event;
                    if ($event->excludeSysEventExists('pipeMessage')) {
                        # 使用事件处理
                        $event->emit('pipeMessage', [$server, $fromId, $message]);
                        return;
                    }

                    $this->worker->onPipeMessage($server, $fromId, $message);
                }
            }
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * @param \Swoole\Server $server
     * @param $taskId
     * @param $data
     */
    public function onFinish($server, $taskId, $data) {
        try {
            $this->masterWorker->event->emit('finish', [$server, $taskId, $data]);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * @param \Swoole\Server $server
     * @param \Swoole\Server\Task $task
     */
    public function onTask($server, $task) {
        try {
            $event = $this->workerTask->event;
            if ($event->excludeSysEventExists('task')) {
                $this->workerTask->event->emit('task', [$server, $task]);
                return;
            }

            # 使用默认方式调用
            $this->workerTask->onTask($server, $task);
        }
        catch (\Swoole\ExitException $e){}
        catch (\Exception $e){$this->trace($e);}
        catch (\Throwable $t){$this->trace($t);}
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onStart($server) {
        if ($this->serverType === 0) {
            $this->info('Server: ' . current($this->masterHost['listen']) . '/');
        }

        if ($this->serverType === 1 || $this->serverType === 3) {
            $this->info('Http Server: ' . preg_replace('#^(upload|api|manager)://#', 'http://', current($this->masterHost['listen'])) . '/');
        }

        if ($this->serverType === 2 || $this->serverType === 3) {
            $this->info('WebSocket Server: ' . current($this->masterHost['listen']) . '/');
        }
    }

    /**
     * @param \Swoole\Server $server
     */
    public function onShutdown($server) {

    }

    /**
     * @param \Swoole\Server $server
     */
    public function onManagerStart($server) {
        if (isset($this->config['swoole']['daemonize']) && $this->config['swoole']['daemonize'] == 1) {
            $this->pid = $this->server->master_pid;
            $this->_realMasterPid->set($this->pid);
        }

        $this->setProcessTag('manager');
        $this->event->emit('managerStart', [$server]);
    }

    /**
     * 热更新服务器
     *
     * 和 \Swoole\Server 的 reload() 方法的差别是它可以重启自定义子进程
     */
    public function reload($includeCustomWorker = true) {
        if (true === $includeCustomWorker && count($this->customWorkerProcessList) > 0) {
            # 有自定义子进程
            $this->reloadCustomWorker();
            usleep(300000);
        }

        $this->server->reload();
    }

    /**
     * 重启自定义的子进程
     *
     * 可以单个重启也可以全部重启
     *
     * @param string|null $key
     */
    public function reloadCustomWorker($key = null) {
        /**
         * @var $process \Swoole\Process
         */
        if (null === $key) {
            foreach ($this->customWorkerProcessList as $k => $process) {
                if ($k === $this->customWorkerKey) {
                    // 在自定义进程中调用时
                    \Swoole\Timer::after(10, function() use ($k) {
                        try {
                            $this->debug("Custom#{$k} 现已重启");
                            $this->customWorker->unbindWorker();
                            $this->customWorker->event->emit('stop');
                        }
                        catch (\Swoole\ExitException $e){}
                        catch (\Exception $e){$this->trace($e);}
                        catch (\Throwable $t){$this->trace($t);}
                        exit;
                    });
                }
                elseif ($process->pipe) {
                    $process->write('.sys.reload');
                }
                elseif ($p = $this->customWorkerTable->get($k)) {
                    $pid = $p['pid'];
                    if ($p['pid']) {
                        # 发送一个信号
                        \Swoole\Process::kill($pid);
                    }
                    else {
                        $this->warn("重启 Process#{$k} 失败，没有开启 pipe 也无法获取子进程pid");
                    }
                }
                else {
                    $this->warn("重启 Process#{$k} 失败，没有获取到子进程相关信息");
                }
            }
        }
        elseif (isset($this->customWorkerProcessList[$key])) {
            $process = $this->customWorkerProcessList[$key];

            if ($key === $this->customWorkerKey) {
                // 在自定义进程中重启自己
                try {
                    $this->debug("Custom#{$key} 现已重启");
                    $this->customWorker->unbindWorker();
                    $this->customWorker->event->emit('stop');
                }
                catch (\Swoole\ExitException $e){}
                catch (\Exception $e){$this->trace($e);}
                catch (\Throwable $t){$this->trace($t);}
                exit;
            }
            elseif ($process->pipe) {
                $process->write('.sys.reload');
            }
            elseif ($p = $this->customWorkerTable->get($key)) {
                $pid = $p['pid'];
                if ($p['pid']) {
                    # 发送一个信号
                    \Swoole\Process::kill($pid);
                }
                else {
                    $this->warn("重启 Process#{$key} 失败，没有开启 pipe 也无法获取子进程pid");
                }
            }
            else {
                $this->warn("重启 Process#{$key} 失败，没有获取到子进程相关信息");
            }
        }
    }

    /**
     * 获取一个自定义子进程对象
     *
     * @param $key
     * @return \Swoole\Process|array|null
     */
    public function getCustomWorkerProcess($key = null) {
        if (null === $key) {
            return $this->customWorkerProcessList;
        }
        elseif (isset($this->customWorkerProcessList[$key])) {
            return $this->customWorkerProcessList[$key];
        }
        else {
            return null;
        }
    }

    /**
     * 根据自定义进程workerId获取进程对象
     *
     * @param $workerId
     * @return \Swoole\Process|null
     */
    public function getCustomWorkerProcessByWorkId($workerId) {
        if (isset($this->customWorkerIdForKey[$workerId])) {
            $key = $this->customWorkerIdForKey[$workerId];

            return $this->customWorkerProcessList[$key];
        }
        else {
            return null;
        }
    }

    /**
     * 获取自定义子进程对象共享内存数据
     *
     * @return \Swoole\Table|array
     */
    public function getCustomWorkerTable($key = null) {
        if (null === $key) {
            return $this->customWorkerTable;
        }
        else {
            return $this->customWorkerTable->get($key);
        }
    }

    /**
     * 给进程设置一个Tag名
     *
     * @param $tag
     */
    public function setProcessTag($tag) {
        global $argv;
        $this->processTag = $tag;
        $this->setProcessName("php " . implode(' ', $argv) . " [{$this->pid}-$tag]");

        # 设置默认日志名称
        Logger::setDefaultName($tag);
    }

    /**
     * 设置进程的名称
     *
     * @param $name
     */
    public function setProcessName($name) {
        if (PHP_OS === 'Darwin') {
            # Mac 系统设置不了
            return;
        }

        if (function_exists('\cli_set_process_title')) {
            @cli_set_process_title($name);
        }
        else {
            if (function_exists('\swoole_set_process_name')) {
                @swoole_set_process_name($name);
            }
            else {
                trigger_error(__METHOD__ . ' failed. require cli_set_process_title or swoole_set_process_name.');
            }
        }
    }

    /**
     * 获取服务器总的QPS
     *
     * @return int
     */
    public function getServerQPS() {
        $qps = 0;
        foreach ($this->counterQPSTable as $item) {
            $qps += $item['qps'];
        }

        return $qps;
    }

    /**
     * 设定一个定时器
     *
     * 支持内置协程、增加异常捕获
     *
     * @param int $ms
     * @param callable $callback
     * @return int
     */
    public function tick($ms, callable $callback) {
        $isObj = is_object($callback);
        return \Swoole\Timer::tick($ms, function($tick) use ($callback, $isObj) {
            try {
                if (true === $isObj) {
                    $callback($tick);
                }
                else {
                    call_user_func($callback, $tick);
                }
            }
            catch (\Swoole\ExitException $e){}
            catch (\Exception $e){$this->trace($e);}
            catch (\Throwable $t){$this->trace($t);}
        });
    }

    /**
     * 清楚一个定时器
     *
     * @param $tickId
     * @return bool
     */
    public function clearTick($tickId) {
        return \Swoole\Timer::clear($tickId);
    }

    /**
     * 在一定时间后执行
     *
     * 支持内置协程、增加异常捕获
     *
     * @param int $ms
     * @param callable $callback
     * @return int
     */
    public function after($ms, callable $callback) {
        return \Swoole\Timer::after($ms, function($tick) use ($callback) {
            try {
                if (is_object($callback)) {
                    $callback($tick);
                }
                else {
                    call_user_func($callback, $tick);
                }
            }
            catch (\Swoole\ExitException $e){}
            catch (\Exception $e){$this->trace($e);}
            catch (\Throwable $t){$this->trace($t);}
        });
    }

    /**
     * 返回一个Redis实例化对象
     *
     * @param string $config
     * @return Redis|\Redis
     */
    public function getRedis($config = 'default') {
        return Redis::instance($config);
    }

    /**
     * 中断执行
     *
     * 将会抛出一个结束的异常让系统自动忽略达到中断执行的目的
     */
    public function exit($msg = '') {
        $this->throwExitSignal($msg);
    }

    /**
     * @throws \Swoole\ExitException
     */
    public function throwExitSignal($msg = 'die') {
        throw new \Swoole\ExitException($msg);
    }

    /**
     * 初始化配置
     */
    public function initConfig() {
        $this->config->initConfig();
    }

    /**
     * 检查服务器配置，请使用 initConfig() 方法
     * 
     * @deprecated 
     */
    public function checkConfig() {
        $this->initConfig();
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
        $result->ssl = 0;
        $result->opt = [];

        if (false === $p && substr($uri, 0, 8) == 'unix:///')
        {
            $p = [
                'scheme' => 'unix',
                'path'   => substr($uri, 7),
            ];
        }
        if (false !== $p)
        {
            if (isset($p['query']))
            {
                parse_str($p['query'], $opt);
                if (isset($opt['ssl']))
                {
                    $result->ssl = SWOOLE_SSL;
                }
                $result->opt = $opt;
            }

            switch ($scheme = strtolower($p['scheme']))
            {
                case 'http':
                case 'ws':
                case 'upload':
                case 'api':
                case 'manager':
                case 'redis':
                case 'tcp':
                case 'tcp4':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_SOCK_TCP;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    break;

                case 'https':
                case 'wss':
                case 'ssl':
                case 'sslv2':
                case 'sslv3':
                case 'tls':
                    $result->scheme = $scheme;
                    $result->type   = SWOOLE_SOCK_TCP;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    $result->ssl    = SWOOLE_SSL;
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
                    $result->host   = (isset($p['host']) ? '/' . $p['host'] : '') . $p['path'];
                    $result->port   = 0;
                    break;

                default:
                    $result->scheme = $scheme;
                    $result->type   = $scheme;
                    $result->host   = $p['host'];
                    $result->port   = $p['port'];
                    break;
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
     * @param string $key
     * @param \Swoole\Server\Port $listen
     * @param \stdClass $opt
     */
    protected function setListenCallback($key, $listen, \stdClass $opt) {
        switch ($opt->scheme) {
            case 'http':
            case 'https':
            case 'api':
            case 'manager':
                $serverName = isset($this->config['servers'][$key]['name']) && $this->config['servers'][$key]['name'] ?: 'MQSRV';
                $listen->on('request', function($request, $response) use ($key, $serverName) {
                    /**
                     * @var \Swoole\Http\Request $request
                     * @var \Swoole\Http\Response $response
                     */
                    # 计数器
                    $this->counterRequest++;

                    try {
                        # 发送一个头信息
                        $response->header('Server', $serverName);

                        /**
                         * @var Event $event
                         */
                        $worker = $this->workers[$key];
                        $event  = $worker->event;
                        if ($event->excludeSysEventExists('request')) {
                            # 使用事件处理
                            $event->emit('request', [$request, $response]);

                            return;
                        }

                        # 检查域名是否匹配
                        if (false === $worker->onCheckDomain($request->header['host'])) {
                            $response->status(403);
                            $response->end('forbidden domain');

                            return;
                        }

                        $worker->onRequest($request, $response);
                    }
                    catch (\Swoole\ExitException $e) {
                    }
                    catch (\Exception $e) {
                        $this->trace($e);
                    }
                    catch (\Throwable $t) {
                        $this->trace($t);
                    }
                });
                break;

            case 'ws':
            case 'wss':
                $listen->on('message', function($server, $frame) use ($key) {
                    try {
                        /**
                         * @var Event $event
                         */
                        $event = $this->workers[$key]->event;
                        if ($event->excludeSysEventExists('message')) {
                            # 使用事件处理
                            $event->emit('message', [$server, $frame]);

                            return;
                        }

                        $this->counterRequest++;

                        $this->workers[$key]->onMessage($server, $frame);
                    }
                    catch (\Swoole\ExitException $e) {
                    }
                    catch (\Exception $e) {
                        $this->trace($e);
                    }
                    catch (\Throwable $t) {
                        $this->trace($t);
                    }
                });

                if ($this->config['servers'][$key]['handShake']) {
                    $listen->on('handShake', function($request, $response) use ($key) {
                        try {
                            /**
                             * @var Event $event
                             */
                            $event = $this->workers[$key]->event;
                            $event->emit('handShake', [$request, $response]);
                        }
                        catch (\Swoole\ExitException $e) {
                        }
                        catch (\Exception $e) {
                            $this->trace($e);
                        }
                        catch (\Throwable $t) {
                            $this->trace($t);
                        }
                    });
                }
                else {
                    $listen->on('open', function($server, $request) use ($key) {
                        try {
                            /**
                             * @var Event $event
                             */
                            $event = $this->workers[$key]->event;
                            $event->emit('open', [$server, $request]);
                        }
                        catch (\Swoole\ExitException $e) {
                        }
                        catch (\Exception $e) {
                            $this->trace($e);
                        }
                        catch (\Throwable $t) {
                            $this->trace($t);
                        }
                    });
                }

                $listen->on('close', function($server, $fd, $fromId) use ($key) {
                    try {
                        $this->workers[$key]->event->emit('close', [$server, $fd, $fromId]);
                    }
                    catch (\Swoole\ExitException $e) {
                    }
                    catch (\Exception $e) {
                        $this->trace($e);
                    }
                    catch (\Throwable $t) {
                        $this->trace($t);
                    }
                });
                break;

            default:
                $listen->on('receive', function($server, $fd, $fromId, $data) use ($key) {
                    try {
                        $this->counterRequest++;

                        /**
                         * @var Event $event
                         */
                        $event = $this->workers[$key]->event;
                        if ($event->excludeSysEventExists('receive')) {
                            # 使用事件处理
                            $event->emit('receive', [$server, $fd, $fromId, $data]);

                            return;
                        }

                        $this->workers[$key]->onReceive($server, $fd, $fromId, $data);
                    }
                    catch (\Swoole\ExitException $e) {
                    }
                    catch (\Exception $e) {
                        $this->trace($e);
                    }
                    catch (\Throwable $t) {
                        $this->trace($t);
                    }
                });

                switch ($opt->type) {
                    case SWOOLE_SOCK_TCP:
                    case SWOOLE_SOCK_TCP6:
                        $listen->on('connect', function($server, $fd, $fromId) use ($key) {
                            try {
                                $this->workers[$key]->event->emit('connect', [$server, $fd, $fromId]);
                            }
                            catch (\Swoole\ExitException $e) {
                            }
                            catch (\Exception $e) {
                                $this->trace($e);
                            }
                            catch (\Throwable $t) {
                                $this->trace($t);
                            }
                        });

                        $listen->on('close', function($server, $fd, $fromId) use ($key) {
                            try {
                                $this->workers[$key]->event->emit('close', [$server, $fd, $fromId]);
                            }
                            catch (\Swoole\ExitException $e) {
                            }
                            catch (\Exception $e) {
                                $this->trace($e);
                            }
                            catch (\Throwable $t) {
                                $this->trace($t);
                            }
                        });

                        break;
                    case SWOOLE_UNIX_STREAM:

                        $listen->on('packet', function($server, $data, $client) use ($key) {
                            try {
                                $this->counterRequest++;

                                /**
                                 * @var Event $event
                                 */
                                $event = $this->workers[$key]->event;
                                if ($event->excludeSysEventExists('packet')) {
                                    # 使用事件处理
                                    $event->emit('packet', [$server, $data, $client]);

                                    return;
                                }

                                $this->workers[$key]->onPacket($server, $data, $client);
                            }
                            catch (\Swoole\ExitException $e) {
                            }
                            catch (\Exception $e) {
                                $this->trace($e);
                            }
                            catch (\Throwable $t) {
                                $this->trace($t);
                            }
                        });
                        break;
                }
                break;
        }
    }

    /**
     * 在列表中获取一个存在的类名称
     *
     * @param string|array $classList
     * @return null
     */
    public static function getFirstExistsClass($classList) {
        foreach ((array)$classList as $class) {
            $class = '\\' . trim($class, '\\');
            if (class_exists($class, true)) {
                return $class;
            }
        }

        return false;
    }
}

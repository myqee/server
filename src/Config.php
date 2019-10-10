<?php

namespace MyQEE\Server;

/**
 * 全局 Config 对象
 *
 * @package MyQEE\Server
 */
class Config extends \ArrayIterator {

    protected $_serversSettingForServer = [];

    /**
     * 实例化对象
     *
     * @var static
     */
    public static $instance;

    /**
     * 默认最大协程数
     *
     * @var int
     */
    protected static $defaultMaxCoroutine = 1000000;

    /**
     * 默认内存限制
     * 
     * @var string 
     */
    protected static $defaultMemoryLimit = '4G';

    /**
     * 默认时区
     * 
     * @var string 
     */
    protected static $defaultTimeZone = 'Asia/Shanghai';

    /**
     * 默认 swoole.unixsock_buffer_size 值
     *
     * 1048576 = 1MB
     *
     * @var int
     */
    protected static $defaultSwooleUnixSockBufferSize = 1048576;

    /**
     * 默认 Session 配置
     *
     * @var array
     */
    public static $defaultSessionConfig = [
        'storage'  => 'default',
        // 存储配置key
        'name'     => 'sid',
        // 名称
        'checkSid' => true,
        // 是否验证SID
        'sidInGet' => false,
        // 在get参数中读取sid，false 表示禁用, 例如设置 _sid, 则如果cookie里没有获取则尝试在 GET['_sid'] 获取sid，用于在禁止追踪的浏览器内嵌入第三方domain中在get参数里传递sid
        'expire'   => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
        // 新增，可以是 Strict，Lax，None，其中None必须 secure = true 时可用
        'class'    => Http\Session::class,
    ];

    public function __construct($array = [], $flags = 0) {
        if (self::$instance) {
            throw new \Exception('Can not create GlobalConfig,it singleton mode');
        }
        parent::__construct($array, $flags);
    }

    /**
     * 获取配置项
     *
     * @param string $key 配置项名称 支持点语法
     * @return mixed
     */
    public function get($key = '', $default = null) {
        $data = self::$instance;
        $keys = explode('.', $key);
        while ($item = array_shift($keys)) {
            if (isset($data[$item])) {
                $data = $data[$item];
            }
            else {
                return $default;
            }
        }

        return $data;
    }

    /**
     * 深度合并数组到本对象
     *
     * @param array $config
     * @return $this
     */
    public function deepMergeConfig(array $config) {
        self::doDeepMergeConfig($this, $config);

        return $this;
    }

    /**
     * 从配置文件中初始化
     *
     * @param null|string $file 如果不传文件则从根目录的 config.yml 中读取
     * @return $this
     * @throws \Exception
     */
    public function mergeFromYaml($file = null) {
        if (!Util\Text::yamlSupportType()) {
            throw new \Exception("不能启动，请先安装php的 yaml 扩展，CentOS: yum install -y php-yaml，或通过 composer require symfony/yaml 命令来安装 yaml 的php版本\n");
        }

        if (!$file) {
            $file = BASE_DIR . 'config.yml';
        }

        if (!is_file($file)) {
            throw new \Exception('file not found');
        }
        $conf = Util\Text::yamlParse($file);

        return self::deepMergeConfig($conf);
    }

    /**
     * 初始化配置
     */
    public function initConfig() {
        $this->initConfigForBase();
        $this->initConfigCompatible();
        $this->initConfigForLog();
        $this->initConfigForPHP();
        $this->initConfigForSwoole();
        $this->initConfigForServers();
        $this->initConfigForCustomWorker();
        $this->initConfigForDev();
    }

    protected function initConfigForBase() {
        foreach (['servers', 'dependencies', 'php', 'swoole', 'redis', 'log', 'customWorker'] as $item) {
            if (!isset($this[$item])) {
                $this[$item] = [];
            }
        }
        if (!isset($this['php']['ini']))$this['php']['ini'] = [];

        if (!isset($this['mode'])) {
            $this['mode'] = SWOOLE_PROCESS;
        }
        else if (is_string($this['mode'])) {
            $this['mode'] = $this['mode'] === 'base' || $this['mode'] === 'SWOOLE_BASE' ? SWOOLE_BASE : SWOOLE_PROCESS;
        }
    }

    /**
     * 处理一些兼容的配置
     */
    protected function initConfigCompatible() {
        if (isset($this['hosts'])) {
            // 兼容旧版本 hosts
            $this['servers'] = $this['hosts'];
            unset($this['hosts']);
        }

        if (isset($this['socket_block'])) {
            $this['php']['socket_block'] = $this['socket_block'];
            unset($this['socket_block']);
        }

        # 处理 server 相关配置
        if (isset($this['server'])) {
            if (isset($this['server']['mode'])) {
                $this['mode'] = $this['server']['mode'] === 'process' ? SWOOLE_PROCESS : SWOOLE_BASE;
            }
            if (isset($this['server']['name'])) {
                $this['serverName'] = $this['server']['name'];
            }
            if (isset($this['server']['unixsock_buffer_size'])) {
                $this['php']['swoole.unixsock_buffer_size'] = $this['server']['unixsock_buffer_size'];
            }
            if (isset($this['server']['worker_memory_limit'])) {
                $this['worker_memory_limit'] = $this['server']['worker_memory_limit'];
            }
            if (isset($this['server']['task_worker_memory_limit'])) {
                $this['worker_memory_limit'] = $this['server']['task_worker_memory_limit'];
            }
            if (isset($this['server']['worker_num'])) {
                $this['swoole']['worker_num'] = $this['server']['worker_num'];
            }
            unset($this['server']);
        }

    }

    /**
     * 检查log相关配置
     */
    protected function initConfigForLog() {
        global $argv;
        $log = $this['log'];

        if (!isset($log) || !is_array($log)) {
            $log = [];
        }

        if (in_array('-vvv', $argv) || in_array('--dev', $argv)) {
            $log['level'] = Logger::TRACE;
        }
        elseif (in_array('-vv', $argv) || in_array('--debug', $argv)) {
            $log['level'] = Logger::DEBUG;
        }
        elseif (in_array('-v', $argv)) {
            $log['level'] = Logger::INFO;
        }
        elseif (!isset($log['level'])) {
            $log['level'] = Logger::WARNING;
        }
        elseif (is_string($log['level'])) {
            $levels = Logger::getLevels();
            if ($log['level'] === 'warn') {
                $log['level'] = $upper = 'WARNING';
            }
            else {
                $upper = strtoupper($log['level']);
            }

            if (isset($levels[$upper])) {
                $log['level'] = $levels[$upper];
            }
            else {
                echo "不支持的log等级：{$log['level']}\n";
            }
        }
        elseif (is_array($log['level'])) {
            # 兼容旧版的设置
            if (in_array('trace', $log['level'])) {
                $log['level'] = Logger::TRACE;
            }
            elseif (in_array('debug', $log['level'])) {
                $log['level'] = Logger::DEBUG;
            }
            elseif (in_array('info', $log['level'])) {
                $log['level'] = Logger::INFO;
            }
            elseif (in_array('warn', $log['level'])) {
                $log['level'] = Logger::WARNING;
            }
        }

        $logActiveDef = [
            'sizeLimit' => 0,
            'timeLimit' => false,
            'timeKey'   => null,
            'compress'  => false,
            'prefix'    => 'active.',
            'path'      => null,
        ];
        if (!isset($log['active'])) {
            $log['active'] = $logActiveDef;
        }
        else {
            $log['active'] += $logActiveDef;
        }

        if ($log['active']['compress']) {
            exec('tar --version', $tmp, $tmp2);
            if (0 !== $tmp2) {
                echo "log设置自动存档压缩，但是系统不支持 tar 命令, 无法自动压缩存档，请先安装 tar 命令\n";
            }
        }

        if (!isset($log['path']) || !$log['path']) {
            $log['path']          = false;
            $log['loggerProcess'] = false;
        }
        else if (!isset($log['loggerProcess']) || !$log['loggerProcess']) {
            $log['loggerProcess'] = false;
        }
        else {
            if (!isset($log['loggerProcessName']) || !$log['loggerProcessName']) {
                $log['loggerProcessName'] = 'logger';
            }

            $pName = $log['loggerProcessName'];

            $this['customWorker'][$pName] = [
                'name'  => $pName,
                'class' => $log['loggerProcess'],
            ];
        }

        # 是否在log输出时显示文件信息, false 不输出，数字表示等级，
        if (!isset($log['withFilePath'])) {
            $log['withFilePath'] = 0;
        }
        elseif (is_bool($log['withFilePath'])) {
            $log['withFilePath'] = $log['withFilePath'] ? 0 : false;
        }
        elseif (is_string($log['withFilePath'])) {
            $levels = Logger::getLevels();
            if ($log['level'] === 'warn') {
                $upper = 'WARNING';
            }
            else {
                $upper = strtoupper($log['withFilePath']);
            }

            if (isset($levels[$upper])) {
                $log['withFilePath'] = $levels[$upper];
            }
        }
        elseif (is_numeric($log['withFilePath'])) {
            $log['withFilePath'] = (int)$log['withFilePath'];
        }
        else {
            $log['withFilePath'] = 0;
        }
        $this['log'] = $log;
    }

    /**
     * 检查PHP相关配置
     */
    protected function initConfigForPHP() {
        # 默认时区
        if (!isset($this['php']['timezone'])) {
            $this['php']['timezone'] = static::$defaultTimeZone;
        }
        
        # 默认内容
        if (!isset($this['php']['ini']['memory_limit'])) {
            $this['php']['ini']['memory_limit'] = static::$defaultMemoryLimit;
        }

        # Swoole进程间通信的UnixSocket缓存区尺寸
        if (!isset($this['php']['ini']['swoole.unixsock_buffer_size'])) {
            $this['php']['ini']['swoole.unixsock_buffer_size'] = static::$defaultSwooleUnixSockBufferSize;
        }
    }

    /**
     * 检查 swoole 相关配置
     */
    protected function initConfigForSwoole() {
        $swoole = $this['swoole'];

        # 处理进程数
        if (isset($this['worker_num'])) {
            $swoole['worker_num'] = $this['worker_num'];
        }
        elseif (isset($this['swoole']['worker_num'])) {
            $this['worker_num'] = $swoole['worker_num'];
        }

        if (!isset($swoole['worker_num'])) {
            $this['worker_num'] = $swoole['worker_num'] = function_exists('\\swoole_cpu_num') ? \swoole_cpu_num() : 4;
        }

        # 设置 swoole 的log输出路径
        if (!isset($swoole['log_file']) && $this['log']['path']) {
            if (strpos($this['log']['path'], '$type') !== false) {
                $swoole['log_file'] = str_replace('$type', 'swoole', $this['log']['path']);
            }
            else {
                $swoole['log_file'] = preg_replace('#\.log$#i', '', $this['log']['path']) . '.swoole.log';
            }
        }

        # 设置日志等级
        if (!isset($swoole['log_level'])) {
            # see https://wiki.swoole.com/wiki/page/538.html
            switch ($this['log']['level']) {
                case Logger::TRACE:
                case Logger::DEBUG:
                    # 由于 swoole 的 debug 等级比 trace 高，所以这边全部都设置成 SWOOLE_LOG_DEBUG
                    $swoole['log_level'] = SWOOLE_LOG_DEBUG;
                    break;

                case Logger::INFO:
                    $swoole['log_level'] = SWOOLE_LOG_INFO;
                    break;

                case Logger::NOTICE:
                    $swoole['log_level'] = SWOOLE_LOG_NOTICE;
                    break;

                case Logger::WARNING:
                    $swoole['log_level'] = SWOOLE_LOG_WARNING;
                    break;

                case Logger::ERROR:
                    $swoole['log_level'] = SWOOLE_LOG_ERROR;
                    break;

                default:
                    $swoole['log_level'] = SWOOLE_LOG_WARNING;
                    break;
            }
        }

        # 默认开启异步安全特性，1.9.17 支持 see https://wiki.swoole.com/wiki/page/775.html
        if (!isset($swoole['reload_async'])) {
            $swoole['reload_async'] = true;
        }

        # 缓存目录
        if (isset($swoole['task_tmpdir'])) {
            if (!is_dir($swoole['task_tmpdir']) && !mkdir($swoole['task_tmpdir'], 0755, true)) {
                echo "定义的 swoole.task_tmpdir 的目录 {$swoole['task_tmpdir']} 不存在, 并且无法创建\n";
                exit;
            }
        }

        if (!isset($swoole['send_yield'])) {
            $swoole['send_yield'] = true;
        }

        if (!isset($swoole['max_coroutine'])) {
            $swoole['max_coroutine'] = static::$defaultMaxCoroutine;
        }

        if (isset($this['task']['number']) && $this['task']['number'] > 0) {
            # 启动的任务进程数
            $swoole['task_worker_num'] = $this['task']['number'];
        }

        $swoole['enable_coroutine'] = true;

        if (isset($swoole['task_worker_num']) && $swoole['task_worker_num'] > 0) {
            #see https://wiki.swoole.com/wiki/page/p-task_enable_coroutine.html
            $swoole['task_enable_coroutine'] = true;

            # 任务进程最大请求数后会重启worker
            if (isset($this['task']['task_max_request'])) {
                $swoole['task_max_request'] = (int)$this['task']['task_max_request'];
            }
        }

        $this['swoole'] = $swoole;
    }

    /**
     * 检查 $config['hosts'] 相关配置
     */
    protected function initConfigForServers() {
        $mainHost = null;

        $serverName     = null;
        $masterHostKey  = null;
        $serverType     = 0;
        $hostsHttpAndWs = [];
        $masterHost     = [];
        
        foreach ($this['servers'] as $key => & $hostConfig) {
            if (!isset($hostConfig['class'])) {
                $hostConfig['class'] = "\\Worker{$key}";
            }
            elseif (is_string($hostConfig['class']) && substr($hostConfig['class'], 0, 1) !== '\\') {
                $hostConfig['class'] = "\\" . $hostConfig['class'];
            }

            if (!isset($hostConfig['listen'])) {
                $hostConfig['listen'] = [];
            }
            elseif (!is_array($hostConfig['listen'])) {
                $hostConfig['listen'] = [$hostConfig['listen']];
            }

            if (!isset($hostConfig['type']) || !$hostConfig['type']) {
                if ($hostConfig['listen']) {
                    try {
                        $tmp                = self::parseSockUri($hostConfig['listen'][0]);
                        $hostConfig['type'] = $tmp->scheme ?: 'tcp';
                    }
                    catch (\Exception $e) {
                        echo $e->getMessage() ."\n";
                        exit;
                    }
                }
                else {
                    $hostConfig['type'] = 'tcp';
                }
            }

            # Session 相关配置
            if (isset($hostConfig['session']) && $hostConfig['session']) {
                $hostConfig['session'] += static::$defaultSessionConfig;
            }

            if (isset($hostConfig['host']) && $hostConfig['port']) {
                array_unshift($hostConfig['listen'], "{$hostConfig['type']}://{$hostConfig['host']}:{$hostConfig['port']}");
            }
            elseif (!isset($hostConfig['listen']) || !is_array($hostConfig['listen']) || !$hostConfig['listen']) {
                echo ('hosts “' . $key . '”配置错误，必须 host, port 或 listen 参数.');
                exit;
            }

            if ($serverType < 3) {
                switch ($hostConfig['type']) {
                    case 'ws':
                    case 'wss':
                        # 使用 onHandShake 回调 see http://wiki.swoole.com/wiki/page/409.html
                        $hostConfig['handShake'] = isset($hostConfig['handShake']) && $hostConfig['handShake'] ? true : false;

                        if ($serverType === 1) {
                            # 已经有 http 服务了
                            $serverType = 3;
                        }
                        else {
                            $serverType = 2;
                        }
                        $hostsHttpAndWs[$key] = $hostConfig;
                        if (null === $mainHost) {
                            $mainHost = [$key, $hostConfig];
                        }
                        if (!isset($hostConfig['conf']) || !is_array($hostConfig['conf'])) {
                            $hostConfig['conf'] = [];
                        }
                        # 需要开启 websocket 协议
                        $hostConfig['conf'] = array_merge($hostConfig['conf'], ['open_websocket_protocol' => true]);

                        if (isset($hostConfig['http2']) && $hostConfig['http2']) {
                            $hostConfig['conf'] = array_merge($hostConfig['conf'], ['open_http2_protocol' => true]);
                        }
                        break;

                    case 'http':
                    case 'https':
                    case 'manager':
                    case 'api':
                        if ($serverType === 2) {
                            # 已经有 webSocket 服务了
                            $serverType = 3;
                        }
                        else {
                            $serverType = 1;
                        }
                        if (!isset($hostConfig['conf']) || !is_array($hostConfig['conf'])) {
                            $hostConfig['conf'] = [];
                        }
                        # 需要开启 http 协议
                        $hostConfig['conf'] = array_merge($hostConfig['conf'], ['open_http_protocol' => true]);

                        if (isset($hostConfig['http2']) && $hostConfig['http2']) {
                            $hostConfig['conf'] = array_merge($hostConfig['conf'], ['open_http2_protocol' => true]);
                        }

                        $hostsHttpAndWs[$key] = $hostConfig;
                        break;

                    case 'redis':
                        # Redis 服务器
                        if (!($this instanceof ServerRedis)) {
                            $this->warn('启动 Redis 服务器必须使用或扩展到 MyQEE\\Server\\ServerRedis 类，当前“' . get_class($this) . '”不支持');
                            exit;
                        }

                        $serverType = 4;
                        $mainHost   = [$key, $hostConfig];
                        break;

                    case 'upload':
                        # 上传服务器
                        if (!isset($hostConfig['conf']) || !is_array($hostConfig['conf'])) {
                            $hostConfig['conf'] = [];
                        }

                        # 设定参数
                        $hostConfig['conf'] = [
                            'open_eof_check'    => false,
                            'open_length_check' => false,
                            'open_eof_split'    => false,
                        ] + $hostConfig['conf'] + [
                            'upload_tmp_dir'           => is_dir('/tmp/') ? '/tmp/' : sys_get_temp_dir() . '/',
                            'heartbeat_idle_time'      => 180,
                            'heartbeat_check_interval' => 61,
                        ];

                        if (substr($hostConfig['conf']['upload_tmp_dir'], -1) != '/') {
                            $hostConfig['conf']['upload_tmp_dir'] .= '/';
                        }
                        break;

                    default:
                        break;
                }
            }

            switch ($hostConfig['type']) {
                case 'ws':
                case 'wss':
                case 'http':
                case 'https':
                case 'manager':
                case 'api':
                    break;

                default:
                    $defConf = [
                        'open_websocket_protocol' => false,
                        'open_http2_protocol'     => false,
                        'open_http_protocol'      => false,
                    ];
                    if (!isset($hostConfig['conf'])) {
                        $hostConfig['conf'] = $defConf;
                    }
                    else {
                        $hostConfig['conf'] += $defConf;
                    }
                    break;
            }

            if (!$masterHostKey) {
                $masterHostKey = $key;
                $masterHost    = $hostConfig;
            }
        }
        
        if ($serverType === 4 && $hostsHttpAndWs) {
            echo "Redis 服务器和 Http、WebSocket 服务不能同时启用在一个服务里\n";
            exit;
        }

        if ($mainHost) {
            $masterHostKey = $mainHost[0];
            $masterHost    = $mainHost[1];
        }
        elseif ($hostsHttpAndWs) {
            reset($hostsHttpAndWs);
            $masterHostKey = key($hostsHttpAndWs);
            $masterHost    = current($hostsHttpAndWs);
        }

        if (isset($masterHost['conf']) && $masterHost['conf']) {
            $this['swoole'] = array_merge($masterHost['conf'], $this['swoole']);
        }

        if (!$serverName) {
            try {
                $opt        = self::parseSockUri($masterHost['listen'][0]);
                $serverName = $opt->host . ':' . $opt->port;
                unset($opt);
            }
            catch (\Exception $e) {
                echo $e->getMessage() ."\n";
                exit;
            }
        }

        if ($serverType > 0 && $serverType < 4) {
            if (!isset($this['swoole']['open_tcp_nodelay'])) {
                # 开启后TCP连接发送数据时会关闭Nagle合并算法，立即发往客户端连接, http服务器，可以提升响应速度
                # see https://wiki.swoole.com/wiki/page/316.html
                $this['swoole']['open_tcp_nodelay'] = true;
            }

            if (!isset($masterHost['name'])) {
                # 默认 Server 名称
                $masterHost['name'] = 'MQSRV';
            }
        }

        $this->_serversSettingForServer = [
            'serverName'     => $serverName,
            'masterHostKey'  => $masterHostKey,
            'serverType'     => $serverType,
            'hostsHttpAndWs' => $hostsHttpAndWs,
            'masterHost'     => $masterHost,
        ];
    }


    /**
     * 初始化自定义进程配置
     */
    protected function initConfigForCustomWorker() {
        if (isset($this['customWorker'])) {
            if (!is_array($this['customWorker'])) {
                $key = (string)$this['customWorker'];

                $this['customWorker'] = [
                    $key => [
                        'name'  => $key,
                        'class' => 'WorkerCustom' . ucfirst($key),
                    ],
                ];
            }

            $tmp = $this['customWorker'];

            $this['customWorker'] = [];
            foreach ($tmp as $key => $conf) {
                if (!$conf) {
                    echo "customWorker {$key} 配置错误, 缺少内容\n";
                    exit;
                }

                if (!is_array($conf)) {
                    $key  = (string)$conf;
                    $conf = [
                        'name'  => $key,
                        'class' => 'WorkerCustom' . ucfirst($conf),
                    ];
                }
                if (!isset($conf['name'])) {
                    $conf['name'] = $key;
                }
                elseif ($conf['name'] !== $key) {
                    $key = $conf['name'];
                }
                if (!isset($conf['class'])) {
                    $conf['class'] = 'WorkerCustom' . ucfirst($key);
                }

                if (!isset($conf['redirect_stdin_stdout'])) {
                    $conf['redirect_stdin_stdout'] = false;
                }
                elseif ($conf['redirect_stdin_stdout']) {
                    # 当启用 redirect_stdin_stdout 时忽略 create_pipe 设置，将使用下列策略设置
                    #see https://wiki.swoole.com/wiki/page/214.html
                    unset($conf['create_pipe']);
                }
                if (!isset($conf['create_pipe'])) {
                    if (version_compare(SWOOLE_VERSION, '1.9.6', '>=')) {
                        if ($conf['redirect_stdin_stdout']) {
                            $conf['create_pipe'] = 1;
                        }
                        else {
                            $conf['create_pipe'] = 2;
                        }
                    }
                    elseif (version_compare(SWOOLE_VERSION, '1.8.3', '>=')) {
                        $conf['create_pipe'] = 2;
                    }
                    elseif (version_compare(SWOOLE_VERSION, '1.7.22', '>=')) {
                        $conf['create_pipe'] = 1;
                    }
                }

                $this['customWorker'][$key] = $conf;
            }
            $this['swoole']['custom_worker_num'] = count($this['customWorker']);
        }
        else {
            $this['swoole']['custom_worker_num'] = 0;
        }
    }

    /**
     * 初始化其它配置
     */
    protected function initConfigForDev() {
        if (!isset($this->config['debugger'])) {
            $this['debugger'] = [
                'open' => false,
            ];
        }
        else {
            $this['debugger']['open'] = isset($this['debugger']['open']) ? (bool)$this['debugger']['open'] : false;
        }
    }

    /**
     * 使配置中参数生效
     */
    public function effectiveConfig() {
        $this->effectiveForLog();
        $this->effectiveForPHP();

        Logger::instance()->info('PHP: '. PHP_VERSION . ', Swoole: '. SWOOLE_VERSION. ', argv: '. implode(' ', $_SERVER['argv']));
        Logger::instance()->info("======= Servers Config ========\n". str_replace('\\/', '/', json_encode($this['servers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
    }

    public function getServersSetting() {
        return $this->_serversSettingForServer;
    }

    /**
     * 处理Log相关参数
     */
    protected function effectiveForLog() {
        Logger::init($this['log']);
    }

    /**
     * 处理PHP相关参数
     */
    protected function effectiveForPHP() {
        if (isset($this['php']['timezone'])) {
            date_default_timezone_set($this['php']['timezone']);
            Logger::instance()->info("php date_default_timezone_set: {$this['php']['timezone']}");
        }

        if (isset($this['php']['error_reporting'])) {
            error_reporting($this['php']['error_reporting']);
            Logger::instance()->info("php error reporting: {$this['php']['error_reporting']}");
        }

        if (!$this['php']['socket_block']) {
            swoole_async_set(['socket_dontwait' => 1]);
        }

        foreach ($this['php']['ini'] as $key => $item) {
            $rs = ini_set($key, $item);

            if (false === $rs) {
                Logger::instance()->info("php ini_set {$key}: $item fail.");
            }
            else {
                Logger::instance()->info("php ini_set {$key}: {$rs} => $item success");
            }
        }
    }

    /**
     * 初始化对象
     *
     * @param array $config
     * @return static
     */
    public static function create(array $config) {
        if (!isset($config['dependencies'][__CLASS__])) {
            if (!isset($config['dependencies'])) {
                $config['dependencies'] = [];
            }
            $class = $config['dependencies'][__CLASS__] = static::class;
        }
        else {
            $class = $config['dependencies'][__CLASS__];
        }
        self::$instance = new $class($config);

        return self::$instance;
    }

    protected static function doDeepMergeConfig(& $obj, array $conf) {
        foreach ($conf as $k => $v) {
            if (is_array($v) && isset($obj[$k]) && is_array($obj[$k])) {
                self::doDeepMergeConfig($obj[$k], $v);
            }
            else {
                $obj[$k] = $v;
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
    protected static function parseSockUri($uri) {
        $result      = new \stdClass();
        $p           = parse_url($uri);
        $result->ssl = 0;
        $result->opt = [];

        if (false === $p && substr($uri, 0, 8) == 'unix:///') {
            $p = [
                'scheme' => 'unix',
                'path'   => substr($uri, 7),
            ];
        }
        if (false !== $p) {
            if (isset($p['query'])) {
                parse_str($p['query'], $opt);
                if (isset($opt['ssl'])) {
                    $result->ssl = SWOOLE_SSL;
                }
                $result->opt = $opt;
            }

            switch ($scheme = strtolower($p['scheme'])) {
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
        else {
            throw new \Exception("Can't parse this Uri: " . $uri);
        }

        return $result;
    }
}
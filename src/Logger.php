<?php
namespace MyQEE\Server;

if (!class_exists('\\Monolog\\Logger'))
{
    class_alias(Logger\Lite::class, LoggerLite::class);
}
else
{
    class_alias(\Monolog\Logger::class, LoggerLite::class);
}


class Logger extends LoggerLite
{
    public static $level = self::WARNING;

    /**
     * @var static
     */
    protected static $defaultLogger;

    /**
     * 默认日志频道名
     *
     * @var string
     */
    protected static $defaultName = 'server';

    /**
     * @var array
     */
    protected static $loggers = [];

    /**
     * 是否使用文件保存
     *
     * @var bool
     */
    protected static $saveFile = false;

    /**
     * 是否控制台输出
     *
     * @var bool
     */
    protected static $stdout = true;

    protected static $logWithFilePath = true;

    const TRACE = 50;

    /**
     * Logging levels from syslog protocol defined in RFC 5424
     *
     * @var array $levels Logging levels
     */
    protected static $levels = [
        self::TRACE     => 'TRACE',
        self::DEBUG     => 'DEBUG',
        self::INFO      => 'INFO',
        self::NOTICE    => 'NOTICE',
        self::WARNING   => 'WARNING',
        self::ERROR     => 'ERROR',
        self::CRITICAL  => 'CRITICAL',
        self::ALERT     => 'ALERT',
        self::EMERGENCY => 'EMERGENCY',
    ];

    /**
     * Adds a log record at the DEBUG level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string|\Exception|\Throwable $message The log message
     * @param array  $context The log context
     */
    public function debug($message, array $context = [])
    {
        self::convertTraceMessage($message, $context);
        $this->addRecord(static::DEBUG, (string) $message, $context);
    }

    /**
     * Adds a log record at the WARNING level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string|\Exception|\Throwable $message The log message
     * @param array  $context The log context
     */
    public function warning($message, array $context = [])
    {
        self::convertTraceMessage($message, $context);
        $this->addRecord(static::WARNING, (string) $message, $context);
    }

    /**
     * Adds a log record at the ERROR level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string|\Exception|\Throwable $message The log message
     * @param array  $context The log context
     */
    public function error($message, array $context = [])
    {
        self::convertTraceMessage($message, $context);
        $this->addRecord(static::ERROR, (string) $message, $context);
    }

    /**
     * Adds a log record at the CRITICAL level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string|\Exception|\Throwable $message The log message
     * @param array  $context The log context
     */
    public function critical($message, array $context = [])
    {
        self::convertTraceMessage($message, $context);
        $this->addRecord(static::CRITICAL, (string) $message, $context);
    }

    /**
     * Adds a log record at the ALERT level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string|\Exception|\Throwable $message The log message
     * @param array  $context The log context
     */
    public function alert($message, array $context = [])
    {
        self::convertTraceMessage($message, $context);
        $this->addRecord(static::ALERT, (string) $message, $context);
    }

    /**
     * Adds a log record at the EMERGENCY level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string|\Exception|\Throwable $message The log message
     * @param array  $context The log context
     */
    public function emergency($message, array $context = [])
    {
        self::convertTraceMessage($message, $context);
        $this->addRecord(static::EMERGENCY, (string) $message, $context);
    }

    /**
     * Logger实例化对象
     *
     * @return static
     */
    public static function instance()
    {
        return self::$defaultLogger;
    }

    /**
     * 初始化Monolog对象
     *
     * 设置系统默认的 formatter 以及 handler 等
     *
     * @param \Monolog\Logger $logger
     */
    public static function initMonolog(\Monolog\Logger $logger)
    {
        if (self::$logWithFilePath)
        {
            # 添加一个附带文件路径的处理器
            $logger->pushProcessor(new Logger\BacktraceProcessor());
        }

        if (self::$saveFile)
        {
            # 增加文件输出
            self::initMonologForFile($logger);
        }

        if (self::$stdout)
        {
            # 增加控制台输出
            self::initMonologForStdout($logger);
        }
    }

    /**
     * 初始化Monolog对象控制台输出
     *
     * @param \Monolog\Logger $logger
     */
    public static function initMonologForStdout(\Monolog\Logger $logger)
    {
        $lineFormatter = new Logger\LineFormatter();
        $lineFormatter->withColor = true;

        $stdoutHandler = new Logger\StdoutHandler(self::$level);
        $stdoutHandler->setFormatter($lineFormatter);

        $logger->pushHandler($stdoutHandler);
    }

    /**
     * 初始化Monolog对象文件输出
     *
     * @param \Monolog\Logger $logger
     */
    public static function initMonologForFile(\Monolog\Logger $logger)
    {
        $lineFormatter = new Logger\LineFormatter();
        $lineFormatter->withColor = false;

        $fileHandler = new Logger\SpecialProcessHandler(self::$level);
        $fileHandler->setFormatter($lineFormatter);

        $logger->pushHandler($fileHandler);
    }

    /**
     * 设置默认日志频道名称
     *
     * @param string $name
     */
    public static function setDefaultName($name)
    {
        self::$defaultName = $name;

        if (self::$defaultLogger)
        {
            self::$defaultLogger = self::$defaultLogger->withName($name);
        }
    }

    /**
     * 获取一个Logger对象
     *
     * @param string $name
     * @return static|\Monolog\Logger|null
     */
    public static function getLogger($name)
    {
        if (!isset(self::$loggers[$name]))return null;

        return self::$loggers[$name];
    }

    /**
     * 设置一个Logger对象
     *
     * @param string $name
     * @param \Monolog\Logger $logger
     */
    public static function setLogger($name, \Monolog\Logger $logger)
    {
        self::$loggers[$name] = $logger;
    }

    /**
     * 将 Exception 或 Throwable 的信息转换成字符串
     *
     * @param $message
     * @param $context
     */
    public static function convertTraceMessage(& $message, & $context)
    {
        if (is_object($message) && ($message instanceof \Exception || $message instanceof \Throwable))
        {
            $context['_trace'] = $message;
            $message           = $message->getMessage();
        }
    }

    /**
     * 初始化log配置
     *
     * ```php
     * $config = [
     *      'path'              => '/tmp/my_log.log',   # 路径，false = 不输出，支持 $level 替换，比如 /tmp/my_$level.log
     *      'loggerProcess'     => false,               # 在开启 path 时有效，是否使用日志独立进程
     *      'loggerProcessName' => 'logger',            # 进程名，默认 logger
     *      'stdout'            => null,                # 是否输出到控制台，开启log是默认false，否则默认true
     *      'withFilePath'      => true,                # 日志是否带文件路径
     * ];
     * ```
     *
     * @param array $config
     */
    public static function init($config)
    {
        # 初始化 Lite 对象
        Logger\Lite::init($config);

        self::$saveFile        = $config['path'] === false ? false : true;
        self::$logWithFilePath = (bool)$config['withFilePath'];

        if (self::$saveFile)
        {
            self::$stdout = isset($config['stdout']) && $config['stdout'] ? true : false;
        }
        else
        {
            self::$stdout = true;
        }

        self::$defaultLogger = new static(self::$defaultName);

        self::initMonolog(self::$defaultLogger);
        self::$defaultLogger->debug("Use Monolog\\Logger output logs.");
    }
}
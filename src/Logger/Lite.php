<?php

namespace MyQEE\Server\Logger;

use MyQEE\Server\Logger;
use MyQEE\Server\Message;
use MyQEE\Server\Server;
use MyQEE\Server\Util\Text;

/**
 * 无 Monolog 版本
 *
 * @package MyQEE\Server\Logger
 */
class Lite {
    protected $name;

    /**
     * 系统写log的进程名
     *
     * @var null
     */
    public static $sysLoggerProcessName = null;

    /**
     * 是否使用系统写入进程
     *
     * @var bool
     */
    public static $useProcessLoggerSaveFile = false;

    /**
     * 是否在log输出中带文件路径
     *
     * @var bool
     */
    public static $logWithFileLevel = 0;

    /**
     * 是否在控制台输出
     *
     * @var bool
     */
    public static $stdout = true;

    /**
     * @var \DateTimeZone
     */
    protected static $timezone;

    public static $typeToLevels = [
        'TRACE'     => self::TRACE,
        'DEBUG'     => self::DEBUG,
        'INFO'      => self::INFO,
        'NOTICE'    => self::NOTICE,
        'LOG'       => self::NOTICE,
        'WARNING'   => self::WARNING,
        'WARN'      => self::WARNING,
        'ERROR'     => self::ERROR,
        'CRITICAL'  => self::CRITICAL,
        'ALERT'     => self::ALERT,
        'EMERGENCY' => self::EMERGENCY,
    ];

    /**
     * 日志输出设置
     *
     * @var array
     */
    public static $logPathByLevel = [
        self::TRACE     => false,
        self::DEBUG     => false,
        self::INFO      => false,
        self::NOTICE    => false,
        self::WARNING   => true,
        self::ERROR     => true,
        self::CRITICAL  => true,
        self::ALERT     => true,
        self::EMERGENCY => true,
    ];

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

    const TRACE     = 50;
    const DEBUG     = 100;
    const INFO      = 200;
    const NOTICE    = 250;
    const WARNING   = 300;
    const ERROR     = 400;
    const CRITICAL  = 500;
    const ALERT     = 550;
    const EMERGENCY = 600;

    /**
     * @param string $name The logging channel
     */
    public function __construct($name) {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * @param $name
     * @return $this
     */
    public function withName($name) {
        $this->name = $name;

        return $this;
    }

    /**
     * Adds a log record at the DEBUG level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function debug($message, array $context = []) {
        return $this->addRecord(static::DEBUG, $message, $context);
    }

    /**
     * Adds a log record at the INFO level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function info($message, array $context = []) {
        return $this->addRecord(static::INFO, $message, $context);
    }

    /**
     * Adds a log record at the NOTICE level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function notice($message, array $context = []) {
        return $this->addRecord(static::NOTICE, $message, $context);
    }

    /**
     * Adds a log record at the WARNING level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function warn($message, array $context = []) {
        return $this->addRecord(static::WARNING, $message, $context);
    }

    /**
     * Adds a log record at the WARNING level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function warning($message, array $context = []) {
        return $this->addRecord(static::WARNING, $message, $context);
    }

    /**
     * Adds a log record at the ERROR level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function err($message, array $context = []) {
        return $this->addRecord(static::ERROR, $message, $context);
    }

    /**
     * Adds a log record at the ERROR level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function error($message, array $context = []) {
        return $this->addRecord(static::ERROR, $message, $context);
    }

    /**
     * Adds a log record at the CRITICAL level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function crit($message, array $context = []) {
        return $this->addRecord(static::CRITICAL, $message, $context);
    }

    /**
     * Adds a log record at the CRITICAL level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function critical($message, array $context = []) {
        return $this->addRecord(static::CRITICAL, $message, $context);
    }

    /**
     * Adds a log record at the ALERT level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function alert($message, array $context = []) {
        return $this->addRecord(static::ALERT, $message, $context);
    }

    /**
     * Adds a log record at the EMERGENCY level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function emerg($message, array $context = []) {
        return $this->addRecord(static::EMERGENCY, $message, $context);
    }

    /**
     * Adds a log record at the EMERGENCY level.
     *
     * This method allows for compatibility with common interfaces.
     *
     * @param string $message The log message
     * @param array $context The log context
     * @return bool   Whether the record has been processed
     */
    public function emergency($message, array $context = []) {
        return $this->addRecord(static::EMERGENCY, $message, $context);
    }

    /**
     * Adds a log record.
     *
     * @param int $level The logging level
     * @param string $message The log message
     * @param array $context The log context
     * @return bool Whether the record has been processed
     */
    public function addRecord($level, $message, array $context = []) {
        try {
            if ($level < Logger::$level) {
                return false;
            }

            if ($level === self::TRACE) {
                if (isset($context['_trace'])) {
                    $trace = $context['_trace'];
                    unset($context['_trace']);
                    self::saveTrace($trace, $context, 3);
                }
                else {
                    self::saveTrace($message, $context, 3);
                }
            }
            else {
                $levelName = static::getLevelName($level);
                $record    = [
                    'message'    => (string)$message,
                    'context'    => $context,
                    'level'      => $level,
                    'level_name' => $levelName,
                    'channel'    => $this->name,
                    'datetime'   => new \DateTimeImmutable('now', self::$timezone),
                    'extra'      => [],
                ];
                if (false !== static::$logWithFileLevel) {
                    $record['extra']['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
                }

                # 输出到控制台
                $isFile = true !== self::$logPathByLevel[$level];
                if (static::$stdout || !$isFile) {
                    echo self::formatToString($record, true);
                }

                # 保存到log文件
                if ($isFile) {
                    self::tryWriteLog($level, self::formatToString($record, false));
                }
            }
        }
        catch (\Exception $e) {
            echo $e->getTraceAsString(), "\n";
        }

        return true;
    }

    /**
     * Gets the name of the logging level.
     *
     * @param int $level
     * @return string
     */
    public static function getLevelName($level) {
        if (!isset(static::$levels[$level])) {
            throw new \InvalidArgumentException('Level "' . $level . '" is not defined, use one of: ' . implode(', ', array_keys(static::$levels)));
        }

        return static::$levels[$level];
    }

    /**
     * 重新打开日志文件句柄
     *
     * @return bool
     */
    public static function loggerReopenFile() {
        $process = Server::$instance->getCustomWorkerProcess(self::$sysLoggerProcessName);
        if (null !== $process) {
            $str = Message::createSystemMessageString('__reopen_log__', '', Server::$instance->server->worker_id);

            return $process->write($str) == strlen($str);
        }
        else {
            return false;
        }
    }

    /**
     * 立即存档日志
     *
     * @return bool
     */
    public static function loggerActive() {
        $process = Server::$instance->getCustomWorkerProcess(self::$sysLoggerProcessName);
        if (null !== $process) {
            $str = Message::createSystemMessageString('__active_log__', '', Server::$instance->server->worker_id);

            return $process->write($str) == strlen($str);
        }
        else {
            return false;
        }
    }

    /**
     * 将一个日志对象格式化成字符串
     *
     * @param array $record
     * @param bool $withColor
     * @return string
     */
    public static function formatToString(array $record, $withColor = false) {
        if ($withColor) {
            switch ($record['level']) {
                case Logger::TRACE:
                case Logger::DEBUG:
                    $color = '[36m';
                    break;

                case Logger::WARNING:
                    $color = '[35m';
                    break;

                case Logger::INFO:
                    $color = '[33m';
                    break;

                case Logger::NOTICE:
                    $color = '[32m';
                    break;

                case Logger::ERROR:
                case Logger::CRITICAL:
                case Logger::ALERT:
                case Logger::EMERGENCY:
                    $color = '[31m';
                    break;

                default:
                    $color = null;
                    break;
            }
        }
        else {
            $color = null;
        }

        if (isset($record['context']['_trace'])) {
            /**
             * @var \Throwable $tmp
             */
            $tmp  = $record['context']['_trace'];
            $file = $tmp->getFile();
            if ($file) {
                $line = $tmp->getLine();
                $file = Text::debugPath($file);
            }
            else {
                $file = $line = null;
            }
            unset($tmp);
        }
        elseif (isset($record['extra']['backtrace'])) {
            $line = isset($record['extra']['backtrace']['line']) && $record['extra']['backtrace']['line'] ? $record['extra']['backtrace']['line'] : '';
            $file = Text::debugPath($record['extra']['backtrace']['file']);
        }
        else {
            $file = $line = null;
        }

        /**
         * @var \DateTime $date
         */
        $date = $record['datetime'];
        $str  = $record['message'];

        if (isset($record['context']['_trace'])) {
            unset($record['context']['_trace']);
        }

        if (null === $color) {
            return $date->format('Y-m-d H:i:s.u') . " | {$record['level_name']} | {$record['channel']}" . ($file ? " | {$file}:{$line}" : '') . ($record['message'] ? " | {$str}" : '') . (is_array($record['context']) && $record['context'] ? ' | ' . json_encode($record['context'], JSON_UNESCAPED_UNICODE) : '') . "\n";
        }
        else {
            $beg = "\e$color";
            $end = "\e[0m";

            return $beg . $date->format('Y-m-d H:i:s.u') . " | {$record['level_name']} | {$record['channel']}" . $end . ($file ? "\e[2m | {$file}:{$line}$end" : '') . ($record['message'] ? "\e[37m | {$record['message']}{$end}" : '') . (is_array($record['context']) && $record['context'] ? ' | ' . json_encode($record['context'], JSON_UNESCAPED_UNICODE) : '') . "\n";
        }
    }

    /**
     * 获取 trace 内容
     *
     * 此方法用于扩展，请不要直接调用此方法，可使用 `$server->trace()`
     *
     * @param string|array|\Exception|\Throwable $trace
     * @param array $context
     * @param int $debugTreeIndex 用于获取 debug_backtrace() 里的错误文件的序号
     * @param bool $withColor
     * @return string
     */
    public static function formatTraceToString($trace, array $context, $debugTreeIndex = 1, $withColor = false) {
        if (is_array($trace)) {
            # 支持 Monolog 的数组数据
            if (isset($trace['extra']['backtrace'])) {
                $backtrace = $trace['extra']['backtrace'];
            }

            $context = $trace['context'];
            if (isset($context['_trace'])) {
                $trace = $context['_trace'];
                unset($context['_trace']);
            }
            else {
                $trace = $trace['message'];
            }

            $datetime = isset($context['datetime']) ? $context['datetime'] : new \DateTimeImmutable('now', self::$timezone);
        }
        else {
            $datetime = new \DateTimeImmutable('now', self::$timezone);
        }

        $timeStr    = $datetime->format('Y-m-d H:i:s.u');
        $contextStr = $context ? str_replace("\n", "\n       ", json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) : 'NULL';
        $pid        = getmypid();

        if ($withColor) {
            $begin1 = "\e[32m";
            $begin2 = "\e[32m";
            $end    = "\e[0m";
        }
        else {
            $begin1 = $begin2 = $end = '';
        }

        // 兼容PHP7 & PHP5
        if (is_object($trace) && ($trace instanceof \Exception || $trace instanceof \Throwable)) {
            /**
             * @var \Exception $trace
             */
            $class    = get_class($trace);
            $code     = $trace->getCode();
            $msg      = $trace->getMessage();
            $line     = $trace->getLine();
            $file     = Text::debugPath($trace->getFile());
            $traceStr = str_replace(BASE_DIR, './', $trace->getTraceAsString());
            $pTag     = Server::$instance->processTag;
            $str      = <<<EOF
{$begin1}-----------------TRACE-INFO-----------------{$end}
{$begin2}name    :{$end} {$pTag}
{$begin2}pid     :{$end} {$pid}
{$begin2}time    :{$end} {$timeStr}
{$begin2}class   :{$end} {$class}
{$begin2}code    :{$end} {$code}
{$begin2}file    :{$end} {$file}:{$line}
{$begin2}msg     :{$end} {$msg}
{$begin2}context :{$end} {$contextStr}
{$begin1}-----------------TRACE-TREE-----------------{$end}
{$traceStr}
{$begin1}--END--{$end}


EOF;
            if ($previous = $trace->getPrevious()) {
                $str = "caused by:\n" . static::saveTrace($previous);
            }
        }
        else {
            $backtrace = isset($backtrace) ? $backtrace : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $debugTreeIndex + 1)[$debugTreeIndex];
            $file      = isset($backtrace['file']) ? Text::debugPath($backtrace['file']) : $backtrace['class'] . $backtrace['type'] . $backtrace['function'];
            $line      = isset($backtrace['line']) ? ":{$backtrace['line']}" : '';
            $pTag      = Server::$instance->processTag;

            if ($debugTreeIndex > 0) {
                # 调整序号
                $traceArr = explode("\n", (new \Exception(''))->getTraceAsString());
                for ($i = 0; $i < $debugTreeIndex; $i++) {
                    array_shift($traceArr);
                }
                foreach ($traceArr as $i => & $item) {
                    $item = "#$i " . explode(' ', $item, 2)[1];

                }
                $traceStr = str_replace(BASE_DIR, '', implode("\n", $traceArr));
            }
            else {
                $traceStr = str_replace(BASE_DIR, '', (new \Exception(''))->getTraceAsString());
            }

            if (is_array($trace)) {
                $trace = str_replace("\n", "\n       ", json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            else {
                $trace = (string)$trace;
            }

            $str = <<<EOF
{$begin1}-----------------TRACE-INFO-----------------{$end}
{$begin2}name    :{$end} {$pTag}
{$begin2}pid     :{$end} {$pid}
{$begin2}time    :{$end} {$timeStr}
{$begin2}file    :{$end} {$file}{$line}
{$begin2}msg     :{$end} {$trace}
{$begin2}context :{$end} {$contextStr}
{$begin1}-----------------TRACE-TREE-----------------{$end}
{$traceStr}
{$begin1}--END--{$end}


EOF;
        }

        return $str;
    }

    public static function saveTrace($trace, array $context = [], $debugTreeIndex = 0) {
        $isFile = is_string(self::$logPathByLevel[self::TRACE]);
        if (static::$stdout || !$isFile) {
            echo self::formatTraceToString($trace, $context, $debugTreeIndex, true);
        }

        if ($isFile) {
            self::tryWriteLog(self::TRACE, self::formatTraceToString($trace, $context, $debugTreeIndex, false));
        }
    }

    /**
     * 尝试写入文件
     *
     * @param array $record
     * @return bool|int
     */
    public static function tryWriteLog($level, $logFormatted) {
        if (is_bool(self::$logPathByLevel[$level])) {
            return 0;
        }

        if (false === static::$useProcessLoggerSaveFile || null === self::$sysLoggerProcessName || strlen($logFormatted) > 8000) {
            # 在没有就绪前或log文件很长直接写文件
            \MyQEE\Server\Util\Co::writeFile(self::$logPathByLevel[$level], $logFormatted, FILE_APPEND);

            return true;
        }

        $data    = [
            '__mq_log__',
            $level,
            $logFormatted,
        ];
        $str     = Message::createSystemMessageString($data, '', Server::$instance->server->worker_id);
        $process = Server::$instance->getCustomWorkerProcess(self::$sysLoggerProcessName);
        if (null === $process) {
            \MyQEE\Server\Util\Co::writeFile(self::$logPathByLevel[$level], $logFormatted, FILE_APPEND);

            return true;
        }
        else {
            return $process->write($str);
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
    public static function init(array $config) {
        $logPath = isset($config['path']) && $config['path'] ? $config['path'] : false;

        if (false !== $logPath) {
            foreach (self::$levels as $level => $key) {
                if (!isset(self::$typeToLevels[$key])) {
                    exit("不被支持的log类型：$key\n");
                }
                $lowerKey                     = strtolower($key);
                self::$logPathByLevel[$level] = str_replace('$type', $lowerKey, $logPath);
                if (is_file(self::$logPathByLevel[$level]) && !is_writable(self::$logPathByLevel[$level])) {
                    exit("给定的log文件不可写: " . Text::debugPath(self::$logPathByLevel[$level]) . "\n");
                }
            }
            static::$stdout = isset($config['stdout']) && $config['stdout'] ? true : false;
        }
        else {
            foreach (self::$levels as $level => $key) {
                if (!isset(self::$typeToLevels[$key])) {
                    exit("不被支持的log类型：$key\n");
                }
                self::$logPathByLevel[$level] = true;
            }
            static::$stdout = true;
        }

        if ($config['loggerProcess']) {
            self::$sysLoggerProcessName       = $config['loggerProcessName'];
            static::$useProcessLoggerSaveFile = true;
        }

        static::$logWithFileLevel = $config['withFilePath'];

        self::$timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
    }

    /**
     * Gets all supported logging levels.
     *
     * @return array Assoc array with human-readable level names => level codes.
     */
    public static function getLevels() {
        return array_flip(static::$levels);
    }
}
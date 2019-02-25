<?php
namespace MyQEE\Server;

class Logger
{
    public $log;
    public $time;
    public $micro;
    public $type;
    public $pTag;
    public $file;
    public $line;
    public $data;

    protected $_d;

    const TYPE_LOG   = 0;
    const TYPE_WARN  = 1;
    const TYPE_ERROR = 2;
    const TYPE_INFO  = 3;
    const TYPE_DEBUG = 4;

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
    public static $useSysLoggerSaveFile = false;

    /**
     * 是否在log输出中带文件路径
     *
     * @var bool
     */
    public static $logWithFilePath = true;

    /**
     * 日志输出设置
     *
     * @var array
     */
    public static $logPath = [
        'warn'  => true,
        'error' => true,
    ];

    public function __construct()
    {
    }

    public function __sleep()
    {
        # 减小序列化后内容长度
        $this->_d = [
            $this->log,
            $this->time,
            $this->micro,
            $this->type,
            $this->pTag,
            $this->file,
            $this->line,
            $this->data,
        ];
        return ['_d'];
    }

    public function __wakeup()
    {
        $this->log   = $this->_d[0];
        $this->time  = $this->_d[1];
        $this->micro = $this->_d[2];
        $this->type  = $this->_d[3];
        $this->pTag  = $this->_d[4];
        $this->file  = $this->_d[5];
        $this->line  = $this->_d[6];
        $this->data  = $this->_d[7];
        $this->_d    = [];
    }

    /**
     * log格式化
     *
     * @param null|string $color
     * @return string
     */
    public function format($color = null)
    {
        $line  = $this->line ? ':'. $this->line : '';
        $float = substr($this->micro, 1, 6);

        if (null === $color)
        {
            return $str = date("Y-m-d\TH:i:s", $this->time) . "{$float} | {$this->type} | {$this->pTag}" .
                ($this->file ? " | {$this->file}{$line}" : '') .
                ($this->log ? " | {$this->log}" : '') .
                (is_array($this->data) ? ' | '. json_encode($this->data, JSON_UNESCAPED_UNICODE): '') . "\n";
        }
        else
        {
            $beg = "\e{$color}";
            $end = "\e[0m";

            return $beg . date("Y-m-d\TH:i:s", $this->time) . "{$float} | {$this->type} | {$this->pTag}" .
                $end .
                ($this->file ? "\e[2m | {$this->file}{$line}$end" : '') .
                ($this->log ? "\e[37m | {$this->log}{$end}" : '') .
                (is_array($this->data) ? ' | '. json_encode($this->data, JSON_UNESCAPED_UNICODE): '') . "\n";
        }
    }

    /**
     * 输出自定义log
     *
     * 此方法用于扩展，请不要直接调用此方法，可以使用 `$server->log()` 或 `$server->warn()` 等等
     *
     * @param string|array|\Exception $log
     * @param string|array $log
     * @param string $type
     * @param string $color
     * @param int $debugTreeIndex 用于获取 debug_backtrace() 里的错误文件的序号
     */
    public static function saveLog($log, array $data = null, $type = 'log', $color = '[36m', $debugTreeIndex = 0)
    {
        if (!isset(self::$logPath[$type]))return;

        if (is_array($log))
        {
            $data = $log;
            $log  = null;
        }

        if (is_object($log) && $log instanceof \Exception)
        {
            # 接受异常对象的捕获
            if (true === self::$logWithFilePath)
            {
                $file = Server::debugPath($log->getFile());
                $line = $log->getLine();
                $log  = get_class($log) .': '.$log->getMessage();
            }
            else
            {
                $log  = 'File: '. Server::debugPath($log->getFile()) .':'. $log->getLine() .' '. get_class($log) .': '.$log->getMessage();
            }
        }
        elseif (true === self::$logWithFilePath)
        {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $debugTreeIndex + 1)[$debugTreeIndex];
            $file  = isset($trace['file']) ? Server::debugPath($trace['file']) : $trace['class'] . $trace['type'] . $trace['function'];
            $line  = isset($trace['line']) ? $trace['line'] : '';
        }
        else
        {
            $file = $line = '';
        }

        $logObj        = new static();
        $time          = explode(' ', microtime());
        $logObj->time  = intval($time[1]);
        $logObj->micro = $time[0];
        $logObj->type  = $type;
        $logObj->pTag  = Server::$instance->processTag;
        $logObj->file  = $file;
        $logObj->line  = $line;
        $logObj->log   = $log;
        $logObj->data  = $data;

        if (is_string(self::$logPath[$type]))
        {
            if (false === self::saveLogFile($logObj))
            {
                file_put_contents(self::$logPath[$type], $logObj->format(), FILE_APPEND);
            }
        }
        else
        {
            # 直接输出
            echo $logObj->format($color);
        }
    }

    /**
     * 写log文件
     *
     * @param Logger $logObj
     * @return bool
     */
    protected static function saveLogFile($logObj)
    {
        if (false === self::$useSysLoggerSaveFile || null === self::$sysLoggerProcessName || strlen($logObj->log) > 8000)
        {
            # 在没有就绪前或log文件很长直接写文件
            $str = $logObj->format();
            return file_put_contents(self::$logPath[$logObj->type], $str, FILE_APPEND) === strlen($str);
        }

        $process = Server::$instance->getCustomWorkerProcess(self::$sysLoggerProcessName);
        if (null !== $process)
        {
            $str = Message::createSystemMessageString($logObj, '', Server::$instance->server->worker_id);
            return $process->write($str) == strlen($str);
        }
        else
        {
            return false;
        }
    }

    /**
     * 输出 trace 内容
     *
     * 此方法用于扩展，请不要直接调用此方法，可使用 `$server->trace()`
     *
     * @param mixed $trace
     * @param array|null $data
     * @param int $debugTreeIndex 用于获取 debug_backtrace() 里的错误文件的序号
     */
    public static function saveTrace($trace, array $data = null, $debugTreeIndex = 0)
    {
        $timeStr = date('Y-m-d H:i:s');
        $tFloat  = substr(microtime(true), 10, 5);
        $dataStr = $data ? str_replace("\n", "\n       ", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) : 'NULL';
        $pid     = getmypid();

        if (is_string(self::$logPath['trace']))
        {
            $isFile = true;
            $begin1 = $begin2 = $end = '';
        }
        else
        {
            $isFile = false;
            $begin1 = "\e[32m";
            $begin2 = "\e[32m";
            $end    = "\e[0m";
        }

        // 兼容PHP7 & PHP5
        if (is_object($trace) && ($trace instanceof \Exception || $trace instanceof \Throwable))
        {
            /**
             * @var \Exception $trace
             */
            $class    = get_class($trace);
            $code     = $trace->getCode();
            $msg      = $trace->getMessage();
            $line     = $trace->getLine();
            $file     = Server::debugPath($trace->getFile());
            $traceStr = str_replace(BASE_DIR, './', $trace->getTraceAsString());
            $pTag     = Server::$instance->processTag;
            $str      = <<<EOF
{$begin1}-----------------TRACE-INFO-----------------{$end}
{$begin2}name :{$end} {$pTag}
{$begin2}pid  :{$end} {$pid}
{$begin2}time :{$end} {$timeStr}{$tFloat}
{$begin2}class:{$end} {$class}
{$begin2}code :{$end} {$code}
{$begin2}file :{$end} {$file}:{$line}
{$begin2}msg  :{$end} {$msg}
{$begin2}data :{$end} {$dataStr}
{$begin1}-----------------TRACE-TREE-----------------{$end}
{$traceStr}
{$begin1}--END--{$end}


EOF;
            if ($previous = $trace->getPrevious())
            {
                $str = "caused by:\n" . static::trace($previous);
            }
        }
        else
        {
            $debug    = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $debugTreeIndex + 1)[$debugTreeIndex];
            $file     = isset($debug['file']) ? Server::debugPath($debug['file']) : $debug['class'] . $debug['type'] . $debug['function'];
            $line     = isset($debug['line']) ? ":{$debug['line']}" : '';
            $traceStr = str_replace(BASE_DIR, './', (new \Exception(''))->getTraceAsString());
            $pTag     = Server::$instance->processTag;

            if (is_array($trace))
            {
                $trace = str_replace("\n", "\n       ", json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            else
            {
                $trace = (string)$trace;
            }

            $str = <<<EOF
{$begin1}-----------------TRACE-INFO-----------------{$end}
{$begin2}name :{$end} {$pTag}
{$begin2}pid  :{$end} {$pid}
{$begin2}time :{$end} {$timeStr}{$tFloat}
{$begin2}file :{$end} {$file}{$line}
{$begin2}msg  :{$end} {$trace}
{$begin2}data :{$end} {$dataStr}
{$begin1}-----------------TRACE-TREE-----------------{$end}
{$traceStr}
{$begin1}--END--{$end}


EOF;
        }

        if (true === $isFile)
        {
            # 写文件
            @file_put_contents(self::$logPath['trace'], $str, FILE_APPEND);
        }
        else
        {
            # 直接输出
            echo $str;
        }
    }

    /**
     * 重新打开日志文件句柄
     *
     * @return bool
     */
    public static function loggerReopenFile()
    {
        $process = Server::$instance->getCustomWorkerProcess(self::$sysLoggerProcessName);
        if (null !== $process)
        {
            $str = Message::createSystemMessageString('__reopen_log__', '', Server::$instance->server->worker_id);
            return $process->write($str) == strlen($str);
        }
        else
        {
            return false;
        }
    }

    /**
     * 立即存档日志
     *
     * @return bool
     */
    public static function loggerActive()
    {
        $process = Server::$instance->getCustomWorkerProcess(self::$sysLoggerProcessName);
        if (null !== $process)
        {
            $str = Message::createSystemMessageString('__active_log__', '', Server::$instance->server->worker_id);
            return $process->write($str) == strlen($str);
        }
        else
        {
            return false;
        }
    }
}
<?php
namespace MyQEE\Server\Logger;

/**
 * 协程方式写文件
 *
 * @package MyQEE\Server\Logger
 */
class WriteFileCoHandler extends \Monolog\Handler\AbstractProcessingHandler
{
    protected $logPathByLevel;

    public function __construct(int $level = \MyQEE\Server\Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->logPathByLevel = Lite::$logPathByLevel;
    }

    /**
     * 指定日志文件
     *
     * 立即生效，不设置 $level 则全部指定为此文件
     *
     * @param string $file 完整路径，请确保可写
     * @param null|int $level
     */
    public function setFileByLevel($file, $level = null)
    {
        if (!$level)
        {
            foreach ($this->logPathByLevel as $l => & $f)
            {
                $f = $file;
            }
        }
        else
        {
            $this->logPathByLevel[$level] = $file;
        }
    }

    protected function write(array $record)
    {
        # 超过列队容量则协程切换并等待
        $level = $record['level'];
        if (isset($this->logPathByLevel[$level]) && $this->logPathByLevel[$level])
        {
            \MyQEE\Server\Co::writeFile($this->logPathByLevel[$level], $record['formatted'], FILE_APPEND);
        }
    }
}
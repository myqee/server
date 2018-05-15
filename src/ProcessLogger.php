<?php
namespace MyQEE\Server;

/**
 * 独立的写入日志进程
 *
 * @package MyQEE\Server
 */
class ProcessLogger extends WorkerCustom
{
    /**
     * 文件句柄
     *
     * @var array
     */
    protected $fpByPath = [];

    /**
     * @var \SplQueue
     */
    protected $queue;

    /**
     * 单个log文件限制大小
     *
     * @var int
     */
    protected $limitSize = 0;

    /**
     * 记录当前文件大小
     *
     * @var array
     */
    protected $fileSize = [];

    /**
     * 当前存档定时器
     *
     * @var int
     */
    protected $activeTimeTick;

    /**
     * 上次的存档key
     *
     * @var string
     */
    protected $lastActiveKey = null;

    /**
     * 存档配置
     *
     * @var array
     */
    protected $activeConfig = [];

    /**
     * 存档的时间key
     *
     * @var string
     */
    protected $activeTimeKey = 'YmdHis';

    public function onStart()
    {
        $this->activeConfig = self::$Server->config['log']['active'];
        $this->queue        = new \SplQueue();

        foreach (self::$Server->logPath as $type => $path)
        {
            if (is_string($path))
            {
                if (!isset($this->fpByPath[$path]))
                {
                    $this->fpByPath[$path] = fopen($path, 'a');
                    $this->fileSize[$path] = filesize($path);
                }
            }
        }

        if (isset($this->activeConfig['sizeLimit']) && $this->activeConfig['sizeLimit'])
        {
            $this->limitSize = $this->formatSizeLimit($this->activeConfig['sizeLimit']);
        }

        if ($this->activeConfig['path'] && ($this->limitSize > 0 || $this->activeConfig['timeLimit']))
        {
            if (!is_dir($this->activeConfig['path']))
            {
                if (!@mkdir($this->activeConfig['path']))
                {
                    echo "转存路径创建失败: {$this->activeConfig['path']}\n";
                }
            }
        }

        # 日志自动存档
        if ($this->activeConfig['timeLimit'])
        {
            $nextTime = strtotime(date('Y-m-d 23:59:59')) + 1;
            $isHour   = false;

            switch (strtolower(substr($this->activeConfig['timeLimit'], -1)))
            {
                case 'h':
                    # 按小时
                    $nextTime = strtotime(date('Y-m-d H:59:59')) + 1;
                    $isHour   = true;
                    $timeKey  = 'Ymd-H';
                    break;

                case 'w':
                    # 按星期
                    $this->lastActiveKey = date('Y-m-d-W');
                    $timeKey = 'Ym-\\w\\e\\e\\k-W';
                    break;

                case 'm':
                    # 按月
                    $this->lastActiveKey = date('Y-m');
                    $timeKey = 'Ym';
                    break;

                case 'd':
                default:
                    # 按天的不用记录，因为定时器最大1天执行1次
                    $timeKey = 'Ymd';
                    break;
            }

            $this->activeTimeKey = $this->activeConfig['timeKey'] ?: $timeKey;

            swoole_timer_after(max(1, ceil(($nextTime - microtime(true)) * 1000)), function() use ($isHour)
            {
                # 设定一个定时器
                $this->activeTimeTick = swoole_timer_tick(true === $isHour ? 3600000 : 86400000, function()
                {
                    $this->activeTickCallback();
                });

                # 立即执行
                $this->activeTickCallback();
            });
        }

        # 每秒钟自动刷新一次
        swoole_timer_tick(1000, function()
        {
            $this->saveLogToFile();
        });

        # 定时自动重新打开文件，避免文件被移动或删除时无法感知
        swoole_timer_tick(1000 * 60 * 10, function()
        {
            $this->reOpenFileResource();
        });
    }

    public function onWorkerExit()
    {
        $this->saveLogToFile();

        foreach ($this->fpByPath as $fp)
        {
            fclose($fp);
        }
        $this->fpByType = [];
        $this->fpByPath = [];

        if ($this->activeTimeTick)
        {
            swoole_timer_clear($this->activeTimeTick);
        }
    }

    public function appendLog($log)
    {
        $this->queue->enqueue($log);

        # 超过100条，立即写入
        if ($this->queue->count() > 100)
        {
            $this->saveLogToFile();
        }
    }

    protected function saveLogToFile()
    {
        $logStr = [];
        while (false === $this->queue->isEmpty())
        {
            $log  = $this->queue->dequeue();
            $str  = self::$Server->logFormatter($log);
            $path = self::$Server->logPath[$log->type];
            if (isset($logStr[$path]))
            {
                $logStr[$path] .= $str;
            }
            else
            {
                $logStr[$path] = $str;
            }
        }

        foreach ($logStr as $path => $str)
        {
            if (!isset($this->fpByPath[$path]) || false === $this->fpByPath[$path])
            {
                # 没有对应的文件句柄
                $rs = file_put_contents($path, $str, FILE_APPEND);
                if (false === $rs)
                {
                    echo $str;
                }
                continue;
            }

            if ($this->limitSize > 0)
            {
                # 超过大小
                if ($this->fileSize[$path] + strlen($str) > $this->limitSize)
                {
                    @fclose($this->fpByPath[$path]);
                    $newFile = $this->getActiveFilePath($path, time());
                    if (false === @rename($path, $newFile))
                    {
                        echo "转存log失败 {$path} to {$newFile}\n";
                    }

                    $this->fpByPath[$path] = fopen($path, 'a');
                    $this->fileSize[$path] = filesize($path);

                    if ($this->activeConfig['compress'])
                    {
                        # 开启压缩
                        $this->compressArchiveFile($newFile);
                    }
                }
            }

            $rs = fwrite($this->fpByPath[$path], $str);
            if (false === $rs)
            {
                # 写入失败, 重新打开一个文件句柄
                $fp = fopen($path, 'a');
                if (false !== $fp)
                {
                    @fclose($this->fpByPath[$path]);
                    $this->fileSize[$path] = filesize($path);
                    $this->fpByPath[$path] = $fp;
                }

                # 重写写入
                $rs = fwrite($this->fpByPath[$path], $str);

                if (false === $rs)
                {
                    # 依旧写入失败
                    echo $str;
                }
                else
                {
                    $this->fileSize[$path] += $rs;
                }
            }
            else
            {
                # 更新大小
                $this->fileSize[$path] += $rs;
            }
        }
    }

    public function onPipeMessage($server, $fromWorkerId, $message, $fromServerId = -1)
    {
        if (is_object($message) && $message instanceof \stdClass && isset($message->log))
        {
            $this->appendLog($message);
        }
        elseif ($message === 'reopen')
        {
            # 重新打开资源
            $this->reOpenFileResource();
        }
        elseif ($message === 'active')
        {
            # 存档log
            $this->activeLog('now');
        }
        else
        {
            $this->warn("未知消息类型: ". str_replace("\n", '\\n', var_export($message, true)));
        }
    }

    protected function reOpenFileResource()
    {
        foreach (array_keys($this->fpByPath) as $path)
        {
            clearstatcache($path);
            @fclose($this->fpByPath[$path]);
            $this->fpByPath[$path] = fopen($path, 'a');
            $this->fileSize[$path] = filesize($path);
        }
    }

    protected function activeTickCallback()
    {
        $type = strtolower(substr($this->activeConfig['timeLimit'], -1));
        switch ($type)
        {
            case 'w':
                # 按星期
                $key = date('Y-m-d-W');
                break;

            case 'm':
                # 按月
                $key = date('Y-m');
                break;

            case 'h':
                # 按小时, 不用判断，直接运行，因为此时是每个小时执行的
            case 'd':
                # 定时器最大是1天运行一次，此时每天执行1次
            default:
                $this->activeLog($type);
                return;
        }

        if ($key !== $this->lastActiveKey)
        {
            $this->activeLog($type);
            $this->lastActiveKey = $key;
        }
    }

    protected function activeLog($timeKey)
    {
        # 重命名文件
        switch ($timeKey)
        {
            case 'h':
            case 'd':
            case 'm':
            case 'w':
                $key = date($this->activeTimeKey, time() - 1000);
                break;

            case 'now':
                $key = time();
                break;

            default:
                $key = $timeKey;
                break;
        }

        foreach ($this->fileSize as $path => $size)
        {
            if ($size > 0)
            {
                $newFile = $this->getActiveFilePath($path, $key);
                if (false === @rename($path, $newFile))
                {
                    echo "转存log失败 {$path} to {$newFile}\n";
                }

                if ($this->activeConfig['compress'])
                {
                    # 开启压缩
                    $this->compressArchiveFile($newFile);
                }
            }
        }

        # 存档 swoole 的log
        /*
        if (isset(Server::$instance->config['swoole']['log_file']) && Server::$instance->config['swoole']['log_file'])
        {
            $path = Server::$instance->config['swoole']['log_file'];
            if (is_file($path) && filesize($path) > 0)
            {
                $newFile = $this->getActiveFilePath($path, $key);
                if (false === @move($path, $newFile))
                {
                    echo "转存log失败 {$path} to {$newFile}\n";
                }

                if ($this->activeConfig['compress'])
                {
                    # 开启压缩
                    $this->compressArchiveFile($newFile);
                }
            }
        }
        */

        $this->reOpenFileResource();
    }

    /**
     * 根据当前路径和时间key获取存档的log路径
     *
     * @param string $path
     * @param string $timeKey
     * @return string
     */
    protected function getActiveFilePath($path, $timeKey)
    {
        $dir   = dirname($path);
        $name  = explode('.', basename($path));
        $count = count($name);
        if ($count === 1)
        {
            $name[] = $timeKey;
        }
        else
        {
            $tmp = $name[$count - 1];
            $name[$count - 1] = $timeKey;
            $name[] = $tmp;
        }

        return ($this->activeConfig['path'] ?: $dir). '/' .$this->activeConfig['prefix']. implode('.', $name);
    }

    /**
     * 压缩文件
     *
     * @param $file
     */
    protected function compressArchiveFile($file)
    {
        $path = dirname($file);
        $name = basename($file);
        $cmd  = 'sh -c "cd '. escapeshellarg($path) .' && tar -zcf '. escapeshellarg($name.'.tar.gz'). ' ' . escapeshellarg($name) ." && rm -rf ". escapeshellarg($name) .'" > /dev/null &';
        exec($cmd);
    }

    /**
     * 处理大小设置
     *
     * @param $size
     * @return int
     */
    protected function formatSizeLimit($size)
    {
        if (is_string($size))
        {
            switch (strtoupper(substr($size, -1)))
            {
                case 'M':
                    $tmp = 1024 * 1024;
                    break;

                case 'G':
                    $tmp = 1024 * 1024 * 1024;
                    break;

                case 'T':
                    $tmp = 1024 * 1024 * 1024 * 1024;
                    break;

                case 'K':
                    $tmp = 1024;
                    break;

                default:
                    $tmp = 1;
                    break;
            }

            # 转成整数
            return intval(substr($size, 0, -1) * $tmp);
        }
        else
        {
            return (int)$size;
        }
    }
}
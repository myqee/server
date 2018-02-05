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

    public function onStart()
    {
        $this->queue = new \SplQueue();

        foreach (self::$Server->logPath as $type => $path)
        {
            if (is_string($path))
            {
                if (!isset($this->fpByPath[$path]))
                {
                    $this->fpByPath[$path] = fopen($path, 'a');
                }
            }
        }

        # 每秒钟自动刷新一次
        swoole_timer_tick(1000, function()
        {
            $this->saveLogToFile();
        });

        if (self::$Server->config['log']['size'] > 0)
        {
            $this->limitSize = self::$Server->config['log']['size'];

            foreach ($this->fpByPath as $path => $fp)
            {
                $this->fileSize[$path] = filesize($path);
            }
        }

        # 日志自动存档
        if (self::$Server->config['log']['limit'])
        {
            $nextTime = strtotime(date('Y-m-d 23:59:59')) + 1;
            $isHour   = false;

            switch (strtolower(substr(self::$Server->config['log']['limit'], -1)))
            {
                case 'h':
                    # 按小时
                    $nextTime = strtotime(date('Y-m-d H:59:59')) + 1;
                    $isHour   = true;
                    break;


                case 'w':
                    # 按星期
                    $this->lastActiveKey = date('Y-m-d-W');
                    break;

                case 'm':
                    # 按月
                    $this->lastActiveKey = date('Y-m');
                    break;

                case 'd':
                default:
                    # 按天的不用记录，因为定时器最大1天执行1次
                    break;
            }

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
            $type = $log['type'];
            $str  = self::$Server->logFormatter($log);
            $path = self::$Server->logPath[$type];
            $logStr[$path] .= $str;
        }

        foreach ($logStr as $path => $str)
        {
            if (!isset($this->fpByPath[$path]))
            {
                # 没有对应的文件
                echo $str;
                continue;
            }

            if ($this->limitSize > 0)
            {
                # 超过大小
                if ($this->fileSize[$path] + strlen($str) > $this->limitSize)
                {
                    @fclose($this->fpByPath[$path]);
                    $newFile = preg_replace('#\.log$#i', '', $path) .'.' . time() . '.log';
                    rename($path, $newFile);

                    $this->fpByPath[$path] = fopen($path, 'a');
                    $this->fileSize[$path] = filesize($path);

                    if (self::$Server->config['log']['compress'])
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
        if (is_array($message) && isset($message['log']))
        {
            $this->appendLog($message);
        }
    }

    protected function activeTickCallback()
    {
        $type = strtolower(substr(self::$Server->config['log']['limit'], -1));
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

    protected function activeLog($type)
    {
        $lastTime = time() - 1000;
        switch ($type)
        {
            case 'h':
                $suffix = date('YmdH', $lastTime);
                break;

            case 'd':
                $suffix = date('Ymd', $lastTime);
                break;

            case 'm':
                $suffix = date('Ym', $lastTime);
                break;

            case 'w':
                $suffix = date('Ym', $lastTime).'-week-' . date('W', $lastTime);
                break;

            default:
                $suffix = time() - 1;
                break;
        }

        # 重命名文件
        foreach ($this->fileSize as $path => $size)
        {
            if ($size > 0)
            {
                @fclose($this->fpByPath[$path]);
                $newFile = preg_replace('#\.log$#i', '', $path) .'.' . $suffix . '.log';
                rename($path, $newFile);

                # 开一个新文件
                $this->fpByPath[$path] = fopen($path, 'a');
                $this->fileSize[$path] = filesize($path);

                if (self::$Server->config['log']['compress'])
                {
                    # 开启压缩
                    $this->compressArchiveFile($newFile);
                }
            }
        }
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
        exec('cd '. escapeshellarg($path) .' && tar -zcf '. escapeshellarg($name.'.tar.gz'). ' ' . escapeshellarg($name) ." --remove-files &");
    }
}
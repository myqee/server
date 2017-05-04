<?php
namespace MyQEE\Server;

abstract class Action
{
    private static $_cachedFileAction = [];

    /**
     * 缓存的对象的文件Hash
     *
     * @var array
     */
    private static $_cachedFileHash = [];

    /**
     * 被缓存的文件列表（按组分类）
     *
     * @var array
     */
    private static $_cachedActionGroupFileList = [];

    /**
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return mixed
     */
    abstract public function exec($request, $response);

    /**
     * 移除缓存的Action对象
     *
     * @param null|string $file
     */
    public static function cleanCachedAction($file = null)
    {
        if (null !== $file)
        {
            unset(self::$_cachedFileAction[$file]);
            unset(self::$_cachedFileHash[$file]);
            clearstatcache(null, $file);
        }
        else
        {
            self::$_cachedFileAction = [];
            self::$_cachedFileHash   = [];
            clearstatcache();
        }
    }

    public static function runActionByFile($file, $request, $response)
    {
        if (isset(self::$_cachedFileAction[$file]))
        {
            $rs = self::$_cachedFileAction[$file];
            if ($rs instanceof \Closure)
            {
                # 一个匿名对象
                $rs = $rs($request, $response);
            }
            elseif ($rs instanceof Action)
            {
                $rs = $rs->exec($request, $response);
            }
            else
            {
                throw new \Exception('error cached action type', 404);
            }
        }
        else
        {
            if (!is_file($file))
            {
                throw new \Exception('can not found api', 404);
            }

            $rs = include($file);
            if (!$rs)
            {
                throw new \Exception('api result empty', 500);
            }
            elseif (is_object($rs))
            {
                if ($rs instanceof \Closure)
                {
                    # 一个匿名对象
                    self::$_cachedFileAction[$file] = $rs;
                    self::$_cachedFileHash[$file]   = md5_file($file);
                    $rs                             = $rs($request, $response);
                }
                elseif ($rs instanceof Action)
                {
                    self::$_cachedFileAction[$file] = $rs;
                    self::$_cachedFileHash[$file]   = md5_file($file);
                    $rs                             = $rs->exec($request, $response);
                }
            }
        }

        return $rs;
    }

    /**
     * 获取一个 uri 的路径
     *
     * @param        $uri
     * @param string $group
     * @return bool|string
     */
    public static function getActionFile($uri, $group = 'default')
    {
        if (isset(self::$_cachedActionGroupFileList[$group][$uri]))
        {
            return self::$_cachedActionGroupFileList[$group][$uri];
        }
        else
        {
            return false;
        }
    }

    /**
     * 加载指定目录Action, 支持多个目录合并
     *
     * @param array|string $dir 返回的列表
     * @param string $group 分组
     */
    public static function loadAction($dir, $group = 'default')
    {
        $list = [];
        foreach ((array)$dir as $item)
        {
            if (!is_dir($item))continue;
            $item = rtrim($item, '/') . '/';
            self::loadActionFileList($list, $item, strlen($item));
        }

        if (isset(self::$_cachedActionGroupFileList[$group]) && self::$_cachedActionGroupFileList[$group])
        {
            $delFiles = array_diff(self::$_cachedActionGroupFileList[$group], $list);
            if (count($delFiles) > 0)
            {
                # 已经被移除的Action文件，在缓存中移除
                foreach ($delFiles as $v)
                {
                    self::cleanCachedAction($v);
                }
            }

            $sameFiles = array_intersect(self::$_cachedActionGroupFileList[$group], $list);
            if (count($delFiles) > 0)
            {
                # 相同的文件，检查下文件是否变动过
                foreach ($sameFiles as $v)
                {
                    if (isset(self::$_cachedFileHash[$v]) && self::$_cachedFileHash[$v] !== md5_file($v))
                    {
                        self::cleanCachedAction($v);
                    }
                }
            }
        }

        self::$_cachedActionGroupFileList[$group] = $list;
    }

    /**
     * 通知所有进程重新加载Action
     *
     * @param bool $reloadAll 是否重载没有修改过的文件
     * @return bool
     */
    public static function reloadAction($dir, $group = 'default', $reloadAll = false)
    {
        try
        {
            $msg        = Message::create(static::class . '::reloadFileListOnMessage');
            $msg->group = $group;
            $msg->dir   = $dir;
            $msg->all   = $reloadAll;

            return $msg->sendMessageToAllWorker(Message::SEND_MESSAGE_TYPE_WORKER);
        }
        catch (\Exception $e)
        {
            Server::$instance->warn($e->getMessage());

            return false;
        }
    }

    /**
     * 这个是 `Action::reloadAction()` 方法执行后会在每个不同的 worker 进程里回调的方法
     *
     * @param $server
     * @param $fromWorkerId
     * @param $message
     * @param int $fromServerId
     */
    public static function reloadFileListOnMessage($server, $fromWorkerId, $message, $fromServerId = -1)
    {
        if ($message->all)
        {
            # 移除全部缓存
            $group = $message->group;
            if (isset(self::$_cachedActionGroupFileList[$group]))foreach (self::$_cachedActionGroupFileList[$group] as $file)
            {
                self::cleanCachedAction($file);
            }
        }

        # 加载文件列表
        self::loadAction($message->dir, $message->group);
    }

    /**
     * 加载指定目录Action文件列表
     *
     * @param string $dir          根目录
     * @param array  $list         返回的列表
     * @param int    $dirPrefixLen 根目录长度
     */
    protected static function loadActionFileList(array & $list, $dir, $dirPrefixLen)
    {
        foreach (glob($dir.'*') as $item)
        {
            $uri = strtolower(substr($item, $dirPrefixLen, -4));
            if (is_dir($item))
            {
                static::loadActionFileList($list, $item .'/', $dirPrefixLen);
            }
            elseif (basename($item) === 'index.php')
            {
                $uri = strtolower(rtrim(substr($item, $dirPrefixLen, -9), '/'));
                $list[$uri] = $item;
            }
            elseif (!isset($list[$uri]) && substr($item, -4) === '.php')
            {
                $list[$uri] = $item;
            }
        }
    }
}
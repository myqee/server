<?php
namespace MyQEE\Server;

class WorkerHttp extends Worker
{
    /**
     * 禁用域名检查
     *
     * @var bool
     */
    public $noCheckDomain = true;

    /**
     * 监听的域名列表
     *
     * 如果监听的是 domain.com， 则访问 http://domain.com/ 允许，访问 http://test.com/ 则不被允许
     * 支持 *.domain.com 通配符支持
     *
     * @var array
     */
    public $listenDomains = [];

    /**
     * 使用Action模式
     *
     * @var bool
     */
    public $useAction = false;

    public $actionGroup = 'action';

    /**
     * 开启静态资源文件输出支持
     *
     * @var bool
     */
    public $useAssets = false;

    /**
     * 静态文件所在目录
     *
     * @var string
     */
    public $assetsPath;

    /**
     * 静态文件路径前缀
     *
     * @var string
     */
    public $assetsUrlPrefix = '/assets/';

    /**
     * 静态文件URL路径前缀长度
     *
     * @var int
     */
    public $assetsUrlPrefixLength = 8;

    /**
     * 缓存的域名
     *
     * @var array
     */
    protected $_cachedDomains = [];

    /**
     * 缓存的无效的域名
     *
     * @var array
     */
    protected $_cachedBadDomains = [];

    /**
     * 图标文件名
     *
     * @var string
     */
    const FAVICON_ICO_FILE = 'favicon.ico';

    public function __construct($arguments)
    {
        parent::__construct($arguments);

        if (null === $this->assetsPath)
        {
            $this->assetsPath = $this->getAssetsPath();
        }

        # 静态文件支持
        if (isset($this->setting['useAssets']) && $this->setting['useAssets'])
        {
            $this->useAssets = true;
            $this->setAssetsUrlPrefix($this->assetsUrlPrefix);
        }

        # 监听的 Hosts
        if (isset($this->setting['domains']) && $this->setting['domains'])
        {
            $this->noCheckDomain = false;
            $this->listenDomains = (array)$this->setting['domains'];
        }

        if (isset($this->setting['useAction']) && $this->setting['useAction'])
        {
            $this->useAction = true;
        }
        if (isset($this->setting['actionGroup']) && $this->setting['actionGroup'])
        {
            $this->actionGroup = $this->setting['actionGroup'];
        }

        if (true === $this->useAction)
        {
            Action::loadAction($this->getActionPath(), $this->actionGroup);
        }
    }

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest($request, $response)
    {
        if (true === $this->useAssets && $this->isAssets($request))
        {
            $this->assets($this->assetsUri($request), $response);

            return;
        }

        # 请求浏览器图标
        if ($request->server['request_uri'] === '/favicon.ico')
        {
            $this->assets(static::FAVICON_ICO_FILE, $response);

            return;
        }

        if (true === $this->useAction)
        {
            $this->loadAction($request, $response);
        }
        else
        {
            $this->loadPage($request, $response);
        }
    }

    /**
     * 加载页面
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    protected function loadAction($request, $response)
    {
        $status = 500;
        $error  = false;
        do
        {
            $file = Action::getActionFile(trim($request->server['request_uri'], '/'), $this->actionGroup);
            if (false === $file)
            {
                $error  = 'page not found';
                $status = 404;
                break;
            }

            try
            {
                # 执行一个 Action
                $rs = Action::runActionByFile($file, $request, $response);
            }
            catch (\Exception $e)
            {
                $error  = $e->getMessage();
                $status = $e->getCode();
                break;
            }

            if (null === $rs || is_bool($rs))
            {
                # 不需要再输出
                return;
            }

            $response->end($rs);
        }
        while(false);

        if (false !== $error)
        {
            $response->status($status);
            $response->end('<html>
<head><title>Server Error</title></head>
<body bgcolor="white">
<center><h1>Server Error</h1></center>
<div>'. $error .'</div>
<hr><center>swoole/'. SWOOLE_VERSION .'</center>
</body>
</html>
');
        }
    }

    /**
     * 加载页面
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    protected function loadPage($request, $response)
    {
        # 访问请求页面
        $__uri__  = str_replace(['\\', '../'], ['/', '/'], $request->server['request_uri']);
        $__file__ = __DIR__ .'/../../../../pages/'. $__uri__ . (substr($__uri__, -1) === '/' ? 'index' : '') . '.php';

        if (!is_file($__file__))
        {
            $response->status(404);
            $response->end('<html>
<head><title>404 Not Found</title></head>
<body bgcolor="white">
<center><h1>404 Not Found</h1></center>
<hr><center>swoole/'. SWOOLE_VERSION .'</center>
</body>
</html>
');
            return;
        }
        unset($arr);

        # 执行页面Page
        ob_start();
        $rs   = include $__file__;
        $html = '';
        while (ob_get_level())
        {
            $html .= ob_get_clean();
        }

        if (false !== $rs)
        {
            $response->end($html);
        }
    }

    /**
     * 验证请求域名是否当前服务
     *
     * 在调用 onRequest 前系统会自动调用此方法判断判断
     *
     * 返回 `false` 则系统将不调用 `onRequest` 而直接返回 403 状态
     *
     * @param $domain
     * @return bool
     */
    public function onCheckDomain($domain)
    {
        if ($this->noCheckDomain)
        {
            return true;
        }
        elseif ($this->listenDomains)
        {
            # 缓存的域名
            if (isset($this->_cachedDomains[$domain]))return true;
            if (isset($this->_cachedBadDomains[$domain]))return false;

            foreach ($this->listenDomains as $h)
            {
                if ($domain === $h)
                {
                    $this->_cachedDomains[$domain] = true;
                    return true;
                }
                elseif ($h[0] === '/' && false !== @preg_match($h, $domain))
                {
                    # 支持正则表达式
                    $this->_cachedDomains[$domain] = true;
                    return true;
                }
            }

            # 缓存无效的域名
            if (count($this->_cachedBadDomains) > 1000)
            {
                $this->_cachedBadDomains = array_slice($this->_cachedBadDomains, -100, 100, true);
            }
            $this->_cachedBadDomains[$domain] = true;
        }

        return false;
    }

    /**
     * 设置路径前缀
     *
     * @param $prefix
     * @return $this
     */
    public function setAssetsUrlPrefix($prefix)
    {
        $this->assetsUrlPrefix    = '/'. ltrim(trim($prefix, ' /') .'/', '/');
        $this->assetsUrlPrefixLength = strlen($this->assetsUrlPrefix);

        return $this;
    }

    /**
     * 判断是否静态文件路径
     *
     * 默认 /assets/ 路径开头为静态文件路径
     *
     * @param \Swoole\Http\Request $request
     * @return bool
     */
    public function isAssets($request)
    {
        if ($this->assetsUrlPrefixLength === 1 || substr($request->server['request_uri'], 0, $this->assetsUrlPrefixLength) === $this->assetsUrlPrefix)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 返回静态文件URI部分（不含前缀）
     *
     * @param \Swoole\Http\Request $request
     * @return string
     */
    protected function assetsUri($request)
    {
        if ($this->assetsUrlPrefixLength > 1)
        {
            $uri = substr($request->server['request_uri'], $this->assetsUrlPrefixLength);
        }
        else
        {
            $uri = $request->server['request_uri'];
        }

        return strtolower(trim($uri, '/'));
    }

    /**
     * 获取目录
     *
     * @return array
     */
    protected function getPagesPath()
    {
        if (isset($this->setting['dir']) && $this->setting['dir'])
        {
            return (array)$this->setting['dir'];
        }
        else
        {
            return [realpath(__DIR__ . '/../../../../') . '/pages/'];
        }
    }

    /**
     * 获取静态文件路径
     *
     * 启动后会存放在 `$this->assetsPath` 变量中
     *
     * @return string
     */
    protected function getAssetsPath()
    {
        if (isset($this->setting['assetsDir']) && $this->setting['assetsDir'])
        {
            return $this->setting['assetsDir'];
        }
        else
        {
            return realpath(__DIR__ . '/../../../../') . '/assets/';
        }
    }

    /**
     * 获取目录
     *
     * @return array
     */
    protected function getActionPath()
    {
        if (isset($this->setting['actionDir']) && $this->setting['actionDir'])
        {
            return (array)$this->setting['actionDir'];
        }
        else
        {
            return [realpath(__DIR__ . '/../../../../') . '/action/'];
        }
    }

    /**
     * 输出静态文件
     *
     * @param $uri
     * @param \Swoole\Http\Response $response
     */
    protected function assets($uri, $response)
    {
        $uri  = str_replace(['\\', '../'], ['/', '/'], $uri);
        $rPos = strrpos($uri, '.');
        if (false === $rPos)
        {
            # 没有任何后缀
            $response->status(404);
            $response->end('file not found');
            return;
        }

        $file = $this->assetsPath. $uri;

        if (is_file($file))
        {
            # 设置缓存头信息
            $time = 86400;
            $response->header('Cache-Control', 'max-age='. $time);
            $response->header('Content-Type' , mime_content_type($file));
            $response->header('Pragma'       , 'cache');
            $response->header('Last-Modified', date('D, d M Y H:i:s \G\M\T', filemtime($file)));
            $response->header('Expires'      , date('D, d M Y H:i:s \G\M\T', time() + $time));

            # 直接发送文件
            $response->sendfile($file);
        }
        else
        {
            $response->status(404);
            $response->end('file not found');
        }
    }
}
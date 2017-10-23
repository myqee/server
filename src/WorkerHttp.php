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
     * 404页面文件路径
     *
     * 不设置则会初始化默认值
     *
     * @var string
     */
    public $errorPage404;

    /**
     * 错误页面文件路径
     *
     * 不设置则会初始化默认值
     *
     * @var string
     */
    public $errorPage500;

    /**
     * 接受客户端的语言设定的Key
     *
     * @var string
     */
    public $langCookieKey = 'lang';

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

    /**
     * 静态文件类型
     *
     * @var array
     */
    protected $assetTypes = [
        'html'    => 'text/html',
        'htm'     => 'text/html',
        'shtml'   => 'text/html',
        'css'     => 'text/css',
        'xml'     => 'text/xml',
        'gif'     => 'image/gif',
        'jpeg'    => 'image/jpeg',
        'jpg'     => 'image/jpeg',
        'js'      => 'application/x-javascript',
        'atom'    => 'application/atom+xml',
        'rss'     => 'application/rss+xml',
        'mml'     => 'text/mathml',
        'txt'     => 'text/plain',
        'jad'     => 'text/vnd.sun.j2me.app-descriptor',
        'wml'     => 'text/vnd.wap.wml',
        'htc'     => 'text/x-component',
        'png'     => 'image/png',
        'tif'     => 'image/tiff',
        'tiff'    => 'image/tiff',
        'wbmp'    => 'image/vnd.wap.wbmp',
        'ico'     => 'image/x-icon',
        'jng'     => 'image/x-jng',
        'bmp'     => 'image/x-ms-bmp',
        'svg'     => 'image/svg+xml',
        'svgz'    => 'image/svg+xml',
        'webp'    => 'image/webp',
        'woff'    => 'application/font-woff',
        'jar'     => 'application/java-archive',
        'war'     => 'application/java-archive',
        'ear'     => 'application/java-archive',
        'json'    => 'application/json',
        'hqx'     => 'application/mac-binhex40',
        'doc'     => 'application/msword',
        'pdf'     => 'application/pdf',
        'ps'      => 'application/postscript',
        'eps'     => 'application/postscript',
        'ai'      => 'application/postscript',
        'rtf'     => 'application/rtf',
        'm3u8'    => 'application/vnd.apple.mpegurl',
        'xls'     => 'application/vnd.ms-excel',
        'eot'     => 'application/vnd.ms-fontobject',
        'ppt'     => 'application/vnd.ms-powerpoint',
        'wmlc'    => 'application/vnd.wap.wmlc',
        'kml'     => 'application/vnd.google-earth.kml+xml',
        'kmz'     => 'application/vnd.google-earth.kmz',
        '7z'      => 'application/x-7z-compressed',
        'cco'     => 'application/x-cocoa',
        'jardiff' => 'application/x-java-archive-diff',
        'jnlp'    => 'application/x-java-jnlp-file',
        'run'     => 'application/x-makeself',
        'pl'      => 'application/x-perl',
        'pm'      => 'application/x-perl',
        'prc'     => 'application/x-pilot',
        'pdb'     => 'application/x-pilot',
        'rar'     => 'application/x-rar-compressed',
        'rpm'     => 'application/x-redhat-package-manager',
        'sea'     => 'application/x-sea',
        'swf'     => 'application/x-shockwave-flash',
        'sit'     => 'application/x-stuffit',
        'tcl'     => 'application/x-tcl',
        'tk'      => 'application/x-tcl',
        'der'     => 'application/x-x509-ca-cert',
        'pem'     => 'application/x-x509-ca-cert',
        'crt'     => 'application/x-x509-ca-cert',
        'xpi'     => 'application/x-xpinstall',
        'xhtml'   => 'application/xhtml+xml',
        'xspf'    => 'application/xspf+xml',
        'zip'     => 'application/zip',
        'bin'     => 'application/octet-stream',
        'exe'     => 'application/octet-stream',
        'dll'     => 'application/octet-stream',
        'deb'     => 'application/octet-stream',
        'dmg'     => 'application/octet-stream',
        'iso'     => 'application/octet-stream',
        'img'     => 'application/octet-stream',
        'msi'     => 'application/octet-stream',
        'msp'     => 'application/octet-stream',
        'msm'     => 'application/octet-stream',
        'docx'    => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx'    => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mid'     => 'audio/midi',
        'midi'    => 'audio/midi',
        'kar'     => 'audio/midi',
        'mp3'     => 'audio/mpeg',
        'ogg'     => 'audio/ogg',
        'm4a'     => 'audio/x-m4a',
        'ra'      => 'audio/x-realaudio',
        '3gpp'    => 'video/3gpp',
        '3gp'     => 'video/3gpp',
        'ts'      => 'video/mp2t',
        'mp4'     => 'video/mp4',
        'mpeg'    => 'video/mpeg',
        'mpg'     => 'video/mpeg',
        'mov'     => 'video/quicktime',
        'webm'    => 'video/webm',
        'flv'     => 'video/x-flv',
        'm4v'     => 'video/x-m4v',
        'mng'     => 'video/x-mng',
        'asx'     => 'video/x-ms-asf',
        'asf'     => 'video/x-ms-asf',
        'wmv'     => 'video/x-ms-wmv',
        'avi'     => 'video/x-msvideo',
    ];

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

        if (isset($this->setting['errorPage404']))
        {
            if (is_file($this->setting['errorPage404']))
            {
                $this->errorPage404 = $this->setting['errorPage404'];
            }
            elseif ($this->id === 0)
            {
                $this->warn("设定的 errorPage404 文件不存在: {$this->setting['errorPage404']}");
            }
        }
        if (isset($this->setting['errorPage500']))
        {
            if (is_file($this->setting['errorPage404']))
            {
                $this->errorPage500 = $this->setting['errorPage500'];
            }
            elseif ($this->id === 0)
            {
                $this->warn("设定的 errorPage500 文件不存在: {$this->setting['errorPage500']}");
            }
        }

        # 设定默认值
        if (!$this->errorPage404)$this->errorPage404 = __DIR__ .'/../error/404.phtml';
        if (!$this->errorPage500)$this->errorPage500 = __DIR__ .'/../error/500.phtml';

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

        # 构造一个新对象
        $reqRsp = $this->getReqRsp($request, $response);

        if (true === $this->useAction)
        {
            $this->loadAction($reqRsp);
        }
        else
        {
            $this->loadPage($reqRsp);
        }

        # 处理完毕后销毁对象
        unset($reqRsp);
    }

    /**
     * 获取 ReqRsp 对象
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    protected function getReqRsp($request, $response)
    {
        $reqRsp = new ReqRsp();
        $reqRsp->request  = $request;
        $reqRsp->response = $response;
        $reqRsp->worker   = $this;

        return $reqRsp;
    }

    /**
     * 加载页面
     *
     * @param ReqRsp $reqRsp
     */
    protected function loadAction($reqRsp)
    {
        $file = Action::getActionFile(trim($reqRsp->uri(), '/'), $this->actionGroup);
        if (false === $file)
        {
            $reqRsp->show404();
            return;
        }

        try
        {
            # 执行一个 Action
            $rs = Action::runActionByFile($file, $reqRsp);
        }
        catch (\Exception $e)
        {
            $status = $e->getCode();
            $reqRsp->show500($e, $status);
            return;
        }

        if (null === $rs || is_bool($rs))
        {
            # 不需要再输出
            return;
        }

        $reqRsp->end($rs);
    }

    /**
     * 加载页面
     *
     * @param ReqRsp $reqRsp
     */
    protected function loadPage($reqRsp)
    {
        # 访问请求页面
        $__uri__  = str_replace(['\\', '../'], ['/', '/'], $reqRsp->uri());
        $__file__ = __DIR__ .'/../../../../pages/'. $__uri__ . (substr($__uri__, -1) === '/' ? 'index' : '') . '.php';

        if (!is_file($__file__))
        {
            $reqRsp->show404();
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
            $reqRsp->end($html);
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
     * 输出页面路径
     *
     * @param $uri
     * @return mixed
     */
    public function url($uri)
    {
        return $uri;
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

        if (strtolower(substr($uri, 0, 4)) === 'src/')
        {
            # 禁止读取 src 目录
            $response->status(403);
            $response->end('Forbidden');
            return;
        }

        $file = $this->assetsPath. $uri;

        if (is_file($file))
        {
            # 设置缓存头信息
            $type = strtolower(substr($uri, $rPos + 1));
            $time = 86400;
            if (isset($this->assetTypes[$type]))
            {
                $response->header('Content-Type', $this->assetTypes[$type]);
            }

            $response->header('Cache-Control', 'max-age='. $time);
            $response->header('Pragma'       , 'cache');
            $response->header('Last-Modified', date('D, d M Y H:i:s \G\M\T', filemtime($file)));
            $response->header('Expires'      , date('D, d M Y H:i:s \G\M\T', time() + $time));

            # 直接发送文件
            $response->sendfile($file);
            @$this->server->close($response->fd);       # 不断开的话浏览器会被卡住
        }
        else
        {
            $response->status(404);
            $response->end('file not found');
        }
    }
}
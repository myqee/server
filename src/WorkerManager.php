<?php
namespace MyQEE\Server;

class WorkerManager extends WorkerHttp
{
    /**
     * 接口前缀
     *
     * @var string
     */
    public $prefix = '/admin/';

    /**
     * 前缀长度
     *
     * @var int
     */
    public $prefixLength = 7;

    /**
     * 管理后台文件所在目录, 默认为根目录下 admin 目录
     *
     * @var string
     */
    public $dir;

    public function __construct($arguments)
    {
        parent::__construct($arguments);

        if (isset($this->setting['prefix']) && $this->setting['prefix'])
        {
            $this->prefix       = $this->setting['prefix'] = '/'. ltrim(trim($this->setting['prefix']) .'/', '/');
            $this->prefixLength = strlen($this->prefix);
        }

        if (isset($this->setting['dir']) && $this->setting['dir'])
        {
            $this->dir = $this->setting['dir'];
        }
        else
        {
            $this->dir = BASE_DIR .'admin/';
        }
    }

    /**
     * 判断是否管理路径
     *
     * 默认 /admin/ 路径开头为API路径
     *
     * @param \Swoole\Http\Request $request
     * @return bool
     */
    public function isManager($request)
    {
        if (substr($request->server['request_uri'], 0, $this->prefixLength) === $this->prefix)
        {
            return true;
        }
        else
        {
            return false;
        }
    }


    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return null|\Generator
     */
    public function onRequest($request, $response)
    {
        try
        {
            $uri = $this->uri($request);
            $arr = explode('/', $uri);
            if ($arr[0] === 'assets')
            {
                # 静态路径
                array_shift($arr);
                $this->assets(implode('/', $arr), $response);
            }
            else
            {
                $this->admin(implode('/', $arr), $response);
            }
        }
        catch (\Exception $e)
        {
            $response->status(500);
        }
    }

    /**
     * 执行后台页面
     *
     * @param $uri
     */
    protected function admin($uri, $response)
    {
        /**
         * @var \Swoole\Http\Response $response
         */
        if ($uri === '')
        {
            $uri = 'index';
        }
        else
        {
            $uri  = str_replace(['\\', '../'], ['/', '/'], $uri);
        }

        $file = $this->dir. $uri . (substr($uri, -1) === '/' ? 'index' : '') .'.php';
        $this->debug("request admin page: $file");

        if (!is_file($file))
        {
            $response->status(404);
            $response->end('page not found');
            return;
        }

        ob_start();
        include $file;
        $html = ob_get_clean();

        $response->end($html);
    }


    /**
     * 返回当前URI部分（不含前缀）
     *
     * @return string
     */
    protected function uri($request)
    {
        if ($this->prefixLength > 1)
        {
            return trim(substr($request->server['request_uri'], $this->prefixLength), '/');
        }
        else
        {
            return trim($request->server['request_uri']);
        }
    }
}
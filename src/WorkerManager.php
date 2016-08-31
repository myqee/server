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
     */
    public function onRequest($request, $response)
    {
        $this->request  = $request;
        $this->response = $response;

        try
        {
            $uri = $this->uri();
            $arr = explode('/', $uri);
            if ($arr[0] === 'assets')
            {
                # 静态路径
                array_shift($arr);
                $this->assets(implode('/', $arr), $response);
            }
            else
            {
                $this->admin(implode('/', $arr));
            }
        }
        catch (\Exception $e)
        {
            $response->status(500);
        }

        $this->request  = null;
        $this->response = null;
    }

    /**
     * 执行后台页面
     *
     * @param $uri
     */
    protected function admin($uri)
    {
        if ($uri === '')
        {
            $uri = 'index';
        }
        else
        {
            $uri  = str_replace(['\\', '../'], ['/', '/'], $uri);
        }

        $file = __DIR__ .'/../../../../admin/'. $uri .'.php';
        self::debug("request admin page: $file");

        if (!is_file($file))
        {
            $this->response->status(404);
            $this->response->end('page not found');
            return;
        }

        ob_start();
        include $file;
        $html = ob_get_clean();

        $this->response->end($html);
    }


    /**
     * 返回当前URI部分（不含前缀）
     *
     * @return string
     */
    protected function uri()
    {
        return substr($this->request->server['request_uri'], $this->prefixLength);
    }
}
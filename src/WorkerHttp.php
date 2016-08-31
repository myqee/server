<?php
namespace MyQEE\Server;

class WorkerHttp extends Worker
{
    /**
     * @var \Swoole\Http\Request
     */
    protected $request;

    /**
     * @var \Swoole\Http\Response
     */
    protected $response;

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest($request, $response)
    {
        $arr = explode('/', $request->server['request_uri']);

        if ($arr[0] === 'assets')
        {
            # 静态路径
            array_shift($arr);
            $this->assets(implode('/', $arr), $response);
        }
        else
        {
            # 访问请求页面
            $uri  = str_replace(['\\', '../'], ['/', '/'], implode('/', $arr));
            $file = __DIR__ .'/../../../../pages/'. $uri . (substr($uri, -1) === '/' ? 'index' : '') . '.php';

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
            $response->end('assets not found');
            return;
        }

        $type = strtolower(substr($uri, $rPos + 1));

        $header = [
            'js'    => 'application/x-javascript',
            'css'   => 'text/css',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'json'  => 'application/json',
            'svg'   => 'image/svg+xml',
            'woff'  => 'application/font-woff',
            'woff2' => 'application/font-woff2',
            'ttf'   => 'application/x-font-ttf',
            'eot'   => 'application/vnd.ms-fontobject',
            'html'  => 'text/html',
        ];

        if (isset($header[$type]))
        {
            $response->header('Content-Type', $header[$type]);
        }

        $file = __DIR__ .'/../../../../assets/'. $uri;
        if (is_file($file))
        {
            # 设置缓存头信息
            $time = 86400;
            $response->header('Cache-Control', 'max-age='. $time);
            $response->header('Pragma'       , 'cache');
            $response->header('Last-Modified', date('D, d M Y H:i:s \G\M\T', filemtime($file)));
            $response->header('Expires'      , date('D, d M Y H:i:s \G\M\T', time() + $time));

            # 直接发送文件
            $response->sendfile($file);
        }
        else
        {
            $response->status(404);
            $response->end('assets not found');
        }
    }
}
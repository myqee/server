<?php
namespace MyQEE\Server;

class WorkerAPI extends WorkerHttp
{
    /**
     * 接口前缀
     *
     * @var string
     */
    public $prefix = '/api/';

    /**
     * 前缀长度
     *
     * @var int
     */
    public $prefixLength = 4;


    /**
     * 判断是否API路径
     *
     * 默认 /api/ 路径开头为API路径
     *
     * @param \Swoole\Http\Request $request
     * @return bool
     */
    public function isApi($request)
    {
        if (substr($request->server['request_uri'], 0, $this->prefixLength) === $this->prefixLength)
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
            switch($this->uri())
            {

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
     * 返回当前URI部分（不含前缀）
     *
     * @return string
     */
    protected function uri()
    {
        return substr($this->request->server['request_uri'], $this->prefixLength);
    }
}
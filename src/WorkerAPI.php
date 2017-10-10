<?php
namespace MyQEE\Server;

class WorkerAPI extends Worker
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
    public $prefixLength = 5;

    public $actionGroup = 'api';

    public function __construct($arguments)
    {
        parent::__construct($arguments);

        if (isset($this->setting['prefix']) && $this->setting['prefix'])
        {
            $this->setPrefix($this->setting['prefix']);
        }
        else
        {
            $this->setPrefix($this->prefix);
        }

        # 读取列表
        Action::loadAction($this->getActionPath(), $this->actionGroup);
    }

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
        if ($this->prefixLength === 1 || substr($request->server['request_uri'], 0, $this->prefixLength) === $this->prefix)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 设置路径前缀
     *
     * @param $prefix
     * @return $this
     */
    public function setPrefix($prefix)
    {
        $this->prefix       = '/'. ltrim(trim($prefix) .'/', '/');
        $this->prefixLength = strlen($this->prefix);

        return $this;
    }

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest($request, $response)
    {
        $response->header('Content-Type', 'application/json');

        $status = 500;
        $error  = false;
        do
        {
            if (false === $this->isApi($request))
            {
                $error  = 'page not found';
                $status = 404;
                break;
            }

            if (false === $this->verify($request))
            {
                $error  = 'Unauthorized';
                $status = 401;
                break;
            }

            $file = Action::getActionFile($this->uri($request), $this->actionGroup);
            if (false === $file)
            {
                $error  = 'api not exist';
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

            $this->output($response, $rs);
        }
        while(false);

        if (false !== $error)
        {
            $response->status($status);
            $this->output($response, ['status' => 'error', 'msg' => $error]);
        }
    }

    /**
     * 通知所有进程重新加载Action
     *
     * @param bool $reloadAll 是否重载没有修改过的文件
     * @return bool
     */
    public function reloadAction($reloadAll = false)
    {
        return Action::reloadAction($this->getActionPath(), $this->actionGroup, $reloadAll);
    }

    /**
     * 输出内容
     *
     * @param \Swoole\Http\Response $response
     * @param mixed $data
     */
    protected function output($response, $data)
    {
        if (!is_array($data))
        {
            $data = [
                'data'   => $data,
                'status' => 'success'
            ];
        }

        if (!isset($data['status']))
        {
            $data['status'] = 'success';
        }

        $response->end(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 返回当前URI部分（不含前缀）
     *
     * @param \Swoole\Http\Request $request
     * @return string
     */
    protected function uri($request)
    {
        if ($this->prefixLength > 1)
        {
            $uri = substr($request->server['request_uri'], $this->prefixLength);
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
    protected function getActionPath()
    {
        if (isset($this->setting['dir']) && $this->setting['dir'])
        {
            return (array)$this->setting['dir'];
        }
        else
        {
            return [realpath(__DIR__ . '/../../../../') . '/api/'];
        }
    }

    /**
     * 验证API是否通过
     *
     * 请自行扩展
     *
     * @param \Swoole\Http\Request $request
     * @return bool
     */
    protected function verify($request)
    {
        return true;
    }
}
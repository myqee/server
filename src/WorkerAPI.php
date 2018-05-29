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
    public $prefixLength = 5;

    public $apiGroup = 'api';

    /**
     * 是否开启混合模式
     *
     * API之外是否支持普通的 http
     * 如果开启 $this->useAction 或 $this->useAssets 则此参数默认 true
     *
     * * false - 则是纯API模式
     * * true  - 则是优先判断是否API路径，是的话使用api，不是API前缀的路径则使用page模式，适合页面和API混合在一起的场景
     *
     * @var bool
     */
    public $mixedMode = false;

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

        if ($this->useAction || $this->useAssets || (isset($this->setting['mixedMode']) && true == $this->setting['mixedMode']))
        {
            # 开启混合模式
            $this->mixedMode = true;
        }

        # 防止 worker 之间相同名称会导致冲突
        $this->apiGroup = "{$this->name}.{$this->apiGroup}";

        # 读取列表
        Action::loadAction($apiPath = $this->getApiPath(), $this->apiGroup);

        if (Server::$isDebug && $this->id === 0)
        {
            $this->debug("Worker{$this->name} api prefix: {$this->prefix}, path: ". implode(', ', Server::$instance->debugPath($apiPath)));
            if ($this->mixedMode)
            {
                $this->debug("Worker{$this->name} open api mixed mode.");
            }
        }
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
        $this->prefix       = '/'. ltrim(trim($prefix, ' /') .'/', '/');
        $this->prefixLength = strlen($this->prefix);

        return $this;
    }

    /**
     * HTTP 接口请求处理的方法
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return mixed
     */
    public function onRequest($request, $response)
    {
        $status = 500;
        $error  = false;
        do
        {
            if (false === $this->isApi($request))
            {
                if (true === $this->mixedMode)
                {
                    # 使用 http 模式
                    parent::onRequest($request, $response);
                    return null;
                }

                $status = 404;
                $error  = 'api not exist';
                break;
            }

            # 设置json文件头
            $response->header('Content-Type', 'application/json');

            $file = Action::getActionFile($this->uri($request), $this->apiGroup);
            if (false === $file)
            {
                $error  = 'api not exist';
                $status = 404;
                break;
            }

            try
            {
                $reqRep = $this->getReqRep($request, $response);

                if (true !== $this->verifyApi($reqRep))
                {
                    if ($reqRep->isEnd())return null;    # 页面已经关闭结束

                    $error  = 'Unauthorized';
                    $status = 401;
                    break;
                }

                $rs = Action::runActionByFile($file, $reqRep);
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
                return null;
            }
            elseif ($rs instanceof \Generator)
            {
                # 返回一个协程对象
                return (function() use ($rs, $response)
                {
                    $rs2 = yield $rs;
                    $this->output($response, $rs2);
                })();
            }

            $this->output($response, $rs);
        }
        while(false);

        if (false !== $error)
        {
            $response->status($status);
            $this->output($response, ['status' => 'error', 'msg' => $error]);
        }

        return null;
    }

    /**
     * 通知所有进程重新加载Action
     *
     * @param bool $reloadAll 是否重载没有修改过的文件
     * @return bool
     */
    public function reloadAction($reloadAll = false)
    {
        return Action::reloadAction($this->getApiPath(), $this->apiGroup, $reloadAll);
    }

    /**
     * 输出内容
     *
     * 可以自行扩展自定义输出格式
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
        return trim($uri, '/');
    }

    /**
     * 获取目录
     *
     * @return array
     */
    protected function getApiPath()
    {
        if (isset($this->setting['dir']) && $this->setting['dir'])
        {
            return (array)$this->setting['dir'];
        }
        else
        {
            return [BASE_DIR .'api/'];
        }
    }

    /**
     * 验证API是否通过
     *
     * 请自行扩展
     *
     * @param ReqRep $reqRep
     * @return bool
     */
    protected function verifyApi($reqRep)
    {
        return true;
    }
}
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

    /**
     * API 文件所在目录, 默认为根目录下 api 目录
     *
     * @var string
     */
    public $dir;

    public static $cachedFileList = [];

    public function __construct($server, $name)
    {
        parent::__construct($server, $name);

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
            $this->dir = realpath(__DIR__ .'/../../../../') . '/api/';
        }

        # 读取列表
        if (is_dir($this->dir))
        {
            Action::loadActionFileList(self::$cachedFileList, $this->dir);
        }

        if ($this->id == 0)
        swoole_timer_tick(3000, function()
        {
            $this->reloadFileList();
        });
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

            $uri = $this->uri($request);
            if (isset(self::$cachedFileList[$uri]))
            {
                $file = self::$cachedFileList[$uri];
            }
            else
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
     * 重新加载列表
     *
     * @return bool
     */
    public function reloadFileList()
    {
        try
        {
            $msg        = Message::create(static::class . '::reloadFileListOnMessage');
            $msg->wName = $this->name;

            return $msg->sendMessageToAllWorker(Message::SEND_MESSAGE_TYPE_WORKER);
        }
        catch (\Exception $e)
        {
            $this->warn($e->getMessage());

            return false;
        }
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

    /**
     * 这个是 `$this->reloadFileList()` 方法执行后会在每个不同的 worker 进程里回调的方法
     *
     * @param     $server
     * @param     $fromWorkerId
     * @param     $message
     * @param int $fromServerId
     */
    public static function reloadFileListOnMessage($server, $fromWorkerId, $message, $fromServerId = -1)
    {
        self::$cachedFileList = [];

        if (!isset($message->wName) || !isset(Server::$instance->workers[$message->wName]))return;

        /**
         * @var WorkerAPI $obj
         */
        $obj = Server::$instance->workers[$message->wName];
        if (is_dir($obj->dir))
        {
            Action::loadActionFileList(self::$cachedFileList, $obj->dir);
        }
    }
}
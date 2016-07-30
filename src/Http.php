<?php
namespace MyQEE\Server;

use \MyQEE\Site;
use \MyQEE\Request;
use \MyQEE\Response;


class Http
{
    /**
     * @var \swoole_http_server
     */
    protected $server;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * 全局配置
     *
     * @var \swoole_table
     */
    protected $globalConfig;

    /**
     * @var \swoole_http_request
     */
    protected $request;

    /**
     * @var \swoole_http_response
     */
    protected $response;

    /**
     * @var array
     */
    protected $argv = [];

    /**
     * 资源对象列表
     *
     * @var array
     */
    protected $resources = [];

    /**
     * @var Site
     */
    protected $site;

    /**
     * 管理服务器
     *
     * @var \swoole_http_server
     */
    protected $managerServer;

    /**
     * 管理进程
     *
     * @var \swoole_process
     */
    protected $managerProcess;

    /**
     * HttpServer constructor.
     */
    public function __construct($config_file = 'http-server.ini')
    {
        $red       = "\x1b[31m";
        $lightBlue = "\x1b[36m";
        $end       = "\x1b[39m";
        $error     = "{$red}✕{$end}";

        # 读取配置
        $config = parse_ini_string(file_get_contents($config_file), true);

        if (!$config)
        {
            echo "{$error} {$red}配置错误{$end}\n";
            exit;
        }

        foreach ($config['conf'] as & $item)
        {
            if (is_string($item) && (strpos($item, '\\n') !== false || strpos($item, '\\r') !== false))
            {
                $item = str_replace(['\\n', '\\r'], ["\n", "\r"], $item);
            }
        }

        # 设置参数
        if (isset($config['php']['error_reporting']))
        {
            error_reporting($config['php']['error_reporting']);
        }

        if (isset($config['php']['timezone']))
        {
            date_default_timezone_set($config['php']['timezone']);
        }

        # 更新配置
        $config['conf']['max_request'] = (int)$config['conf']['max_request'];

        echo "{$lightBlue}======= Swoole Config ========\n", json_encode($config['conf'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "{$end}\n";

        $this->config = $config;
    }

    /**
     * 启动服务
     */
    public function start()
    {
        $this->server = new \swoole_http_server($this->config['server']['host'], $this->config['server']['port']);

//        $this->startManager();

        # 设置配置
        $this->server->set($this->config['conf']);

        $this->bind();

        $this->server->setGlobal(HTTP_GLOBAL_ALL);

        $this->createGlobalConfigTable();

        $this->server->start();
    }

    /**
     * 在子进程中启动管理进程
     *
     * @return \swoole_process
     */
    protected function startManager()
    {
        $process = new \swoole_process(function (\swoole_process $worker)
        {
            # 在子进程中创建一个管理模块
            $port = $this->config['manager']['port'] ?: 9001;
            $host = $this->config['manager']['host'] ?: '127.0.0.1';
            $http = new \swoole_http_server($host, $port);
            $http->on('request', function (\swoole_http_request $request, \swoole_http_response $response) use ($worker)
            {
                $data = [];
                switch (trim($request->server['request_uri'], ' /'))
                {
                    case 'stats':
                        $data['status'] = 'ok';
                        $data['data']   = $this->server->stats();
                        break;
                    case 'restart':
                    case 'reload':
                        if ($worker->write('restart'))
                        {
                            $data['status'] = 'ok';
                        }
                        else
                        {
                            $data['status'] = 'error';
                            $data['message'] = '重启失败';
                        }
                        break;
                    default:
                        $data['status'] = 'error';
                        $data['message'] = '未知方法';
                        break;
                }

                $response->header('Content-Type', 'application/json');
                $response->end(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            });
            $http->on('start', function () use ($host, $port)
            {
                $this->info('Manager Server: http://' . $host . ':' . $port . '/');
            });

            $this->managerServer = $http;
            $http->start();
        }, false);

        $process->start();

        return $process;
    }

    /**
     * 创建全局配置表
     */
    protected function createGlobalConfigTable()
    {
        # 创建一个所有子进程共享的配置表
        $table = new \swoole_table(4096);
        $table->column('value', \swoole_table::TYPE_STRING, 256);
        $table->column('num', \swoole_table::TYPE_INT);
        $table->create();

        $this->globalConfig = $table;
    }

    protected function bind()
    {
        $this->server->on('WorkerStop',   [$this, 'onWorkerStop']);
        $this->server->on('WorkerStart',  [$this, 'onWorkerStart']);
        $this->server->on('PipeMessage',  [$this, 'onPipeMessage']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('request',      [$this, 'onRequest']);
        $this->server->on('Finish',       [$this, 'onFinish']);
        $this->server->on('Task',         [$this, 'onTask']);
        $this->server->on('start',        [$this, 'onStart']);

        return $this;
    }

    /**
     * 接受到一个请求
     *
     * @param \swoole_http_request $request
     * @param \swoole_http_response $response
     */
    public function onRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
        # 绑定对象
        $this->request  = $request;
        $this->response = $response;

        # 输出服务器头信息
        $response->header('Server', $this->config['server']['name'] ?: 'MQS');

        # 执行更新
        $this->site->injector->trigger(Site::EVENT_COMPATIBLE);
        $this->site->request->injector->trigger(Request::EVENT_INIT);

        ob_start();
        try
        {
            # 页面执行
            $this->site->reloadApp()->exec();
        }
        catch (\Exception $e)
        {
            $this->site->exceptionHandler($e);
        }
        $rs = ob_get_clean();

        if (!$response->isEnd)
        {
            $response->end($rs);
        }

        # 释放对象
        $this->request  = null;
        $this->response = null;

        return true;
    }

    public function onWorkerStop(\swoole_server $server, $workerId)
    {
        echo "WorkerStop, {$server->worker_pid}, {$workerId}\n";
    }

    /**
     * 进程启动
     *
     * @param \swoole_server $server
     * @param $workerId
     */
    public function onWorkerStart(\swoole_server $server, $workerId)
    {
        if($server->taskworker)
        {
            self::setProcessName("php {$this->argv[0]} task worker");
        }
        else
        {
            self::setProcessName("php {$this->argv[0]} event worker");
        }

        self::debug("WorkerStart, \$worker_id = {$workerId}, \$worker_pid = {$server->worker_pid}");

        # 加载框架首页面
        $this->site = $this->loadIndexPage();

        # 预加载文件
        $this->site->appFileList = $this->preLoadFileList();

        if ($workerId === 0)
        {
            # 监听管理进程的事件处理
            if ($this->managerProcess)
            {
                swoole_event_add($this->managerProcess->pipe, function($pipe)
                {
                    $data = $this->managerProcess->read();

                    switch ($data)
                    {
                        # 重启进程
                        case 'reload':
                        case 'restart':
                            $this->server->reload();
                            break;
                    }
                });
            }
        }

        if (!$server->taskworker)
        {
            # 增加事件回调
            $this->site->request->injector->on(Request::EVENT_INIT, function()
            {
                $request = $this->request();
                if (!$request)return;

                Request::$GET     = $request->get ?: [];
                Request::$POST    = $request->post ?: [];
                Request::$COOKIE  = $request->cookie ?: [];
                Request::$REQUEST = array_merge(Request::$GET, Request::$POST);

                # 更新请求参数
                $myRequest            = $this->site->request;
                $myRequest->cookie    = Request::sanitize(Request::$COOKIE);
                $myRequest->get       = Request::sanitize(Request::$GET);
                $myRequest->post      = Request::sanitize(Request::$POST);
                $myRequest->request   = Request::sanitize(Request::$REQUEST);
                $myRequest->files     = $request->files;
                $myRequest->input     = $request->rawContent() ?: null;
                $myRequest->header    = $request->header;
                $myRequest->method    = $request->server['request_method'];
                $myRequest->uri       = $request->server['request_uri'];
                $myRequest->pathInfo  = $request->server['path_info'];
                $myRequest->ip        = $request->server['remote_addr'];
                $myRequest->userAgent = $request->header['user_agent'];
                $myRequest->referer   = isset($request->header['referer']) ? $request->header['referer'] : null;
                $myRequest->time      = time();
                $myRequest->timeFloat = microtime(1);

                # 处理可能是json字符串的情况
                if ($myRequest->input && in_array($myRequest->input[0], ['[', '{']))
                {
                    $myRequest->inputJson = @json_decode($myRequest->input, true) ?: [];
                }
            });

            $this->site->response->injector->on(Response::EVENT_SENDHEADERS, [Response::DI_HEADERS, Response::DI_STATUS], function($headers, $status)
            {
                if (!$this->response())return;

                if ($status !== 200)
                {
                    $this->response()->status($status);
                }

                if ($headers)foreach ($headers as $name => $value)
                {
                    $this->response()->header($name, $value);
                }
            })
            ->on(Response::EVENT_END, [Response::DI_HTML], function($html)
            {
                if (!$this->response())return;

                $this->response()->end($html);

                # 标记为已经关闭,避免再次执行
                $this->response()->isEnd = true;
            })
            ->on(Response::EVENT_SEND, [Response::DI_HTML], function($html)
            {
                if (!$this->response())return;

                if (!$this->response()->isEnd)
                {
                    $this->response()->write($html);
                }
            });
        }
    }

    /**
     * 预加载文件列表
     *
     * @return array
     */
    protected function preLoadFileList()
    {
        # 读取语言包、配置、文件夹、路径等
        $fileList = [];
        $fun = function(){};
        $fun = function(& $fileList, $app, $appDir) use (& $fun)
        {
            foreach(glob($appDir .'*') as $file)
            {
                $fileName = basename($file);
                if (is_dir($file))
                {
                    $fun($fileList, $app, $file .'/');
                }
                else
                {
                    $fileNameArr = explode('.', $fileName);
                    $name  = $fileNameArr[0];
                    $count = count($fileNameArr);
                    if ($count === 3 && $fileNameArr[2] === 'php')
                    {
                        $type = $fileNameArr[1];
                    }
                    elseif ($count === 1)
                    {
                        # 无后缀
                        $type = '';
                    }
                    else
                    {
                        $type = array_pop($fileNameArr);
                        $name = implode('.', $fileNameArr);
                    }

                    $fileList[$app][$type][$name] = $file;
                }
            }
        };

        # 系统的文件
        $fun($fileList, '**system**', DIR_SYSTEM);

        # APP的文件
        foreach(glob(DIR_APP .'*') as $appDir)
        {
            if (is_file($appDir))continue;

            $app = basename($appDir);

            # 复制一份系统的路径
            $fileList[$app] = $fileList['**system**'];

            # 读取APP文件列表
            $fun($fileList, $app, $appDir .'/');
        }

        return $fileList;
    }

    public function onPipeMessage(\swoole_server $server, $fromWorkerId, $message)
    {

    }

    public function onFinish(\swoole_server $server, $task_id, $data)
    {

    }

    public function onTask(\swoole_server $server, $taskId, $fromId, $data)
    {
        $server->finish($data);
    }

    public function onStart(\swoole_server $server)
    {
        self::info("ServerStart, http://{$this->config['server']['host']}:{$this->config['server']['port']}/");
    }

    public function onManagerStart(\swoole_server $server)
    {

    }

    /**
     * @return \swoole_http_request
     */
    public function request()
    {
        return $this->request;
    }

    /**
     * @return \swoole_http_response
     */
    public function response()
    {
        return $this->response;
    }

    public static function info($info)
    {
        $beg = "\x1b[33m";
        $end = "\x1b[39m";
        echo $beg. date("[Y-m-d H:i:s]"). "[info]{$end} - ". $info. "\n";
    }

    public static function debug($info)
    {
        $beg = "\x1b[34m";
        $end = "\x1b[39m";
        echo $beg. date("[Y-m-d H:i:s]"). "[debug]{$end} - ". $info. "\n";
    }

    /**
     * 设置进程的名称
     *
     * @param $name
     */
    protected static function setProcessName($name)
    {
        if (function_exists('\cli_set_process_title'))
        {
            @cli_set_process_title($name);
        }
        else
        {
            if (function_exists('\swoole_set_process_name'))
            {
                @swoole_set_process_name($name);
            }
            else
            {
                trigger_error(__METHOD__ .' failed. require cli_set_process_title or swoole_set_process_name.');
            }
        }
    }

    /**
     * 获取站点对象
     *
     * @return Site
     */
    protected function loadIndexPage()
    {
        # 加载首页文件
        return require __DIR__ .'/../../../index.php';
    }
}

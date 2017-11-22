<?php
namespace MyQEE\Server;

use MyQEE\Server\Http\Response;
use MyQEE\Server\Http\Request;

class WorkerHttpRangeUpload extends WorkerHttp
{
    /**
     * 上传单文件最大大小
     *
     * 支持 K, M, G, T 后缀
     *
     * @var int
     */
    public $uploadMaxFileSize = '1G';

    protected $tmpDir;

    /**
     * 记录每个请求的buffer
     *
     * @var array
     */
    protected $_httpBuffers = [];

    /**
     * 表单POST时最大参数数量
     *
     * 可修改 php.ini 中的 `max_input_vars` 参数
     *
     * @var int
     */
    protected static $_maxInputVars;

    /**
     * 表单POST时最大数据大小
     *
     * 支持 K, M, G, T 后缀
     *
     * 可修改 php.ini 中的 `post_max_size` 参数
     *
     * 本参数不会约束上传文件的大小，上传文件的最大大小由 `$this->uploadMaxFileSize` 控制
     *
     * @var string
     */
    protected static $_postMaxSize;

    /**
     * 最大上传文件数
     *
     * 可修改 php.ini 中的 `post_max_size` 参数

     * @var int
     */
    protected static $_maxFileUploads;

    public function __construct($arguments)
    {
        parent::__construct($arguments);

        if (isset($this->setting['max_size']))
        {
            $this->uploadMaxFileSize = $this->setting['max_size'];
        }
        $this->uploadMaxFileSize = self::_conversionToByte($this->uploadMaxFileSize, 1073741824);

        if (null === $this->tmpDir)
        {
            # 设置临时目录
            $this->tmpDir = $this->setting['conf']['upload_tmp_dir'];
        }

        self::$_maxInputVars   = ini_get('max_input_vars') ?: 1000;
        self::$_maxFileUploads = ini_get('max_file_uploads') ?: 20;
        self::$_postMaxSize    = self::_conversionToByte(ini_get('post_max_size'), 8388608);


        # 每小时清理1次上传的临时分片文件
        if ($this->id == 0)
        {
            swoole_timer_tick(3600 * 1000, function()
            {
                $time = time();
                foreach (glob($this->tmpDir.'tmp-http-upload-content-*.tmp') as $file)
                {
                    if ($time - filemtime($file) > 3600)
                    {
                        $size = filesize($file);
                        $rs   = @unlink($file);

                        $tmpFileSt = "{$file}.pos";
                        if (is_file($tmpFileSt))
                        {
                            @unlink($tmpFileSt);
                        }

                        if ($rs)
                        {
                            $this->debug("auto remove range upload tmp file: {$file}, size: {$size}");
                        }
                        else
                        {
                            $this->warn("auto remove range upload tmp file fail: {$file}, size: {$size}");
                        }
                    }
                }
            });
        }

        if ($this !== static::$Server->worker && ($this->id == 0 || SWOOLE_BASE == static::$Server->serverMode))
        {
            # SWOOLE_BASE 模式下只能获取当前进程的连接，所以需要每个进程都去遍历，其它模式会获取全部连接，所以只需要 $this->id = 0 的去遍历
            # 移除不活跃的链接

            $this->timeTick(($this->setting['conf']['heartbeat_check_interval'] ?: 60) * 1000, function()
            {
                $time    = time();
                $fd      = 0;
                $startFd = 0;
                $timeout = ($this->setting['conf']['heartbeat_idle_time'] ?: 180) + 5;

                while(true)
                {
                    $list  = $this->server->connection_list($startFd, 10);
                    $count = count($list);
                    if($list === false || $count === 0)
                    {
                        break;
                    }

                    foreach($list as $fd)
                    {
                        $connectionInfo = $this->server->connection_info($fd);
                        if ($time - $connectionInfo['last_time'] > $timeout)
                        {
                            $this->debug('close timeout client #'. $fd);
                            $this->server->close($fd);
                        }
                    }

                    $startFd = $fd;

                    # 没有拿到10个
                    if ($count < 10)break;
                }
            });
        }
    }

    /**
     * 当收到 header 请求时回调
     *
     * 用于返回是否需要继续给客户端上传， false 则关闭连接
     *
     * @param \Swoole\Http\Request $request
     * @return bool
     */
    public function onBeforeUpload($request)
    {
        return true;
    }

    /**
     * HTTP 接口上传处理完成后回调
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return null|\Generator
     */
    public function onUpload($request, $response)
    {

    }

    /**
     * @param \Swoole\Server $server
     * @param $fd
     * @param $fromId
     * @param $data
     * @return null|\Generator
     */
    public function onReceive($server, $fd, $fromId, $data)
    {
        try
        {
            if (!isset($this->_httpBuffers[$fd]))
            {
                list($method) = explode(' ', substr($data, 0, 7));
                $headerPos = strpos($data, "\r\n\r\n");
                if ($headerPos > 8192 || (!$headerPos && strlen($data) > 8192))
                {
                    # header 太长
                    throw new \Exception('Header Too Large', 400);
                }
                else
                {
                    $buffer = $this->_createHttpBuffer($server, $fd, $fromId, $data);

                    if (false === $buffer)
                    {
                        return null;
                    }

                    if ($headerPos)
                    {
                        # 处理头信息, $headerPos + 4 是附带 \r\n\r\n 字符
                        $rs = $this->_parseHeader($buffer, $headerPos + 4);

                        if ($buffer->status === 2)
                        {
                            # 状态是2的则表示已经处理完毕
                            return $rs;
                        }
                    }
                }

                switch ($method)
                {
                    case 'POST':
                    case 'PUT':
                        $this->_httpBuffers[$fd] = $buffer;
                        return null;

                    case 'GET':
                    case 'OPTIONS':
                    case 'HEAD':
                    case 'DELETE':
                    case 'CONNECT':
                        # 直接调用相应的请求
                        return $this->onRequest($buffer->request, $buffer->response);

                    default:
                        # 未知类型错误
                        throw new \Exception('Unknown Method', 502);
                }
            }
            else
            {
                $buffer = $this->_httpBuffers[$fd];
            }

            # 更新获取到数据的长度
            $buffer->acceptLength += strlen($data);

            if ($buffer->status === 0)
            {
                # 头信息还没获取完整

                # 把内容拼接起来
                $data = $buffer->data . $data;

                $buffer->request->data = $data;
                # 更新最后获取数据的时间
                $buffer->request->server['request_time']       = time();
                $buffer->request->server['request_time_float'] = microtime(1);

                # 重新获取头信息
                $headerPos = strpos($data, "\r\n\r\n");

                if (false === $headerPos)
                {
                    if(strlen($data) > 8192)
                    {
                        throw new \Exception('Header Too Large', 400);
                    }
                    else
                    {
                        # 头信息还没收完整，返回等待继续
                        return null;
                    }
                }
                elseif ($headerPos > 8192)
                {
                    # header 太长
                    throw new \Exception('Header Too Large', 400);
                }
                else
                {
                    # 处理头信息, $headerPos + 4 是附带 \r\n\r\n 字符
                    unset($buffer, $data);
                    return $this->_parseHeader($fd, $headerPos + 4);
                }
            }
            else
            {
                return $this->_parseBody($buffer, $data);
            }
        }
        catch (\Exception $e)
        {
            $response = $this->_getResponseByFd($fd);
            $response->status($e->getCode());
            $response->header('Connection', 'close');
            $response->end($e->getMessage());
            swoole_timer_after(10, function() use ($fd, $fromId)
            {
                $this->server->close($fd, $fromId);
            });

            unset($this->_httpBuffers[$fd]);
        }
    }

    public function onClose($server, $fd, $fromId)
    {
        unset($this->_httpBuffers[$fd]);
    }

    /**
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     * @param string $data
     * @throws \Exception
     * @return \stdClass|false
     */
    protected function _createHttpBuffer($server, $fd, $fromId, $data)
    {
        $mSize = strpos($data, "\r\n");
        if (!$mSize)
        {
            throw new \Exception('Bad Request', 400);
        }

        list($method, $uri, $protocol) = explode(' ', trim(substr($data, 0, $mSize)));

        $connectionInfo = $server->connection_info($fd, $fromId);

        if (false === $connectionInfo)
        {
            return false;
            # 连接已关闭
        }

        # 构造一个 Request
        $uriArr          = explode('?', $uri);
        $request         = new Request();
        $request->fd     = $fd;
        $request->header = [];
        $request->data   = $data;
        $request->server = [
            'request_method'     => strtoupper($method),
            'query_string'       => isset($uriArr[1]) ? $uriArr[1] : null,
            'request_uri'        => $uriArr[0],
            'path_info'          => $uriArr[0],
            'connect_time'       => $connectionInfo['connect_time'],
            'request_time'       => time(),
            'request_time_float' => microtime(1),
            'server_port'        => $connectionInfo['server_port'],
            'remote_addr'        => $connectionInfo['remote_ip'],
            'remote_port'        => $connectionInfo['remote_port'],
            'server_protocol'    => $protocol,
            'server_software'    => 'http-upload-server',
        ];

        if (isset($uriArr[1]))
        {
            /**
             * @var \Swoole\Http\Request $request
             */
            parse_str($uriArr[1], $request->get);
        }

        # 构造一个 Response
        $response = $this->_getResponseByFd($fd);

        # 创建一个对象记录数据
        $buffer = new \stdClass();

        $buffer->status        = 0;                 # 当前接受数据的状态 0 - header , 1 - body, 2 - done
        $buffer->headerLength  = 0;                 # 头信息长度
        $buffer->contentLength = 0;                 # Content-Length 设置的长度
        $buffer->acceptLength  = strlen($data);     # 已接收到的数据长度
        $buffer->mSize         = $mSize;            # http请求的第一行长度
        $buffer->range         = false;             # range 分片断点续传的参数 [from, to, size]
        $buffer->formPost      = false;             # 表单提交 Content-Type: application/x-www-form-urlencoded
        $buffer->formBoundary  = false;             # enctype="multipart/form-data" 的表单提交（含文件上传） Content-Type: multipart/form-data; boundary=----WebKitFormBoundaryTwDKIVhCZbV4YF5Brange 分片断点续传的参数 [from, to, size]
        $buffer->request       = $request;
        $buffer->response      = $response;

        return $buffer;
    }

    /**
     * @param \Swoole\Server $server
     * @param int $fd
     * @param $fromId
     * @param $data
     */
    protected function _parseHeader($buffer, $headerSize)
    {
        /**
         * @var \Swoole\Http\Request $request
         */
        $request              = $buffer->request;
        $buffer->status       = 1;
        $buffer->headerLength = $headerSize;

        foreach (explode("\r\n", substr($request->data, $buffer->mSize, $buffer->headerLength)) as $item)
        {
            if (!$item || !strpos($item, ':')) continue;
            list($k, $v) = explode(':', $item, 2);
            $k = strtolower(trim($k));
            $v = trim($v);

            switch ($k)
            {
                case 'content-length':
                    $buffer->contentLength = $v = intval($v);

                    if ($buffer->contentLength > $this->uploadMaxFileSize)
                    {
                        throw new \Exception('File size is too big', 413);
                    }
                    break;
                case 'content-type':
                    if ($v == 'application/x-www-form-urlencoded')
                    {
                        # post 提交的数据
                        $buffer->formPost = true;
                    }
                    elseif (preg_match('#^multipart/form-data; boundary=(.*)$#', $v, $m))
                    {
                        # multipart/form-data; boundary=----WebKitFormBoundaryTwDKIVhCZbV4YF5B
                        $buffer->formBoundary = '--'. $m[1];
                    }

                    break;

                case 'cookie':
                    # 解析 cookie 参数
                    parse_str(str_replace(['; ', ';'], '&', $v), $request->cookie);
                    continue 2;
            }

            $request->header[$k] = $v;
        }

        if (!in_array($request->server['request_method'], ['POST', 'PUT']))
        {
            $buffer->status = 2;
            return null;
        }

        if (!isset($request->header['content-length']))
        {
            throw new \Exception('Length Required', 411);
        }

        if ($buffer->formPost)
        {
            # 普通表单提交方式（纯数据无文件）
            if ($buffer->contentLength > self::$_postMaxSize)
            {
                # 不允许超过 php.ini 中设置的大小
                throw new \Exception('Post data Too Large', 400);
            }
        }

        if (isset($request->header['content-range']))
        {
            $buffer->range = $this->_checkRangeHeader($request, $request->header['content-range']);
        }
        elseif (isset($request->header['x-content-range']))
        {
            $buffer->range = $this->_checkRangeHeader($request, $request->header['x-content-range']);
        }

        if (!$this->onBeforeUpload($request))
        {
            # 判断是否可以继续
            throw new \Exception('Expectation Failed', 417);
        }

        if (isset($request->header['expect']) && $request->header['expect'] === '100-continue')
        {
            # 支持 100-continue
            # 返回状态后，客户端会立即发送数据上来
            $this->server->send($request->fd, "HTTP/1.1 100 Continue\r\n\r\n");
        }

        # 已经接受到的 body 的部分
        $acceptBodyLength = $buffer->acceptLength - $buffer->headerLength;
        if ($acceptBodyLength == $buffer->contentLength)
        {
            # 数据接受完毕
            $buffer->status = 2;
        }
        elseif ($acceptBodyLength > $buffer->contentLength)
        {
            # 上传的实体大于 Content-Length，说明有问题
            # 例如这个请求 curl 'http://127.0.0.1:9120/' -i --upload-file test.txt -d 'abc'
            # 会将文件上传上来，但是 Content-Length 却是 -d 参数的长度（3）
            throw new \Exception('Bad Request', 400);
        }

        if ($acceptBodyLength || $buffer->status == 2)
        {
            $body = substr($request->data, $buffer->headerLength);
            if ($body)
            {
                # 只保留头信息部分，节约内存
                $request->data = substr($request->data, 0, $buffer->headerLength);
            }

            unset($request);
            return $this->_parseBody($buffer, $body);
        }

        return null;
    }

    /**
     * @param \stdClass $buffer
     * @param string $body 新接受来的数据
     */
    protected function _parseBody($buffer, $body)
    {
        # 已经接受到的 body 的部分
        $acceptBodyLength = $buffer->acceptLength - $buffer->headerLength;
        if ($acceptBodyLength == $buffer->contentLength)
        {
            # 数据接受完毕
            $buffer->status = 2;
        }
        elseif ($acceptBodyLength > $buffer->contentLength)
        {
            # 上传的实体大于 Content-Length，说明有问题
            # 例如这个请求 curl 'http://127.0.0.1:9120/' -i --upload-file test.txt -d 'abc'
            # 会将文件上传上来，但是 Content-Length 却是 -d 参数的长度（3）
            throw new \Exception('Bad Request', 400);
        }

        $rs = null;

        if ($buffer->range)
        {
            $rs = $this->_parseRangeUpload($buffer, $body);
        }
        elseif ($buffer->formPost)
        {
            # 表单提交数据

            $buffer->tmpBody .= $body;
            if ($buffer->status == 2)
            {
                # 数据接受完毕

                if (substr_count($buffer->tmpBody, '&') >= self::$_maxInputVars)
                {
                    throw new \Exception('Too Much Post Items', 400);
                }

                /**
                 * @var \Swoole\Http\Request $request
                 */
                $request  = $buffer->request;
                $response = $buffer->response;

                # 解析成数组
                parse_str($buffer->tmpBody, $request->post);

                # 释放对象
                unset($this->_httpBuffers[$request->fd], $buffer);

                # 页面完成
                $rs = $this->onUpload($request, $response);
            }
        }
        elseif ($buffer->formBoundary)
        {
            $rs = $this->_parseFormBoundary($buffer, $body);
        }
        else
        {
            $contentType        = isset($buffer->request->header['content-type']) ? $buffer->request->header['content-type'] : 'application/octet-stream';
            $contentDisposition = isset($buffer->request->header['content-disposition']) ? $buffer->request->header['content-disposition'] : '';
            # 完整的文件上传
            /*
            POST /upload HTTP/1.1
            Host: example.com
            Content-Length: 10000
            Content-Type: application/octet-stream
            Content-Disposition: attachment; filename="name.txt"

            <文件数据>
             */
            $buffer->tmpBody .= $body;
            $tmpFile    = $this->tmpDir .'tmp-http-upload-content-'. md5(microtime(1). $contentType. $contentDisposition) .'.tmp';

            if (false === @file_put_contents($tmpFile, $buffer->tmpBody))
            {
                # 写入失败
                throw new \Exception('Save tmp file fail', 415);
            }

            /**
             * @var \Swoole\Http\Request $request
             * @var \Swoole\Http\Response $response
             */
            $request  = $buffer->request;
            $response = $buffer->response;

            # 全部上传完毕
            if ($contentDisposition && preg_match('#^attachment; filename=(.*)$#i', $contentDisposition, $m))
            {
                # 读取文件名
                $fileName = trim($m[1], '\'"');
            }
            else
            {
                $fileName = 'unknown.tmp';
            }

            # 给 request 加一个文件信息
            $request->files['upload'] = [
                'name'     => $fileName,
                'type'     => $contentType,
                'tmp_name' => $tmpFile,
                'error'    => 0,
                'size'     => strlen($buffer->tmpBody),
            ];

            unset($this->_httpBuffers[$request->fd], $buffer);
            $rs = $this->onUpload($request, $response);
        }

        return $rs;
    }

    /**
     * 解析 FormBoundary 格式的数据
     *
     * @param \stdClass $buffer
     * @param string $data 新接受来的数据
     * @return mixed
     */
    protected function _parseFormBoundary($buffer, $data)
    {
        /**
         * @var \Swoole\Http\Request $request
         * @var Response $response
         */
        $request  = $buffer->request;
        $response = $buffer->response;

        if (isset($buffer->tmpBody))
        {
            $data            = $buffer->tmpBody . $data;
            $buffer->tmpBody = $data;
        }

        if ($buffer->status == 2)
        {
            # 将结尾的 -- 移除
            $data = rtrim($data, "\r\n-");
        }

        /*
        例：
        POST /upload HTTP/1.1
        Host: 127.0.0.1:9120
        Connection: keep-alive
        Content-Length: 415
        Content-Type: multipart/form-data; boundary=----WebKitFormBoundary6p4ZnGPDUu7qmVtR

        ------WebKitFormBoundary6p4ZnGPDUu7qmVtR
        Content-Disposition: form-data; name="test"

        aaa
        bbb
        ------WebKitFormBoundary6p4ZnGPDUu7qmVtR
        Content-Disposition: form-data; name="upload"; filename="create.log"
        Content-Type: application/octet-stream

        {"cid":80,"sid":0,"account":"","pid":173,"time":1480984700,"ip":"218.6.70.174","name":"无敌小宁#74","guest":0}


        ------WebKitFormBoundary6p4ZnGPDUu7qmVtR--
        */

        /**
         * @var \Swoole\Http\Request $request
         * @var Response $response
         */
        $offset             = 0;
        $buffer->tmpIsFile  = isset($buffer->tmpIsFile) ? $buffer->tmpIsFile : false;
        $buffer->tmpIsValue = isset($buffer->tmpIsValue) ? $buffer->tmpIsValue : false;
        $buffer->tmpName    = isset($buffer->tmpName) ? $buffer->tmpName : null;

        while(true)
        {
            $pos = strpos($data, "\r\n", $offset);
            if (false === $pos)
            {
                # 读取到最后
                if ($offset > 0)
                {
                    $buffer->tmpBody = substr($data, $offset);

                    if ($buffer->tmpBody == $buffer->formBoundary)
                    {
                        # 到达结束位置
                        unset($buffer->tmpBody);
                    }
                }
                break;
            }

            $tmp = substr($data, $offset, $pos - $offset);
            $offset = $pos + 2;

            if (!$buffer->tmpIsValue && $tmp === '')
            {
                # 第一个换行符
                $buffer->tmpIsValue = true;
                continue;
            }
            elseif ($buffer->formBoundary === $tmp)
            {
                # 遇到新的分隔符
                $buffer->tmpIsValue = false;
                $buffer->tmpName    = null;
                unset($buffer->tmpValue);
                continue;
            }

            if ($buffer->tmpIsValue)
            {
                # 处理数据部分
                if (null === $buffer->tmpName)continue;

                if ($buffer->tmpIsFile)
                {

                    if (0 != $buffer->tmpValue['size'])
                    {
                        $tmp = "\r\n" . $tmp;
                    }

                    $rs = @file_put_contents($buffer->tmpValue['tmp_name'], $tmp, FILE_APPEND);
                    if (false === $rs)
                    {
                        # 写入失败
                        $buffer->tmpValue['error'] = 1;
                    }
                    else
                    {
                        $buffer->tmpValue['size'] += $rs;
                    }
                }
                else
                {
                    if (empty($value))
                    {
                        $buffer->tmpValue = $tmp;
                    }
                    else
                    {
                        $buffer->tmpValue .= "\r\n" . $tmp;
                    }
                }
            }
            else
            {
                # 解析开头数据
                if (!strpos($tmp, ':'))
                {
                    Server::$instance->debug('Ignore unknown FormBoundary: ' . $tmp);
                    continue;
                }

                list($k1, $v1) = explode(':', $tmp, 2);

                switch ($k1)
                {
                    case 'Content-Disposition':
                        if (preg_match('#^form-data; name="([^"]+)"(?:; filename="(.*)")?$#i', trim($v1), $m))
                        {
                            # 读取文件名
                            $buffer->tmpName = $m[1];

                            if (isset($m[2]))
                            {
                                $buffer->tmpIsFile = true;
                                $buffer->tmpFileCount++;

                                if ($buffer->tmpFileCount > self::$_maxFileUploads)
                                {
                                    throw new \Exception('Too Much Files', 400);
                                }
                            }
                            else
                            {
                                $buffer->tmpIsFile = false;
                                $buffer->tmpPostCount++;

                                if ($buffer->tmpPostCount > self::$_maxInputVars)
                                {
                                    throw new \Exception('Too Much Post Items', 400);
                                }
                            }

                            if (strpos($buffer->tmpName, '['))
                            {
                                # name = abc[def][] 这种格式
                                $nameArr = explode("\n", str_replace(['][', ']', '['], "\n", rtrim($buffer->tmpName, ']')));

                                if ($buffer->tmpIsFile)
                                {
                                    if (!isset($request->files))$request->files = [];
                                    $parentKey = array_shift($nameArr);

                                    if (!isset($request->files[$parentKey]) || !is_array($request->files[$parentKey]) || !is_array($request->files[$parentKey]['name']))
                                    {
                                        # 一个 name = abc 的文件后又一个 name = abc[def] 的文件，这样会覆盖前者
                                        $request->files[$parentKey] = [
                                            'name'     => [],
                                            'type'     => [],
                                            'tmp_name' => [],
                                            'error'    => [],
                                            'size'     => [],
                                        ];
                                    }

                                    $buffer->tmpValue =& $request->files[$parentKey];

                                    foreach ($nameArr as $tmpKey)
                                    {
                                        if (!is_array($buffer->tmpValue['name']))
                                        {
                                            $buffer->tmpValue['name']     = [];
                                            $buffer->tmpValue['type']     = [];
                                            $buffer->tmpValue['tmp_name'] = [];
                                            $buffer->tmpValue['error']    = [];
                                            $buffer->tmpValue['size']     = [];
                                        }

                                        if ('' === $tmpKey)
                                        {
                                            # 自增 abc[]
                                            $buffer->tmpValue['name'][] = '';
                                            end($buffer->tmpValue['name']);
                                            $tmpKey = key($buffer->tmpValue['name']);
                                        }

                                        if (!isset($buffer->tmpValue['name'][$tmpKey]))
                                        {
                                            # 初始化数据
                                            $buffer->tmpValue['name'][$tmpKey]     = '';
                                            $buffer->tmpValue['type'][$tmpKey]     = '';
                                            $buffer->tmpValue['tmp_name'][$tmpKey] = '';
                                            $buffer->tmpValue['error'][$tmpKey]    = 0;
                                            $buffer->tmpValue['size'][$tmpKey]     = 0;
                                        }

                                        # 更新引用
                                        $tmpValue             = [];
                                        $tmpValue['name']     =& $buffer->tmpValue['name'][$tmpKey];
                                        $tmpValue['type']     =& $buffer->tmpValue['type'][$tmpKey];
                                        $tmpValue['tmp_name'] =& $buffer->tmpValue['tmp_name'][$tmpKey];
                                        $tmpValue['error']    =& $buffer->tmpValue['error'][$tmpKey];
                                        $tmpValue['size']     =& $buffer->tmpValue['size'][$tmpKey];

                                        unset($buffer->tmpValue);
                                        $buffer->tmpValue =& $tmpValue;
                                        unset($tmpValue);
                                    }
                                }
                                else
                                {
                                    $buffer->tmpValue =& $request->post;
                                    foreach ($nameArr as $tmpKey)
                                    {
                                        if (!is_array($buffer->tmpValue))
                                        {
                                            $buffer->tmpValue = [];
                                        }

                                        if ('' === $tmpKey)
                                        {
                                            # 自增 abc[]
                                            $buffer->tmpValue[] = '';
                                            end($buffer->tmpValue);
                                            $tmpKey = key($buffer->tmpValue);
                                        }
                                        elseif (!isset($buffer->tmpValue[$tmpKey]))
                                        {
                                            # 指定key abc[def]
                                            $buffer->tmpValue[$tmpKey] = '';
                                        }

                                        # 用下一级的数据
                                        $tmpValue =& $buffer->tmpValue[$tmpKey];
                                        unset($buffer->tmpValue);
                                        $buffer->tmpValue =& $tmpValue;
                                        unset($tmpValue);
                                    }
                                }
                            }
                            else
                            {
                                if ($buffer->tmpIsFile)
                                {
                                    $request->files[$buffer->tmpName] = [
                                        'name'     => null,
                                        'type'     => null,
                                        'tmp_name' => null,
                                        'error'    => 0,
                                        'size'     => 0,
                                    ];
                                    $buffer->tmpValue =& $request->files[$buffer->tmpName];
                                }
                                else
                                {
                                    if (!isset($request->post[$buffer->tmpName]))
                                    {
                                        $request->post[$buffer->tmpName] = '';
                                    }
                                    $buffer->tmpValue =& $request->post[$buffer->tmpName];
                                }
                            }

                            if ($buffer->tmpIsFile)
                            {
                                $buffer->tmpValue['name']     = $m[2];
                                $buffer->tmpValue['tmp_name'] = $this->tmpDir .'tmp-http-upload-content-' . md5(microtime(1) .$tmp) .'.tmp';
                            }
                        }
                        else
                        {
                            Server::$instance->debug('Ignore unknown Content-Disposition: '. trim($v1));
                            $buffer->tmpName = null;
                        }

                        break;

                    case 'Content-Type':
                        $buffer->tmpValue['type'] = trim($v1);
                        break;
                }
            }
        }

        if ($buffer->status == 2)
        {
            # 回调
            unset($this->_httpBuffers[$request->fd], $buffer, $data, $tmp);

            return $this->onUpload($request, $response);
        }

        return null;
    }

    /**
     * 解析分片、断点续传数据
     *
     * @param \stdClass $buffer
     * @param string $data 新接受来的数据
     * @return mixed
     */
    protected function _parseRangeUpload($buffer, $data)
    {
        /**
         * @var \Swoole\Http\Request $request
         * @var Response $response
         */
        $request  = $buffer->request;
        $response = $buffer->response;
        $length   = strlen($data);

        list($from, $to, $allSize) = $buffer->range;

        $tmpFile = $this->tmpDir. 'tmp-http-upload-content-'. md5($request->header['x-session-id'] .'_'. $request->header['content-disposition'] .'_'. $allSize) .'.tmp';
        $rs      = self::_writeRangeUploadFile($tmpFile, $from, $length, $data);
        if ($rs)
        {
            if ($length < $to - $from + 1)
            {
                # 收到的包还没完整，先写一部分
                return null;
            }

            if (self::_rangeUploadFileIsDone("{$tmpFile}.pos", $allSize))
            {
                # 全部上传完毕
                if (preg_match('#^attachment; filename=(.*)$#i', $request->header['content-disposition'], $m))
                {
                    # 读取文件名
                    $fileName = trim($m[1], '\'"');
                }
                else
                {
                    $fileName = 'unknown.tmp';
                }

                # 给 request 加一个文件信息
                $request->files['upload'] = [
                    'name'     => $fileName,
                    'type'     => $request->header['content-type'],
                    'tmp_name' => $tmpFile,
                    'error'    => 0,
                    'size'     => $allSize,
                ];

                if (is_file("$tmpFile.pos"))
                {
                    # 移除临时 pos 文件
                    unlink("$tmpFile.pos");
                }

                # 调用 onUpload 处理
                unset($this->_httpBuffers[$request->fd], $buffer);

                return $this->onUpload($request, $response);
            }
            else
            {
                $writeRange = "bytes {$from}-".($from + $rs - 1)."/{$allSize}";
                $response->status($from === 0 ? 201 : 202);         # 201 为 created 类型
                $response->header('Range', $writeRange);
                $response->end($writeRange);
            }
        }
        else
        {
            throw new \Exception('Save content fail', 501);
        }

        return null;
    }

    /**
     * @param \Swoole\Http\Request $request
     * @throws \Exception
     * @return array
     */
    protected function _checkRangeHeader($request, $range)
    {
        if (!isset($request->header['x-session-id']) || !$request->header['x-session-id'])
        {
            throw new \Exception('X-Session-Id Required', 400);
        }

        if (preg_match('#bytes (\d+)\-(\d+)/(\d+)#', $range, $m))
        {
            list(, $from, $to, $allSize) = $m;

            if ($allSize > $this->uploadMaxFileSize)
            {
                throw new \Exception('File size is too big', 413);
            }

            # 上传内容的长度
            $length = $request->header['content-length'];

            if ($to >= $allSize || $from >= $to || $length != $to - $from + 1)
            {
                # 检查分片参数
                throw new \Exception("Error Range $range", 400);
            }

            return [$from, $to, $allSize];
        }
        else
        {
            throw new \Exception("Error Range $range", 400);
        }
    }

    /**
     * 根据Fd获取 Response 对象
     *
     * @param int $fd
     * @return Response
     */
    protected function _getResponseByFd($fd)
    {
        $response = new Response();
        $response->header('Server', static::$Server->config['hosts'][$this->name]['name'] ?: 'MQSRV');
        $response->fd        = $fd;
        $response->keepAlive = $this->setting['conf']['heartbeat_idle_time'] ?: 180;

        return $response;
    }

    /**
     * 判断一个断点续传的文件是否全部上传完毕
     *
     * @param $posFile
     * @param $allSize
     * @return bool
     */
    protected static function _rangeUploadFileIsDone($posFile, $allSize)
    {
        $allPos = file_get_contents($posFile);
        if ($allPos)
        {
            $pos = [];
            $max = 0;
            $min = $allSize;
            foreach (explode("\n", $allPos) as $item)
            {
                $item = trim($item);
                if ($item)
                {
                    list($f, $t) = explode('-', $item);

                    $f     = intval($f);
                    $t     = intval($t);
                    $pos[] = [$f, $t];
                    $min   = min($f, $min);
                    $max   = max($t, $min);
                }
            }

            # 没有0节点的位置或者最大pos比文件尺寸还小，肯定没有完成
            if ($min > 0 || $max + 1 < $allSize)return false;

            # 移除重复内容
            $pos = array_unique($pos, SORT_REGULAR);

            # 先按起始位置排个序
            usort($pos, function($a, $b)
            {
                if ($a[0] == $b[0])
                {
                    return 0;
                }
                return ($a[0] < $b[0]) ? -1 : 1;
            });

            $maxPos = 0;
            $count  = count($pos);
            for ($i = 1; $i <= $count; $i++)
            {
                $j       = $i - 1;
                $current = $pos[$j];
                if ($i == $count)
                {
                    # 最后1个，如果结尾位置和文件尺寸匹配则返回 true
                    if ($current[1] >= $allSize - 1)return true;
                }
                $next = $pos[$i];

                if ($maxPos + 1 < $next[0] && $current[1] + 1 < $next[0])
                {
                    # 当前的结尾位置以及历史最高位置 和 下一个的开始位置是否衔接，如果不衔接则返回 false
                    return false;
                }

                # 记录一个最后成功的位置
                $maxPos = max($maxPos, $current[1]);
            }

            return false;
        }
        else
        {
            return false;
        }
    }

    protected static function _writeRangeUploadFile($file, $offset, $len, $data)
    {
        if (!is_file($file))
        {
            $fp = @fopen($file, 'w+');
        }
        else
        {
            $fp = @fopen($file, 'r+');
        }

        if (!$fp)return false;

        if ($offset)
        {
            fseek($fp, $offset);
        }
        $rs = @fwrite($fp, $data, $len);

        if ($rs)
        {
            # 写入 pos 成功的位置
            file_put_contents("{$file}.pos", "{$offset}-". ($offset + $rs - 1) ."\n", FILE_APPEND);
            return $rs;
        }
        else
        {
            return false;
        }
    }

    /**
     * 转换单位到字节数
     *
     * @param $size
     * @param $default
     * @return int
     */
    protected static function _conversionToByte($size, $default)
    {
        if (preg_match('#^(\d+)(M|K|G|T)$#i', $size, $m))
        {
            switch (strtoupper($m[2]))
            {
                case 'T':
                    $size = 1024 * 1024 * 1024 * 1024 * $m[1];
                    break;
                case 'G':
                    $size = 1024 * 1024 * 1024 * $m[1];
                    break;
                case 'M':
                    $size = 1024 * 1024 * $m[1];
                    break;
                case 'K':
                    $size = 1024 * $m[1];
                    break;
            }

            return $size;
        }
        else
        {
            $size = (int)$size ?: $default;
        }

        return $size;
    }
}
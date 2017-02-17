<?php
namespace MyQEE\Server\Http;

use MyQEE\Server\Server;

class Response extends \Swoole\Http\Response
{
    public $fd = 0;

    /**
     * @var \Swoole\Http\Request
     */
    public $request;

    /**
     * @var array
     */
    public $header = [];

    public $cookie = [];

    /**
     * 默认超时时间
     *
     * @var int
     */
    public $keepAlive = 180;

    protected $headerIsSend = false;

    protected $status = 200;

    protected $gzipLevel = 0;

    protected $chunked = false;

    protected $isClose = false;

    /**
     * HTTP status codes and messages
     *
     * @var array
     */
    public static $messages = [
        // Informational 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',

        // Success 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',

        // Redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Moved Temporarily',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        // 306 is deprecated but reserved
        307 => 'Temporary Redirect',

        // Client Error 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',

        // Server Error 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        507 => 'Insufficient Storage',
        509 => 'Bandwidth Limit Exceeded'
    ];

    public function __destruct()
    {
        if (!$this->isClose)
        {
            $this->end();
        }
    }

    public function sendHeader($contentLength = null)
    {
        if ($this->headerIsSend)return;

        # 只执行一次
        $this->headerIsSend = true;

        # 拼接投信息
        $head = "HTTP/1.1 {$this->status} ". self::$messages[$this->status] ."\r\n";
        $header = $this->header + [
            'Server' => 'aaa',
            'Connection'   => 'keep-alive',
            'Date'         => date('D, d M Y H:i:s \G\M\T'),
            'Content-Type' => 'text/html',
        ];

        if ($header['Connection'] === 'keep-alive')
        {
            $header['Keep-Alive'] = $this->keepAlive;
        }

        if ($this->chunked)
        {
            $header['Transfer-Encoding'] = 'chunked';
        }
        elseif ($contentLength)
        {
            $header['Content-Length'] = $contentLength;
        }

        foreach ($header as $k => $v)
        {
            $head .= "{$k}: {$v}\r\n";
        }

        if ($this->cookie)
        {
            foreach (array_unique($this->cookie) as $cookie)
            {
                $head .= "Set-Cookie: {$cookie}\r\n";
            }
        }

        if ($this->gzipLevel)
        {
            $head .= "Content-Encoding: gzip\r\n";
        }

        $head .= "\r\n";

        # 发送头信息
        Server::$instance->server->send($this->fd, $head);
    }

    /**
     * 启用Http-Chunk分段向浏览器发送数据
     *
     * @param $html
     */
    public function write($html)
    {
        if ($this->isClose)
        {
            Server::$instance->warn('Http request is end.');
            return;
        }

        if (!$this->headerIsSend)
        {
            $this->chunked = true;
            $this->sendHeader();
        }

        if ($this->gzipLevel)
        {
            $html = gzencode($html, $this->gzipLevel);
        }

        $html = dechex(strlen($html)) ."\r\n". $html ."\r\n";

        Server::$instance->server->send($this->fd, $html);
    }

    /**
     * 结束Http响应，发送HTML内容
     *
     * @param string $html
     */
    public function end($html = '')
    {
        if ($this->isClose)
        {
            Server::$instance->warn('Http request is end.');
            return;
        }
        $this->isClose = true;

        if (!$this->chunked)
        {
            if ($this->gzipLevel)
            {
                $html = gzencode($html, $this->gzipLevel);
            }

            if (!$this->headerIsSend)
            {
                $this->sendHeader(strlen($html));
            }

            if ($html)
            {
                Server::$instance->server->send($this->fd, $html);
            }
        }
        else
        {
            if (!$this->headerIsSend)
            {
                $this->sendHeader();
            }

            Server::$instance->server->send($this->fd, "0\r\n\r\n");
        }

        //Server::$instance->server->close($this->fd);
    }

    /**
     * 发送文件到浏览器
     *
     *  * $filename 要发送的文件名称，文件不存在或没有访问权限sendfile会失败
     *  * 底层无法推断要发送文件的MIME格式因此需要应用代码指定Content-Type
     *  * 调用sendfile后会自定执行end，中途不得使用Http-Chunk
     *  * sendfile不支持gzip压缩
     *
     * @param $filename
     * @return bool
     */
    public function sendfile($filename, $offset = null)
    {
        if (!is_file($filename))return false;
        if (substr($filename, -4) === '.php')return false;

        if (!$this->headerIsSend)
        {
            $this->sendHeader(filesize($filename));
        }

        return Server::$instance->server->sendfile($this->fd, $filename, $offset);
    }

    /**
     * 设置Http头信息
     *
     * @param $key
     * @param $value
     */
    public function header($key, $value, $ucwords = null)
    {
        $k = explode('-', strtolower($key));
        $h = function(& $v, $k)
        {
            $v = ucfirst($v);
        };
        array_walk($k, $h);
        $key = implode('-', $k);

        if ($key === 'Set-Cookie')
        {
            $this->cookie[] = $value;
            return;
        }

        $this->header[$key] = $value;
    }

    /**
     * 设置Cookie
     *
     * @param string $key
     * @param string $value
     * @param int    $expire
     * @param string $path
     * @param string $domain
     * @param bool   $secure
     * @param bool   $httponly
     */
    public function cookie($key, $value = null, $expire = null, $path = null, $domain = null, $secure = null, $httponly = null)
    {
        $cookie = "{$key}={$value}";

        if ($expire)
        {
            $cookie .= '; expires='. date('D, d M Y H:i:s \G\M\T', $expire);
        }

        if ($path)
        {
            $cookie .= "; path={$path}";
        }

        if ($domain)
        {
            $cookie .= "; domain={$domain}";
        }

        if ($secure)
        {
            $cookie .= "; secure";
        }

        if ($httponly)
        {
            $cookie .= "; httponly";
        }

        $this->cookie[] = $cookie;
    }

    /**
     * 设置HttpCode，如404, 501, 200
     *
     * @param $code
     */
    public function status($code)
    {
        $this->status = $code;
    }

    /**
     * 设置Http压缩格式
     *
     * @param int $level
     */
    function gzip($level = 1)
    {
        if ($this->headerIsSend)return;

        $this->gzipLevel = $level;
    }
}
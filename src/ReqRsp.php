<?php
namespace MyQEE\Server;

/**
 * 集成了 Request, Response 的对象
 *
 * @package MyQEE\Server
 */
class ReqRsp
{
    /**
     * @var \Swoole\Http\Request
     */
    public $request;

    /**
     * @var \Swoole\Http\Response
     */
    public $response;

    /**
     * 当前对象
     *
     * @var Worker|\WorkerMain
     */
    public $worker;

    public $status = 200;

    public $message = '';

    /**
     * 抛错对象
     *
     * @var \Exception|null
     */
    public $exception;

    protected $_isEnd = false;

    /**
     * @param string $message
     */
    public function show404($message = 'Page Not Found')
    {
        $this->status = 404;
        $this->response->status(404);
        $this->response->header('Content-Type', 'text/html;charset=utf-8');
        $this->message = $message;
        ob_start();
        try
        {
            include ($this->worker->errorPage404);
        }
        catch (\Exception $e)
        {
            ob_end_clean();
            $this->end($this->message);
            return;
        }

        $html = ob_get_clean();
        $this->end($html);
    }

    /**
     * @param string $message
     * @param int    $status
     */
    public function show500($message = 'Internal Server Error', $status = 500)
    {
        $this->response->status(500);
        $this->response->header('Content-Type', 'text/html;charset=utf-8');
        $this->status = $status;
        if (is_object($message) && $message instanceof \Exception)
        {
            $this->message   = $message->getMessage();
            $this->exception = $message;
        }
        else
        {
            $this->message = $message;
        }

        ob_start();
        try
        {
            include ($this->worker->errorPage500);
        }
        catch (\Exception $e)
        {
            ob_end_clean();
            $this->end($this->message);
            return;
        }
        $html = ob_get_clean();
        $this->end($html);
    }

    /**
     * 获取当前URI路径
     *
     * @return string
     */
    public function uri()
    {
        return $this->request->server['request_uri'];
    }

    /**
     * 对象是否结束
     *
     * @return bool
     */
    public function isEnd()
    {
        return $this->_isEnd;
    }

    /**
     * 页面结束
     *
     * 在调用此方法后，worker，request, response 对象将移除
     *
     * @param $html
     * @return bool
     */
    public function end($html)
    {
        if (true === $this->_isEnd)return false;
        $this->_isEnd = true;
        if ($this->status !== 200)
        {
            $this->response->status($this->status);
        }
        $this->response->end($html);
        unset($this->worker);
        unset($this->request);
        unset($this->response);

        return true;
    }

    /**
     * 获取一个语言包对象
     *
     * @param null $package
     * @return I18n
     */
    public function i18n($package = null)
    {
        return new I18n($package, $this->getLang());
    }

    /**
     * 获取当前请求接受的语言
     *
     * @return array
     */
    public function getLang()
    {
        if ($this->worker->langCookieKey && isset($this->request->cookie[$this->worker->langCookieKey]))
        {
            return $this->request->cookie[$this->worker->langCookieKey];
        }
        else
        {
            return I18n::getAcceptLanguage(isset($this->request->header['http_accept_language']) ? $this->request->header['http_accept_language'] : '');
        }
    }
}
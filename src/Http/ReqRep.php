<?php

namespace MyQEE\Server\Http;

use MyQEE\Server\I18n;
use MyQEE\Server\Server;

/**
 * 集成了 Request, Response 的对象
 *
 * @package MyQEE\Server\Http
 */
class ReqRep {
    /**
     * @var \Swoole\Http\Request
     */
    public $request;

    /**
     * @var \Swoole\Http\Response
     */
    public $response;

    /**
     * 当前进程对象
     *
     * @var \MyQEE\Server\Worker\SchemeHttp|\WorkerMain
     */
    public $worker;

    /**
     * 服务器对象
     *
     * @var Server|\Server
     */
    public $server;

    public $status = 200;

    public $message = '';

    /**
     * 抛错对象
     *
     * @var \Exception|null
     */
    public $exception;

    /**
     * @var Session
     */
    protected $session;

    protected $isEnd = false;

    public function __construct() {
        $this->server = Server::$instance;
    }

    /**
     * 获取对象
     *
     * @return static
     */
    public static function factory() {
        return new static();
    }

    /**
     * 显示404页面
     *
     * @param string $message
     */
    public function show404($message = 'Page Not Found') {
        $this->status = 404;
        $this->response->status(404);
        $this->response->header('Content-Type', 'text/html;charset=utf-8');
        $this->message = $message;
        ob_start();
        try {
            include($this->worker->errorPage404);
        }
        catch (\Exception $e) {
            ob_end_clean();
            $this->end($this->message);
            return;
        }

        $html = ob_get_clean();
        $this->exit($html);
    }

    /**
     * 显示页面错误
     *
     * @param string $message
     * @param int $status
     */
    public function showError($message = 'Internal Server Error', $status = 500) {
        $this->response->status(500);
        $this->response->header('Content-Type', 'text/html;charset=utf-8');
        $this->status = $status;
        if (is_object($message) && $message instanceof \Exception) {
            $this->message   = $message->getMessage();
            $this->exception = $message;
        }
        else {
            $this->message = $message;
        }

        ob_start();
        try {
            include($this->worker->errorPage500);
        }
        catch (\Exception $e) {
            ob_end_clean();
            $this->exit($this->message);

            return;
        }
        $html = ob_get_clean();
        $this->exit($html);
    }

    /**
     * 获取当前URI路径
     *
     * @return string
     */
    public function uri() {
        return $this->request->server['request_uri'];
    }

    /**
     * 对象是否结束
     *
     * @return bool
     */
    public function isEnd() {
        return $this->isEnd;
    }

    /**
     * 页面结束
     *
     * 在调用此方法后，worker，request, response 对象将移除
     *
     * @param $html
     * @return bool
     */
    public function end($html) {
        if (true === $this->isEnd) {
            return false;
        }
        $this->isEnd = true;
        if ($this->status !== 200) {
            $this->response->status($this->status);
        }
        $this->response->end($html);
        $this->reset();

        return true;
    }

    /**
     * 重定向
     *
     * @param string $url
     * @param int $status
     * @return bool
     */
    public function redirect($url, $status = 302) {
        if (true === $this->isEnd) {
            return false;
        }
        $this->isEnd  = true;
        $this->status = $status;
        $this->response->status($status);
        $this->response->header('Location', $url);
        $this->response->end();
        $this->reset();

        return true;
    }

    protected function reset() {
        unset($this->worker);
        unset($this->request);
        unset($this->response);
        unset($this->session);
    }

    /**
     * 获取一个语言包对象
     *
     * @param null $package
     * @return I18n
     */
    public function i18n($package = null) {
        return new I18n($package, $this->getLang());
    }

    /**
     * 获取当前请求接受的语言
     *
     * @return array
     */
    public function getLang() {
        if ($this->worker->langCookieKey && isset($this->request->cookie[$this->worker->langCookieKey])) {
            return $this->request->cookie[$this->worker->langCookieKey];
        }
        else {
            return I18n::getAcceptLanguage(isset($this->request->header['http_accept_language']) ? $this->request->header['http_accept_language'] : '');
        }
    }

    /**
     * 页面输出header缓存
     *
     * 0表示不缓存
     *
     * @param int $time 缓存时间，单位秒
     * @param int $lastModified 文件最后修改时间，不设置则当前时间，在 $time > 0 时有效
     */
    public function setHeaderCache($time, $lastModified = null) {
        $this->worker->setHeaderCache($this->response, $time, $lastModified);
    }

    /**
     * 中断执行
     *
     */
    public function exit($html = '') {
        $this->end($html);
        Server::$instance->throwExitSignal();
    }

    /**
     * 获取 Session 对象
     *
     * 获取对象时将自动加载原数据
     *
     * @return Session
     */
    public function session() {
        if (null === $this->session) {
            $this->session = $this->createSession();
        }

        return $this->session;
    }

    /**
     * 获取当前请求的数组
     *
     * @return array
     */
    public function ip() {
        $ip = [];

        if (isset($this->request->header['http_x_forwarded_for']) && $this->request->header['http_x_forwarded_for']) {
            $ip = explode(',', str_replace(' ', '', $this->request->header['http_x_forwarded_for']));
        }

        if (isset($this->request->header['http_client_ip']) && $this->request->header['http_client_ip']) {
            $ip = array_merge($ip, explode(',', str_replace(' ', '', $this->request->header['http_client_ip'])));
        }

        if (isset($this->request->header['remote_addr']) && $this->request->header['remote_addr']) {
            $ip = array_merge($ip, explode(',', str_replace(' ', '', $this->request->header['remote_addr'])));
        }

        return $ip;
    }

    /**
     * 创建一个Session实例
     *
     * @return Session
     */
    protected function createSession() {
        if (!isset($this->worker->setting['session'])) {
            $this->worker->setting['session'] = Server::$defaultSessionConfig;
        }

        $conf  = $this->worker->setting['session'];
        $name  = $conf['name'];
        $class = $conf['class'];
        $sid   = $this->getSidFromRequest($name, $conf['sidInGet']);

        /**
         * @var Session $class
         * @var Session $session
         */

        # 验证SID是否合法
        if (true == $conf['checkSid'] && null !== $sid) {
            if (false === $class::checkSessionId($sid)) {
                Server::$instance->warn("Session | 收到一个不合法的SID: $sid");
                $sid = null;
                $this->response->cookie($name, null);
                $this->showError('session id error.', 403);
            }
        }

        if (null === $sid) {
            # 创建一个新的session
            $sid = $class::createSessionId();

            # 设置 cookie
            $this->response->cookie($name, $sid, $conf['expire'], $conf['path'], $conf['domain'], $conf['secure'], $conf['httponly']);

            $session = new $class($sid, [], $conf['storage']);
        }
        else {
            $session = new $class($sid, [], $conf['storage']);

            if (false === $session->start()) {
                $this->showError('获取Session失败');
            }
        }

        return $session;
    }

    /**
     * 获取 Session ID
     *
     * $sidInGet 参数说明：
     *
     * 例如设置 _sid, 则如果cookie里没有获取则尝试在 GET['_sid'] 获取sid，可用于在禁止追踪的浏览器内嵌入第三方domain中在get参数里传递sid
     *
     * @param string $name , SESSION 的名称
     * @param false|string $sidInGet 在get参数中读取sid，false 表示禁用
     * @return null
     */
    protected function getSidFromRequest($name = 'sid', $sidInGet = false) {
        $sid = null;

        if (isset($this->request->cookie[$name])) {
            $sid = $this->request->cookie[$name];
        }
        elseif (true === $sidInGet) {
            if (isset($this->request->get[$name])) {
                $sid = $this->request->get[$name];
            }
        }
        elseif ($sidInGet) {
            if (isset($this->request->get[$sidInGet])) {
                $sid = $this->request->get[$sidInGet];
            }
        }

        return $sid;
    }
}
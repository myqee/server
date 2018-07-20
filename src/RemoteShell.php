<?php
namespace MyQEE\Server;

class RemoteShell
{
    /**
     * @var \Swoole\Table
     */
    protected $contexts;

    /**
     * @var bool
     */
    private $canAutoClear = false;

    private $clearTimeTick = 0;

    private $publicKeys = [];

    private $codeKey;

    /**
     * @var \Swoole\Server
     */
    public static $server;

    public static $menu = [
        "e|exec [code]  执行PHP代码，\$this 变量为 Server 对象，例: e echo \$this->pid;",
        "d|debug        载入调试文件: bin/debug.php, \$this 变量为 Server 对象",
        "w|worker [id]  切换Worker进程，执行exec,debug 时会在指定进程中执行",
        //"l|log [type]   接受服务器log",
        "f|fd           打印服务器所有连接的fd",
        "s|stats        打印服务器状态",
        "i|info [fd]    显示某个连接的信息",
        "r|reload       重新加载所有进程",
        "h|help         显示帮助界面",
        "q|quit         退出终端",
    ];

    const PAGE_SIZE = 20;

    /**
     * @var static
     */
    protected static $instance;

    function __construct($publicKeyFiles = null)
    {
        if ($publicKeyFiles)
        {
            if (!function_exists('\\openssl_pkey_get_public'))
            {
                Server::$instance->warn('你启用了 remote_shell 的密钥功能，但服务器没有安装 openssl 扩展，无法启用，你可以关闭 public_key 或安装扩展后重新启动');
                exit;
            }

            foreach ($publicKeyFiles as $file)
            {
                if (!is_file($file))
                {
                    Server::$instance->warn('你启用了 remote_shell 的密钥功能，但文件不存在，' . $file);
                    exit;
                }

                $key = openssl_pkey_get_public(file_get_contents($file));
                if (false === $key)
                {
                    Server::$instance->warn('你启用了 remote_shell 的密钥功能，但它不是一个有效的公钥文件，' . $file);
                    exit;
                }
                $this->publicKeys[] = $key;
            }
        }

        $this->contexts = new \Swoole\Table(128);
        $this->contexts->column('workerId', \SWOOLE\Table::TYPE_INT, 2);
        $this->contexts->column('time',     \SWOOLE\Table::TYPE_INT, 4);
        $this->contexts->column('auth',     \SWOOLE\Table::TYPE_INT, 1);
        $this->contexts->create();

        if (isset(self::$server->setting['swoole']['dispatch_mode']) && self::$server->setting['swoole']['dispatch_mode'] && in_array(self::$server->setting['swoole']['dispatch_mode'], [1, 3]))
        {
            # 1，3 模式下会屏蔽onConnect/onClose事件
            $this->canAutoClear  = false;
            $this->clearTimeTick = time();
        }
        else
        {
            $this->canAutoClear = true;
        }

        $this->codeKey = __DIR__ . microtime(1);
    }

    /**
     * 获取实例化对象
     *
     * @return static
     */
    public static function instance($publicKeyFiles = null)
    {
        if (static::$instance)return static::$instance;

        return static::$instance = new static($publicKeyFiles);
    }

    /**
     * @param \Swoole\Server $server
     * @param string         $host
     * @param int            $port
     * @return bool
     */
    public function listen($server, $host = '127.0.0.1', $port = 9599)
    {
        $port = $server->listen($host, $port, SWOOLE_SOCK_TCP);
        if (!$port)
        {
            return false;
        }

        $port->set([
            'open_eof_split' => true,
            'package_eof'    => "\r\n",
        ]);
        $port->on("Receive", [$this, 'onReceive']);

        if ($this->canAutoClear)
        {
            $port->on("Connect", [$this, 'onConnect']);
            $port->on("Close",   [$this, 'onClose']);
        }
        self::$server = $server;

        return true;
    }

    /**
     * @param \Swoole\Server $serv
     * @param $fd
     * @param $reactorId
     */
    public function onConnect($serv, $fd, $reactorId)
    {
        $this->contexts->set($fd, [
            'workerId' => $serv->worker_id,
            'auth'     => 0,
            'time'     => time(),
        ]);
        if ($this->canAutoClear)
        {
            $this->help($fd);
        }
        else
        {
            $serv->send($fd, "passive\r\n");
        }
    }

    public function output($fd, $msg)
    {
        $obj = $this->contexts->get($fd);
        if (!$obj)
        {
            $msg .= "\r\n#" . self::$server->worker_id . ">";
        }
        else
        {
            $msg .= "\r\n#" . $obj['workerId'] . ">";
        }
        self::$server->send($fd, $msg);
    }

    public function onStart()
    {

    }

    public function onStop()
    {
    }

    public function onClose($serv, $fd, $reactorId)
    {
        $this->contexts->del($fd);
    }

    /**
     * @param \Swoole\Server $serv
     * @param $fd
     * @param $reactor_id
     * @param $data
     */
    public function onReceive($serv, $fd, $reactorId, $data)
    {
        $args = explode(" ", $data, 2);
        $cmd  = strtolower(trim($args[0]));
        $obj  = $this->contexts->get($fd) ?: ['workerId' => $serv->worker_id, 'auth' => 0];

        if ($this->publicKeys)
        {
            if ($args[0] == 'auth')
            {
                foreach ($this->publicKeys as $key)
                {
                    $verify = openssl_verify('login', base64_decode($args[1]), $key);
                    if ($verify)
                    {
                        $this->contexts->set($fd, [
                            'workerId' => $serv->worker_id,
                            'time'     => time(),
                            'auth'     => 1,
                        ]);
                        $this->help($fd);
                        return;
                    }
                }
                $this->output($fd, '认证失败');
                return;
            }

            if ($obj['auth'] != 1)
            {
                $this->output($fd, 'Auth fail.');
                $serv->close($fd);
                return;
            }
        }

        if (false === $this->contexts->exist($fd))
        {
            $this->contexts->set($fd, [
                'workerId' => $serv->worker_id,
                'time'     => time(),
                'auth'     => 0,
            ]);
        }

        switch ($cmd)
        {
            case 'w':
            case 'worker':
                if (empty($args[1]))
                {
                    $this->output($fd, "invalid command.");
                    break;
                }
                $workerId = intval($args[1]);
                $this->contexts->set($fd, ['workerId' => $workerId]);
                $this->output($fd, "[switching to worker " . $workerId . "]");
                break;

            case 'e':
            case 'exec':
                # 不在当前Worker进程
                if ($obj['workerId'] != $serv->worker_id)
                {
                    $msg       = Message::create(static::class . '::msgCall');
                    $msg->type = 'exec';
                    $msg->fd   = $fd;
                    $msg->code = $args[1];
                    $msg->time = time();
                    $msg->hash = $this->getExecHash($msg);
                    $msg->send($obj['workerId']);
                }
                else
                {
                    ob_start();
                    eval($args[1] . ";");
                    $out = ob_get_clean();
                    $this->output($fd, $out);
                }
                break;

            case 'd':
            case 'debug':
                $file = BASE_DIR .'bin/debug.php';
                if (!is_file($file))
                {
                    $this->output($fd, '服务器 bin/debug.php 文件不存在, 请先创建');
                }
                else
                {
                    if ($obj['workerId'] != $serv->worker_id)
                    {
                        $msg       = Message::create(static::class . '::msgCall');
                        $msg->type = 'debug';
                        $msg->fd   = $fd;
                        $msg->send($obj['workerId']);
                    }
                    else
                    {
                        $this->runDebugFile($fd, $file);
                    }
                }
                break;

            case 'h':
            case 'help':
                $this->help($fd);
                break;

            case 's':
            case 'stats':
                $stats = $serv->stats();
                $stats['qps'] = Server::$instance->getServerQPS();
                $this->output($fd, json_encode($stats, JSON_PRETTY_PRINT));
                break;

            case 'i':
            case 'info':
                if (empty($args[1]))
                {
                    $this->output($fd, "invalid command.");
                    break;
                }
                list($fd, $fromId) = explode(' ', trim($args[1]) .' ');
                $fd     = intval($fd);
                $fromId = $fromId == '' || $fromId === null ? -1 : intval($fromId);
                $info   = $serv->connection_info($fd, $fromId);
                $this->output($fd, json_encode($info, JSON_PRETTY_PRINT));
                break;

            case 'f':
            case 'fd':
                $tmp = array();
                foreach ($serv->connections as $fd)
                {
                    $tmp[] = $fd;
                    if (count($tmp) > self::PAGE_SIZE)
                    {
                        $this->output($fd, json_encode($tmp));
                        $tmp = array();
                    }
                }
                if (count($tmp) > 0)
                {
                    $this->output($fd, json_encode($tmp));
                }
                break;

            case 'q':
            case 'quit':
                $this->contexts->del($fd);
                $serv->close($fd);
                break;

            case 'r':
            case 'reload':
                $this->output($fd, 'server is reloaded.');
                Server::$instance->reload();
                break;

            case '.ping':
                $this->contexts->set($fd, ['time' => time()]);
                break;

            default:
                $this->output($fd, "unknown command[$cmd]");
                break;
        }
    }

    public function help($fd)
    {
        $this->output($fd, implode("\r\n", self::$menu) ."\r\n");
    }

    private function getExecHash($obj)
    {
        return md5($this->codeKey . $obj->time . $obj->code . $obj->fd);
    }

    protected function runDebugFile($fd, $file)
    {
        ob_start();
        clearstatcache(true, $file);
        include $file;
        $out = ob_get_clean();
        $this->output($fd, $out);
    }

    protected function clear()
    {
        if (!$this->canAutoClear)
        {
            if (!$this->clearTimeTick)
            {
                $this->clearTimeTick = swoole_timer_after(1000 * 60 * 5, function()
                {
                    $this->clearTimeTick = null;
                    $time  = time();
                    $rmIds = [];
                    foreach ($this->contexts as $_fd => $v)
                    {
                        if ($time - $v['time'] > 180)
                        {
                            $rmIds[] = $_fd;
                        }
                    }
                    foreach ($rmIds as $id)
                    {
                        $this->contexts->del($id);
                    }
                });
            }
        }
    }

    /**
     * 通过进程间输出回调
     *
     * @param \Swoole\Server $server
     * @param int $fromWorkerId
     * @param mixed $obj
     * @param int $fromServerId
     */
    public static function msgCall($server, $fromWorkerId, Message $obj, $fromServerId)
    {
        if (!isset($obj->type) || !isset($obj->fd))return;

        switch ($obj->type)
        {
            case 'debug':
                $file = BASE_DIR .'bin/debug.php';
                static::$instance->runDebugFile($obj->fd, $file);
                break;

            case 'exec':
                if (time() - $obj->time > 5)
                {
                    Server::$instance->warn("RemotShell收到一个回调超时的数据，当前时间:". time() .", 数据: ". serialize($obj));
                    return;
                }
                if ($obj->hash !== static::$instance->getExecHash($obj))
                {
                    Server::$instance->warn("RemotShell收到一个回调错误的验证数据: ". serialize($obj));
                    return;
                }

                ob_start();
                eval($obj->code . ";");
                static::$instance->output($obj->fd, ob_get_clean());
                break;
        }
    }
}
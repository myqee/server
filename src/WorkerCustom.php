<?php
namespace MyQEE\Server;

/**
 * 自定义子进程工作对象
 *
 * 注意：在自定义子进程里 `$this->server->worker_id` 是 null
 *
 * @package MyQEE\Server
 */
class WorkerCustom
{
    use Traits\Worker;

    public $name = '';

    /**
     * 自定义进程序号, 从0开始
     *
     * @var int
     */
    public $customId;

    /**
     * 当前子进程的对象
     *
     * 在初始化时会自动设置上
     *
     * @var \Swoole\Process
     */
    protected $process;

    /**
     * 当前子进程绑定的worker进程序号
     *
     * @var int
     */
    protected $bindWorkerId;

    protected $setting = [];

    /**
     * 定义每次收到的消息长度
     *
     * @var int
     */
    protected static $PIPE_READ_BUFF_LEN = 8192;

    /**
     * 写数据
     *
     * Linux系统下最大不超过8K，MacOS/FreeBSD下最大不超过2K
     *
     * @param $data
     * @return int
     */
    public function write($data)
    {
        if ($this->process->pipe == 0)return false;
        return $this->process->write($data);
    }

    /**
     * write的数据绑定到工作进程中异步接受
     *
     * 只可绑定到Worker进程，不可以绑定到Task进程，因为它不支持异步
     * 当使用 `$this->write($data)` 或 `$this->process->write($data)` 给进程管道写信息时，被绑定的worker进程会收到一个异步信息
     *
     * @param int $id 进程id
     */
    public function bindToWorker($id, $workerName = null)
    {
        if ($this->process->pipe == 0)return false;

        if ($id >= $this->server->setting['worker_num'])return false;

        if ($workerName && !isset(static::$Server->workers[$workerName]))
        {
            self::$Server->warn("Custom#{$this->name} 需要绑定到 Worker: {$workerName}，但是不存在，取消绑定");
            return false;
        }

        # 先尝试解绑
        $this->unbindWorker();

        $message         = Message::create(static::class. '::_bindMessageCallback');
        $message->name   = $this->name;
        $message->myId   = $this->id;
        $message->type   = 'bind';
        $message->worker = $workerName;
        $rs              = $message->send($id);

        if ($rs)
        {
            $this->bindWorkerId = $id;
        }

        return $rs;
    }

    /**
     * 解绑工作进程
     *
     * @return bool
     */
    public function unbindWorker()
    {
        if (null !== $this->bindWorkerId)
        {
            $message       = Message::create(static::class. '::_bindMessageCallback');
            $message->name = $this->name;
            $message->type = 'unbind';
            if (false === $message->send($this->bindWorkerId))
            {
                self::$Server->warn("Custom#{$this->name} 解除绑定失败");
                return false;
            }
        }

        return true;
    }

    /**
     * 在子进程里系统调用回调函数
     *
     * 系统将通过 `swoole_event_add($process->pipe, [$this, 'systemEventCallback']);` 绑定一个回调
     *
     * !! 这个不用自行执行，在初始化后系统会自动绑定调用
     */
    public function readInProcessCallback($pipe)
    {
        /**
         * @var \Swoole\Process $process
         */
        $message = $this->process->read(static::$PIPE_READ_BUFF_LEN);
        switch ($message)
        {
            case '.sys.reload':
                # 重启进程
                $this->debug("Custom#{$this->name} 收到一个重启请求，现已重启");
                $this->unbindWorker();
                $this->onStop();
                exit;

            default:
                list($isMessage, $workerName, $serverId, $workerId) = Message::parseSystemMessage($message);

                if (true === $isMessage)
                {
                    /**
                     * @var Message $message
                     */
                    $message->onPipeMessage($this->server, $workerId, $serverId);
                    return;
                }
                
                $this->onPipeMessage($this->server, $workerId, $message, $serverId);
                break;
        }
    }

    /**
     * 系统绑定时回调方法
     *
     * @param \Swoole\Server $server
     * @param int $fromWorkerId
     * @param Message|\stdClass $obj
     * @param int $fromServerId
     */
    public static function _bindMessageCallback($server, $fromWorkerId, $obj, $fromServerId)
    {
        switch ($obj->type)
        {
            case 'bind':
                $process = Server::$instance->getCustomWorkerProcess($obj->name);
                if ($process)
                {
                    $fromWorkerId = $obj->myId;
                    $toWorkerName = $obj->worker;
                    swoole_event_add($process->pipe, function($pipe) use ($fromWorkerId, $toWorkerName, $process)
                    {
                        $data   = $process->read(static::$PIPE_READ_BUFF_LEN);
                        $worker = $toWorkerName? Server::$instance->workers[$toWorkerName] : Server::$instance->worker;

                        $worker->onPipeMessage(Server::$instance->server, $fromWorkerId, $data);
                    });

                    Server::$instance->debug("Worker#{$server->worker_id} 绑定了 Custom#{$obj->name} 的异步Pipe消息");
                }
                break;

            case 'unbind':
                $process = Server::$instance->getCustomWorkerProcess($obj->name);
                if ($process)
                {
                    swoole_event_del($process->pipe);
                    Server::$instance->debug("Worker#{$server->worker_id} 解除绑定 Custom#{$obj->name} 的异步Pipe消息");
                }
                break;
        }
    }
}
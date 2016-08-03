<?php

namespace MyQEE\Server\Clusters;

use MyQEE\Server\Server;
use Swoole\Table;

class Host
{
    /**
     * 服务器ID
     *
     * @var int
     */
    public $id;

    /**
     * 通讯ip
     *
     * @var string
     */
    public $ip;

    /**
     * 端口
     *
     * @var int
     */
    public $port = 1311;

    /**
     * Task端口
     *
     * @var int
     */
    public $taskPort = 1312;

    /**
     * 通讯密钥
     *
     * @var string
     */
    public $key;

    /**
     * 进程数
     *
     * @var int
     */
    public $workerNum;

    /**
     * 任务进程数
     *
     * @var int
     */
    public $taskNum;

    /**
     * 是否加密
     *
     * @var bool
     */
    public $encrypt;

    /**
     * 连接到服务器的fd序号
     *
     * @var int
     */
    public $fd;

    /**
     * 删除时间
     *
     * @var int
     */
    public $removed = 0;

    /**
     * 记录Host列表
     *
     * @var \Swoole\Table
     */
    public static $table;

    /**
     * 记录FD对应的ID
     *
     * @var \Swoole\Table
     */
    public static $fdToIdTable;

    /**
     *
     */
    public function __construct()
    {

    }

    /**
     * 返回一个数组
     *
     * @return array
     */
    public function asArray()
    {
        return [
            'id'         => $this->id,
            'fd'         => $this->fd,
            'ip'         => $this->ip,
            'port'       => $this->port,
            'task_port'  => $this->taskPort,
            'key'        => $this->key,
            'worker_num' => $this->workerNum,
            'task_num'   => $this->taskNum,
            'removed'    => $this->removed,
            'encrypt'    => $this->encrypt ? 1 : 0,
        ];
    }

    /**
     * 保存数据
     *
     * @return bool
     */
    public function save()
    {
        return self::$table->set($this->id, $this->asArray());
    }

    /**
     * 移除
     *
     * @return bool
     */
    public function remove()
    {
        self::$fdToIdTable->del($this->id);

        # 标记为移除
        return self::$table->set($this->id, ['removed' => time()]);
    }

    /**
     * 投递信息
     *
     * @param     $data
     * @param int $workerId
     * @return bool
     */
    public function task($data, $workerId = -1)
    {
        if ($workerId < 0)
        {
            # 随机的任务ID
            $workerId = mt_rand(0, $this->taskNum - 1);
        }


    }

    /**
     * 返回所有的服务器
     *
     * @return array
     */
    public static function getAll()
    {
        $hosts = [];
        foreach (self::$table as $item)
        {
            # 已经标记为移除掉了的
            if ($item['removed'])continue;

            $hosts[$item['id']] = self::initHostByData($item);
        }

        return $hosts;
    }

    /**
     * 返回一个HOST对象
     *
     * @param $hostId
     * @return bool|Host
     */
    public static function get($hostId)
    {
        $rs = self::$table->get($hostId);
        if (!$rs)return false;

        return self::initHostByData($rs);
    }

    protected static function initHostByData($rs)
    {
        $host            = new Host();
        $host->id        = $rs['id'];
        $host->fd        = $rs['fd'];
        $host->host      = $rs['host'];
        $host->port      = $rs['port'];
        $host->taskPort  = $rs['task_port'];
        $host->key       = $rs['key'];
        $host->workerNum = $rs['worker_num'];
        $host->taskNum   = $rs['task_num'];
        $host->removed   = $rs['removed'];
        $host->encrypt   = $rs['encrypt'] ? true : false;

        return $host;
    }

    /**
     * 根据FD获取服务器
     *
     * @param $fd
     * @return bool|Host
     */
    public static function getHostByFd($fd)
    {
        $rs = self::$fdToIdTable->get($fd);
        if ($rs)
        {
            $rs = self::$table->get($rs['id']);
            if ($rs)
            {
                return self::initHostByData($rs);
            }
            else
            {
                return false;
            }
        }
        else
        {
            return false;
        }
    }

    /**
     * 获取一个自动分配的序号
     *
     * @return int|false
     */
    public static function getNewHostId()
    {
        return 0;
    }

    /**
     * 初始化执行
     */
    public static function init()
    {
        if (self::$table)return;

        if (isset(Server::$config['clusters']['count']) && $size = Server::$config['clusters']['count'])
        {
            # 必须是2的指数, 如1024,8192,65536等
            $size = bindec(str_pad(1, strlen(decbin((int)$size - 1)), 0)) * 2;
        }
        else
        {
            $size = 1024;
        }

        $table = new Table($size * 2);
        $table->column('id',         Table::TYPE_INT, 5);
        $table->column('port',       Table::TYPE_INT, 5);
        $table->column('task_port',  Table::TYPE_INT, 5);
        $table->column('worker_num', Table::TYPE_INT, 5);
        $table->column('task_num',   Table::TYPE_INT, 5);
        $table->column('encrypt',    Table::TYPE_INT, 1);
        $table->column('removed',    Table::TYPE_INT, 10);
        $table->column('fd',         Table::TYPE_INT, 10);
        $table->column('key',        Table::TYPE_STRING, 32);
        $table->column('host',       Table::TYPE_STRING, 128);
        $table->create();

        $fdTable = new Table($size * 2);
        $fdTable->column('id', Table::TYPE_INT, 5);
        $fdTable->create();

        self::$table       = $table;
        self::$fdToIdTable = $fdTable;
    }
}
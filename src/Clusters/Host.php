<?php

namespace MyQEE\Server\Clusters;

use MyQEE\Server\Server;
use Swoole\Table;

class Host
{
    /**
     * 所在分组
     *
     * @var string
     */
    public $group;

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
    public $port;

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
     * 是否加密
     *
     * @var bool
     */
    public $encrypt;

    /**
     * 连接到服务器的fd序号（注册服务器端可用）
     *
     * @var int
     */
    public $fd;

    /**
     * 来自哪个ID（注册服务器端可用）
     *
     * @var int
     */
    public $fromId;

    /**
     * 删除时间（注册服务器端可用）
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
     * 自动分配ID存放的ID
     *
     * @var \Swoole\Table
     */
    public static $groupIdTable;

    /**
     * 按组记录的连接到服务器的数量
     *
     * @var \Swoole\Atomic
     */
    public static $taskIdAtomic;

    /**
     * 按组记录的连接到服务器的数量
     *
     * @var \Swoole\Atomic
     */
    public static $lastChangeTime;

    /**
     * 按分组记录HOST
     *
     * @var array
     */
    protected static $hostByGroup = [];

    /**
     * 当前进程最后更新时间
     *
     * @var int
     */
    protected static $lastTime = 0;

    /**
     * 是否注册服务器
     *
     * @var bool
     */
    protected static $isRegisterServer = false;

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
        $arr = [
            'group'      => $this->group,
            'id'         => $this->id,
            'ip'         => $this->ip,
            'port'       => $this->port,
            'key'        => $this->key,
            'worker_num' => $this->workerNum,
            'encrypt'    => $this->encrypt ? 1 : 0,
        ];

        if (self::$isRegisterServer)
        {
            $arr['fd']      = $this->fd;
            $arr['from_id'] = $this->fromId;
            $arr['removed'] = $this->removed;
        }

        return $arr;
    }

    /**
     * 保存数据
     *
     * @return bool
     */
    public function save()
    {
        return self::$table->set("{$this->group}_{$this->id}", $this->asArray());
    }

    /**
     * 移除
     *
     * @return bool
     */
    public function remove()
    {
        if (self::$isRegisterServer)
        {
            self::$fdToIdTable->del($this->fd);

            # 标记为移除
            return self::$table->set("{$this->group}_{$this->id}", ['removed' => time()]);
        }
        else
        {
            return self::$table->del("{$this->group}_{$this->id}");
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
        foreach (self::$table as $k => $item)
        {
            # 已经标记为移除掉了的
            if ($item['removed'])continue;

            $hosts[$k] = self::initHostByData($item);
        }

        return $hosts;
    }

    /**
     * 返回一个HOST对象
     *
     * @param $hostId
     * @return bool|Host
     */
    public static function get($hostId, $group = 'default')
    {
        $rs = self::$table->get("{$group}_{$hostId}");
        if (!$rs)return false;

        return self::initHostByData($rs);
    }

    /**
     * 初始化一个Host对象
     *
     * @param $rs
     * @return Host
     */
    protected static function initHostByData($rs)
    {
        $host            = new Host();
        $host->group     = $rs['group'];
        $host->id        = $rs['id'];
        $host->ip        = $rs['ip'];
        $host->port      = $rs['port'];
        $host->key       = $rs['key'];
        $host->workerNum = $rs['worker_num'];
        $host->encrypt   = $rs['encrypt'] ? true : false;

        if (self::$isRegisterServer)
        {
            $host->fd        = $rs['fd'];
            $host->fromId    = $rs['from_id'];
            $host->removed   = $rs['removed'];
        }

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
            $rs = self::$table->get("{$rs['group']}_{$rs['id']}");
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
     * 获取一个自动分配的序号(注册服务器用)
     *
     * @return int|false
     */
    public static function getNewHostId($group = 'default')
    {
        if (!self::$isRegisterServer)
        {
            throw new \Exception('function Host::getNewHostId() only run by register server.');
        }

        while (true)
        {
            # 获取一个自增ID
            $id = self::$groupIdTable->incr($group, 'id');

            if (false === $id)
            {
                return false;
            }
            else
            {
                $id--;
                if (self::$table->exist("{$group}_{$id}"))
                {
                    # 已经存在
                    continue;
                }
                else
                {
                    return $id;
                }
            }
        }

        return false;
    }

    /**
     * 获取一个随机Host数组
     *
     * @param $group
     * @return array|bool
     */
    public static function getRandHostData($group)
    {
        $time = self::$lastChangeTime->get();
        if (self::$lastTime < $time)
        {
            # 需要更新数据
            self::$hostByGroup = [];
            self::$lastTime    = $time;

            foreach (self::$table as $v)
            {
                self::$hostByGroup[$v['group']][$v['id']] = $v['id'];
            }
        }

        if (isset(self::$hostByGroup[$group]) && self::$hostByGroup[$group])
        {
            $hostId = array_rand(self::$hostByGroup[$group]);

            return self::$table->get("{$group}_{$hostId}");
        }
        else
        {
            return false;
        }
    }

    /**
     * 初始化执行
     *
     * @param bool $isRegisterServer
     */
    public static function init($isRegisterServer = false)
    {
        if (self::$table)return;

        self::$isRegisterServer = $isRegisterServer ? true : false;

        if (isset(Server::$instance->config['clusters']['count']) && $size = Server::$instance->config['clusters']['count'])
        {
            # 必须是2的指数, 如1024,8192,65536等
            $size = bindec(str_pad(1, strlen(decbin((int)$size - 1)), 0)) * 2;
        }
        else
        {
            $size = 1024;
        }

        $table = new Table($size * 2);
        $table->column('id',         Table::TYPE_INT, 5);       // 所在组ID
        $table->column('group',      Table::TYPE_STRING, 64);   // 分组
        $table->column('ip',         Table::TYPE_STRING, 64);   // IP
        $table->column('port',       Table::TYPE_INT, 5);       // 端口
        $table->column('worker_num', Table::TYPE_INT, 5);       // 进程数
        $table->column('encrypt',    Table::TYPE_INT, 1);       // 通讯数据是否加密
        $table->column('key',        Table::TYPE_STRING, 32);   // 数据加密密钥

        if (self::$isRegisterServer)
        {
            # 注册服务器需要多几个字段
            $table->column('fd',         Table::TYPE_INT, 10);  // 所在 fd
            $table->column('from_id',    Table::TYPE_INT, 10);  // 所在 from_id
            $table->column('removed',    Table::TYPE_INT, 10);  // 移除时间

            # 记录自动分配ID
            $groupIdTable = new Table(1024);
            $groupIdTable->column('id', Table::TYPE_INT, 5);
            $groupIdTable->create();

            # fd 所对应的序号
            $fdTable = new Table($size * 2);
            $fdTable->column('group', Table::TYPE_STRING, 64);
            $fdTable->column('id',    Table::TYPE_INT, 5);
            $fdTable->create();

            self::$fdToIdTable  = $fdTable;
            self::$groupIdTable = $groupIdTable;
        }

        $table->create();
        self::$table = $table;

        self::$lastChangeTime = new \Swoole\Atomic(time());
        self::$taskIdAtomic   = new \Swoole\Atomic();
    }
}
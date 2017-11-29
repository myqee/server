<?php
namespace MyQEE\Server;

/**
 * 支持自动读写分离、自动重连的轻量级 MySQL 对象
 *
 * @package MyQEE\Server
 */
class MariaDB extends \mysqli
{
    /**
     * 连接配置
     *
     * @var array
     */
    protected $_config = [];

    /**
     * slave 配置
     *
     * @var array
     */
    protected $_slaveConfig = [];

    /**
     * 活跃的从服务器数
     *
     * @var int
     */
    protected $_slaveActiveCount = 0;

    /**
     * 从库实例化对象列表
     *
     * @var array
     */
    protected $_slaveInstance = [];

    /**
     * 权重累加值
     *
     * @var int
     */
    protected $_weightSize = 0;

    protected $_weightGroup = [];

    /**
     * 编码
     *
     * @var string
     */
    protected $_charset = 'utf8';

    protected $_isInit = false;

    protected $_isAlwaysUseMaster = false;

    /**
     * 最后查询的SQL语句
     *
     * @var string
     */
    public $lastQuery;

    public function __construct($host = null, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null)
    {
        $this->_config = func_get_args();

        parent::__construct($host, $username, $passwd, $dbname, $port, $socket);
    }

    /**
     * 添加一个从库
     *
     * 不设置的参数等同主库参数
     *
     * @param      $host
     * @param null $username
     * @param null $passwd
     * @param null $dbname
     * @param null $port
     * @param null $socket
     * @param int $weight 权重
     * @return bool
     */
    public function addSlave($host, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null, $weight = 10)
    {
        if (!$host)return false;

        $config = func_get_args();
        unset($config[6]);          # 将 weight 移除
        $size   = count($config) - 1;
        for ($i = $size; $i >= 0; $i--)
        {
            # 将结尾都是 null 的移除
            if (null === $config[$i])
            {
                unset($config[$i]);
            }
            else
            {
                break;
            }
        }

        $config = array_merge($this->_config, $config);
        $key    = "{$config[1]}@{$config[0]}:{$config[4]}";

        if (isset($this->_slaveConfig[$key]))
        {
            # 如果已经存在，先移除
            $this->removeSlave($host, $username, $passwd, $dbname, $port);
        }

        $weight = max(0, min(20, $weight));

        $this->_slaveConfig[$key] = $config;
        $this->_slaveActiveCount  = count($this->_slaveConfig);

        for($i = 1; $i <= $weight; $i++)
        {
            $this->_weightGroup[] = $key;
        }
        $this->_weightSize = count($this->_weightGroup);

        return true;
    }

    /**
     * 移除一个从库
     *
     * @param      $host
     * @param null $username
     * @param null $passwd
     * @param null $dbname
     * @param null $port
     * @return $this
     */
    public function removeSlave($host, $username = null, $passwd = null, $dbname = null, $port = null)
    {
        $config = array_merge($this->_config, func_get_args());
        $key    = "{$config[1]}@{$config[0]}:{$config[4]}";

        if (isset($this->_slaveConfig[$key]))
        {
            $g = [];
            foreach ($this->_weightGroup as $item)
            {
                if ($item != $key)
                {
                    $g[] = $item;
                }
            }
            $this->_weightGroup = $g;
            $this->_weightSize  = count($this->_weightGroup);

            unset($this->_slaveConfig[$key]);
            unset($this->_slaveInstance[$key]);
            $this->_slaveActiveCount = count($this->_slaveConfig);
        }

        return $this;
    }

    /**
     * 在主库上查询
     *
     * @param     $sql
     * @param int $resultMode
     * @return bool|\mysqli_result
     */
    public function queryOnMaster($sql, $resultMode = MYSQLI_STORE_RESULT)
    {
        return $this->_query($sql, $resultMode, true);
    }

    /**
     * 执行一个查询
     *
     * @param string $sql
     * @param int    $resultMode
     * @return bool|\mysqli_result
     */
    public function query($sql, $resultMode = MYSQLI_STORE_RESULT)
    {
        return $this->_query($sql, $resultMode, null);
    }

    /**
     * 使用协程的方式执行一个SQL语句
     *
     * 协程里最终返回的是 mysql 的执行的返回内容
     *
     * 例：
     *
     * ```php
     * use MyQEE\Server\MariaDB;
     * use MyQEE\Server\Server;
     * $MariaDB = new MariaDB('127.0.0.1', 'root', '123456', 'test');
     * $q1 = $MariaDB->queryCo('select * from db1 limit 10');
     * $q2 = $MariaDB->queryCo('select * from db2 limit 10');
     * $q3 = $MariaDB->queryCo('show tables');
     *
     *
     * # 方法1: 使用系统协调调度器并行执行3个sql（挂载在异步里执行）
     * list ($rs1, $rs2, $rs3) = yield Server::$instance->parallelCoroutine($q1, $q2, $q3);
     * # 获取内容
     * while($row = $rs1->fetch_assoc())
     * {
     *     print_r($row);
     * }
     * while($row = $rs2->fetch_assoc())
     * {
     *     print_r($row);
     * }
     * while($row = $rs2->fetch_assoc())
     * {
     *     print_r($row);
     * }
     * $rs1->free();
     * $rs2->free();
     * $rs3->free();
     *
     * # 方法2: 自行调度，非异步执行
     * $gen  = Server::$instance->parallelCoroutine($q1, $q2, $q3);
     * $task = new MyQEE\Server\Server\Coroutine\Task($gen);
     * list ($rs1, $rs2, $rs3) = $task->runAndGetResult();
     * # 以下部分同方法1的获取内容部分
     * # ...
     * ```
     *
     * @param string $sql
     * @param bool $createNewConnection 是否创建一个新的 mysql 连接，默认 true
     * @param int $timeout 超时时间，单位秒，0表示不设定
     * @return \Generator
     */
    public function queryCo($sql, $createNewConnection = true, $timeout = 60)
    {
        if ($createNewConnection)
        {
            $mysql = self::_getInstance($this->_config);
            if ($mysql->connect_errno)
            {
                yield false;

                return;
            }
        }
        else
        {
            $mysql = $this;
        }

        $rs = $mysql->query($sql, MYSQLI_ASYNC);
        if (false === $rs)
        {
            yield false;
            return;
        }

        $time = microtime(true);
        while (true)
        {
            $links = $errors = $reject = [$mysql];

            if (false === (yield mysqli_poll($links, $errors, $reject, 0, 0)))
            {
                if ($timeout > 0 && microtime(true) - $time > $timeout)
                {
                    # 超时，将错误的记录在 $errorIndexes 返回出去

                    yield false;
                    break;
                }
                continue;
            }

            yield $mysql->reap_async_query();
            break;
        }
    }

    /**
     * 执行一个查询
     *
     * @param $sql
     * @param $resultMode
     * @param bool $queryOnMaster
     * @return bool|\mysqli_result
     */
    public function _query($sql, $resultMode, $queryOnMaster)
    {
        $this->lastQuery = $sql;

        if (true !== $queryOnMaster && false === $this->_isAlwaysUseMaster && $this->_slaveActiveCount > 0)
        {
            # 主从自动切换
            $type = strtolower(substr($sql, 0, 6));
            if ($type === 'select')
            {
                return $this->_queryOnSlave($sql, $resultMode);
            }
        }

        if (false === $this->_isInit)
        {
            # 设置编码
            $this->set_charset($this->_charset);
        }

        $rs = parent::query($sql, $resultMode);

        if ($this->errno > 0)
        {
            $errNo     = $this->errno;
            $error     = $this->error;
            $errorList = $this->error_list;
            if ($errNo == 104 || ($errNo >= 2000 && $errNo < 2100) || false === $this->ping())
            {
                # 2006 的错误比较常见 MySQL server has gone away
                # 2013 Lost connection to MySQL server during query
                # 连接断开
                if ($this->_connect())
                {
                    $rs = parent::query($sql, $resultMode);
                }
                else
                {
                    $rs = false;
                }
            }
            else
            {
                $rs = false;
            }

            $this->errno      = $errNo;
            $this->error      = $error;
            $this->error_list = $errorList;

            return $rs;
        }

        return $rs;
    }

    /**
     * 在 slave 上进行查询操作
     *
     * @param $sql
     * @param $resultMode
     * @return bool|\mysqli_result
     */
    protected function _queryOnSlave($sql, $resultMode)
    {
        $rand = mt_rand(0, $this->_weightSize - 1);
        $key  = $this->_weightGroup[$rand];

        if (!isset($this->_slaveInstance[$key]))
        {
            $mysql = $this->_slaveInstance[$key] = self::_getInstance($this->_slaveConfig[$key]);
            $mysql->set_charset($this->_charset);
        }
        else
        {
            $mysql = $this->_slaveInstance[$key];
        }

        $rs = $mysql->query($sql, $resultMode);

        if ($mysql->errno > 0)
        {
            # 有错误，换一个从库
        }

        $this->errno      = $mysql->errno;
        $this->error      = $mysql->error;
        $this->error_list = $mysql->error_list;
        return $rs;
    }

    /**
     * 设置是否一直用主库
     *
     * @param bool $alwaysUseMaster
     * @return $this
     */
    public function setAlwaysUseMaster($alwaysUseMaster)
    {
        $this->_isAlwaysUseMaster = $alwaysUseMaster;

        return $this;
    }

    /**
     * @return bool
     */
    protected function _connect()
    {
        $this->close();

        list($host, $username, $passwd, $dbname, $port, $socket) = $this->_config;

        parent::connect($host, $username, $passwd, $dbname, $port, $socket);

        if ($this->connect_errno)
        {
            return false;
        }

        $this->set_charset($this->_charset);

        return true;
    }

    /**
     * 根据一个数组构造出插入语句
     *
     * @param $db
     * @param array $data
     * @param bool $replace
     * @return string
     */
    public function composeInsertSql($db, array $data, $replace = false)
    {
        if (!$db)throw new \Exception('构造sql语句缺少 db 参数');
        $fields = [];
        $values = [];
        foreach ($data as $key => $value)
        {
            $fields[] = $key;
            $values[] = self::quote($value);
        }

        return ($replace ? 'REPLACE':'INSERT'). " INTO `{$db}` (`". implode('`, `', $fields) ."`) VALUES (" . implode(", ", $values) . ")";
    }

    /**
     * 根据一个数组构造出更新语句
     *
     * @param $db
     * @param array $data
     * @return string
     */
    public function composeUpdateSql($db, array $data)
    {
        if (!$db)throw new \Exception('构造sql语句缺少 db 参数');
        $values = [];
        foreach ($data as $key => $value)
        {
            $value = self::quote($value);

            $values[] = "`$key` = $value";
        }

        return "UPDATE `{$db}` SET ". implode(', ', $values);
    }

    /**
     * 转换为一个可用于SQL语句的字符串
     *
     * @param $value
     * @return int|null|string
     */
    public function quote($value)
    {
        $value = self::_getFieldTypeValue($value);
        if (is_string($value))
        {
            return "'". $this->real_escape_string($value) . "'";
        }
        elseif (is_object($value))
        {
            if ($value instanceof \stdClass && isset($value->value))
            {
                return $value->value;
            }
            else
            {
                return "'". $this->real_escape_string(serialize($value)) ."'";
            }
        }
        elseif (is_null($value))
        {
            return 'NULL';
        }
        else
        {
            # int, float
            return "'$value'";
        }
    }

    /**
     * 获取一个数据库存储的类型的数据
     *
     * @param $value
     * @return int|null|string
     */
    protected static function _getFieldTypeValue($value)
    {
        if (is_null($value))
        {
            return null;
        }

        if (is_numeric($value))
        {

        }
        elseif (is_bool($value))
        {
            $value = (int)$value;
        }
        elseif (is_array($value))
        {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        elseif (is_object($value))
        {
            return serialize($value);
        }
        else
        {
            $value = (string)$value;
        }

        return $value;
    }

    /**
     * 获取实例对象
     *
     * @param $conf
     * @return \mysqli
     */
    protected static function _getInstance($conf)
    {
        switch (count($conf))
        {
            case 0:
                $mysql = new \mysqli();
                break;

            case 1:
                $mysql = new \mysqli($conf[0]);
                break;

            case 2:
                $mysql = new \mysqli($conf[0], $conf[1]);
                break;

            case 3:
                $mysql = new \mysqli($conf[0], $conf[1], $conf[2]);
                break;

            case 4:
                $mysql = new \mysqli($conf[0], $conf[1], $conf[2], $conf[3]);
                break;

            case 5:
                $mysql = new \mysqli($conf[0], $conf[1], $conf[2], $conf[3], $conf[4]);
                break;

            case 6:
            default:
                $mysql = new \mysqli($conf[0], $conf[1], $conf[2], $conf[3], $conf[4], $conf[5]);
                break;
        }

        return $mysql;
    }
}
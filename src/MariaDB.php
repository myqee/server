<?php
namespace MyQEE\Server;

/**
 * 支持自动读写分离、自动重连的轻量级 MySQL、MariaDB 客户端对象
 *
 * @package MyQEE\Server
 */
class MariaDB
{
    /**
     * 最后查询的SQL语句
     *
     * @var string
     */
    protected $lastQuery;

    /**
     * 对象
     *
     * @var \mysqli
     */
    protected $mysqli;

    /**
     * 最后错误
     *
     * @var array
     */
    protected $lastError = [0, '', [], false];

    /**
     * 连接配置
     *
     * @var array
     */
    protected $config = [];

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
    protected $_slaveWeightSize = 0;

    protected $_slaveWeightGroup = [];

    /**
     * 编码
     *
     * @var string
     */
    protected $_charset = 'utf8';

    protected $_isAlwaysUseMaster = false;

    protected $_isAsyncQuering = false;
    
    const LOW_QUERY_TIME = 1.0;

    public function __construct($host = null, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null)
    {
        $this->config = func_get_args();
        $this->mysqli = $mysqli = new \mysqli($host, $username, $passwd, $dbname, $port, $socket);

        if ($mysqli->connect_errno)
        {
            $this->lastError = [$mysqli->connect_errno, $mysqli->connect_error, [], true];
            Server::$instance->warn("mysql error no: {$mysqli->connect_errno}, error: {$mysqli->connect_error}");
        }
        else
        {
            $this->_initConnection($this->mysqli);
        }

        unset($mysqli);
    }

    public function __destruct()
    {
        @$this->mysqli->close();
    }

    function __call($name, $arguments)
    {
        switch (count($arguments))
        {
            case 0:
                return $this->mysqli->$name();

            case 1:
                return $this->mysqli->$name($arguments[0]);

            case 2:
                return $this->mysqli->$name($arguments[0], $arguments[1]);

            case 3:
                return $this->mysqli->$name($arguments[0], $arguments[1], $arguments[2]);

            case 4:
                return $this->mysqli->$name($arguments[0], $arguments[1], $arguments[2], $arguments[3]);

            case 5:
                return $this->mysqli->$name($arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4]);

            case 6:
                return $this->mysqli->$name($arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5]);

            default:
                return call_user_func_array([$this->mysqli, $name], $arguments);
        }
    }

    public function __get($name)
    {
        return $this->mysqli->$name;
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
     * @param int $weight 权重，数字越大越多，最大100
     * @return bool
     */
    public function addSlave($host, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null, $weight = 1)
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

        $config = array_merge($this->config, $config);
        $key    = "{$config[1]}@{$config[0]}:{$config[4]}";

        if (isset($this->_slaveConfig[$key]))
        {
            # 如果已经存在，先移除
            $this->removeSlave($host, $username, $passwd, $dbname, $port);
        }

        $weight = max(1, min(100, $weight));

        $this->_slaveConfig[$key] = $config;
        $this->_slaveActiveCount  = count($this->_slaveConfig);

        for($i = 0; $i < $weight; $i++)
        {
            $this->_slaveWeightGroup[] = $key;
        }
        $this->_slaveWeightSize = count($this->_slaveWeightGroup);

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
        $config = array_merge($this->config, func_get_args());
        $key    = "{$config[1]}@{$config[0]}:{$config[4]}";

        if (isset($this->_slaveConfig[$key]))
        {
            $g = [];
            foreach ($this->_slaveWeightGroup as $item)
            {
                if ($item != $key)
                {
                    $g[] = $item;
                }
            }
            $this->_slaveWeightGroup = $g;
            $this->_slaveWeightSize  = count($this->_slaveWeightGroup);

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
     * @param int $timeout 超时时间，单位秒，0表示不设定
     * @param bool $createNewConnection 是否创建一个新的 mysql 连接用于本次查询，默认 true
     * @param bool 是否强制在 master 上查询
     * @return \Generator
     */
    public function queryCo($sql, $timeout = 60, $createNewConnection = true, $queryOnMaster = null)
    {
        $queryOnSlave = false;

        if (true !== $queryOnMaster && false === $this->_isAlwaysUseMaster && $this->_slaveActiveCount > 0)
        {
            $type = strtoupper(substr($sql, 0, 6));
            if ($type === 'SELECT')
            {
                $queryOnSlave = true;
            }
        }

        if ($createNewConnection)
        {
            if (true === $queryOnSlave)
            {
                $rand  = mt_rand(0, $this->_slaveWeightSize - 1);
                $key   = $this->_slaveWeightGroup[$rand];
                $mysql = $this->createSlaveInstance($key);

                if (false === $mysql)
                {
                    yield false;
                    return;
                }
            }
            else
            {
                $mysql = self::_getInstance($this->config);
            }

            if ($mysql->connect_errno)
            {
                $this->lastError = [$mysql->connect_errno, $mysql->connect_error, [], true];
                yield false;
                return;
            }
            $this->_initConnection($mysql);
        }
        else
        {
            if (true === $this->_isAsyncQuering)
            {
                # 当前连接已经在异步查询了，排队等候
                $time = microtime(true);
                while (true)
                {
                    yield;

                    if (false === $this->_isAsyncQuering)
                    {
                        # 跳出
                        break;
                    }

                    if ($timeout > 0)
                    {
                        if (microtime(true) - $time + 1 > $timeout)
                        {
                            Server::$instance->warn("MySQL协程同一个连接被占用，查询等待超时放弃查询，SQL: $sql");
                            $this->lastError = [3024, 'Query execution was interrupted, maximum statement execution time exceeded', [], false];
                            yield false;
                            return;
                        }
                    }
                }
            }

            $this->_isAsyncQuering = true;
            $mysql                 = $this->mysqli;
        }

        $this->lastQuery = $sql;
        $rs              = $mysql->query($sql, MYSQLI_ASYNC);
        if (false === $rs)
        {
            $this->lastError = [$mysql->errno, $mysql->error, $mysql->error_list, false];
            yield false;
            return;
        }

        $time  = microtime(true);
        while (true)
        {
            yield;
            $links = $errors = $reject = [$mysql];

            if (false === mysqli_poll($links, $errors, $reject, 0, 0))
            {
                if ($timeout > 0 && ($useTime = microtime(true) - $time) > $timeout)
                {
                    # 超时，将错误的记录在 $errorIndexes 返回出去
                    Server::$instance->warn("MySQL协程查询请求超时，耗时: {$useTime}s, SQL: $sql");
                    if ($createNewConnection)
                    {
                        $mysql->close();
                    }
                    else
                    {
                        $this->_isAsyncQuering = false;
                        $this->_connect();              # 里面有 close
                    }

                    $this->lastError = [3024, 'Query execution was interrupted, maximum statement execution time exceeded', [], false];
                    yield false;
                    break;
                }
                continue;
            }

            if (!$createNewConnection)
            {
                $this->_isAsyncQuering = false;
            }

            if (($useTime = microtime(true) - $time) > static::LOW_QUERY_TIME)
            {
                Server::$instance->warn("MySQL慢查询(协程)，耗时: {$useTime}s, SQL: $sql");
            }

            yield $mysql->reap_async_query();
            break;
        }
    }

    /**
     * 开始一个事务
     *
     * @param int  $flags
     * @param null $name
     * @return bool
     */
    public function beginTransaction($flags = 0, $name = null)
    {
        return $this->mysqli->begin_transaction($flags, $name);
    }

    /**
     * 提交事务
     *
     * @param int  $flags
     * @param null $name
     * @return bool
     */
    public function commit($flags = 0, $name = null)
    {
        return $this->mysqli->commit($flags, $name);
    }

    /**
     * 回滚事务
     *
     * @return bool
     */
    public function rollback()
    {
        return $this->mysqli->rollback();
    }

    /**
     * 执行一个查询
     *
     * @param $sql
     * @param $resultMode
     * @param bool $queryOnMaster
     * @return bool|\mysqli_result
     */
    protected function _query($sql, $resultMode, $queryOnMaster)
    {
        $this->lastQuery = $sql;

        if (true !== $queryOnMaster && false === $this->_isAlwaysUseMaster && $this->_slaveActiveCount > 0)
        {
            # 主从自动切换
            $type = strtoupper(substr($sql, 0, 6));
            if ($type === 'SELECT')
            {
                return $this->_queryOnSlave($sql, $resultMode);
            }
        }

        $time = microtime(true);
        $rs   = $this->mysqli->query($sql, $resultMode);

        if (null === $rs)
        {
            // 没有连接上或账号错误
            if ($this->_connect())
            {
                return $this->mysqli->query($sql, $resultMode);
            }
            else
            {
                return false;
            }
        }

        if (($useTime = microtime(true) - $time) > static::LOW_QUERY_TIME)
        {
            Server::$instance->warn("MySQL慢查询，耗时: {$useTime}s, SQL: $sql");
        }

        if ($this->mysqli->errno > 0)
        {
            $errNo     = $this->mysqli->errno;
            $error     = $this->mysqli->error;
            $errorList = $this->mysqli->error_list;

            Server::$instance->warn("MySQL查询错误：errNo: {$errNo}, error: {$error}, sql: ". $sql);

            if ($errNo == 104 || ($errNo >= 2000 && $errNo < 2100) || false === $this->ping())
            {
                # 2006 的错误比较常见 MySQL server has gone away
                # 2013 Lost connection to MySQL server during query
                # 连接断开
                if ($this->_connect())
                {
                    return $this->mysqli->query($sql, $resultMode);
                }
            }

            $this->lastError = [$errNo, $error, $errorList, false];

            return false;
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
        list($key, $mysql) = $this->getRandSlaveInstance();
        if (false === $key)
        {
            return false;
        }
        $retry = false;

        doQuery:
        $rs = $mysql->query($sql, $resultMode);

        if ($mysql->errno > 0)
        {
            $errNo     = $mysql->errno;
            $error     = $mysql->error;
            $errorList = $mysql->error_list;

            Server::$instance->warn("MySQL查询错误：errNo: {$errNo}, error: {$error}, sql: ". $sql);
            if (Server::$isTrace)
            {
                Server::$instance->trace($errorList);
            }

            if (true === $retry)
            {
                # 重试过
                $rs = false;
            }
            elseif ($errNo == 104 || ($errNo >= 2000 && $errNo < 2100) || false === $mysql->ping())
            {
                # 2006 的错误比较常见 MySQL server has gone away
                # 2013 Lost connection to MySQL server during query
                $mysql = $this->createSlaveInstance($key);

                if (false === $mysql)return false;

                $this->_slaveInstance[$key] = $mysql;
                $retry = true;
                goto doQuery;
            }
            else
            {
                $rs = false;
            }

            $this->lastError = [$errNo, $error, $errorList, false];
        }

        return $rs;
    }

    /**
     * 获取一个随机的 slave 对象
     *
     * @return array
     */
    protected function getRandSlaveInstance()
    {
        $rand = mt_rand(0, $this->_slaveWeightSize - 1);
        $key  = $this->_slaveWeightGroup[$rand];

        if (!isset($this->_slaveInstance[$key]))
        {
            $mysql = $this->createSlaveInstance($key);

            if (false === $mysql)
            {
                return [false, null];
            }

            $this->_slaveInstance[$key] = $mysql;
        }
        else
        {
            $mysql = $this->_slaveInstance[$key];
        }

        return [$key, $mysql];
    }

    /**
     * 创建一个slave实例
     *
     * @param $key
     * @return bool|\mysqli
     */
    protected function createSlaveInstance($key)
    {
        $mysql = self::_getInstance($this->_slaveConfig[$key]);

        if ($mysql->connect_errno > 0)
        {
            # 连接有错误
            $this->lastError = [$mysql->connect_errno, $mysql->connect_error, [], true];
            return false;
        }

        $this->_initConnection($mysql);

        return $mysql;
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

    public function realConnect($host = null, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null, $flags = null)
    {
        $this->config = func_get_args();
        $rs           = $this->mysqli->real_connect($host, $username, $passwd, $dbname, $port, $socket, $flags);
        if ($rs)
        {
            $this->_initConnection($this);
        }
        return $rs;
    }

    /**
     * 返回 MySQLi 实例
     *
     * @return \mysqli
     */
    public function getRealInstance()
    {
        return $this->mysqli;
    }

    /**
     * @return bool
     */
    protected function _connect()
    {
        $this->close();

        list($host, $username, $passwd, $dbname, $port, $socket) = $this->config;

        $this->mysqli->connect($host, $username, $passwd, $dbname, $port, $socket);

        if ($this->mysqli->connect_errno)
        {
            $this->lastError = [$this->mysqli->connect_errno, $this->mysqli->connect_error, [], true];
            Server::$instance->warn("mysql error no: {$this->mysqli->connect_errno}, error: {$this->mysqli->connect_error}");
            return false;
        }

        $this->_initConnection($this);

        return true;
    }

    public function close()
    {
        return $this->mysqli->close();
    }

    /**
     * 操作相应数
     *
     * @return int
     */
    public function affectedRows()
    {
        return $this->mysqli->affected_rows;
    }

    /**
     * 最后的查询SQL语句
     *
     * @return int
     */
    public function lastQuery()
    {
        return $this->lastQuery;
    }

    /**
     * 插入ID
     *
     * @return int
     */
    public function insertId()
    {
        return $this->mysqli->insert_id;
    }

    /**
     * 获取最后错误
     *
     * ```php
     * list($errNo, $error, $errorList, $isConnectError) = $this->lastError();
     * ```
     *
     * @return array
     */
    public function lastError()
    {
        return $this->lastError;
    }

    /**
     * @return bool
     */
    public function ping()
    {
        return $this->mysqli->ping();
    }

    /**
     * 设置编码类型
     *
     * @param $charset
     * @return bool
     */
    public function setCharset($charset)
    {
        $this->_charset = $charset;
        return $this->mysqli->set_charset($this->_charset);
    }

    /**
     * 根据一个数组构造出插入语句
     *
     * @param $db
     * @param array $data
     * @param bool $replace
     * @return string
     * @throws \Exception
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
     * @throws \Exception
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
     * 序列话字符串
     *
     * @param string $escapestr
     * @return string
     */
    public function realEscapeString($escapestr)
    {
        return $this->mysqli->real_escape_string($escapestr);
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
            return "'". $this->mysqli->real_escape_string($value) . "'";
        }
        elseif (is_object($value))
        {
            if ($value instanceof \stdClass && isset($value->value))
            {
                return $value->value;
            }
            else
            {
                return "'". $this->mysqli->real_escape_string(serialize($value)) ."'";
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
     * @param \mysqli|MariaDB $mysql
     */
    protected function _initConnection($mysql)
    {
        $mysql->set_charset($this->_charset);
        $mysql->set_opt(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
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
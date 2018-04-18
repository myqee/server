<?php
namespace MyQEE\Server;

/**
 * 数据可落地的内存表
 *
 * @package MyQEE\Server
 */
class Table extends \Swoole\Table
{
    /**
     * 连接对象
     *
     * @var array
     */
    protected $_link;

    /**
     * @var string
     */
    protected $_type;

    protected $_driver;

    /**
     * mysql, sqlite 类型用到的
     *
     * @var array
     */
    protected $_column;

    /**
     * 内存表
     *
     * @param int    $size
     * @param string $link 连接,例如: mysql://user:pass@127.0.0.1:3306/my_database/my_table?charset=utf8
     */
    public function __construct($size, $link = null)
    {
        if ($size >= 1)
        {
            $size = bindec(str_pad(1, strlen(decbin((int)$size - 1)), 0)) * 2;
        }
        else
        {
            $size = 1024;
        }

        if ($link)
        {
            $p           = parse_url($link);
            $p['scheme'] = strtolower($p['scheme']);

            if (!$p)
            {
                throw new \Exception("Can't parse this uri: " . $link);
            }

            switch ($p['scheme'])
            {
                case 'mysql':
                    $this->_type = $p['scheme'];
                    list($db, $table) = explode('/', trim($p['path']));
                    $p['db']          = $db;
                    $p['table']       = $table ?: 'memory_table';
                    $this->_column    = [];
                    if ($p['query'])
                    {
                        parse_str($p['query'], $query);
                        $p['query'] = $query ?: [];
                    }
                    break;

                # todo 暂不支持 sqlite
                //case 'sqlite':
                //    $this->_type = $p['scheme'];
                //    break;

                case 'redis':
                case 'ssdb':
                case 'rocksdb':
                    $this->_type = $p['scheme'];
                    if (!$p['path'])
                    {
                        $p['path'] = 'memory_table';
                    }
                    break;
                default:
                    throw new \Exception("The system does not support “{$p['scheme']}”");
            }

            $this->_link = $p;
        }

        parent::__construct($size);
    }

    /**
     * 设置字段类型
     *
     * @param     $name
     * @param     $type
     * @param int $size
     */
    public function column($name, $type = null, $size = null)
    {
        if ($this->_type === 'mysql' || $this->_type === 'sqlite')
        {
            # 记录下类型
            $this->_column[$name] = [$type, $size];
        }

        return parent::column($name, $type, $size);
    }

    /**
     * 创建
     *
     * @return bool
     */
    public function create()
    {
        if (parent::create())
        {
            if ($this->_link)
            {
                # 读取数据
                switch ($this->_type)
                {
                    case 'mysql':
                        return $this->_createByMySQL();

                    case 'sqlite':
                        return false;

                    case 'redis':
                    case 'ssdb':
                        return $this->_createByRedis();
                    case 'rocksdb':
                }

                return true;
            }
            else
            {
                return true;
            }
        }
        else
        {
            return false;
        }
    }

    /**
     * 设置内容
     *
     * @param       $key
     * @param array $value
     * @return bool
     */
    public function set($key, $value)
    {
        if ($this->_link)
        {
            if (false === $this->_driverSet($key, $value))
            {
                return false;
            }

            return parent::set($key, $value);
        }
        else
        {
            return parent::set($key, $value);
        }
    }

    /**
     * 删除key
     *
     * @param $key
     * @return bool
     */
    function del($key)
    {
        if ($this->_link)
        {
            if (false === $this->_driverDel($key))
            {
                return false;
            }

            return parent::del($key);
        }
        else
        {
            return parent::del($key);
        }
    }

    /**
     * 原子自增操作，可用于整形或浮点型列
     *
     * @param $key
     * @param $column
     * @param $incrby
     * @return bool|int
     */
    function incr($key, $column, $incrby = 1)
    {
        if ($this->_link)
        {
            $rs = parent::incr($key, $column, $incrby);
            if (false === $rs)
            {
                return false;
            }

            if (false === $this->_driverSet($key, [$column => $rs]))
            {
                # 重试
                usleep(300);
                $this->_driverSet($key, [$column => $rs]);
            }

            return $rs;
        }
        else
        {
            return parent::incr($key, $column, $incrby);
        }
    }

    /**
     * 原子自减操作，可用于整形或浮点型列
     *
     * @param $key
     * @param $column
     * @param $decrby
     * @return bool|int
     */
    function decr($key, $column, $decrby = 1)
    {
        if ($this->_link)
        {
            $rs = parent::incr($key, $column, $decrby);
            if (false === $rs)
            {
                return false;
            }

            if (false === $this->_driverSet($key, [$column => $rs]))
            {
                # 重试
                usleep(300);
                $this->_driverSet($key, [$column => $rs]);
            }

            return $rs;
        }
        else
        {
            return parent::decr($key, $column, $decrby);
        }
    }

    protected function _driverSet($k, $v)
    {
        switch ($this->_type)
        {
            case 'mysql':
                foreach ($v as &$tmp)
                {
                    $tmp = "'". $this->_driver()->real_escape_string($tmp). "'";
                }
                unset($tmp);
                $k    = $this->_driver()->real_escape_string($k);
                $sql  = 'INSERT INTO `' . $this->_link['table'] .'`, (`_key`, `' . implode('`, `', array_keys($v)) .'`) VALUES (\'' . $k . '\', ' . implode(', ', $v) . ')';
                $sql .= 'ON DUPLICATE KEY UPDATE ';
                foreach ($v as $kk => $vv)
                {
                    $sql .= "`$kk` = $vv, ";
                }
                $sql = rtrim($sql, ' ,');
                # INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE c = 1;
                $rs = $this->_driver()->query($sql);

                return $rs ? true : false;
            case 'sqlite':
                break;

            case 'redis':
            case 'ssdb':
                return $this->_driver()->hSet($this->_link['path'], $k, serialize(parent::get($k)));

            case 'rocksdb':
                if (!$this->_driver)
                {
                    $this->_driver = new \RocksDB($this->_link['path']);
                }
                return $this->_driver->set($k, serialize($v));
        }

        return false;
    }

    protected function _driverDel($k)
    {

        switch ($this->_type)
        {
            case 'mysql':
                $sql = "DELETE `{$this->_link['table']}` WHERE `_key` = '". $this->_driver()->real_escape_string($k). "'";
                $rs = $this->_driver()->query($sql);

                return $rs ? true : false;
            case 'sqlite':
                break;

            case 'redis':
            case 'ssdb':
                try
                {
                    return $this->_driver()->hdel($this->_link['path'], $k);
                }
                catch (\Exception $e)
                {
                    return false;
                }

            case 'rocksdb':
                if (!$this->_driver)
                {
                    $this->_driver = new \RocksDB($this->_link['path']);
                }
        }

        return false;
    }

    /**
     * @return \mysqli|\redis
     */
    protected function _driver()
    {
        if ($this->_driver)return $this->_driver;

        switch ($this->_type)
        {
            case 'mysql':
                $this->_driver = new \mysqli($this->_link['host'], $this->_link['user'], $this->_link['pass'], $this->_link['db'], $this->_link['port'] ?: 3306);
                $this->_driver->set_charset($this->_link['query']['charset'] ?: 'utf8');
                break;
        }

        return $this->_driver;
    }

    protected function _createByRedis()
    {
        foreach ($this->_driver()->hgetall($this->_link['path']) as $key => $item)
        {
            parent::set($key, unserialize($item));
        }
    }

    /**
     * 创建MySQL类型的数据
     *
     * @return bool
     */
    protected function _createByMySQL()
    {
        # 先检查表是否存在
        $table = $this->_link['table'];
        $sql   = "SHOW TABLES LIKE `$table`";
        $rs    = $this->_driver()->query($sql);
        $has   = $rs->num_rows ? true : false;
        $rs->free();

        if ($has)
        {
            # 已经存在, 检查表结构

            # 读取所有数据
            $rs  = $this->_driver()->query("select * from `$table`");
            while ($row = $rs->fetch_assoc())
            {
                $key = $row['_key'];
                unset($row['_key']);
                try
                {
                    # 设置数据
                    parent::set($key, $row);
                }
                catch (\Exception $e)
                {
                    Server::$instance->warn($e->getMessage());
                }
            }
            unset($row);
            $rs->free();

            # 检查表结构是否变化过
            $sql           = "SHOW COLUMNS FROM `$table`";
            $rs            = $this->_driver()->query($sql);
            $col           = $this->_column;
            $removedFields = [];
            $changedFields = [];

            while ($row = $rs->fetch_assoc())
            {
                $field   = $row['Field'];
                $oldType = $row['Type'];
                if ($field === '_key')continue;

                if (isset($col[$field]))
                {
                    # 此字段在字段设置里存在
                    list($newType, $newSize) = $col[$field];
                    $fieldChanged            = false;

                    if ($oldType === 'text')
                    {
                        # 超过 2000 则为 text 类型
                        if ($newType === \Swoole\Table::TYPE_STRING && $newSize > 2000)
                        {
                            # 相同
                        }
                        else
                        {
                            $fieldChanged = true;
                        }
                    }
                    elseif (preg_match('#(varchar|bigint|int)\((\d+)\)#', $oldType, $m))
                    {
                        # int(10), varchar(255)
                        if ($newType === \Swoole\Table::TYPE_INT)
                        {
                            if ($newSize > 10)
                            {
                                if ($m[1] !== 'bigint')
                                {
                                    $fieldChanged = true;
                                }
                            }
                            else
                            {
                                if ($m[1] !== 'int')
                                {
                                    $fieldChanged = true;
                                }
                            }
                        }
                        elseif ($newType === \Swoole\Table::TYPE_FLOAT)
                        {
                            $fieldChanged = true;
                        }
                        else
                        {
                            # \Swoole\Table::TYPE_STRING
                            if ($m[1] !== 'varchar' || $newSize > 2000)
                            {
                                $fieldChanged = true;
                            }
                        }
                    }
                    elseif (preg_match('#decimal\((\d+),(\d+)\)#', $oldType, $m))
                    {
                        if ($newType !== \Swoole\Table::TYPE_FLOAT)
                        {
                            $fieldChanged = true;
                        }
                    }
                    else
                    {
                        $fieldChanged = true;
                    }

                    if ($fieldChanged)
                    {
                        $changedFields[$field] = $col[$field];
                    }

                    unset($col[$field]);
                }
                else
                {
                    # 没有对应的字段, 则说明已经被删除
                    $removedFields[] = $field;
                }
            }


            if ($col || $removedFields || $changedFields)
            {
                # 有新增字段或移除的字段或修改的字段

                $tmp = [];

                if ($removedFields)
                {
                    # ALTER TABLE `adv` DROP `t`;
                    foreach ($removedFields as $field)
                    {
                        $tmp[] = "DROP `$col`";
                    }
                }

                if ($changedFields)
                {
                    static::_mysqlBuilderFieldSQL($tmp, $changedFields, 1);
                }

                if ($col)
                {
                    static::_mysqlBuilderFieldSQL($tmp, $changedFields, 0);
                }

                if ($tmp)
                {
                    $sql = "ALTER TABLE `$table` ". implode(', ', $tmp);

                    Server::$instance->debug("change mysql table: $sql");

                    #  执行结构变更
                    $this->_driver()->query($sql);
                }
            }
        }
        else
        {
            # 不存在对应的表, 创建表格
            $tmp = [];
            static::_mysqlBuilderFieldSQL($tmp, $this->_column, 2);

            # 构造SQL
            $sql = "CREATE TABLE `$table` (`_key` VARCHAR(255) NOT NULL, ". implode(', ', $tmp). ", PRIMARY KEY (`_key`)) ENGINE = InnoDB";

            Server::$instance->debug("change mysql table: $sql");

            $this->_driver()->query($sql);
        }

        return true;
    }

    protected static function _mysqlBuilderFieldSQL(& $output, $col, $set = 0)
    {
        # ALTER TABLE `adv` ADD `ss` INT(22) NOT NULL AFTER `noshow`;
        # ALTER TABLE `adv` CHANGE `noshow` `noshow` INT(2) NULL DEFAULT '0';
        foreach ($col as $field => $item)
        {
            list($type, $size) = $item;
            if ($set == 1)
            {
                $t = "CHANGE `$field` `$field`";
            }
            elseif ($set == 2)
            {
                $t = "`$field`";
            }
            else
            {
                $t = "ADD `$field`";
            }

            switch ($type)
            {
                case \Swoole\Table::TYPE_INT:
                    if ($size > 10)
                    {
                        $output[] = "$t INT({$size}) NOT NULL DEFAULT '0'";
                    }
                    else
                    {
                        $output[] = "$t BIGINT({$size}) NOT NULL DEFAULT '0'";
                    }
                    break;
                case \Swoole\Table::TYPE_FLOAT:
                    $output[] = "$t DECIMAL(10,10) NOT NULL DEFAULT '0'";
                    break;
                case \Swoole\Table::TYPE_STRING:
                default:
                    if ($size > 2000)
                    {
                        $output[] = "$t TEXT NOT NULL DEFAULT ''";
                    }
                    else
                    {
                        $output[] = "$t VARCHAR({$size}) NOT NULL DEFAULT ''";
                    }
                    break;
            }
        }
    }
}
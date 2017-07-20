<?php
namespace MyQEE\Server;

/**
 * 轻量级 Dao 对象
 *
 * @package MyQEE\Server
 */
abstract class Dao implements \JsonSerializable, \Serializable
{
    /**
     * 上次 insert 或 update 后的数据
     *
     * @var array
     */
    protected $_old  = [];

    /**
     * 对象是否初始化完成
     *
     * @var bool
     */
    private $_isInit = false;

    /**
     * 表名称
     *
     * @var string
     */
    protected static $TableName;

    /**
     * 主键字段名
     *
     * @var string
     */
    protected static $IdField = 'id';

    /**
     * Key 对应的 Field
     *
     * @var array
     */
    protected static $Fields  = [];

    /**
     * field 对应的 key
     *
     * @var array
     */
    protected static $keyOfFieldByClass = [];

    function __construct($data = null)
    {
        # 没有设置主键或表
        if (!static::$TableName || !static::$IdField || !static::$Fields)
        {
            $class = static::class;
            throw new \InvalidArgumentException('Dao ' . static::class . '配置错误，参数 ' . $class . '::$IdField、' . $class . '::$Fields、' . $class . '::$TableName 必须配置');
        }

        if ($data)
        {
            $this->setData($data);
        }

        $class = static::class;
        if (!isset(self::$keyOfFieldByClass[$class]))
        {
            self::$keyOfFieldByClass[$class] = array_flip(static::$Fields);
        }

        $this->_isInit = true;
    }

    /**
     * 插入数据到数据库
     *
     * @return bool
     */
    public function insert()
    {
        $sql = $this->getInsertSql();
        if (IS_DEBUG)
        {
            Server::$instance->debug($sql);
        }

        $mysql = static::getDB();
        if ($mysql->query($sql))
        {
            if ($mysql->affected_rows > 0)
            {
                $id = static::_idKey();
                foreach (static::$Fields as $key => $field)
                {
                    if (isset($this->$key))
                    {
                        $this->_old[$field] = $this->$key;
                    }
                }

                if ($this->$id)
                {
                    # 非自增
                    $this->_old[static::$IdField] = $this->$id;
                }
                else
                {
                    $this->_old[static::$IdField] = $this->$id = $mysql->insert_id;
                }
            }

            return true;
        }
        else
        {
            Server::$instance->warn($sql .'; error: '. $mysql->error);

            return false;
        }
    }

    /**
     * @return MySQLi
     */
    abstract public static function getDB();

    /**
     * 获取一个插入SQL语句
     *
     * @return string
     */
    public function getInsertSql()
    {
        $fields = [];
        $values = [];
        foreach (static::$Fields as $key => $field)
        {
            if (isset($this->$key))
            {
                $values[]     = static::_quoteValue($this->$key);
                $fields[]     = $field;
            }
        }

        return "INSERT INTO `". static::$TableName ."` (`". implode('`, `', $fields) ."`) VALUES (" . implode(", ", $values) . ")";
    }

    /**
     * 执行更新数据数据库操作，成功返回操作行数
     *
     * @return bool|int
     */
    public function update()
    {
        $id = static::_idKey();
        if (!$this->$id)return false;

        $values  = [];
        $changed = [];
        foreach (static::$Fields as $key => $field)
        {
            $now = isset($this->$key) ? $this->$key : null;
            if (false === ($isset = isset($this->_old[$field])) || $now !== $this->_old[$field])
            {
                $oldQuoted = $isset ? static::_quoteValue($this->_old[$field]) : 'NULL';
                $nowQuoted = static::_quoteValue($now);

                # 如果对象、数组序列化后也不相同
                if ($oldQuoted !== $nowQuoted)
                {
                    $changed[$field] = static::_getFieldTypeValue($now);
                    $values[]        = "`$field` = {$nowQuoted}";
                }
            }
        }

        if ($values)
        {
            $mysql = static::getDB();
            $sql   = "UPDATE `". static::$TableName ."` SET ". implode(', ', $values) ." WHERE `". static::$IdField ."` = '". $this->$id ."'";
            $rs    = $mysql->query($sql);
            if ($rs)
            {
                # 更新进去
                $this->_old = $this->_old ? array_merge($this->_old, $changed) : $changed;

                return $mysql->affected_rows;
            }
            else
            {
                Server::$instance->warn($mysql->error);
                return false;
            }
        }
        else
        {
            return 0;
        }
    }

    /**
     * 执行删除数据数据库操作，成功返回操作行数
     *
     * @return bool|int
     */
    public function delete()
    {
        $id    = static::_idKey();
        $value = $this->$id;

        if (!$value)return 0;

        if ($rs = static::deleteById($value))
        {
            $this->_old = [];
        }

        return $rs;
    }

    /**
     * 给对象设置一个初始化数据
     *
     * @param array $data
     */
    public function setData(array $data)
    {
        $this->_old = $data;

        $class = static::class;
        if (!isset(self::$keyOfFieldByClass[$class]))
        {
            $map = self::$keyOfFieldByClass[$class] = array_flip(static::$Fields);
        }
        else
        {
            $map = self::$keyOfFieldByClass[$class];
        }

        foreach ($data as $k => $v)
        {
            if (isset($map[$k]))
            {
                $key        = $map[$k];
                $this->$key = $v;
            }
            else
            {
                $this->$k = $v;
            }
        }
    }

    public function asArray()
    {
        $arr = [];
        foreach (static::$Fields as $key => $field)
        {
            $arr[$field] = $this->$key;
        }

        return $arr;
    }

    /**
     * 序列化成以数据库字段为Key的数组
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->asArray();
    }

    public function serialize()
    {
        return serialize($this->jsonSerialize());
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);

        if (is_array($data))$this->setData($data);
    }

    public static function getTableName()
    {
        return static::$TableName;
    }

    public function __set($k, $v)
    {
        # 这个方法的用途是在 mysqli 的 $rs->fetch_object('class') 时转换 field 和 key 关系的

        if (is_numeric($v))
        {
            if (false === strpos($v, '.'))
            {
                $v = intval($v);
            }
            else
            {
                $v = floatval($v);
            }
        }

        if ($this->_isInit)
        {
            $this->$k = $v;
            return;
        }

        $class = static::class;
        if (!isset(self::$keyOfFieldByClass[$class]))
        {
            self::$keyOfFieldByClass[$class] = array_flip(static::$Fields);
        }

        if (isset(self::$keyOfFieldByClass[$class][$k]))
        {
            $key            = self::$keyOfFieldByClass[$class][$k];
            $this->$key     = $v;
            $this->_old[$k] = $v;
        }
        else
        {
            $this->$k = $v;
        }
    }

    /**
     * 返回Id字段对应的Key
     *
     * @return string
     */
    protected static function _idKey()
    {
        return self::$keyOfFieldByClass[static::class][static::$IdField];
    }

    /**
     * 根据ID获取一个实例化对象
     *
     * @param $id
     * @return static|bool
     */
    public static function getById($id)
    {
        if (!$id)return false;

        $id = static::_quoteValue($id);

        $mysql = static::getDB();
        $rs    = $mysql->query($sql = "SELECT * FROM `" . static::$TableName . "` WHERE `" . static::$IdField . "` = {$id} LIMIT 1");
        if ($rs)
        {
            if ($rs->num_rows)
            {
                $ret = $rs->fetch_object(static::class);
            }
            else
            {
                $ret = null;
            }
            $rs->free();

            /**
             * @var self $ret
             */
            return $ret;
        }
        else
        {
            Server::$instance->warn($sql.', '. $mysql->error);

            return false;
        }
    }

    /**
     * 删除一条记录
     *
     * @param $id
     * @return bool|int
     */
    public static function deleteById($id)
    {
        $id    = static::_quoteValue($id);
        $mysql = static::getDB();
        $rs    = $mysql->query($sql = "DELETE FROM `". static::$TableName ."` WHERE `". static::$IdField ."` = {$id}");
        if ($rs)
        {
            return $mysql->affected_rows;
        }
        else
        {
            Server::$instance->warn($sql .', '. $mysql->error);
            return false;
        }
    }

    /**
     * 根据一个数组构造出插入语句
     *
     * @param $db
     * @param array $data
     * @param bool $replace
     * @return string
     */
    public static function composeInsertSql($db, array $data, $replace = false)
    {
        if (!$db)throw new \Exception('构造sql语句缺少 db 参数');
        $fields = [];
        $values = [];
        foreach ($data as $key => $value)
        {
            $fields[] = $key;
            $values[] = static::_quoteValue($value);
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
    public static function composeUpdateSql($db, array $data)
    {
        if (!$db)throw new \Exception('构造sql语句缺少 db 参数');
        $values = [];
        foreach ($data as $key => $value)
        {
            $value = static::_quoteValue($value);

            $values[] = "`$key` = $value";
        }

        return "UPDATE `{$db}` SET ". implode(', ', $values);
    }

    /**
     * 获取当前所有字段
     *
     * @return array
     */
    public static function allFields()
    {
        return static::$Fields;
    }

    /**
     * 转换为一个可用于SQL语句的字符串
     *
     * @param $value
     * @return int|null|string
     */
    protected static function _quoteValue($value)
    {
        $value = static::_getFieldTypeValue($value);
        $mysql = static::getDB();
        if (is_string($value))
        {
            return "'". $mysql->real_escape_string($value) . "'";
        }
        elseif (is_object($value))
        {
            if ($value instanceof \stdClass && isset($value->value))
            {
                return $value->value;
            }
            else
            {
                return "'". $mysql->real_escape_string(serialize($value)) ."'";
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
            $value = serialize($value);
        }
        else
        {
            $value = (string)$value;
        }

        return $value;
    }
}
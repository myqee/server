<?php

namespace MyQEE\Server;

/**
 * 轻量级 Dao 对象
 *
 * @package MyQEE\Server
 */
abstract class Dao implements \JsonSerializable, \Serializable {
    /**
     * 上次 insert 或 update 后的数据
     *
     * @var array
     */
    protected $_old = [];

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
    protected static $Fields = [];

    /**
     * field 对应的 key
     *
     * @var array
     */
    protected static $keyOfFieldByClass = [];

    /**
     * @var array
     */
    protected static $shuttles;

    /**
     * 当前DAO的穿梭服务排队任务数
     *
     * @var int
     */
    protected static $shuttleJobSize = 100;

    function __construct($data = null) {
        # 没有设置主键或表
        if (!static::$TableName || !static::$IdField || !static::$Fields) {
            $class = static::class;
            throw new \InvalidArgumentException('Dao ' . static::class . '配置错误，参数 ' . $class . '::$IdField、' . $class . '::$Fields、' . $class . '::$TableName 必须配置');
        }

        if ($data) {
            $this->setData($data);
        }

        $this->_isInit = true;
    }

    /**
     * 插入数据到数据库
     *
     * @return bool
     */
    public function insert() {
        return $this->doInsertData(false);
    }

    public function replace() {
        return $this->doInsertData(true);
    }

    /**
     * 插入数据
     *
     * @param $isReplace
     * @return bool
     */
    protected function doInsertData($isReplace) {
        $sql = $this->getInsertSql($isReplace);
        if (Logger::$isDebug) {
            Logger::instance()->debug($sql);
        }

        $job = static::getShuttle()->go($sql);
        if ($job->yield()) {
            /**
             * @var \Swoole\Coroutine\MySQL $db
             */
            $db = $job->context;
            if ($db->affected_rows > 0) {
                $id = static::_idKey();
                foreach (static::$Fields as $key => $field) {
                    if (isset($this->$key)) {
                        $this->_old[$field] = $this->$key;
                    }
                }

                if ($this->$id) {
                    # 非自增
                    $this->_old[static::$IdField] = $this->$id;
                }
                else {
                    $this->_old[static::$IdField] = $this->$id = $db->insert_id;
                }
            }

            return true;
        }
        else {
            Logger::instance()->warn($sql . '; error: ' . $job->error->getMessage());

            return false;
        }
    }

    /**
     * @return \Swoole\Coroutine\MySQL
     */
    abstract public static function getDB();

    /**
     * 获取一个插入SQL语句
     *
     * @return string
     */
    public function getInsertSql($replace = false) {
        $fields = [];
        $values = [];
        foreach (static::$Fields as $key => $field) {
            if (isset($this->$key)) {
                $values[] = static::escapeValue($this->$key);
                $fields[] = $field;
            }
        }

        return (true === $replace ? 'REPLACE' : 'INSERT') . " INTO `" . static::$TableName . "` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(", ", $values) . ")";
    }

    /**
     * 执行更新数据数据库操作，成功返回操作行数
     *
     * @return bool|int
     */
    public function update() {
        $id = static::_idKey();
        if (!$this->$id) {
            return false;
        }

        $values  = [];
        $changed = [];
        foreach (static::$Fields as $key => $field) {
            $now = isset($this->$key) ? $this->$key : null;
            if (false === ($isset = isset($this->_old[$field])) || $now !== $this->_old[$field]) {
                $oldQuoted = $isset ? static::escapeValue($this->_old[$field]) : 'NULL';
                $nowQuoted = static::escapeValue($now);

                # 如果对象、数组序列化后也不相同
                if ($oldQuoted !== $nowQuoted) {
                    $changed[$field] = static::getFieldTypeValue($now);
                    $values[]        = "`$field` = {$nowQuoted}";
                }
            }
        }

        if ($values) {
            $sql = "UPDATE `" . static::$TableName . "` SET " . implode(', ', $values) . " WHERE `" . static::$IdField . "` = '" . $this->$id . "'";
            $job = static::getShuttle()->go($sql);
            if ($job->yield()) {
                # 更新进去
                $this->_old = $this->_old ? array_merge($this->_old, $changed) : $changed;

                return $job->context->db->affected_rows;
            }
            else {
                Logger::instance()->warn($job->error);

                return false;
            }
        }
        else {
            return 0;
        }
    }

    /**
     * 执行删除数据数据库操作，成功返回操作行数
     *
     * @return bool|int
     */
    public function delete() {
        $id    = static::_idKey();
        $value = $this->$id;

        if (!$value) {
            return 0;
        }

        if ($rs = static::deleteById($value)) {
            $this->_old = [];
        }

        return $rs;
    }

    /**
     * 给对象设置一个初始化数据
     *
     * @param array $data
     */
    public function setData(array $data) {
        $this->_old = $data;
        $map        = static::getKeyOfField();

        foreach ($data as $k => $v) {
            if (isset($map[$k])) {
                $key        = $map[$k];
                $this->$key = $v;
            }
            else {
                $this->$k = $v;
            }
        }
    }

    public function asArray() {
        $arr = [];
        foreach (static::$Fields as $key => $field) {
            $arr[$field] = $this->$key;
        }

        return $arr;
    }

    /**
     * 序列化成以数据库字段为Key的数组
     *
     * @return array
     */
    public function jsonSerialize() {
        return $this->asArray();
    }

    public function serialize() {
        return serialize($this->jsonSerialize());
    }

    public function unserialize($serialized) {
        $data = unserialize($serialized);

        if (is_array($data)) {
            $this->setData($data);
        }
    }

    public static function getTableName() {
        return static::$TableName;
    }

    public function __set($k, $v) {
        # 这个方法的用途是在 mysqli 的 $rs->fetch_object('class') 时转换 field 和 key 关系的

        if (is_int($v) || is_float($v)) {
            # do nothing
        }
        elseif (is_numeric($v)) {
            if (false === strpos($v, '.')) {
                $v = intval($v);
            }
            else {
                $v = floatval($v);
            }
        }
        elseif (is_string($v)) {
            switch (substr($v, 0, 2)) {
                case '["':
                case '{"':
                    $tmp = @json_decode($v);
                    if (false !== $tmp) {
                        $v = $tmp;
                    }
                    break;

                case 'O:':
                    $tmp = @unserialize($v);
                    if (false !== $tmp) {
                        $v = $tmp;
                    }
                    break;
            }
        }

        if ($this->_isInit) {
            $this->$k = $v;

            return;
        }

        $tmp = static::getKeyOfField($k);
        if (null !== $tmp) {
            $key            = $tmp;
            $this->$key     = $v;
            $this->_old[$k] = $v;
        }
        else {
            $this->$k = $v;
        }
    }

    /**
     * 返回Id字段对应的Key
     *
     * @return string
     */
    protected static function _idKey() {
        return static::getKeyOfField(static::$IdField);
    }

    /**
     * 根据ID获取一个实例化对象
     *
     * @param $id
     * @return static|bool
     */
    public static function getById($id) {
        if (!$id) {
            return false;
        }
        $id = static::escapeValue($id);

        $job = static::getShuttle()->go($sql = "SELECT * FROM `" . static::$TableName . "` WHERE `" . static::$IdField . "` = {$id} LIMIT 1");
        $rs  = $job->yield();
        if ($job->status === ShuttleJob::STATUS_SUCCESS) {
            /**
             * @var array $rs
             */
            if (count($rs)) {
                $ret = new static($rs[0]);
            }
            else {
                $ret = null;
            }

            /**
             * @var static $ret
             */
            return $ret;
        }
        else {
            Logger::instance()->warn($sql . ', ' . $job->error->getMessage());

            return false;
        }
    }

    /**
     * 删除一条记录
     *
     * @param $id
     * @return bool|int
     */
    public static function deleteById($id) {
        $id  = static::escapeValue($id);
        $job = static::getShuttle()->go($sql = "DELETE FROM `" . static::$TableName . "` WHERE `" . static::$IdField . "` = {$id}");
        if ($job->yield()) {
            return $job->context->db->affected_rows;
        }
        else {
            Logger::instance()->warn($sql . ', ' . $job->error->getMessage());

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
     * @throws \Exception
     */
    public static function composeInsertSql($db, array $data, $replace = false) {
        if (!$db) {
            throw new \Exception('构造sql语句缺少 db 参数');
        }
        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            $fields[] = $key;
            $values[] = static::escapeValue($value);
        }

        return ($replace ? 'REPLACE' : 'INSERT') . " INTO `{$db}` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(", ", $values) . ")";
    }

    /**
     * 根据一个数组构造出更新语句
     *
     * @param $db
     * @param array $data
     * @return string
     * @throws \Exception
     */
    public static function composeUpdateSql($db, array $data) {
        if (!$db) {
            throw new \Exception('构造sql语句缺少 db 参数');
        }
        $values = [];
        foreach ($data as $key => $value) {
            $value = static::escapeValue($value);

            $values[] = "`$key` = $value";
        }

        return "UPDATE `{$db}` SET " . implode(', ', $values);
    }

    /**
     * 获取当前所有字段
     *
     * @return array
     */
    public static function allFields() {
        return static::$Fields;
    }

    /**
     * @return Shuttle
     */
    public static function getShuttle() {
        $class = static::class;
        if (!isset(self::$shuttles[$class])) {
            self::$shuttles[$class] = new Shuttle([static::class, 'shuttleConsumer'], static::$shuttleJobSize);
            self::$shuttles[$class]->start();
        }

        return self::$shuttles[$class];
    }

    /**
     * 穿梭消费者
     *
     * @param ShuttleJob $job
     */
    public static function shuttleConsumer(ShuttleJob $job) {
        # 执行查询
        $db               = static::getDB();
        $job->result      = $db->query($job->data);
        $job->context->db = $db;
        $job->onRelease   = function() use ($db) {
            # 释放对象
            //$db->query();
        };
        if ($job->result === false) {
            # 查询失败
            $job->error = new \Exception($db->error, $db->errno);
        }
    }

    /**
     * 获取key对应字段的数据
     *
     * @param string $field 字段
     * @return array|string|null
     */
    protected static function getKeyOfField($field = null) {
        $class = static::class;
        if (!isset(self::$keyOfFieldByClass[$class])) {
            self::$keyOfFieldByClass[$class] = array_flip(static::$Fields);
        }

        if (null === $field) {
            return self::$keyOfFieldByClass[$class];
        }
        elseif (isset(self::$keyOfFieldByClass[$class][$field])) {
            return self::$keyOfFieldByClass[$class][$field];
        }

        return null;
    }

    /**
     * 转换为一个可用于SQL语句的字符串
     *
     * @param $value
     * @return int|null|string
     */
    protected static function escapeValue($value) {
        $value = static::getFieldTypeValue($value);
        $db    = static::getDB();
        if (is_string($value)) {
            return "'" . $db->escape($value) . "'";
        }
        elseif (is_object($value)) {
            if ($value instanceof \stdClass && isset($value->value)) {
                return $value->value;
            }
            else {
                return "'" . $db->escape(serialize($value)) . "'";
            }
        }
        elseif (is_null($value)) {
            return 'NULL';
        }
        else {
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
    protected static function getFieldTypeValue($value) {
        if (is_null($value)) {
            return null;
        }

        if (is_numeric($value)) {
        }
        elseif (is_bool($value)) {
            $value = (int)$value;
        }
        elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        elseif (is_object($value)) {
            $value = serialize($value);
        }
        else {
            $value = (string)$value;
        }

        return $value;
    }
}
<?php
namespace MyQEE\Server;

/**
 * 数据可落地的内存表
 *
 * @package MyQEE\Server
 */
class Table extends \Swoole\Table
{
    protected $name;

    protected static $instances = [];

    /**
     * 内存表
     *
     * @param int $size
     */
    public function __construct($size)
    {
        if ($size >= 1)
        {
            $size = bindec(str_pad(1, strlen(decbin((int)$size - 1)), 0)) * 2;
        }
        else
        {
            $size = 1024;
        }

        parent::__construct($size);
    }

    /**
     * 设置当前对象的名称
     *
     * 只有设置过名称的对象才会数据落地和重启恢复, 如果已经有重名的对象则返回 false, 支持重命名
     *
     * @param $name
     * @return bool
     */
    public function name($name)
    {
        if ($name == $this->name)
        {
            # 已经设置过了
            return true;
        }
        elseif (isset(self::$instances[$name]))
        {
            return false;
        }

        if ($this->name && isset(self::$instances[$this->name]))
        {
            # 如果已经有了则移除
            unset(self::$instances[$this->name]);
        }

        $this->name = $name;

        self::$instances[$this->name] = $this;

        return true;
    }

    public function __destruct()
    {
        if (isset(self::$instances[$this->name]))
        {
            unset(self::$instances[$this->name]);
        }
    }

    /**
     * 加载数据
     *
     * @return bool
     */
    public function load()
    {
        if (!$this->name)return false;


        return true;
    }

    /**
     * 保存数据
     *
     * @return bool
     */
    public function save()
    {

    }
}
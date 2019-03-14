<?php
namespace MyQEE\Server\Traits;

trait Instance
{
    /**
     * 实例化对象列表
     *
     * @var array
     */
    protected static $instancesByClassName = [];

    /**
     * 获取实例
     *
     * @return self
     */
    public static function instance()
    {
        $class = static::class;
        if (isset(self::$instancesByClassName[$class]))
        {
            self::$instancesByClassName[$class] = static::createDefaultInstance();
        }

        return self::$instancesByClassName[$class];
    }

    public static function releaseDefaultInstance()
    {
        unset(self::$instancesByClassName[static::class]);
    }

    /**
     * 创建默认参数实例化对象
     *
     * @return static
     */
    abstract public static function createDefaultInstance();
}
<?php
namespace MyQEE\Server;

/**
 * Redis 类型的工作进程对象
 *
 * 实现必要的方法之外如果要实现更多的方法，请扩展 ServerRedis 服务器并绑定对应的方法
 *
 * @package MyQEE\Server
 */
abstract class WorkerRedis extends Worker
{
    /**
     * GET 命令
     *
     * @param string $key
     * @return string|int
     */
    abstract public function get($key);

    /**
     * SET 命令
     *
     * @param string $key
     * @param string|int $value
     * @return bool
     */
    abstract public function set($key, $value);

    /**
     * sAdd 命令
     *
     * @param string $key
     * @param array $value
     * @return int
     */
    abstract public function sAdd($key, array $value);

    /**
     * sMembers 命令
     *
     * @param string $key
     * @return array
     */
    abstract public function sMembers($key);

    /**
     * hSet 命令
     *
     * @param string $key
     * @param string $field
     * @param string|int $value
     * @return bool
     */
    abstract public function hSet($key, $field, $value);

    /**
     * hSet 命令
     *
     * @param string $key
     * @param string $field
     * @return string|int
     */
    abstract public function hGet($key, $field);

    /**
     * hGetAll 命令
     *
     * @param string $key
     * @return array
     */
    abstract public function hGetAll($key);
}
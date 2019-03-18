<?php
namespace MyQEE\Server;

/**
 * 穿梭服务
 *
 * 提高对复杂数据流处理业务的编程体验，降低编程人员对业务处理程序理解难度
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
class MySQL extends \Swoole\Coroutine\MySQL
{
    /**
     * 服务器是否是 myqee/sw-pool 的连接池服务器
     *
     * @var bool
     */
    public $isSwPoolServer = false;

    /**
     * 在主数据库上执行查询语句
     *
     * @param string $sql SQL语句
     * @param float  $timeout 超时时间，超时的话会断开MySQL连接，0表示不设置超时时间。
     * @return false|array 超时/出错返回false，否则以数组形式返回查询结果
     */
    public function queryOnMaster($sql, $timeout = 0)
    {
        if ($this->isSwPoolServer && false === $this->query('SET _POOL=2'))return false;

        return $this->query($sql, $timeout);
    }

    /**
     * 在从库上进行查询
     *
     * @param string $sql
     * @param int $timeout
     * @return array|bool|false
     */
    public function queryOnSlave($sql, $timeout = 0)
    {
        if ($this->isSwPoolServer && false === $this->query('SET _POOL=3'))return false;

        return $this->query($sql, $timeout);
    }

    public function ping()
    {
        $rs = $this->query('select version()');
        if ($rs)return true;
        return false;
    }
}
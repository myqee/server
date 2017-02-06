<?php
namespace MyQEE\Server\Async;

/**
 * MySQLi 的异步连接池
 *
 * @package MyQEE\Server\Async
 */
class MySQL extends Pool
{
    const DEFAULT_PORT = 3306;

    /**
     * 连接服务器
     */
    protected function connect()
    {
        $db = new \Swoole\MySQL();
        $db->on('close', function($db)
        {
            $this->remove($db);
        });

        $db->connect($this->config, function($db, $result)
        {
            if ($result)
            {
                $this->join($db);
            }
            else
            {
                $this->failure();
                trigger_error("connect to mysql server[{$this->config['host']}:{$this->config['port']}] failed. Error: {$db->connect_error}[{$db->connect_errno}].");
            }
        });
    }

    /**
     * 关闭连接池
     */
    public function close()
    {
        foreach ($this->resourcePool as $conn)
        {
            /**
             * @var $conn \Swoole\MySQL
             */
            $conn->close();
        }
    }

    /**
     * 发起一个异步查询请求
     *
     * 成功返回 true（但还没执行 $callback）
     *
     * @param          $sql
     * @param callable $callback
     * @return bool
     */
    function query($sql, callable $callback)
    {
        return $this->request(function(\Swoole\MySQL $db) use ($callback, $sql)
        {
            return $db->query($sql, function(\Swoole\MySQL $db, $result) use ($callback)
            {
                call_user_func($callback, $db, $result);

                $this->release($db);
            });
        });
    }
}
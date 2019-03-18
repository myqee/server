<?php
namespace MyQEE\Server\Pool;

/**
 * MySQL连接池
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */

class MySQLPool
{
    use \MyQEE\Server\Traits\Instance;

    /**
     * @var \MyQEE\Server\Pool
     */
    protected $pool;

    /**
     * @var array
     */
    protected $conf;

    /**
     * MySQL对象默认名称，默认 MyQEE\Server\MySQL
     *
     * @var string
     */
    protected $mysqlObjectClass = \MyQEE\Server\MySQL::class;

    /**
     * 数据库配置
     *
     * @var array
     */
    protected static $defaultConf = [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'user'     => 'root',
        'password' => '123456',
        'database' => 'test',
    ];

    public function __construct(array $conf, $poolSize = 100, $spaceSize = 10)
    {
        $this->conf = $conf;
        $this->pool = new \MyQEE\Server\Pool($poolSize, $spaceSize);
        $swPool     = isset($conf['sw_pool']) && $conf['sw_pool'];

        $this->pool->setCreateObjectFunc(function() use ($swPool)
        {
            /**
             * @var \MyQEE\Server\MySQL $conn
             */
            $class = $this->mysqlObjectClass;
            $conn  = new $class();

            if (!$conn->connect($this->conf))
            {
                return false;
            }

            if ($swPool && $class instanceof \MyQEE\Server\MySQL)
            {
                $conn->isSwPoolServer = $conn->query('SET _POOL=1');
            }

            return $conn;
        });

        # for myqee swoole pool server
        if ($swPool)$this->pool->setGivebackObjectFunc(function($conn)
        {
            /**
             * @var \MyQEE\Server\MySQL $conn
             */
            if ($conn->connected)
            {
                if (!isset($conn->isSwPoolServer) || !$conn->isSwPoolServer)
                {
                    # 不是 sw-pool 连接池，不需要判断
                    return true;
                }
                elseif ($conn->query('SET _POOL=0'))
                {
                    # 归还连接
                    return true;
                }
                else
                {
                    return false;
                }
            }
            else
            {
                return false;
            }
        });

        # for ping server
        $this->pool->setPingConnFunc(function($conn)
        {
            /**
             * @var \MyQEE\Server\MySQL $conn
             */
            $rs = $conn->query('select version()');
            if ($rs)return true;
            return false;
        });
    }

    /**
     * 获取一个实例化对象
     *
     * @param array $conf
     * @param int   $poolSize
     * @param int   $spaceSize
     * @return static
     */
    public static function factory(array $conf, $poolSize = 100, $spaceSize = 10)
    {
        return new static($conf, $poolSize, $spaceSize);
    }

    /**
     * 创建一个默认实例化对象
     *
     * @return static
     */
    public static function createDefaultInstance()
    {
        return new static(static::$defaultConf);
    }

    /**
     * 设置默认配置
     *
     * @param array $conf
     */
    public static function setDefaultConf(array $conf)
    {
        static::$defaultConf = $conf;
    }

    /**
     * 归还连接
     *
     * @param \Swoole\Coroutine\MySQL $conn
     */
    public function put($conn)
    {
        $this->pool->put($conn);
    }

    /**
     * 获取连接
     *
     * @return false|\Swoole\Coroutine\MySQL|\MyQEE\Server\MySQL|mixed
     */
    public function get()
    {
        return $this->pool->get();
    }
}
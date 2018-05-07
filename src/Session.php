<?php
namespace MyQEE\Server;

/**
 * Session处理对象
 *
 * @package MyQEE\Server
 */
class Session
{
    /**
     * 最后活跃时间
     *
     * @var int
     */
    public $lastActiveTime = 0;

    /**
     * @var string
     */
    protected $sid;

    /**
     * Session内容
     *
     * @var array
     */
    protected $session = [];

    /**
     * 设置一次的Session内容
     *
     * @var array
     */
    protected $flash = [];

    /**
     * Session内容的Hash值
     *
     * @var string
     */
    protected $sessionHash = null;

    /**
     * 存储配置
     *
     * @var string
     */
    protected $storage = 'default';

    /**
     * 校验Sid用的Key
     *
     * @var string
     */
    public static $randKey = 'm%y-q!e~e$&kfj@#ld^';

    /**
     * 存储缓存时间
     *
     * @var int
     */
    public static $storageCacheTime = 86400;

    public function __construct($sid, array $var = [], $storage = 'default')
    {
        $this->sid     = $sid;
        $this->session = $var;
        $this->storage = $storage;
    }

    public function __destruct()
    {
        $this->save();
    }

    /**
     * 开启Session
     *
     * @return bool
     */
    public function start()
    {
        if ($this->sid)
        {
            $data = $this->load();
            if (false === $data)return false;

            list($session, $hash) = $data;

            $this->sessionHash = $hash;

            # 设置 flash 的 Session
            if (!isset($session['__flash__']))
            {
                $session['__flash__'] = [];
            }
            if (!isset($session['__active__']))
            {
                $session['__active__'] = time();
            }

            if ($this->session)
            {
                # 合并数据
                $this->session = array_merge($session, $this->session);
            }
            else
            {
                $this->session = $session;
            }

            $this->flash          =& $this->session['__flash__'];
            $this->lastActiveTime =& $this->session['__active__'];

            # 清理 flash 的 session
            $this->expireFlash();

            return true;

        }
        else
        {
            return false;
        }
    }

    /**
     * 加载数据
     *
     * @return array|bool
     */
    protected function load()
    {
        try
        {
            $data = Redis::instance($this->storage)->get($this->sid);
        }
        catch (\Exception $e)
        {
            Server::$instance->debug("Session | 加载数据失败, sid: {$this->sid}");
            Server::$instance->warn($e);

            return false;
        }

        if (false === $data)
        {
            # 空数据
            $session = [
                '__flash__'  => [],
                '__active__' => time(),
            ];
            $data = static::serialize($session);
        }
        else
        {
            $session = static::unSerialize($data);

            if (!is_array($session))
            {
                Server::$instance->warn("Session | 获取到的Session类型有误，加载的未解析的数据是: ". $data);

                return false;
            }
        }

        return [$session, md5($data)];
    }

    /**
     * 保存 Session
     *
     * @return bool
     */
    public function save()
    {
        if ($this->sid)
        {
            if (time() - $this->lastActiveTime > 300)
            {
                # 超过5分钟更新延迟一次最后活跃时间
                $this->lastActiveTime = time();
            }

            $data = static::serialize($this->session);
            $hash = md5($data);

            if ($hash !== $this->sessionHash)
            {
                # 有更新
                try
                {
                    $rs = Redis::instance($this->storage)->set($this->sid, $data, static::$storageCacheTime);

                    Server::$instance->debug("Session | 存储 sid: {$this->sid} 数据". (true === $rs ? '成功':'失败'));
                }
                catch (\Exception $e)
                {
                    Server::$instance->debug("Session | 保存数据失败, sid: {$this->sid}");
                    Server::$instance->warn($e);

                    $rs = false;
                }
            }
            else
            {
                $rs = true;
            }

            return $rs;
        }
        else
        {
            return false;
        }
    }

    /**
     * 立即销毁Session数据
     *
     * 将清除存储中的内容，通常用于退出登录时清除数据
     *
     * @return bool
     */
    public function destroy()
    {
        if ($this->sid)
        {
            try
            {
                $rs = Redis::instance($this->storage)->delete($this->sid);
            }
            catch (\Exception $e)
            {
                Server::$instance->debug("Session | 移除数据失败, sid: {$this->sid}");
                Server::$instance->warn($e);

                return false;
            }

            # 设置成 null 后就不会自动保存 session 内容了
            $this->sid = null;

            return $rs;
        }
        else
        {
            return true;
        }
    }

    /**
     * 设置内容
     *
     * @param string|array $key
     * @param mixed $value
     */
    public function set($key, $value = null)
    {
        if (is_array($key))
        {
            foreach ($key as $k => $v)
            {
                $this->set($k, $v);
            }
            return;
        }

        $this->session[$key] = $value;
    }

    /**
     * 设置一个闪存SESSION数据，在下次请求的时候会获取后自动销毁
     *
     * @param  string|array $keys key, or array of values
     * @param  mixed $val value (if keys is not an array)
     * @return void
     */
    public function setFlash($keys, $val = false)
    {
        if (empty($keys))return;

        if (!is_array($keys))
        {
            $keys = [$keys => $val];
        }

        foreach ($keys as $key => $val)
        {
            $this->flash[$key] = 1;
            $this->set($key, $val);
        }
    }

    /**
     * 保持闪存SESSION数据不销毁
     *
     * @param  string $keys variable key(s)
     * @return void
     */
    public function keepFlash($keys = null)
    {
        $keys = (null===$keys) ? array_keys($this->flash) : func_get_args();

        foreach ($keys as $key)
        {
            if (isset($this->flash[$key]))
            {
                $this->flash[$key] = 1;
            }
        }
    }

    /**
     * 标记闪存SESSION数据为过期
     *
     * @return  void
     */
    public function expireFlash()
    {
        if (!empty($this->flash))
        {
            foreach ($this->flash as $key => $state)
            {
                if ($state === 0)
                {
                    unset($this->flash[$key], $this->session[$key]);
                }
                else
                {
                    $this->flash[$key] = 0;
                }
            }
        }
    }

    /**
     * 获取数据
     *
     * @param $key
     * @return mixed|null
     */
    public function get($key, $default = null)
    {
        if (!isset($this->session[$key]))return $default;
        return $this->session[$key];
    }

    /**
     * 获取后删除相应KEY的SESSION数据
     *
     * @param string $key variable key
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getAndDel($key, $default = null)
    {
        $rs = $this->get($key, $default);
        $this->delete($key);

        return $rs;
    }

    /**
     * 指定的key是否存在
     *
     * @param $key
     * @return bool
     */
    public function exist($key)
    {
        return isset($this->session[$key]);
    }

    /**
     * 删除指定key的SESSION数据
     *
     *     $session->delete('key');
     *
     *     //删除key1和key2的数据
     *     $session->delete('key1', 'key2');
     *
     * @param  string $key1 variable key(s)
     * @return void
     */
    public function delete($key1 = null, $key2 = null)
    {
        $args = func_get_args();

        foreach ($args as $key)
        {
            unset($this->session[$key]);
        }
    }

    /**
     * 获取SESSION ID
     *
     * @return  string
     */
    public function id()
    {
        return $this->sid;
    }

    public function asArray()
    {
        return $this->session;
    }

    public function __toString()
    {
        return json_encode($this->session);
    }

    /**
     * 生成一个新的Session ID
     *
     * @return string 返回一个32长度的session id
     */
    public static function createSessionId()
    {
        # 获取一个唯一字符
        $mtStr = substr(md5(microtime(1).'d2^2**(fgGs@.d3l-' . mt_rand(1, 9999999)), 2, 28);
        # 校验位
        $mtStr .= substr(md5('doe9%32' . $mtStr . static::$randKey), 8, 4);

        return $mtStr;
    }

    /**
     * 检查当前Session ID是否合法
     *
     * @param string $sid
     * @return bool
     */
    public static function checkSessionId($sid)
    {
        if (strlen($sid) !== 32)return false;
        if (!preg_match('/^[a-fA-F\d]{32}$/', $sid))return false;

        $mtStr    = substr($sid, 0, 28);
        $checkStr = substr($sid, -4);

        if (substr(md5('doe9%32' . $mtStr . static::$randKey), 8, 4) === $checkStr)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 序列化方法
     *
     * @param $data
     * @return string
     */
    protected static function serialize($data)
    {
        return serialize($data);
    }

    /**
     * 反序列化数据方法
     *
     * @param $data
     * @return array
     */
    protected static function unSerialize($data)
    {
        return unserialize($data);
    }
}
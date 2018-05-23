<?php
namespace MyQEE\Server\Traits;

use MyQEE\Server\Coroutine\Scheduler;
use MyQEE\Server\ExitSignal;
use MyQEE\Server\Server;

/**
 * 注入器特性类
 *
 * 注入器提供了一个更加灵活的业务代码注入的功能
 *
 * 注入器和服务的差别在于:
 * 服务全局服务只有一个, 而注入器多个存在, 并且每个注入器都是单独设定注入方法, 他们都可以调用全局服务.
 * 可以认为每一个注入器都是一个特定的服务, 服务是一个全局的注入器.
 * 另外, 注入器比服务多了一个事件调用的功能, 可以进行 on, off, trigger 的事件调用
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @subpackage Traits
 * @copyright  Copyright (c) 2008-2018 myqee.com
 * @license    http://www.myqee.com/license.html
 */
trait Injector
{
    /**
     * 注册器对象列表
     *
     * @var array
     */
    protected $_injectors = [];

    /**
     * 事件列表
     *
     * @var array
     */
    protected $_events = [];

    /**
     * 记录是否有用户定义的事件
     *
     * @var array
     */
    protected $_excludeSysEvents = [];

    protected static $levelBefore = 90;
    protected static $levelEvent  = 50;
    protected static $levelAfter  = 10;

    /**
     * 设置一个注入器对象
     *
     * !!! 如果设置了一个有依赖的回调方法, 则每次获取都会再次执行本次回调, 如果设置的是一个没有依赖的回调方法, 则只执行1次
     *
     *      // 设置一个依赖对象
     *      $this->set('$test', 1234);
     *
     *      // 执行回调
     *      $this->call('abc', ['$test'], function($id)
     *      {
     *          var_dump($id);
     *      }
     *
     *      // 回调执行 abc 的事件
     *      $this->trigger('abc');
     *      // 将输出 string(4) "1234"
     *
     *
     * @param string|array   $name
     * @param mixed          $relyOrFunc
     * @param mixed|\Closure $func
     * @return $this
     */
    public function injectorSet($name, $relyOrFunc = null, $func = null)
    {
        # 支持数组方式
        if (is_array($name))
        {
            foreach ($name as $key => $value)
            {
                $this->injectorSet($key, $value);
            }

            return $this;
        }

        if (null === $func)
        {
            $func       = $relyOrFunc;
            $relyOrFunc = null;
        }

        if (is_object($func) && $func instanceof \Closure)
        {
            # 回调函数, 标记为未执行过
            $run = false;
        }
        else
        {
            $run        = true;
            $relyOrFunc = null;
        }

        $injector         = new \stdClass();
        $injector->run    = $run;
        $injector->object = $func;
        $injector->rely   = $relyOrFunc;

        $this->_injectors[$name] = $injector;

        return $this;
    }

    /**
     * 移除一个注入器
     *
     * @param $name
     * @return $this
     */
    public function injectorRemove($name)
    {
        unset($this->_injectors[$name]);

        return $this;
    }

    /**
     * 获取一个注入器对象
     *
     * 如果不存在则获取全局服务的对象
     *
     * $injector 参数可以是字符串也可以是数组
     *
     *      // 将返回 $db 的依赖服务对象
     *      $db = $this->injectorGet('$db');
     *
     *      // 将返回2个
     *      list($db, $request) = $this->injectorGet(['$db', '$request']);
     *
     *      // 或
     *      $arr = $this->injectorGet(['$db', '$request']);
     *      $db  = $arr['$db'];
     *      $request = $arr['$request'];
     *
     *
     * 如果 $injector 是数组, `$flag` 参数为 1 时则返回一个带 key 索引和序号的数组, 例如:
     *
     *      $rs = $this->injectorGet(['$db', '$request']);
     *
     * 返回的$rs 为:
     *
     *      [
     *          0 => '...',
     *          1 => '...',
     *          '$db1' => '...',
     *          '$db2' => '...',
     *      ];
     *
     * 可以通过 `list($db1, $db2) = $rs` 也可以直接 `$db1 = $rs['$db1'];` 和 `$db2 = $rs['$db2']` 获取
     *
     *
     * @param string|array $name
     * @param int $flag 当 `$inject` 参数是数组时有用, 0: 仅仅list模式, 1:仅map模式, 2:包括map方式也包括list方式(list序列在前map在后)
     * @return array|null|mixed
     */
    public function injectorGet($name, $flag = 0)
    {
        if (is_array($name))
        {
            $list = [];
            foreach($name as $key)
            {
                $list[] = $this->injectorGetByName($key);
            }

            switch ($flag)
            {
                case 1:
                    # 输出map结构
                    return array_combine($name, $list);

                case 2:
                    # 包括map方式也包括list方式(list序列在前map在后)
                    foreach ($name as $i => $key)
                    {
                        $list[$key] = $list[$i];
                    }
                    return $list;

                case 0:
                default:
                    # 输出列表结构
                    return $list;
            }
        }
        else
        {
            return $this->injectorGetByName($name);
        }
    }

    /**
     * 获取一个注入对象
     *
     * @param $name
     * @return mixed
     */
    protected function injectorGetByName($name)
    {
        if (isset($this->_injectors[$name]))
        {
            # 当前对象的依赖注入器
            $injector =& $this->_injectors[$name];

            if (false === $injector->run)
            {
                $injector->run    = true;
                $injector->object = $this->call($injector->rely, $injector->object);
            }
            return $injector->object;
        }
        elseif ($name === '$this')
        {
            return $this;
        }
        else
        {
            return null;
        }
    }

    /**
     * 指定的注入器是否存在
     *
     * @param string $name 注入器名
     * @return bool
     */
    public function injectorExists($name)
    {
        if (isset($this->_injectors[$name]))
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 立即执行一个自定义的方法
     *
     * ```php
     * $this->call(['$db'], function($db){
     *     //....
     * });
     *
     * # 传入一个临时的依赖对象, 可以在本次临时覆盖已存在的依赖对象
     * $this->call(['$db', '$test'], function($db, $test) {
     *     //....
     * }, ['$test' => 123]);
     *
     * # 如果不是要临时覆盖已存在依赖对象, 推荐使用 use 方式, 例如:
     * $test = 123;
     * $this->call(['$db'], function($db) use ($test) {
     *     //....
     * });
     * ```
     *
     * @param array $rely 依赖的注入对象名称
     * @param \Closure $callback 回调方法
     * @param array $tmpRelyObject 临时依赖对象数组
     * @return mixed
     */
    public function call(array $rely, callable $callback, array $tmpRelyObject = [])
    {
        if (empty($rely))
        {
            return call_user_func($callback);
        }
        elseif (!empty($tmpRelyObject))
        {
            # 依赖数据
            $obj = [];
            foreach ($rely as $key)
            {
                $obj[] = array_key_exists($key, $tmpRelyObject) ? $tmpRelyObject[$key] : $this->injectorGetByName($key);
            }

            return call_user_func_array($callback, $obj);
        }
        else
        {
            return call_user_func_array($callback, $this->injectorGet($rely));
        }
    }

    /**
     * 直接调用
     *
     * 适用于绑定的所有事件都是相同的参数，如果设定的参数不一致，将会导致调用传参数异常
     *
     * @param string $event
     * @param array $args
     * @return bool
     */
    public function emit($event, array $args = [])
    {
        if (!isset($this->_events[$event]))return false;

        # 执行主事件
        foreach ($this->_events[$event] as $item)
        {
            try
            {
                $rs = call_user_func_array($item->callback, $args);
                if (false === $rs)
                {
                    return true;
                }
                elseif (null !== $rs && $rs instanceof \Generator)Scheduler::addCoroutineScheduler($rs);
            }
            catch (\Exception $e){Server::$instance->trace($e);}
        }

        return true;
    }

    /**
     * 触发一个预定义好的事件
     *
     * 区分大小写
     *
     * ```php
     *  $this->on('test', function(){
     *     echo "123";
     *  });
     *  $this->trigger('test');
     *  // 将会输出 123
     * ```
     *
     * @param $event
     * @param array $tmpRelyObject 临时依赖对象数组
     * @return bool 有对应事件则返回 true，没有对应事件则返回 false
     */
    public function trigger($event, array $tmpRelyObject = [])
    {
        if (!isset($this->_events[$event]))return false;

        # 执行主事件
        foreach ($this->_events[$event] as $item)
        {
            try
            {
                $rs = $this->call($item->rely, $item->callback, $tmpRelyObject);
                if (false === $rs)
                {
                    return true;
                }
                elseif (null !== $rs && $rs instanceof \Generator)Scheduler::addCoroutineScheduler($rs);
            }
            catch (\Exception $e){Server::$instance->trace($e);}
        }

        return true;
    }

    /**
     * 绑定一个事件调用
     *
     * 支持协程，如果加入2个相同 event 事件，则后加入的被先执行
     *
     * 将返回事件id, 可用于 `$this->removeEventById($id)` 方法移除当前事件
     *
     * @param string $event
     * @param array|callable $relyOrCallback 依赖数组或回调对象
     * @param callable $callback 回调对象
     * @return string 事件id 可以通过它移除事件
     */
    public function on($event, $relyOrCallback, $callback = null)
    {
        return $this->bindByLevel($event, static::$levelEvent, $relyOrCallback, $callback);
    }

    /**
     * 绑定一个事件前调用
     *
     * 如果加入2个相同 event 的 before 事件，则后加入的被先执行
     *
     * 将返回事件id, 可用于 `$this->removeEventById($id)` 方法移除当前事件
     *
     * 在 `on()` 之前执行, 与 `on()` 不同的是 `on()` 设定事件是唯一的（同名event会被移除）, `before()` 设定的事件是不会覆盖的,同名event会叠加
     *
     * @param string $event
     * @param array|callable $relyOrCallback 依赖数组或回调对象
     * @param callable $callback 回调对象
     * @return string 事件id 可以通过它移除事件
     */
    public function before($event, $relyOrCallback, $callback = null)
    {
        return $this->bindByLevel($event, static::$levelBefore, $relyOrCallback, $callback);
    }

    /**
     * 绑定一个事件后调用
     *
     * 在指定 event 后执行, 类似 before 设置
     *
     * 返回事件ID, 可以通过 `$this->removeEventById($id)` 移除此事件
     *
     * @param string $event
     * @param array|callable $relyOrCallback 依赖数组或回调对象
     * @param callable $callback 回调对象
     * @return string 事件id 可以通过它移除事件
     */
    public function after($event, $relyOrCallback, $callback = null)
    {
        return $this->bindByLevel($event, static::$levelAfter, $relyOrCallback, $callback);
    }

    /**
     * 可设定执行优先级绑定事件
     *
     * ```
     * $this->on($event, $relyOrCallback, $callback)
     * 等同于
     * $this->bindByType($event, 50, $relyOrCallback, $callback)
     *
     * $this->before($event, $relyOrCallback, $callback)
     * 等同于
     * $this->bindByType($event, 90, $relyOrCallback, $callback)
     *
     * $this->after($event, $relyOrCallback, $callback)
     * 等同于
     * $this->bindByType($event, 10, $relyOrCallback, $callback)
     * ```
     *
     * @param string $event
     * @param int|float|string $level 执行优先级，相同的 event 等级高的先执行，相同 level 的后添加的先执行
     * @param array|callable $relyOrCallback 依赖数组或回调对象
     * @param callable $callback 回调对象
     * @return string 事件id 可以通过它移除事件
     */
    protected function bindByLevel($event, $level, $relyOrCallback, $callback = null)
    {
        if (null === $callback)
        {
            $callback = $relyOrCallback;
            $rely     = [];
        }
        else
        {
            $rely = (array)$relyOrCallback;
        }

        if (!isset($this->_events[$event]))
        {
            $this->_events[$event] = [];
        }


        $obj           = new \stdClass();
        $obj->id       = $this->createEventId($event, $level, 1);
        $obj->sys      = false;    # 非系统事件
        $obj->rely     = $rely;
        $obj->callback = $callback;

        $this->pushEvent($event, $obj);

        # 标记为有用户事件
        $this->_excludeSysEvents[$event] = true;

        return $obj->id;
    }

    /**
     * 绑定一个系统事件
     *
     * 默认绑定到普通 event 等级，支持绑定到 after 或 before 等级，$event 加 .after 或 .before 后缀即可，不支持其它后缀
     *
     * ```php
     * # 普通绑定
     * $this->bindSysEvent('test', function(){});
     *
     * # 绑定到after里
     * $this->bindSysEvent('test.after', function(){});
     *
     * # 绑定到before里
     * $this->bindSysEvent('test.before', function(){});
     * ```
     *
     * @param string $event
     * @param callable|array $relyOrCallback
     * @param callable $callback
     * @return string 事件ID，可以通过此id移除事件
     */
    public function bindSysEvent($event, $relyOrCallback, $callback = null)
    {
        if (null === $callback)
        {
            $callback = $relyOrCallback;
            $rely     = [];
        }
        else
        {
            $rely = (array)$relyOrCallback;
        }

        if (($rPos = strrpos($event, '.')) !== false)
        {
            $levelOrType = substr($event, $rPos + 1);
            switch ($levelOrType)
            {
                case 'before':
                    $level = static::$levelBefore;
                    $event = substr($event, 0, $rPos);
                    break;

                case 'after':
                    $level = static::$levelAfter;
                    $event = substr($event, 0, $rPos);
                    break;

                default:
                    $level = static::$levelEvent;
                    break;
            }
        }
        else
        {
            $level = static::$levelEvent;
        }

        $obj            = new \stdClass();
        $obj->id        = $this->createEventId($event, $level, 0);
        $obj->sys       = true;    # 系统事件
        $obj->rely      = $rely;
        $obj->callback  = $callback;

        # 加入列表
        $this->pushEvent($event, $obj);

        return $obj->id;
    }

    /**
     * 插入一个事件对象到列表里
     *
     * @param string $event
     * @param \stdClass $obj
     */
    protected function pushEvent($event, $obj)
    {
        # 加入列表
        $this->_events[$event][$obj->id] = $obj;

        # 自然语言方式倒序排列
        krsort($this->_events[$event], SORT_NATURAL);
    }

    /**
     * 创建一个事件ID
     *
     * @param string $event
     * @param int|float|string $level 执行优先级
     * @param int $userEvent 1用户等级，0系统等级
     * @return string
     */
    protected function createEventId($event, $level, $userEvent)
    {
        return "{$event}-{$level}-{$userEvent}-". substr(microtime(true), 0, 20);
    }

    /**
     * 释放事件
     *
     * 系统定义的不会被移除
     *
     * 如果需要移除单个 on 设置的 event 事件，则可以利用 `$this->removeEventById($id)` 来移除（其中 $id 为 `$this->on()` 方法返回的id）
     *
     * @param string $event
     * @return $this
     */
    public function off($event)
    {
        if (!isset($this->_events[$event]))return $this;

        foreach ($this->_events[$event] as $id => $obj)
        {
            # 系统的保留
            if (true === $obj->sys)continue;
            unset($this->_events[$event][$id]);
        }

        if (empty($this->_events[$event]))unset($this->_events[$event]);

        unset($this->_excludeSysEvents[$event]);

        return $this;
    }

    /**
     * 指定的用户定义的事件是否存在，不包括系统定义的事件
     *
     * @param string $event
     * @return bool
     */
    public function excludeSysEventExists($event)
    {
        return isset($this->_excludeSysEvents[$event]);
    }

    /**
     * 指定的事件是否存在，包括系统定义的事件
     *
     * @param string $event
     * @return bool
     */
    public function eventExists($event)
    {
        return isset($this->_events[$event]);
    }

    /**
     * 移除指定事件ID的 before 或 after 事件
     *
     * 其中的参数 `$id` 是在 `$this->after()` 或 `$this->before()` 或 `$this->on()` 设置时返回的ID
     *
     * @param string $id
     * @return $this
     */
    public function removeEventById($id)
    {
        list($event) = explode('-', $id, 2);
        if (!isset($this->_events[$event]))return $this;

        unset($this->_events[$event][$id]);

        if (empty($this->_events[$event]))
        {
            unset($this->_events[$event], $this->_excludeSysEvents[$event]);
        }
        else
        {
            foreach ($this->_events as $obj)
            {
                if (false === $obj->sys)
                {
                    $this->_excludeSysEvents[$event] = true;
                    return $this;
                }
            }
            # 没有用户定义的事件
            unset($this->_excludeSysEvents[$event]);
        }

        return $this;
    }
}
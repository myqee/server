<?php
namespace MyQEE\Server;

/**
 * 系统服务基础类
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2018 myqee.com
 * @license    http://www.myqee.com/license.html
 */
abstract class Service
{
    /**
     * @var Shuttle
     */
    protected static $writeFileShuttle;

    /**
     * 通过另外一个协程方式写文件
     *
     * 与 Swoole\Coroutine::writeFile 的差别是：
     * 它此时会协程切换当前程序会被暂停，而此方法将请求通过 Shuttle 放入了一个独立的协程里切换执行，所以不会暂停当前程序
     *
     * 如果需要获取最终执行结果可以通过如下方式：
     *
     * ```php
     * $job =Service::writeFileGo('test.txt', 123);     // 如果队列不满则不会暂停会继续执行
     * // do some thing
     *
     * $rs = $job->yield();     // 协程切换等待完成结果
     * if ($rs) {
     *     echo "ok";
     * }else {
     *     echo "fail";
     * }
     * ```
     *
     * @param $file
     * @param $content
     * @return ShuttleJob
     */
    public static function writeFileGo($file, $content, $flag = 0)
    {
        if (null === self::$writeFileShuttle)
        {
            if (\Swoole\Coroutine::getCid() <= 0)
            {
                # 还没有进入协程环境，比如服务器启动前
                $rs  = file_put_contents($file, $content, $flag) === strlen($content);
                $job = new ShuttleJob();

                $job->result = $rs;
                $job->status = false === $rs ? ShuttleJob::STATUS_ERROR : ShuttleJob::STATUS_SUCCESS;

                return $job;
            }

            $shuttle = new Shuttle(function(\MyQEE\Server\ShuttleJob $job)
            {
                list($file, $content, $flag) = $job->data;

                return \Swoole\Coroutine::writeFile($file, $content, $flag);
            });
            $shuttle->start();
            self::$writeFileShuttle = $shuttle;
        }

        return self::$writeFileShuttle->go([$file, $content, $flag]);
    }
}
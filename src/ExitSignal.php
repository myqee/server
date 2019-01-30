<?php
namespace MyQEE\Server;

/**
 * 用于程序正常退出的信号对象
 *
 * @package MyQEE\Server
 */
if (class_exists('\\Swoole\\ExitException', false))
{
    class ExitSignal extends \Swoole\ExitException
    {
    }
}
else
{
    class ExitSignal extends \Error
    {
    }
}
<?php
namespace MyQEE\Server\Register;

use MyQEE\Server\Server;
use MyQEE\Server\Clusters\Host;

/**
 * 注册服务器进程对象
 *
 * @package MyQEE\Server\Register
 */
class WorkerMain extends \MyQEE\Server\RPC\Server
{
    public function onStart()
    {
        parent::onStart();

        if ($this->server->worker_id === 0 && in_array($this->server->setting['dispatch_mode'], [1, 3]))
        {
            # 如果 dispatch_mode 是 1, 3 模式, 开启定期清理数据
            swoole_timer_tick(1000 * 60, function()
            {
                foreach (Host::$table as $key => $item)
                {
                    $info = $this->server->connection_info($item['fd'], $item['from_id']);

                    if ($item['removed'])
                    {
                        if ($info)
                        {
                            $this->server->close($item['fd'], $item['from_id']);
                        }

                        # 移除内存数据
                        Host::$table->del($key);
                    }
                    elseif (false === $info)
                    {
                        # 连接已经关闭
                        Host::$table->del($key);

                        # 推送服务器
                        RPC::factory($item['fd'], $item['from_id'])->trigger('server.remove', $item->group, $item->id);

                        Server::$instance->debug("remove closed client#{$item['group']}.{$item['id']}: {$item['ip']}:{$item['port']}");
                    }
                }
            });
        }
        else
        {
            swoole_timer_tick(1000 * 60, function()
            {
                # 清理移除掉的 server
                $time = time();
                foreach (Host::$table as $key => $item)
                {
                    if ($item['removed'] && $item['removed'] - $time > 10)
                    {
                        # 清理数据
                        Host::$table->del($key);
                    }
                }
            });
        }
    }

    public function onClose($server, $fd, $fromId)
    {
        $host = Host::getHostByFd($fd);

        if ($host)
        {
            # 已经有连接上的服务器
            Server::$instance->info("host#{$host->group}.{$host->id}({$host->ip}:{$host->port}) close connection.");
            $host->remove();

            # 移除服务
            foreach (Host::$table as $item)
            {
                if (($item['group'] === $host->group && $item['id'] === $host->id) || $item['removed'])continue;

                # 通知所有客户端移除
                RPC::factory($item['fd'], $item['from_id'])->trigger('server.remove', $host->group, $host->id);
            }
        }
    }
}
#!/usr/bin/env php
<?php
error_reporting(7);
require __DIR__ . '/../../../../vendor/autoload.php';

# 配置
$config = [
    'hosts' => [
        'Main' => [
            'type'=>'ws',
            'host' => '127.0.0.1',
            'port' => 8088,
            'class' => 'test',
        ],
    ],
    'server' => [
        'worker_num' => 5,
    ],
    'log'       => [
        'level' => ['warn', 'debug', 'info', 'log'],
    ],
    'swoole' => [
        //'dispatch_mode' => 1,
    ],
];

class test extends MyQEE\Server\WorkerSocketIO
{
    public function onRequest($request, $response)
    {
        if ($request->server['request_uri'] === '/')
        {
            $html = <<<EOF
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<script src="/socket.io.min.js"></script>
<script>
    var socket = io('ws://{$this->setting['host']}:{$this->setting['port']}/');
    socket.on('connect', function (data)
    {
        socket.emit('my other event', { my: 'data' });
    });
    socket.on('news', function(data)
    {
        document.getElementById('test').innerHTML = "收到news数据：data = "+ data;
        console.log(data);
    });
    setInterval(function () {
        socket.compress(true).emit('abcdef', 'aaaaa', 333, "aaaa");
        
        socket.emit('ferret', 'tobi', function (arg1, arg2)
        {
            document.getElementById('test').innerHTML = "收到服务器回复数据，"+ new Date().getTime();
        
            // 关闭
            //socket.close();
        });
    }, 10000);

</script>
</head>
<body>
<div id="test"></div>
</html>
EOF;
            $response->end($html);

            return;
        }

        parent::onRequest($request, $response);
    }
}

$server = new MyQEE\Server\Server($config);
$server->start();
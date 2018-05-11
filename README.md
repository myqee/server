# MyQEE 服务器类库

[![Build Status](https://img.shields.io/wercker/ci/wercker/docs.svg)](https://packagist.org/packages/myqee/server)
![Supported PHP versions: 5.5 .. 7.1](https://img.shields.io/badge/php-5.5~7.1-blue.svg)
![License](https://img.shields.io/hexpm/l/plug.svg)
![Packagist](https://img.shields.io/packagist/v/myqee/server.svg)

### 介绍

MyQEE 服务器类库是一套基础服务器类库，让你可以摒弃 Swoole 传统的 On 回调写法，在不损失性能和功能的前提下实现功能和服务的对象抽象化，实现全新的编程体验，让代码清晰有条理。特别适合复杂的应用服务器开发，不管是你要在一起集成 Http 还是 Tcp 还是 WebSocket 服务，解决了使用 Swoole 开发复杂服务器的痛点。

### MyQEE 服务器为我做了什么？

* 为每个 Worker、TaskWorker、以及端口监听分配一个对象，业务层自己实现相应功能即可，让开发代码清晰有条理；
* 填补了 Swoole 服务器开发中的很多坑；
* 支持大文件、断点、分片上传功能并完美融合服务（`swoole_http_server` 不支持大文件上传，会有内存问题，也存在一些细节上的bug）
* 解决服务器选型痛点；
* 解决代码混乱的痛点；
* 解决新手搞不清 Worker、TaskWorker 和多端口之间的功能、关系、使用特性；
* 更加简单易用的热更新方案；
* 更多的周边功能特性；
* 更简洁的协程处理代码功能；

#### 真正的对象抽象化编程体验

写了很多年php的你也许还是在“面向过程”编程，在 `swoole_server` 里你需要设置各种 on 回调，这些回调且不说对初学者来说多难理解，混杂在一起的代码变得很难维护。我本人具有12+年（非2012）的php编程经验，我将服务器、Worker、TaskWorker、多端口监听进行功能上的重组后分配成一个个独立的对象，使得你可以体验“面向对象”编程带来的乐趣，解决了代码混乱的痛点。

#### 解决服务器选型痛点

很多初学者在写自己的服务器时非常迷茫，不知道到底用 `swoole_server` 还是 `swoole_http_server` 还是 `swoole_websocket_server`，用MyQEE服务器类库则不需要纠结这个问题。不管你是要创建自有的TCP服务还是HTTP还是WebSocket服务，以及多端口监听，你只需要在配置文件里简单设置，实现对应业务层面的代码即可，系统会根据配置情况自动选型服务器、监听端口并绑定对象回调方法。

#### 不约束你的代码规则

MyQEE服务器类库使用 Composer 安装，采用 psr-4 自动加载规则，提供了一些默认的处理逻辑但不限制你业务中的任何规则，你只需要设置好端口分配配置、然后为它们设定好自己的类库名称，并实现对应端口对象的业务代码即可。

#### 已实现或将会实现的功能和方案

* 易于使用的多重混合服务器端口监听方案；
* Worker、TaskWorker 面向对象化代码结构；
* `MyQEE\Server\Table` 继承 `Swoole\Table` 并支持数据落地、重启恢复，数据落地提供灵活的设置：本地文件、数据库、Redis、SSDB(LevelDB 的 Redis 协议实现)、RocksDB；
* 服务器集群方案，服务器间RPC调用，支持任意服务器间进程发送消息；
* 日志输出；
* 后台管理功能方案；
* API 功能方案；
* yield 协程支持（不依赖swoole 2.0的协程功能）；
* 连接池、资源池；
* 热更新、不停服重新加载代码方案；
* 支持大文件、断点续传、分片上传的 Http 服务器；

### 快速使用

请使用 `composer` 进行安装（无需手动下载MyQEE服务器代码，see https://getcomposer.org/doc/00-intro.md or http://docs.phpcomposer.com/00-intro.html）

1.新建一个文件夹，并创建 `composer.json` 文件，内容如下：

```json
{
    "name": "TestServer",
    "description": "test",
    "config": {
        "bin-dir": "bin",
        "data-dir": "data"
    },
    "autoload": {
        "psr-0": {
            "": "classes/"
        }
    },
    "require": {
        "myqee/server": "~1.0"
    },
    "require-dev": {
        "myqee/server": "dev-master"
    }
}
```

2.创建文件 `classes/WorkerMain.php`，内容如下：

```php
<?php
# Http的工作进程对象
class WorkerMain extendsMyQEE\Server\WorkerHttp
{
    public function onRequest($request, $response)
    {
        $response->end('hello world');
        
        # 投递一个任务给任务进程异步执行
        $this->task('hello');
    }
}
```

3.创建文件 `classes/WorkerTask.php`，内容如下：

```php
# 异步任务进程对象
class WorkerTask extends MyQEE\Server\WorkerTask
{
    public function onTask($server, $taskId, $fromId, $data, $fromServerId = -1)
    {
        echo 'onTask = ';
        var_dump($data);
    }
}
```

4.在 `bin/` 目录中创建 `server` 文件，并执行 `chmod +x bin/server` 内容如下：

```
#!/usr/bin/env php
<?php
require __DIR__ .'/../vendor/autoload.php';
use MyQEE\Server\Server;
$server = new Server(__DIR__ .'/server.yal');
$server->start();
```

5.在 `bin/` 中创建 `server.yal` 文件(详细配置见本代码库的 `example/server-full.yal` 文件)，内容：

```
---
hosts:
  # 服务1，http 类型，监听端口 9000
  Main:
    type: http
    host: 0.0.0.0
    port: 9001
    listen:
      - tcp://0.0.0.0:9010   # 再额外监听一个 9010 端口
    name: MQSRV               # 会输出 Server: MQSRV 的头信息
    # class: WorkerHttpTest   # 自定义抽象化的类名称

  # 自定义端口
  Test:
    type: tcp
    host: 127.0.0.1
    port: 2200
    conf:
      # 端口监听参数设置 see http://wiki.swoole.com/wiki/page/526.html
      open_eof_check: true
      open_eof_split: true
      package_eof: "\n"

  # WebSocket、多端口服务见完整的配置例子

# 异步任务进程配置
task:
  # 任务进程数，2 个只是测试，请根据实际情况调整
  number: 2
  class: WorkerTask

# php 相关配置
php:
  error_reporting: 7
  timezone: PRC
```

然后执行 `composer install` 安装服务器类库，此时你可以看到 `bin/example/` 目录下有 `server` 和 `server-lite.yal` 文件。执行 `./bin/example/server` 启动服务，打开浏览器访问 `http://127.0.0.1:9001/`。

**实际开发时建议将 `server` 和 `server-lite.yal` 文件复制到bin目录后自行修改。**

### 自定义服务器配置

在 `bin/example/` 目录下，有 `server-lite.yal` 和 `server-full.yal` 配置样例文件，lite 文件是比较简洁的常用配置文件，参照使用即可；full 文件是完整的配置，适合深度配置。

一般情况下，只需要根据自己的服务器定义好 hosts 里的服务器配置就可以了（类型、监听端口）非常简单，然后再实现对应的类的方法。


#### 错误解决

 * 如果网络很慢或被墙，可执行 `composer config -g repo.packagist composer https://packagist.phpcomposer.com` 使用国内的镜像；

### 程序依赖

PHP 扩展：Swoole (>=1.8.0), Yaml，如果开启集群模式，必须安装 MsgPack 扩展，如果使用到 Redis、MySQL、RocksDB 等则需要相应的扩展支持。

### 安装PHP

php推荐使用 REMI 源，[http://mirror.innosol.asia/remi/](http://mirror.innosol.asia/remi/)。

CentOS 7/RHEL/Scientific Linux 7 x86_64 安装：
```
yum install https://mirrors4.tuna.tsinghua.edu.cn/remi/enterprise/remi-release-7.rpm
```
CentOS 6/RHEL/Scientific Linux 6 i386 or x86_64安装：
```
yum install https://mirrors4.tuna.tsinghua.edu.cn/remi/enterprise/remi-release-7.rpm
```

安装成功后，修改 `vim /etc/yum.repos.d/remi-php70.repo` 文件，将
`[remi-php70]`标签下的 `enabled=0` 改成 `enabled=1`，这样就默认用php7了(要启用 php7.1 则修改 `remi-php71.repo` 文件)。

然后执行
```bash
yum install php php-swoole php-yaml php-msgpack
```
即可。

更多的安装方法见：[Install PHP 7.0 (7.0.1, 7.0.2, 7.0.3 & 7.0.4) on Linux](http://www.2daygeek.com/install-php-7-on-ubuntu-centos-debian-fedora-mint-rhel-opensuse/)


### 高级服务器集群方案

在单机功能上再加入集群功能，可以让服务器变得更加强悍，如果你的服务器是像传统php那样“无状态”的，那么只需要用 nginx, haproxy 等做一个负载均衡器就可以了，无需使用此方案，但是如果你要做的是一个有状态的服务器，也许就没那么简单了。

这里先来普及下什么是有状态什么是无状态，一般情况下，无状态的服务就是程序本身不存任何数据，它通过第三方存储（比如 mysql、redis、memcache）等，客户端请求可以发往任何一个服务器任何一个进程处理；而有状态的服务器，它的数据也许最终是会存到 mysql 等服务器里，但是运行期间也许为了服务器性能等很多原因，数据是直接放在进程里面的，这样的服务器就是有状态的，比如大部分游戏服务器都是有状态的。

### 连接池、资源池

得益于swoole的强大，在php下可以提供连接池服务，使得程序可以更加强劲、灵活，但是 swoole 并没有提供一整套简单易用的方案，MyQEE 服务器类库则提供了一套简单易用的方案。


### 基本对象

类名称                           |  说明
--------------------------------|--------------------
`\MyQEE\Server\Server`          | 服务器对象
`\MyQEE\Server\ServerRedis`     | 支持Redis协议服务器对象
`\MyQEE\Server\Worker`          | 工作进程基础对象
`\MyQEE\Server\WorkerTask`      | 任务进程基础对象
`\MyQEE\Server\WorkerTCP`       | 自定义TCP协议的进程基础对象
`\MyQEE\Server\WorkerUDP`       | 自定义UDP协议的进程基础对象
`\MyQEE\Server\WorkerHttp`      | Http协议的进程基础对象
`\MyQEE\Server\WorkerWebSocket` | 支持WebSocket协议的进程基础对象
`\MyQEE\Server\WorkerAPI`       | API类型的进程基础对象
`\MyQEE\Server\WorkerManager`   | 管理后台类型的进程基础对象
`\MyQEE\Server\WorkerRedis`     | 支持Redis协议的进程基础对象
`\MyQEE\Server\WorkerCustom`    | 托管在Manager里和Worker、Task平级的独立的自定义子进程基础对象
`\MyQEE\Server\WorkerHttpRangeUpload` | 支持断点续传、分片上传的大文件上传服务器对象
`\MyQEE\Server\WorkerHprose`    | 支持Hprose的RPC服务器对象
`\MyQEE\Server\Action`          | 一个简单好用的类似控制器的Http请求动作对象基础类
`\MyQEE\Server\Message`         | 可以用于进程间通信的数据对象

### 如何使用

一个传统的 Swoole 包括：

* Reactor线程，它是真正处理TCP连接，收发数据的线程；
* Manager进程，管理Swoole内部的进程，这个一般不需要关心；
* Worker进程，它接受 Reactor 线程投递的请求数据包，是真正php业务处理的进程；
* Task进程，接受 Worker 进程投递的任务，通常用于辅助Worker进程处理耗时的或需要异步处理的数据任务；

详细的说明见：http://wiki.swoole.com/wiki/page/163.html

我们一般开发 Swoole 服务器只需要实现 Worker 进程相关业务逻辑即可，复杂一些的服务器可以用 Task 进程来进行配合使用。为了优化代码结构，MyQEE 服务器类库里为每一个监听的端口分配了一个 Worker 对象，一般情况下你只需要关心 `WorkerMain` 和 `WorkerTask` 的相关代码实现即可。

#### Worker进程
你需要创建一个 `WorkerMain` 的类(可以自定义类名称，见 `bin/example/server-full.yal` 文件配置样例)，然后根据你服务的特性选择继承到对应的类上面，选择的方式如下：

* 如果不需要任何 http、websocket 相关服务，TCP的继承到 `\MyQEE\Server\WorkerTCP` 并实现 `onReceive` 方法，UDP服务继承到 `\MyQEE\Server\WorkerUDP` 类，并实现 `onPacket` 方法；
* 如果需要 Http 但不需要 WebSocket，则继承 `\MyQEE\Server\WorkerHttp` 类，实现 `onRequest` 方法，这个方法系统默认已经提供，使用方法详见下面 Http 使用部分；
* 如果你的服务需要 WebSocket，则继承 `\MyQEE\Server\WorkerWebSocket` 类，实现 `onMessage` 方法，也可以实现 `onOpen` 方法；
* 如果服务即需要 Http 也需要 WebSocket，仍旧是继承 `\MyQEE\Server\WorkerWebSocket`，同时实现即可；
* 如果需要大文件上传服务器，则继承 `\MyQEE\Server\WorkerHttpRangeUpload`，它具备 `\MyQEE\Server\WorkerHttp` 所有功能，特有 `checkAllow($request)` 方法你可以自行实现，它在收到POST头信息时就会调用（不需要等到文件上传完毕），返回 `false` 则立即断开服务禁止上传文件，全部文件上传完毕后会调用 `onRequest($request, $response)` 方法；

**注意：** 若使用 Http 或 WebSocket 需要在配置中将 `server.http.use` 设置成 `true`。

```php
<?php
class WorkerMain extends MyQEE\Server\WorkerHttp
{
    public function onRequest($request, $response)
    {
        $response->end('hello world');
    }
}
```
以上是代码样例

#### Task进程

Task进程是一个可以帮 Worker 进程异步处理数据的进程，你可以将比较耗时的数据投递给 task 去处理。

如果你需要使用 task 功能，你只需要创建一个 `WorkerTask` 的类，并继承到 `MyQEE\Server\WorkerTask` 上，然后实现 `function onTask($server, $taskId, $fromId, $data, $fromServerId = -1)` 方法即可，有数据投递时，系统会回调此方法。注意，比 swoole 的参数多一个 `fromServerId` 参数，然后就可以使用 Task 相关功能了。

```php
<?php
class WorkerTask extends MyQEE\Server\WorkerTask
{
    public function onTask($server, $taskId, $fromId, $data, $fromServerId = -1)
    {
        var_dump($data);
    }
}
```
以上是代码样例

#### Custom进程

自定义子进程并不是swoole内的概念，而是 MyQEE Server 特有的功能，它通常用于提供你自主的创建一个新的进程用于处理一些额外的任务（比如各种定时器任务、独立功能的任务等等）

它是利用Swoole的 `$server->addProces()` 方法在服务器启动时创建的用户自定义进程，和worker进程平级并且支持异步功能，并且当子进程异常退出时系统会自动重新创建。使用方法：

首先在配置中加入 

```yaml
customWorker:
  Test:
    name: myTest
    class: myTestClass
```

这样的配置，然后创建类文件 `myTestClass`，（其中 myTestClass 就是配置中定义的 class 的名称，支持命名空间形式）内容大致如下：

```php
<?php
class myTestClass extends \MyQEE\Server\CustomWorker
{
    public function onStart()
    {
        # 创建一个定时器
        swoole_timer_tick(1000 * 10, function()
        {
            echo 'hello world';
        });
    }
}
```

这样在启动服务器时，系统就会自动创建一个很 worker 平级的子进程运行这个自定义的子进程。注意，创建的自定义子进程在调用 Swoole 的 `$server->reload()` 方法时是不会重新加载的，可以使用 MyQEE Server 的 `reload()` 方法或 `reloadCustomWorker()` 方法进程重新加载子进程


#### 多端口使用

配置选项中 `hosts` 项目可以任意添加多个，例如:

```yaml
hosts:
  # 服务1，http 类型，监听端口 9000
  Main:
    type: http
    host: 0.0.0.0
    port: 9001
    listen:
      - tcp://0.0.0.0:9010   # 再额外监听一个 9010 端口
    name: MQSRV               # 会输出 Server: MQSRV 的头信息
    # class: WorkerHttpTest   # 自定义抽象化的类名称

  # 自定义端口
  Test1:
    type: tcp
    host: 127.0.0.1
    port: 2200
    conf:
      # 端口监听参数设置 see http://wiki.swoole.com/wiki/page/526.html
      open_eof_check: true
      open_eof_split: true
      package_eof: "\n"
  Test2:
    link: tcp://0.0.0.0:1314
    class: MyTest2Worker
    conf:
      open_eof_check: true
      open_eof_split: true
      package_eof: "\n"
```

表示监听一个TCP端口服务，此时你需要创建一个 `WorkerTest` 对象并继承到 `\MyQEE\Server\WorkerTCP`，然后实现 `onReceive` 方法即可。

#### 入口文件
```php
<?php
class WorkerTest extends MyQEE\Server\WorkerTCP
{
    public function onReceive($server, $fd, $fromId, $data)
    {
        var_dump($data);
    }
}
```
以上是代码样例


以下是服务器的最基本的启动代码：

```php
#!/usr/bin/env php
<?php
require __DIR__ .'/../vendor/autoload.php';

use MyQEE\Server\Server;

$server = new Server(__DIR__ .'/server.yal');

$server->start();
```


### 高级使用

#### API控制器的使用

`MyQEE\Server\WorkerAPI` 实现了一个简单好用的 Action 的调度（类似于一个简单的控制器逻辑），默认使用根目录下 api 目录，你可以创建一个 test.php 内容如下：

```php
<?php
return function($request, $response)
{
    /**
     * @var \Swoole\Http\Request $request
     * @var \Swoole\Http\Response $response
     */
    print_r($request);
    
    return "hello world, now is: ". time();
};
```

启动服务器后，你就可以在访问 `http://127.0.0.1:8080/api/test` 了，其中 8080 是你监听的端口。得到的内容如下：

```
HTTP/1.1 200 OK
Server: MQSRV
Content-Type: application/json
Connection: keep-alive
Date: Thu, 04 May 2017 06:33:22 GMT
Content-Length: 61

{"data":"hello world, now is: 1493879602","status":"success"}
```

#### 使用协程开发

在 MyQEE/Server 中使用协程是非常简单的。在大多数回调函数里 yield 一个协程处理器系统就会自动调度构造器运行。例如 Http 服务器只需要在 WorkerMain 的 onRequest 使用 yield 关键字即可：

```php
<?php
class WorkerMain extends \MyQEE\Server\WorkerHttp
{
    public function onRequest($request, $response)
    {
        $begin = microtime(true);
        for ($i = 0; $i < 1000; $i++)
        {
        	  yield $this->test($request->fd);
        }
        $response->end($request->fd . ' use time: ' .(microtime(true) - $begin));
        
        yield;
    }
    
    public function test($fd)
    {
        echo "fd: $fd - ". microtime(true) ."\n";
        usleep(50000);
    }
}
```

MyQEE/Server 的协程调度器是异步+并行执行，以上例子在只有1个进程的情况下，如果同时有2个url请求，输出的结果可能是这样：

```
fd: 1 - 1511332850.6000
fd: 1 - 1511332850.6500
fd: 2 - 1511332850.6500
fd: 1 - 1511332850.7000
fd: 2 - 1511332850.7000
fd: 1 - 1511332850.7500
fd: 2 - 1511332850.7500
```

需要注意的是：协程并不缩短程序运行时间，在执行每一步的时候，它仍旧是独占进程的，但是在每个 yield 关键词的位置，它是可以被“暂时中断”的，这样的好处就是你可以控制程序的运行，比如需要一个 mysql 或 tcp 或 http 请求，如果是传统的写法，必须等到程序返回时才会进行下一个处理，后面的请求都得排队等候，所以会耗费更多的时间，因为大部分时间都在“等候”中浪费了，而通过协程并行执行，每个请求都会得到及时的处理，从而大大提高并发处理能力，但是此时耗费的内存可能会更大。

在 Worker 进程中，提供了如下方法： 

* `function addCoroutineScheduler(\Generator $gen)`
* `function parallelCoroutine(\Generator $genA, \Generator $genB, $genC = null, ...)` 

`addCoroutineScheduler` 这个方法的用途是让你自己加入一个异步并行调度的调度器，比如：

```php
$fun = function() {
   for ($i = 0; $i < 100; $i++) {
       yield $i;
   }
}
$worker->addCoroutineScheduler($fun());
```

这样就把 `$fun` 放在了系统并行调度器列队里了，可以用于非协程+协程混合编写的情景下。

`parallelCoroutine` 这个方法提供了并行调度的能力。你可能会有疑问，前面不是说 MyQEE/Server 的协程调度器是并行调度的么？怎么又提供这样的并行调度功能？前面提到的并行调度能力是在不同的请求或不同的异步方法里这样实现的，在同一个协程栈里实际上还是得按顺序执行，比如下面这个协程栈：

```php
$fun = function($key) {
   for ($i = 0; $i < 100; $i++) {
       echo "$key - $i\n";
       yield;
   }
   yield $key;
}
$rs1 = yield $fun('a');
$rs2 = yield $fun('b');
```

这样的写法，你会发现程序最终还是按按顺序执行的。所以如果是两个没有上下文关系的协程你是可以使用 `parallelCoroutine()` 方法实现并行调度的，代码如下：

```php
$fun = function($key) {
   for ($i = 0; $i < 100; $i++) {
       yield echo "$key - $i\n";
   }
   yield $key;
}
list($rs1, $rs2) = yield $worker->parallelCoroutine($fun('a'), $fun('b'));
// 如果不需要得到 $rs1, $rs2 也可以这样加入异步的协程处理队列：
// $worker->addCoroutineScheduler($fun('a'));
// $worker->addCoroutineScheduler($fun('b'));
```

协程客户端：`class MyQEE\Server\Coroutine\Client` 用法：

```php
// 其中 $worker 是当前进程对象

use MyQEE\Server\Coroutine\Client;

$time    = microtime(1);
$client1 = new Client();
$client2 = new Client();
$c1      = $client1->connect('127.0.0.1', 8901);
$c2      = $client2->connect('127.0.0.1', 8901);

list($cc1, $cc2) = yield $worker->parallelCoroutine($c1, $c2);
var_dump($cc1, $cc2);

$client1->send("aaaa\n");
$client2->send("bbbb\n");

// 顺序调度
$d2 = yield $client2->recv();
$d1 = yield $client1->recv();
// 并行调度
// list($d1, $d2) = yield $worker->parallelCoroutine($client1->recv(), $client2->recv());

echo 'd1 = ';
var_dump($d1);

echo 'd2 = ';
var_dump($d2);

echo "done\n";
var_dump(microtime(1) - $time);
```

#### 支持集成 Hprose RPC服务端

需要安装 `hprose/hprose`, `hprose/hprose-swoole`，安装方法：

```bash
composer require hprose/hprose:dev-master hprose/hprose-swoole:dev-master
``` 

支持 tcp、http、webSocket 协议服务端，使用方法同普通的Worker，取代了 Hprose 官网提供的swoole-server版本，事件支持相同，见
[https://github.com/hprose/hprose-php/wiki/07-Hprose-%E6%9C%8D%E5%8A%A1%E5%99%A8%E4%BA%8B%E4%BB%B6](https://github.com/hprose/hprose-php/wiki/07 Hprose 服务器事件)

如果要扩展 `onBeforeInvoke` 方法，只需要在 `WorkerHprose` 里扩展 `onBeforeInvoke` 方法即可。 


### Event事件的使用

在 worker 对象中会自动创建一个 `MyQEE\Server\Event` 对象，可以通过 `$worker->event` 获取对象。使用方法：

```php
# 绑定
$worker->event->on('test', function() 
{
    echo "test\n";
});

# 触发
$worker->event->trigger('test');
```

### 常见问题

* 问：MyQEE 服务器类库提供了这么多功能，性能是否会有损失？<br>答：和你自己写的原生服务器差不多，几乎不会有什么性能损失；
* 问：使用 MyQEE 开发的服务再提供给别人使用，但是不希望有那么多配置，如何精简处理？<br>答：你可以自己写一个类继承到 `MyQEE\Server\Server` 上，然后把一些不怎么用的配置写到自己类里面，把最终的配置用数组（`$config`）传给 `parent::__construct($config)` 就可以；
* 问：我是新手，用这个复杂吗？<br>答：MyQEE类库可谓是新手的福音，因为有了它你再也不用担心 swoole 里那些复杂的功能关系，比如 task、worker 等关系和功能差别，多端口时怎么绑定服务，怎么分配回调等等；
* 问：我是老手，用这个合适吗？<br>答：MyQEE类库为复杂编程而生，如果你要创建一个多端口或者是即有http又有tcp等的服务器，用 MyQEE 服务器类库可以让你的基础代码规划更加合理，因为类库帮你把相应的功能分配到了对应的对象上了；
* 问：WorkerAPI 的路径必须 /api/ 开头，如何可以从根目录开始？<br>答：你可以在对应的 Hosts 参数里加一个 prefix 的参数值为 / 即可，也可以自己实现一个 Worker 对象继承到 `MyQEE\Server\WorkerAPI` 上并覆盖默认值 `$prefix`


### License

Apache License Version 2.0 see http://www.apache.org/licenses/LICENSE-2.0.html


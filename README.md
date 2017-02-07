# MyQEE 服务器框架

[![Build Status](https://img.shields.io/wercker/ci/wercker/docs.svg)](https://packagist.org/packages/myqee/server)
![Supported PHP versions: 5.5 .. 7.1](https://img.shields.io/badge/php-5.5~7.1-blue.svg)
![License](https://img.shields.io/hexpm/l/plug.svg)
![Packagist](https://img.shields.io/packagist/v/myqee/server.svg)

### 介绍

MyQEE 服务器框架基于 Swoole 开发，将服务的各项功能通过对象化思路进行开发，填补了在 Swoole 开发中遇到的各种坑，每个端口服务对应一个对象，使得代码更加清晰有条理，并解决了使用 Swoole 开发服务器的一些痛点。

### 服务器选型

很多初学者在写自己的服务器时非常迷茫，不知道到底用 `swoole_server` 还是 `swoole_http_server` 还是 `swoole_websocket_server`，用MyQEE服务器框架则不需要纠结这个问题。不管你是要创建自有的TCP服务还是HTTP还是WebSocket服务，以及多端口监听，你只需要在配置文件里简单设置，然后实现对应业务层面的代码即可，系统会根据配置情况自动选型服务器、监听端口并绑定对象回调方法。

### 已实现或将会实现的功能和方案

* 易于使用的多重混合服务器端口监听方案；
* Worker、TaskWorker 面向对象化代码结构；
* `MyQEE\Server\Table` 继承 `Swoole\Table` 并支持数据落地、重启恢复，数据落地提供灵活的设置：本地文件、数据库、Redis、SSDB(LevelDB 的 Redis 协议实现)、RocksDB；
* 服务器集群方案，服务器间RPC调用，支持任意服务器间进程发送消息；
* 日志输出；
* 多线程方案；
* 后台管理功能方案；
* API 功能方案；
* 连接池、资源池；
* 热更新、不停服重新加载代码方案；

### 快速使用

请使用 `composer` 进行安装（see https://getcomposer.org/doc/00-intro.md or http://docs.phpcomposer.com/00-intro.html）
创建 `composer.json` 文件，内容如下：

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

创建文件 `classes/WorkerMain.php`，内容如下：

```php
<?php
# Http的工作进程对象
class WorkerMain extendsMyQEE\Server\WorkerHttp
{
    public function onRequest($request, $response)
    {
        $response->end('hello world');
    }
}
```

创建文件 `classes/WorkerTask.php`，内容如下：

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

然后执行 `composer install` 安装服务器框架，此时你可以看到 `bin/` 目录下有 `example-server` 和 `example-server-lite.yal` 文件。执行 `./bin/example-server` 启动服务，打开浏览器访问 `http://127.0.0.1:9000/`。

**实际开发时建议将 `example-server` 和 `example-server-lite.yal` 文件复制出来后自行修改。**

### 自定义服务器配置

在 bin/ 目录下，有 `example-server-lite.yal` 和 `example-server-full.yal` 配置样例文件，lite 文件是比较简洁的常用配置文件，参照使用即可；full 文件是完整的配置，适合深度配置。

一般情况下，只需要根据自己的服务器定义好 hosts 里的服务器配置就可以了（类型、监听端口）非常简单，然后再实现对应的类的方法。


#### 错误解决

 * 如果网络很慢或被墙，可执行 `composer config -g repo.packagist composer https://packagist.phpcomposer.com` 使用国内的镜像；
 * 如果报 `The requested package myqee/server ~1.0 exists as myqee/server[dev-master] but these are rejected by your constraint.` 错误，是因为现在还没有发布正式1.0版本，可以把 `"require": {"myqee/server": "~1.0"}` 去掉，只用 master 分支即可；

### 程序依赖

PHP 扩展：Swoole (>=1.8.0), Yaml，如果开启集群模式，必须安装 MsgPack 扩展，如果使用到 Redis、MySQL、RocksDB 等则需要相应的扩展支持。

### 安装程序

php推荐使用 REMI 源，[http://mirror.innosol.asia/remi/](http://mirror.innosol.asia/remi/)。

CentOS 7/RHEL/Scientific Linux 7 x86_64 安装：
```
yum install http://mirror.innosol.asia/remi/enterprise/remi-release-7.rpm
```
CentOS 6/RHEL/Scientific Linux 6 i386 or x86_64安装：
```
yum install http://mirror.innosol.asia/remi/enterprise/remi-release-6.rpm
```

安装成功后，修改 `vim /etc/yum.repos.d/remi-php70.repo` 文件，将
`[remi-php70]`标签下的 `enabled=0` 改成 `enabled=1`，这样就默认用php7了。

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

得益于swoole的强大，在php下可以提供连接池服务，使得程序可以更加强劲、灵活，但是 swoole 并没有提供一整套简单易用的方案，MyQEE 服务器框架则提供了一套简单易用的方案。


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

### 如何使用

一个传统的 Swoole 包括：

* Reactor线程，它是真正处理TCP连接，收发数据的线程；
* Manager进程，管理Swoole内部的进程，这个一般不需要关心；
* Worker进程，它接受 Reactor 线程投递的请求数据包，是真正php业务处理的进程；
* Task进程，接受 Worker 进程投递的任务，通常用于辅助Worker进程处理耗时的或需要异步处理的数据任务；

详细的说明见：http://wiki.swoole.com/wiki/page/163.html

我们一般开发 Swoole 服务器只需要实现 Worker 进程相关业务逻辑即可，复杂一些的服务器可以用 Task 进程来进行配合使用。为了优化代码结构，MyQEE 服务器框架里为每一个监听的端口分配了一个 Worker 对象，一般情况下你只需要关心 `WorkerMain` 和 `WorkerTask` 的相关代码实现即可。

#### Worker进程
你需要创建一个 `WorkerMain` 的类，然后根据你服务的特性选择继承到对应的类上面，选择的方式如下：

* 如果不需要任何 http、websocket 相关服务，TCP的继承到 `\MyQEE\Server\WorkerTCP` 并实现 `onReceive` 方法，UDP服务继承到 `\MyQEE\Server\WorkerUDP` 类，并实现 `onPacket` 方法；
* 如果需要 Http 但不需要 WebSocket，则继承 `\MyQEE\Server\WorkerHttp` 类，实现 `onRequest` 方法，这个方法系统默认已经提供，使用方法详见下面 Http 使用部分；
* 如果你的服务需要 WebSocket，则继承 `\MyQEE\Server\WorkerWebSocket` 类，实现 `onMessage` 方法，也可以实现 `onOpen` 方法；
* 如果服务即需要 Http 也需要 WebSocket，仍旧是继承 `\MyQEE\Server\WorkerWebSocket`，同时实现即可；

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



### 常见问题

* 问：MyQEE 服务器框架提供了这么多功能，性能是否会有损失？<br>答：和你自己写的原生服务器差不多，几乎不会有什么性能损失；
* 问：使用 MyQEE 开发的服务再提供给别人使用，但是不希望有那么多配置，如何精简处理？<br>答：你可以自己写一个类继承到 `MyQEE\Server\Server` 上，然后把一些不怎么用的配置写到自己类里面，把最终的配置用数组（`$config`）传给 `parent::__construct($config)` 就可以；


### License

Apache License Version 2.0 see http://www.apache.org/licenses/LICENSE-2.0.html


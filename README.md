# MyQEE 服务器框架

[![Build Status](https://img.shields.io/wercker/ci/wercker/docs.svg)](https://packagist.org/packages/myqee/server)
![Supported PHP versions: 5.5 .. 7.1](https://img.shields.io/badge/php-5.5~7.1-blue.svg)
![License](https://img.shields.io/hexpm/l/plug.svg)
![Packagist](https://img.shields.io/packagist/v/myqee/server.svg)

### 介绍

MyQEE 服务器框架基于 Swoole 扩展开发，是本人经过1年多的开发实践后提炼的代码，填补了在 swoole 开发中遇到的各种坑，解决了使用 swoole 开发服务器的一些痛点，可以避免重走我的老路。

本框架和 Swoole 官方的 framework 的差别在于官方提供了更多服务器的代码实现，而本框架的重点并不是各种服务功能的实现，而是提供了一种更好的编程结构规划，这样当你面对日益复杂的功能时可以更加从容，这是一个即适合新手也适合高手的服务器框架。

如果你不是一个PHP老手、也不是具有很高服务器架构功底和实战的人，用 swoole 写一些简单的 demo 也许可以，但是试图在 swoole 上开发大型服务程序还是很有挑战的。虽然 swoole 提供的类和功能看似并不多，但是它带来的是编程思路是完全颠覆性的改变，你需要很好的理解它的运行原理，然后针对你的服务器部署方案进行编程，但是也许一开始就错了，因为相同功能可以有很多种方案，不同的量级有不同的处理方式，但是没有经过大量的测试很难确定哪个可行，这就是经验，在此框架里，我把这些经验都加入进去了，解决了用 swoole 开发时的很多痛点，很多的方案都是已经得到验证的，也欢迎更多的人一起贡献代码。


### 服务器选型

很多初学者在写自己的服务器时非常迷茫，不知道到底用 swoole_server 还是 swoole_http_server 还是 swoole_websocket_server，用MyQEE服务器框架则不需要纠结这个问题。不管你是要创建自有的TCP服务还是HTTP还是WebSocket服务，甚至是多端口监听，你只需要在配置文件里简单设置，然后实现对应业务层面的代码即可。

### 已实现或将会实现的功能和方案

* 多重混合服务器端口监听方案；
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
            "":"classes/"
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

然后执行 `composer install` 安装服务器框架，此时你可以看到 `bin/` 目录下有 `example-server` 和 `example-server.yaml` 文件。然后参考“如何使用”章节。

#### 错误解决

 * 如果网络很慢或被墙，可执行 `composer config -g repo.packagist composer https://packagist.phpcomposer.com` 使用国内的镜像；
 * 如果报 `The requested package myqee/server ~1.0 exists as myqee/server[dev-master] but these are rejected by your constraint.` 错误，是因为现在还没有发布正式1.0版本，所以可以把 `"require": {"myqee/server": "~1.0"}` 去掉，只用 master 分支即可；

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
`\MyQEE\Server\Worker`          | 工作进程对象
`\MyQEE\Server\WorkerTask`      | 任务进程对象
`\MyQEE\Server\WorkerTCP`       | 自定义TCP协议的进程对象
`\MyQEE\Server\WorkerUDP`       | 自定义UDP协议的进程对象
`\MyQEE\Server\WorkerHttp`      | Http协议的进程对象
`\MyQEE\Server\WorkerWebSocket` | 支持WebSocket协议的进程对象

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
        $response->end('asdfasdfs');
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

配置选项中有一个 `sockets` 项目，可以任意添加，例如:

```yaml
  Test:
    link: tcp://0.0.0.0:1314
    conf:
      # 更多参数见 http://wiki.swoole.com/wiki/page/526.html
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

$server = new Server(__DIR__ .'/server.yaml');

$server->start();
```



### 常见问题

* 问：MyQEE 服务器框架提供了这么多功能，性能是否会有损失？<br>答：和你自己写的原生服务器差不多，几乎不会有什么性能损失；
* 问：使用 MyQEE 开发的服务再提供给别人使用，但是不希望有那么多配置，如何精简处理？<br>答：你可以自己写一个类继承到 `MyQEE\Server\Server` 上，然后把一些不怎么用的配置写到自己类里面，把最终的配置用数组（`$config`）传给 `parent::__construct($config)` 就可以；


### License

Apache License Version 2.0 see http://www.apache.org/licenses/LICENSE-2.0.html


<?php
namespace Swoole
{
    class Server extends \swoole_server{}
    class Client extends \swoole_client{}
    class Process extends \swoole_process{}
    class Timer extends \swoole_timer{}
    class Table extends \swoole_table{}
    class Lock extends \swoole_lock{}
    class Atomic extends \swoole_atomic{}
    class Buffer extends \swoole_buffer{}
    class Redis extends \swoole_redis{}
    class Event extends \swoole_event{}
    class Module extends \swoole_module{}
}

namespace Swoole\Server
{
    class Port extends \swoole_server_port{}
}

namespace Swoole\Http
{
    class Server extends \swoole_http_server{}
    class Client extends \swoole_http_client{}
    class Request extends \swoole_http_request{}
    class Response extends \swoole_http_response{}
}

namespace Swoole\WebSocket
{
    class Server extends \swoole_websocket_server{}
}
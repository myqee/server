<?php

namespace MyQEE\Server\Logger;

class StdoutHandler extends \Monolog\Handler\AbstractProcessingHandler {
    protected function write(array $record) {
        echo $record['formatted'];
    }
}
<?php
namespace MyQEE\Server\Logger;

class SpecialProcessHandler extends \Monolog\Handler\AbstractProcessingHandler
{
    protected function write(array $record)
    {
        Lite::tryWriteLog($record['level'], $record['formatted']);
    }
}
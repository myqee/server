<?php
namespace MyQEE\Server\Logger;

class SpecialProcessHandler extends \Monolog\Handler\AbstractProcessingHandler
{
    protected function write(array $record)
    {
        Lite::tryWriteLogToSpecialProcess($record['level'], $record['formatted']);
    }
}
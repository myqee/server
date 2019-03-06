<?php
namespace MyQEE\Server\Logger;

use MyQEE\Server\Logger;

class LineFormatter extends \Monolog\Formatter\NormalizerFormatter
{
    public $withColor = null;

    /**
     * {@inheritdoc}
     */
    public function format(array $record)
    {
        $withColor = is_bool($this->withColor) ? $this->withColor : Lite::$logPathByLevel[$record['level']] === true;

        if ($record['level'] === Logger::TRACE)
        {
            return Lite::formatTraceToString($record, $record['context'], 4, $withColor);
        }
        else
        {
            return Lite::formatToString($record, $withColor);
        }
    }
}
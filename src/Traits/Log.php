<?php

namespace MyQEE\Server\Traits;

trait Log {
    /**
     * 普通Log信息
     *
     * @param string $message
     * @param array $context
     */
    final public function log($message, array $context = []) {
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::NOTICE, $message, $context);
    }

    /**
     * 输出信息
     *
     * @param string $message
     * @param array $context
     */
    final public function info($message, array $context = []) {
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::INFO, $message, $context);
    }

    /**
     * 输出紧急信息
     *
     * @param string $message
     * @param array $context
     */
    final public function emergency($message, array $context = []) {
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::EMERGENCY, $message, $context);
    }

    /**
     * 输出警报信息
     *
     * @param string $message
     * @param array $context
     */
    final public function alert($message, array $context = []) {
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::ALERT, $message, $context);
    }

    /**
     * 输出危急信息
     *
     * @param string $message
     * @param array $context
     */
    final public function critical($message, array $context = []) {
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::CRITICAL, $message, $context);
    }

    /**
     * 输出注意信息
     *
     * @param string $message
     * @param array $context
     */
    final public function notice($message, array $context = []) {
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::NOTICE, $message, $context);
    }

    /**
     * 错误信息
     *
     * @param string|\Exception|\Traversable $message
     * @param array $context
     */
    final public function error($message, array $context = []) {
        \MyQEE\Server\Logger::convertTraceMessage($trace, $context);
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::ERROR, $message, $context);
    }

    /**
     * 警告信息
     *
     * @param string|\Exception|\Traversable $message
     * @param array $context
     */
    final public function warn($message, array $context = []) {
        \MyQEE\Server\Logger::convertTraceMessage($trace, $context);
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::WARNING, $message, $context);
    }

    /**
     * 警告信息
     *
     * @param string|\Exception|\Traversable $message
     * @param array $context
     */
    final public function warning($message, array $context = []) {
        \MyQEE\Server\Logger::convertTraceMessage($trace, $context);
        \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::WARNING, $message, $context);
    }

    /**
     * 调试信息
     *
     * @param string|\Exception|\Throwable $log
     * @param array $data
     */
    final public function debug($message, array $context = []) {
        if (true === \MyQEE\Server\Logger::$isDebug) {
            \MyQEE\Server\Logger::convertTraceMessage($trace, $context);
            \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::DEBUG, $message, $context);
        }
    }

    /**
     * 跟踪信息
     *
     * 如果需要扩展请扩展 `$this->saveTrace()` 方法
     *
     * @param string|\Exception|\Throwable $trace
     * @param array $context
     */
    final public function trace($trace, array $context = []) {
        if (true === \MyQEE\Server\Logger::$isTrace) {
            \MyQEE\Server\Logger::convertTraceMessage($trace, $context);
            \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::TRACE, $trace, $context);
        }
        elseif (is_object($trace) && ($trace instanceof \Exception || $trace instanceof \Throwable)) {
            \MyQEE\Server\Logger::convertTraceMessage($trace, $context);
            \MyQEE\Server\Logger::instance()->addRecord(\MyQEE\Server\Logger::WARNING, $trace, $context);
        }
    }
}
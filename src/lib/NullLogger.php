<?php
require_once __DIR__ . '/psr/LogLevel.php';
require_once __DIR__ . '/psr/LoggerInterface.php';
require_once __DIR__ . '/psr/AbstractLogger.php';

use Psr\Log\AbstractLogger;

class NullLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void {}
}

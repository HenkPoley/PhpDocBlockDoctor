<?php
namespace Pitfalls\PropertyTypedStaticCallNullable;

class Logger {
    public static function warning(): void {
        throw new \RuntimeException('fail');
    }
}

class Service {
    private ?Logger $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function doCall(): void {
        $this->logger::warning();
    }
}

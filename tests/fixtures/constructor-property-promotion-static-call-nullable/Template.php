<?php
namespace Pitfalls\ConstructorPropertyPromotionStaticCallNullable;

class Logger {
    public static function warning(): void {
        throw new \RuntimeException('fail');
    }
}

class Service {
    public function __construct(private ?Logger $logger) {}

    public function doCall(): void {
        $this->logger::warning();
    }
}

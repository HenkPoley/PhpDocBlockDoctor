<?php
// tests/fixtures/property-static-call/SubjectStaticPropertyCall.php
namespace Pitfalls\PropertyStaticCall;

class Logger {
    public static function warning(): void {
        throw new \RuntimeException('fail');
    }
}

class SubjectID {
    /**
     * @var Logger|string
     * @psalm-var Logger|class-string
     */
    protected $logger = Logger::class;

    public function doCall(): void {
        $this->logger::warning();
    }
}

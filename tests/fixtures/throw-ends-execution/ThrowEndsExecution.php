<?php
// tests/fixtures/throw-ends-execution/ThrowEndsExecution.php
namespace Pitfalls\ThrowEndsExecution;

class Helper {
    public function boom(): void {
        throw new \RuntimeException('fail');
    }
}

class Runner {
    public function run(): void {
        throw new \LogicException('stop');
        (new Helper())->boom();
    }
}

class Caller {
    public function call(): void {
        (new Runner())->run();
    }
}

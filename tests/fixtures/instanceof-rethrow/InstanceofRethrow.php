<?php
// tests/fixtures/instanceof-rethrow/InstanceofRethrow.php
namespace Pitfalls\InstanceofRethrow;

class Worker {
    public function doWork(): void {
        throw new \ErrorException('fail', 0, 1, '', 0, new \Exception('cause'));
    }
}

class Wrapper {
    public function handle(): void {
        try {
            (new Worker())->doWork();
        } catch (\Exception $e) {
            if ($e instanceof \ErrorException) {
                $cause = $e->getPrevious();
                if ($cause instanceof \Exception) {
                    throw $cause;
                }
            }
            throw $e;
        }
    }
}

class Runner {
    public function run(): void {
        (new Wrapper())->handle();
    }
}

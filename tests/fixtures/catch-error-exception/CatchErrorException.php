<?php
namespace Pitfalls\CatchErrorException;

class Thrower {
    public function doThrow(): void {
        throw new \RuntimeException('fail');
    }
}

class Wrapper {
    public function handle(): void {
        try {
            $t = new Thrower();
            $t->doThrow();
        } catch (\Error $e) {
            // swallow errors
        } catch (\Exception $e) {
            // swallow exceptions
        }
    }
}

class Runner {
    public function run(): void {
        (new Wrapper())->handle();
    }
}

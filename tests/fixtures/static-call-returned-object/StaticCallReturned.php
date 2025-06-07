<?php
namespace Pitfalls\StaticCallReturnedObject;

class Provider {
    /**
     * @return Handler
     */
    public static function getHandler(): Handler {
        return new Handler();
    }
}

class Handler {
    public function handle(): void {
        throw new \RuntimeException('fail');
    }
}

class Runner {
    public function run(): void {
        $h = Provider::getHandler();
        $h->handle();
    }
    public function runDirect(): void {
        Provider::getHandler()->handle();
    }
}

<?php
// tests/fixtures/returned-object-assignment/ReturnAssignFixture.php
namespace Pitfalls\ReturnAssign;

class Provider {
    /**
     * @return Handler
     */
    public function getHandler(): Handler {
        return new Handler();
    }
}

class Handler {
    /**
     * @throws \DomainException
     */
    public function handle(): void {
        throw new \DomainException('oops');
    }
}

class Runner {
    public function run(): void {
        $h = (new Provider())->getHandler();
        $h->handle();
    }
}

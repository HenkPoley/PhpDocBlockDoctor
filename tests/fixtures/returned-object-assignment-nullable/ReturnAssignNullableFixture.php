<?php
// tests/fixtures/returned-object-assignment-nullable/ReturnAssignNullableFixture.php
namespace Pitfalls\ReturnedObjectAssignmentNullable;

class Provider {
    public function maybe(): ?Handler {
        return new Handler();
    }
}

class Handler {
    public function handle(): void {
        throw new \DomainException('oops');
    }
}

class Runner {
    public function run(): void {
        $h = (new Provider())->maybe();
        $h->handle();
    }
}

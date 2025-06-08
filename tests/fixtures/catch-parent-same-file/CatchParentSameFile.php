<?php
namespace Pitfalls\CatchParentSameFile;

class ParentException extends \Exception {}
class ChildException extends ParentException {}

class Worker {
    public function doThing(): void {
        throw new ChildException('fail');
    }
}

class Wrapper {
    public function handle(): void {
        try {
            (new Worker())->doThing();
        } catch (ParentException $e) {
            // handled here
        }
    }
}

class Runner {
    public function run(): void {
        (new Wrapper())->handle();
    }
}

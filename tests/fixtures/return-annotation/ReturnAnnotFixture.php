<?php
// tests/fixtures/return-annotation/ReturnAnnotFixture.php
namespace Pitfalls\ReturnAnnotation;

class Builder {
    /**
     * @return Worker
     */
    public function getWorker(): Worker {
        return new Worker();
    }
}

class Worker {
    /**
     * @throws \UnderflowException
     */
    public function work(): void {
        throw new \UnderflowException("oops");
    }
}

class Runner {
    public function go(): void {
        $w = (new Builder())->getWorker();
        $w->work();
    }
}
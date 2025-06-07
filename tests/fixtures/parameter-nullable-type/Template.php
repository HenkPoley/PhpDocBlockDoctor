<?php
namespace Pitfalls\ParameterNullableType;

class Worker {
    /**
     * @throws \RuntimeException
     */
    public function doWork(): void {
        throw new \RuntimeException("fail");
    }
}

class Processor {
    public function run(?Worker $worker): void {
        $worker->doWork();
    }
}

<?php
namespace Pitfalls\ParameterNullableType;

class Worker {
    public function doWork(): void {
        throw new \RuntimeException("fail");
    }
}

class Processor {
    public function run(?Worker $worker): void {
        $worker->doWork();
    }
}

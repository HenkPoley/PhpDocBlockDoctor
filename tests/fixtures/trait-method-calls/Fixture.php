<?php
namespace Pitfalls\TraitMethodCalls;

trait WorkerTrait
{
    public function run(): void
    {
        $this->helper();
    }

    private function helper(): void
    {
        throw new \RuntimeException();
    }
}

class Runner
{
    use WorkerTrait;
}

class Caller
{
    public function execute(): void
    {
        (new Runner())->run();
    }
}

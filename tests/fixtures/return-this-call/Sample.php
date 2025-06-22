<?php
namespace Pitfalls\ReturnThisCall;

class Sample
{
    protected function inner(): void
    {
        throw new \RuntimeException();
    }

    public function outer(): void
    {
        $this->inner();
    }
}

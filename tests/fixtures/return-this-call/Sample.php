<?php
namespace Pitfalls\ReturnThisCall;

class Sample
{
    /**
     * @throws \RuntimeException
     */
    protected function inner(): void
    {
        throw new \RuntimeException();
    }

    public function outer(): void
    {
        return $this->inner();
    }
}

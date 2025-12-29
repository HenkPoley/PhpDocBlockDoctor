<?php

namespace Pitfalls\TraceCallSitesShift;

class Caller
{
    /**
     * @throws \RuntimeException :13
     */
    public function run(): void
    {
        $callee = new Callee();
        $callee->explode();
    }
}

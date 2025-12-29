<?php

namespace Pitfalls\TraceCallSitesShift;

class Caller
{
    public function run(): void
    {
        $callee = new Callee();
        $callee->explode();
    }
}

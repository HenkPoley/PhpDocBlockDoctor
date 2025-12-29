<?php

namespace Pitfalls\TraceCallSitesShift;

class Callee
{
    public function explode(): void
    {
        throw new \RuntimeException('boom');
    }
}

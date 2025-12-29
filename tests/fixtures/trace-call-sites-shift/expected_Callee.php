<?php

namespace Pitfalls\TraceCallSitesShift;

class Callee
{
    /**
     * @throws \RuntimeException :12
     */
    public function explode(): void
    {
        throw new \RuntimeException('boom');
    }
}

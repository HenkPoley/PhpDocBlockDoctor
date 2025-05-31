<?php

namespace HenkPoley\DocBlockDoctor\SubPath;

class ThirdExampleClass
{
    /**
     * @throws \ErrorException
     */
    public function someOtherNonStaticFunction(): void
    {
        throw new \ErrorException();
    }
}
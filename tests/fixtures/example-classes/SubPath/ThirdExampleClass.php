<?php

// tests/fixtures/example-classes/SubPath/ThirdExampleClass.php
namespace HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath;

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
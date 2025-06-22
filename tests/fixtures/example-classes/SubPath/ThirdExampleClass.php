<?php

// tests/fixtures/example-classes/SubPath/ThirdExampleClass.php
namespace HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath;

class ThirdExampleClass
{
    public function someOtherNonStaticFunction(): void
    {
        throw new \ErrorException();
    }
}
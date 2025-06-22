<?php

// tests/fixtures/example-classes/SubPath/OneMoreClass.php
namespace HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath;

class OneMoreClass
{
    public static function aFunction(): void
    {
        self::bFunction();
    }

    public static function bFunction(): void
    {
        throw new \LogicException();
    }

    /** Does not throw any exception by itself */
    public function nonStaticFunction(): \HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath\ThirdExampleClass {
        return new ThirdExampleClass();
    }

    public function nonStaticFunctionThatThrows() {
        throw new \UnderflowException();
    }
}
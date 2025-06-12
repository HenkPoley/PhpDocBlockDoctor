<?php

// tests/fixtures/example-classes/Example.php
namespace HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses;

use Exception;
use HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath\OneMoreClass;
use HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath\ThirdExampleClass;
use LogicException;

class Example
{
    /** @var OneMoreClass */
    private $externalClass;

    /**
     * @throws \Exception
     * @throws \LogicException
     */
    public function one(): void
    {
        $this->two();
        $this->three('input');
        OneMoreClass::aFunction();
    }

    /**
     * @throws \LogicException
     */
    public function two(): void
    {
        if (random_int(0, 1) !== 0) {
            throw new LogicException();
        }
    }

    /**
     * @param string $input Multi-line
     * with emoji
     * 👨‍🎤
     * @see https://en.wikipedia.org/wiki/Roses_Are_Red#Origins
     * Origin of the poem
     *
     * @throws \Exception Violets are blue.
     * I throw an exception, when true equals true.
     */
    public function three(string $input)
    {
        throw new Exception();
    }

    /**
     * @throws \ErrorException
     */
    function usesFunctionOnObjectReturnedFromCallToClassVariable(): void
    {
        $this->externalClass->nonStaticFunction()->someOtherNonStaticFunction();
    }

    /**
     * @throws \UnderflowException
     */
    function usesFunctionOnClassVariable(): void
    {
        $this->externalClass->nonStaticFunctionThatThrows();
    }

    /**
     * @throws \UnderflowException
     */
    function functionVariable(OneMoreClass $oneMoreClass): void
    {
        $oneMoreClass->nonStaticFunctionThatThrows();
    }

    /**
     * @throws \ErrorException
     */
    function callOnNewObject(): void
    {
        $foo = new ThirdExampleClass();
        $foo->someOtherNonStaticFunction();
    }

    /**
     * @return ThirdExampleClass
     */
    function surelyThisReturnsAnObject()
    {

    }

    /**
    * Ought to throw ThirdExampleClass
    */
    function weTrustReturnAnnotations(): void
    {
        $foo = $this->surelyThisReturnsAnObject();
        $foo->someOtherNonStaticFunction();
    }
}
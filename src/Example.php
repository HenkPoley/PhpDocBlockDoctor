<?php

// src/Example.php
namespace HenkPoley\DocBlockDoctor;

use Exception;
use HenkPoley\DocBlockDoctor\SubPath\OneMoreClass;
use HenkPoley\DocBlockDoctor\SubPath\ThirdExampleClass;
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
    public function three($input)
    {
        throw new Exception();
    }

    /**
     * @throws \ErrorException
     */
    function usesFunctionOnObjectReturnedFromCallToClassVariable()
    {
        $this->externalClass->nonStaticFunction()->someOtherNonStaticFunction();
    }

    /**
     * @throws \UnderflowException
     */
    function usesFunctionOnClassVariable()
    {
        $this->externalClass->nonStaticFunctionThatThrows();
    }

    /**
     * @throws \UnderflowException
     */
    function functionVariable(OneMoreClass $oneMoreClass)
    {
        $oneMoreClass->nonStaticFunctionThatThrows();
    }

    /**
     * @throws \ErrorException
     */
    function callOnNewObject()
    {
        $foo = new ThirdExampleClass();
        $foo->someOtherNonStaticFunction();
    }
}
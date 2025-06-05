<?php

namespace HenkPoley\DocBlockDoctor\SubPath;

class OneMoreClass
{
    /**
     * @throws \LogicException
     */
    public static function aFunction(): void
    {
        self::bFunction();
    }

    /**
     * @throws \LogicException
     */
    public static function bFunction(): void
    {
        throw new \LogicException();
    }

    /** Does not throw any exception by itself */
    public function nonStaticFunction(): \HenkPoley\DocBlockDoctor\SubPath\ThirdExampleClass {
        return new ThirdExampleClass();
    }

    /**
     * @throws \UnderflowException
     */
    public function nonStaticFunctionThatThrows() {
        throw new \UnderflowException();
    }
}
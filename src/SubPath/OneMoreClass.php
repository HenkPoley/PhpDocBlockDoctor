<?php

namespace HenkPoley\DocBlockDoctor\SubPath;

class OneMoreClass
{


    /**
     * @throws \LogicException
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function bFunction(): void
    {
        throw new \LogicException();
    }

    /**
     * Does not throw any exception by itself
     */
    public function nonStaticFunction(): ThirdExampleClass {
        return new ThirdExampleClass();
    }

    /**
     * @throws \UnderflowException
     *
     * @return never
     */
    public function nonStaticFunctionThatThrows() {
        throw new \UnderflowException();
    }
}

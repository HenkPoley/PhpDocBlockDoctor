<?php

// src/Example.php
namespace HenkPoley\DocBlockDoctor;

use Exception;
use HenkPoley\DocBlockDoctor\SubPath\OneMoreClass;
use LogicException;

class Example
{
    /**
     * @throws \Exception
     * @throws \LogicException
     */
    public function one()
    {
        $this->two();
        $this->three('input');
        OneMoreClass::aFunction();
    }

    /**
     * @return void
     *
     * @throws \LogicException
     */
    public function two()
    {
        if (random_int(0, 1)) {
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
}
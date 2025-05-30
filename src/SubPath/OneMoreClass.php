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
}
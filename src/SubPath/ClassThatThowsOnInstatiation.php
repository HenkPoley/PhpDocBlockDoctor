<?php

namespace HenkPoley\DocBlockDoctor\SubPath;

/** @psalm-suppress UnusedClass */
class ClassThatThowsOnInstatiation
{
    /**
     * @throws \BadMethodCallException
     */
    function __construct()
    {
        throw new \BadMethodCallException();
    }
}

<?php

namespace HenkPoley\DocBlockDoctor\SubPath;

class ClassThatThowsOnInstatiation
{
    /**
     * @throws \BadMethodCallException
     */
    function __construct()
    {
        throw new \BadMethodCallException();
    }

    /**
     * @throws \BadMethodCallException
     */
    function instantiateSelf()
    {
        return new self();
    }
}
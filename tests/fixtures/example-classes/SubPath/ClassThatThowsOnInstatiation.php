<?php

// tests/fixtures/example-classes/SubPath/ClassThatThowsOnInstatiation.php
namespace HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath;

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
    function instantiateSelf(): self
    {
        return new self();
    }
}
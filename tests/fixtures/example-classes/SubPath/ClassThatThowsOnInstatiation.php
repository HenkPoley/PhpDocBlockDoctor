<?php

// tests/fixtures/example-classes/SubPath/ClassThatThowsOnInstatiation.php
namespace HenkPoley\DocBlockDoctor\TestFixtures\ExampleClasses\SubPath;

class ClassThatThowsOnInstatiation
{
    function __construct()
    {
        throw new \BadMethodCallException();
    }

    function instantiateSelf(): self
    {
        return new self();
    }
}
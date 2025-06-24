<?php
// tests/fixtures/throw-class-string/ThrowClassStrings.php
namespace Pitfalls\ThrowClassString;

/**
 * Required to stop psalm from crashing
 * @see https://github.com/vimeo/psalm/issues/10895#issuecomment-2999982386
 */
class DivisionByZeroError extends \Exception {
}

class ClassName
{
    public static function staticDoubleDoubleColon(): void
    {
        $foo = \RuntimeException::class;
        throw new $foo('failed');
    }

    public function doubleDoubleColon(): void
    {
        $foo = \BadMethodCallException::class;
        throw new $foo('failed');
    }

    public function doubleDoubleColonActualString(): void
    {
        $foo = '\DivisionByZeroError';
        throw new $foo('failed');
    }
}

class ThingThatCalls
{
    public function first(): void
    {
        ClassName::staticDoubleDoubleColon();
    }

    public function second(): void
    {
        $anObject = new \Pitfalls\ThrowClassString\ClassName();
        $anObject->doubleDoubleColon();
    }

    public function third(): void
    {
        $anObject = new \Pitfalls\ThrowClassString\ClassName();
        $anObject->doubleDoubleColonActualString();
    }
}

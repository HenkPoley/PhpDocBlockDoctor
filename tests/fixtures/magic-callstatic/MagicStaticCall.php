<?php
// tests/fixtures/magic-callstatic/MagicStaticCall.php
namespace Pitfalls\MagicStaticCall;

/**
 * @method static void string(mixed $value, string $message = '', string $exception = '')
 * @method static void stringNotEmpty(mixed $value, string $message = '', string $exception = '')
 */
class Assert
{
    /**
     * @param string $name
     * @param array<mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): void
    {
        throw new \RuntimeException('failed');
    }
}

class User
{
    public function doAssert(): void
    {
        Assert::string('abc');
    }
}

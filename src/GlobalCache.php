<?php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

class GlobalCache
{
    /** Max number of origin call chains stored per exception */
    public const MAX_ORIGIN_CHAINS = 5;

    /**
     * @var array<string, string[]>
     */
    public static array $directThrows = [];

    /**
     * @var array<string, string[]>
     */
    public static array $annotatedThrows = [];

    /**
     * @var array<string, array<string, string>>
     */
    public static array $originalDescriptions = [];

    /**
     * @var array<string, string>
     */
    private static array $fileNamespaces = [];

    /**
     * @var array<string, array<string, string>>
     */
    private static array $fileUseMaps = [];

    /**
     * @var array<string, Function_|ClassMethod>
     */
    public static array $astNodeMap = [];

    /**
     * @var array<string, string>
     */
    private static array $nodeKeyToFilePath = [];

    /**
     * @var array<string, string[]>
     */
    public static array $resolvedThrows = [];

    /**
     * @var array<string, string|null> Mapping of class FQCN to its parent class FQCN
     */
    public static array $classParents = [];

    /**
     * @var array<string,string[]> Mapping of class FQCN to the traits it uses
     */
    public static array $classTraits = [];

    /**
     * @var array<string,string[]> Mapping of interface FQCN to implementing class FQCNs
     */
    public static array $interfaceImplementations = [];

    /**
     * @var array<string, array<string, string[]>> Mapping of method key to
     *     exception FQCN to a list of origin call chain strings. For each method,
     *     each chain starts with the call site location within that method,
     *     followed by the sequence of callee method names in order of invocation
     *     and ends with the file and line where the exception was originally
     *     thrown. Example:
     *     "src/File.php:10 <- SomeClass::method <- Other::callee <- vendor/lib.php:5".
     */
    public static array $throwOrigins = [];

    public static function clear(): void
    {
        self::$directThrows = [];
        self::$annotatedThrows = [];
        self::$originalDescriptions = [];
        self::$fileNamespaces = [];
        self::$fileUseMaps = [];
        self::$astNodeMap = [];
        self::$nodeKeyToFilePath = [];
        self::$resolvedThrows = [];
        self::$throwOrigins = [];
        self::$classParents = [];
        self::$classTraits = [];
        self::$interfaceImplementations = [];
    }

    /**
     * @return string[]
     */
    public static function getResolvedThrowsForKey(string $key): array
    {
        return self::$resolvedThrows[$key] ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public static function getThrowOriginsForKey(string $key): array
    {
        return self::$throwOrigins[$key] ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public static function getAllResolvedThrows(): array
    {
        return self::$resolvedThrows;
    }

    /**
     * @return array<string,string>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getFileNamespaces(): array
    {
        return self::$fileNamespaces;
    }

    public static function getFileNamespace(string $path): string
    {
        return self::$fileNamespaces[$path] ?? '';
    }

    public static function setFileNamespace(string $path, string $namespace): void
    {
        self::$fileNamespaces[$path] = $namespace;
    }

    /**
     * @return array<string, array<string,string>>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getFileUseMaps(): array
    {
        return self::$fileUseMaps;
    }

    /**
     * @return array<string,string>
     */
    public static function getFileUseMap(string $path): array
    {
        return self::$fileUseMaps[$path] ?? [];
    }

    /**
     * @param array<string,string> $map
     */
    public static function setFileUseMap(string $path, array $map): void
    {
        self::$fileUseMaps[$path] = $map;
    }

    /**
     * @return array<string,string>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getNodeKeyToFilePath(): array
    {
        return self::$nodeKeyToFilePath;
    }

    public static function getFilePathForKey(string $key): ?string
    {
        return self::$nodeKeyToFilePath[$key] ?? null;
    }

    public static function setFilePathForKey(string $key, string $path): void
    {
        self::$nodeKeyToFilePath[$key] = $path;
    }
}

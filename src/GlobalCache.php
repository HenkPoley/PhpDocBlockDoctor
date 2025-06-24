<?php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\FunctionLike;

class GlobalCache
{
    /** Max number of origin call chains stored per exception */
    public const MAX_ORIGIN_CHAINS = 5;

    /**
     * @var array<string, string[]>
     */
    private static array $directThrows = [];

    /**
     * @var array<string, string[]>
     */
    private static array $annotatedThrows = [];

    /**
     * @var array<string, array<string, string>>
     */
    private static array $originalDescriptions = [];

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
    private static array $astNodeMap = [];

    /**
     * @var array<string, string>
     */
    private static array $nodeKeyToFilePath = [];

    /**
     * @var array<string, string[]>
     */
    private static array $resolvedThrows = [];

    /**
     * @var array<string, string|null> Mapping of class FQCN to its parent class FQCN
     */
    private static array $classParents = [];

    /**
     * @var array<string,string[]> Mapping of class FQCN to the traits it uses
     */
    private static array $classTraits = [];

    /**
     * @var array<string,string[]> Mapping of interface FQCN to implementing class FQCNs
     */
    private static array $interfaceImplementations = [];

    /**
     * @var array<string, array<string, string[]>> Mapping of method key to
     *     exception FQCN to a list of origin call chain strings. For each method,
     *     each chain starts with the call site location within that method,
     *     followed by the sequence of callee method names in order of invocation
     *     and ends with the file and line where the exception was originally
     *     thrown. Example:
     *     "src/File.php:10 <- SomeClass::method <- Other::callee <- vendor/lib.php:5".
     */
    private static array $throwOrigins = [];

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
     * @return array<string,string[]>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getDirectThrows(): array
    {
        return self::$directThrows;
    }

    /**
     * @return string[]
     */
    public static function getDirectThrowsForKey(string $key): array
    {
        return self::$directThrows[$key] ?? [];
    }

    /**
     * @param string[] $throws
     */
    public static function setDirectThrowsForKey(string $key, array $throws): void
    {
        self::$directThrows[$key] = $throws;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function addDirectThrow(string $key, string $exception): void
    {
        self::$directThrows[$key][] = $exception;
    }

    /**
     * @return array<string,string[]>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getAnnotatedThrows(): array
    {
        return self::$annotatedThrows;
    }

    /**
     * @return string[]
     */
    public static function getAnnotatedThrowsForKey(string $key): array
    {
        return self::$annotatedThrows[$key] ?? [];
    }

    /**
     * @param string[] $throws
     */
    public static function setAnnotatedThrowsForKey(string $key, array $throws): void
    {
        self::$annotatedThrows[$key] = $throws;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function addAnnotatedThrow(string $key, string $exception): void
    {
        self::$annotatedThrows[$key][] = $exception;
    }

    /**
     * @return array<string, array<string,string>>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getOriginalDescriptions(): array
    {
        return self::$originalDescriptions;
    }

    /**
     * @return array<string,string>
     */
    public static function getOriginalDescriptionsForKey(string $key): array
    {
        return self::$originalDescriptions[$key] ?? [];
    }

    public static function setOriginalDescription(string $key, string $exception, string $text): void
    {
        self::$originalDescriptions[$key][$exception] = $text;
    }

    /**
     * @return array<string,string[]>
     */
    public static function getResolvedThrows(): array
    {
        return self::$resolvedThrows;
    }

    /**
     * @return string[]
     */
    public static function getResolvedThrowsForKey(string $key): array
    {
        return self::$resolvedThrows[$key] ?? [];
    }

    /**
     * @param string[] $throws
     */
    public static function setResolvedThrowsForKey(string $key, array $throws): void
    {
        self::$resolvedThrows[$key] = $throws;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function addResolvedThrow(string $key, string $exception): void
    {
        self::$resolvedThrows[$key][] = $exception;
    }

    /**
     * @return array<string, array<string,string[]>>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getThrowOrigins(): array
    {
        return self::$throwOrigins;
    }

    /**
     * @return array<string, string[]>
     */
    public static function getThrowOriginsForKey(string $key): array
    {
        return self::$throwOrigins[$key] ?? [];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function addThrowOrigin(string $key, string $exception, string $chain): void
    {
        if (!isset(self::$throwOrigins[$key][$exception])) {
            self::$throwOrigins[$key][$exception] = [];
        }
        if (!in_array($chain, self::$throwOrigins[$key][$exception], true) && count(self::$throwOrigins[$key][$exception]) < self::MAX_ORIGIN_CHAINS) {
            self::$throwOrigins[$key][$exception][] = $chain;
        }
    }

    /**
     * @param array<string,string[]> $origins
     */
    public static function setThrowOriginsForKey(string $key, array $origins): void
    {
        self::$throwOrigins[$key] = $origins;
    }

    /**
     * @return array<string, string[]>
     */
    /**
     * @psalm-suppress PossiblyUnusedMethod
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
     * @return array<string, Function_|ClassMethod>
     */
    public static function getAstNodeMap(): array
    {
        return self::$astNodeMap;
    }

    public static function getAstNode(string $key): ?Node\FunctionLike
    {
        return self::$astNodeMap[$key] ?? null;
    }

    /**
     * @param Function_|ClassMethod $node
     */
    public static function setAstNode(string $key, Node\FunctionLike $node): void
    {
        self::$astNodeMap[$key] = $node;
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

    /**
     * @return array<string,string|null>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getClassParents(): array
    {
        return self::$classParents;
    }

    public static function getClassParent(string $class): ?string
    {
        return self::$classParents[$class] ?? null;
    }

    public static function setClassParent(string $class, ?string $parent): void
    {
        self::$classParents[$class] = $parent;
    }

    /**
     * @return array<string, string[]>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getClassTraits(): array
    {
        return self::$classTraits;
    }

    /**
     * @return string[]
     */
    public static function getTraitsForClass(string $class): array
    {
        return self::$classTraits[$class] ?? [];
    }

    public static function addTraitForClass(string $class, string $trait): void
    {
        self::$classTraits[$class][] = $trait;
    }

    /**
     * @return array<string,string[]>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getInterfaceImplementations(): array
    {
        return self::$interfaceImplementations;
    }

    /**
     * @return string[]
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function getImplementations(string $interface): array
    {
        return self::$interfaceImplementations[$interface] ?? [];
    }

    public static function addImplementation(string $interface, string $class): void
    {
        self::$interfaceImplementations[$interface][] = $class;
    }
}

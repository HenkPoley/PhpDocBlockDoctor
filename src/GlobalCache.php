<?php

namespace HenkPoley\DocBlockDoctor;

class GlobalCache
{
    /** Max number of origin call chains stored per exception */
    public const MAX_ORIGIN_CHAINS = 5;
    /**
     * @var mixed[]
     */
    public static $directThrows = [];
    /**
     * @var mixed[]
     */
    public static $annotatedThrows = [];
    /**
     * @var mixed[]
     */
    public static $originalDescriptions = [];
    /**
     * @var mixed[]
     */
    public static $fileNamespaces = [];
    /**
     * @var mixed[]
     */
    public static $fileUseMaps = [];
    /**
     * @var mixed[]
     */
    public static $astNodeMap = [];
    /**
     * @var mixed[]
     */
    public static $nodeKeyToFilePath = [];
    /**
     * @var mixed[]
     */
    public static $resolvedThrows = [];
    /**
     * @var array<string,string|null> Mapping of class FQCN to its parent class FQCN
     */
    public static $classParents = [];
    /**
     * @var array<string,string[]> Mapping of class FQCN to the traits it uses
     */
    public static $classTraits = [];
    /**
     * @var array<string,array<string,string[]>> Mapping of method key to
     * exception FQCN to a list of origin call chain strings. For each method,
     * each chain starts with the call site location within that method,
     * followed by the sequence of callee method names in order of invocation
     * and ends with the file and line where the exception was originally
     * thrown. Example:
     * "src/File.php:10 <- SomeClass::method <- Other::callee <- vendor/lib.php:5".
     */
    public static $throwOrigins = [];

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
    }

    /**
     * @return mixed[]
     */
    public static function getResolvedThrowsForKey(string $key): array
    {
        return self::$resolvedThrows[$key] ?? [];
    }

    /**
     * @return mixed[]
     */
    public static function getThrowOriginsForKey(string $key): array
    {
        return self::$throwOrigins[$key] ?? [];
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function getAllResolvedThrows(): array
    {
        return self::$resolvedThrows;
    }

    /**
     * @return array<string, array<string, string[]>>
     */
    public static function getAllThrowOrigins(): array
    {
        return self::$throwOrigins;
    }
}

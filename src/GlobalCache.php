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
     * @var array<string,array<string,string[]>> Mapping of method key to
     * exception FQCN to a list of origin call chain strings. Each chain looks
     * like "Inner::method <- Outer::method <- file.php:line".
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
    }
}
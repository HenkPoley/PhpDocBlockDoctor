<?php

namespace HenkPoley\DocBlockDoctor;

class GlobalCache
{
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
     * @var array<string,array<string,list<list<string>>>> Mapping of method key to
     * exception FQCN to a list of origin call chains. Each call chain is a list
     * like ["Inner::method", "Outer::method", "file.php:line"].
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
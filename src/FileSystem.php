<?php

namespace HenkPoley\DocBlockDoctor;

interface FileSystem
{
    /**
     * @return string|false
     */
    public function getContents(string $path);
    public function putContents(string $path, string $contents): bool;
    public function isFile(string $path): bool;
    public function isDir(string $path): bool;
    /**
     * @return string|false
     */
    public function realPath(string $path);
    /**
     * @return string|false
     */
    public function getCurrentWorkingDirectory();
}

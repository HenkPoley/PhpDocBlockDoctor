<?php

namespace HenkPoley\DocBlockDoctor;

interface FileSystem
{
    public function getContents(string $path): string|false;
    public function putContents(string $path, string $contents): bool;
    public function isFile(string $path): bool;
    public function isDir(string $path): bool;
    public function realPath(string $path): string|false;
    public function getCurrentWorkingDirectory(): string|false;
}

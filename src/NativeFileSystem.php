<?php

namespace HenkPoley\DocBlockDoctor;

class NativeFileSystem implements FileSystem
{
    public function getContents(string $path): string|false
    {
        return @file_get_contents($path);
    }

    public function putContents(string $path, string $contents): bool
    {
        return file_put_contents($path, $contents) !== false;
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public function realPath(string $path): string|false
    {
        return realpath($path);
    }

    public function getCurrentWorkingDirectory(): string|false
    {
        return getcwd();
    }
}

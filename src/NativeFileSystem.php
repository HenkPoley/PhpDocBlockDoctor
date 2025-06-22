<?php

namespace HenkPoley\DocBlockDoctor;

class NativeFileSystem implements FileSystem
{
    /**
     * @return string|false
     */
    public function getContents(string $path)
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

    /**
     * @return string|false
     */
    public function realPath(string $path)
    {
        return realpath($path);
    }

    /**
     * @return string|false
     */
    public function getCurrentWorkingDirectory()
    {
        return getcwd();
    }
}

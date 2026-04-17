<?php

declare(strict_types=1);

namespace App\Utilities;

class FileUtil
{
    public static function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public static function getContents(string $path): ?string
    {
        $contents = @file_get_contents($path);
        return ($contents !== false) ? $contents : null;
    }

    public static function putContents(string $path, string $data): bool
    {
        return (bool)@file_put_contents($path, $data);
    }

    public static function delete(string $path): bool
    {
        return @unlink($path);
    }

    public static function createDirectory(string $path): bool
    {
        if (!self::fileExists($path)) {
            return @mkdir($path, 0755, true);
        }
        return true;
    }
}

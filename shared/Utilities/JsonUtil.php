<?php

declare(strict_types=1);

class JsonUtil
{
    public static function decode(string $json, bool $assoc = false): mixed
    {
        $data = json_decode($json, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log error or throw exception
            return null;
        }
        return $data;
    }

    public static function encode(mixed $value, int $flags = 0): ?string
    {
        $json = json_encode($value, $flags);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log error or throw exception
            return null;
        }
        return $json;
    }
}

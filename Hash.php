<?php

declare(strict_types=1);

class Hash
{
    private string $hex;

    public function __construct(string $hex)
    {
        $this->hex = $hex;
    }

    public static function compute(string $data): Hash
    {
        $hexHash = hash('xxh3', $data, false);
        return new Hash($hexHash);
    }

    public static function computeFile(string $path): Hash
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new Exception("Cannot read file: $path");
        }
        return self::compute($content);
    }

    public function toBase64(): string
    {
        $bytes = hex2bin($this->hex);
        $le = strrev($bytes);
        $encoded = base64_encode($le);
        return substr($encoded, 0, 11);
    }

    public static function fromBase64(string $str): Hash
    {
        $padded = str_pad($str, 12, '=');
        $decoded = base64_decode($padded);
        if ($decoded === false || strlen($decoded) < 8) {
            throw new Exception('Invalid hash');
        }
        $le = $decoded;
        $be = strrev($le);
        $hex = bin2hex($be);
        return new Hash($hex);
    }

    public function getHex(): string
    {
        return $this->hex;
    }

    public function getInt(): int
    {
        return (int)hexdec($this->hex);
    }

    public function equals(Hash $other): bool
    {
        return $this->hex === $other->hex;
    }
}
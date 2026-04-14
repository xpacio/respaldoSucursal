<?php

declare(strict_types=1);

class Logger
{
    private static string $logDir = 'logs';
    private static string $prefix = 'ar';
    private static ?string $currentDate = null;
    private static $handle = null;
    private static bool $verbose = true;

    public static function init(string $logDir, bool $verbose = true): void
    {
        self::$logDir = $logDir;
        self::$verbose = $verbose;
        self::$currentDate = date('Y-m-d');
        
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }

    private static function getHandle()
    {
        $today = date('Y-m-d');
        
        if (self::$handle === null || self::$currentDate !== $today) {
            if (self::$handle !== null) {
                fclose(self::$handle);
            }
            
            self::$currentDate = $today;
            $filepath = self::$logDir . DIRECTORY_SEPARATOR . self::$prefix . '-' . $today . '.log';
            self::$handle = fopen($filepath, 'a');
        }
        
        return self::$handle;
    }

    private static function write(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
        
        $handle = self::getHandle();
        if ($handle !== false) {
            fwrite($handle, $line);
        }
        
        if (self::$verbose || $level === 'ERR' || $level === 'WARN') {
            echo $line;
        }
    }

    public static function debug(string $message): void
    {
        self::write('DBG', $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warn(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function err(string $message): void
    {
        self::write('ERR', $message);
    }

    public static function setVerbose(bool $verbose): void
    {
        self::$verbose = $verbose;
    }

    public static function close(): void
    {
        if (self::$handle !== null) {
            fclose(self::$handle);
            self::$handle = null;
        }
    }
}
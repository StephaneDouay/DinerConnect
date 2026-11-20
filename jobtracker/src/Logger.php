<?php
declare(strict_types=1);

class Logger
{
    private static string $logFile = '';
    private static bool   $dbReady = false;

    public static function init(string $storageDir): void
    {
        self::$logFile = rtrim($storageDir, '/') . '/logs/jobtracker.log';
    }

    public static function setDbReady(bool $ready): void
    {
        self::$dbReady = $ready;
    }

    public static function log(string $level, string $job, string $message, array $context = []): void
    {
        $ts  = date('Y-m-d H:i:s');
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = "[{$ts}] [{$level}] [{$job}] {$message}{$ctx}" . PHP_EOL;

        if (self::$logFile !== '') {
            @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
        }

        if (self::$dbReady) {
            try {
                DB::execute(
                    'INSERT INTO logs (level, job, message, context) VALUES (?, ?, ?, ?)',
                    [$level, $job, $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null]
                );
            } catch (Throwable) {
                // DB not available yet
            }
        }
    }

    public static function info(string $job, string $msg, array $ctx = []): void
    {
        self::log('info', $job, $msg, $ctx);
    }

    public static function warning(string $job, string $msg, array $ctx = []): void
    {
        self::log('warning', $job, $msg, $ctx);
    }

    public static function error(string $job, string $msg, array $ctx = []): void
    {
        self::log('error', $job, $msg, $ctx);
    }

    public static function debug(string $job, string $msg, array $ctx = []): void
    {
        self::log('debug', $job, $msg, $ctx);
    }
}

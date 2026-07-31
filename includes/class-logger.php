<?php

class SpeedafLogger
{
    public static function log(string $message): void
    {
        $file = dirname(__DIR__) . '/logs/plugin.log';

        $time = date('Y-m-d H:i:s');

        file_put_contents(
            $file,
            "[{$time}] {$message}" . PHP_EOL,
            FILE_APPEND
        );
    }
}
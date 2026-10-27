<?php
declare(strict_types=1);

final class Config
{
    private static ?array $data = null;

    public static function get(string $key): mixed
    {
        self::$data ??= require dirname(__DIR__) . '/config/config.php';
        return self::$data[$key] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connect(Config $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = $config->get('DB_HOST');
        $database = $config->get('DB_DATABASE');
        $username = $config->get('DB_USERNAME');
        $password = $config->optional('DB_PASSWORD', '');
        $port = $config->int('DB_PORT', 3306);
        $timezone = $config->get('APP_TIMEZONE', '-05:00');

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            throw new RuntimeException('DB_HOST no es válido.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('DB_DATABASE no es válido.');
        }
        if (!preg_match('/^[+-]\d{2}:\d{2}$/', $timezone)) {
            throw new RuntimeException('APP_TIMEZONE debe tener formato ±HH:MM.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('DB_PORT no es válido.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('SET time_zone = ' . $pdo->quote($timezone));

        self::$connection = $pdo;

        return $pdo;
    }

    public static function connection(): PDO
    {
        if (!self::$connection instanceof PDO) {
            throw new RuntimeException('La conexión de base de datos no está inicializada.');
        }

        return self::$connection;
    }
}

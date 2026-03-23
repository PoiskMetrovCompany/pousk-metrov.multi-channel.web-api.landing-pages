<?php

require_once __DIR__ . '/vendor/autoload.php';

use Medoo\Medoo;
use Klein\Klein;

try {
    $envPath = __DIR__ . '/.env';
    $env = parse_ini_file($envPath);
    if ($env === false) {
        throw new RuntimeException("Cannot read .env file at: {$envPath}");
    }

    $dbType = $env['DB_TYPE'] ?? '';
    $dbFileName = $env['DB_FILE'] ?? '';
    if ($dbType === '' || $dbFileName === '') {
        throw new RuntimeException('DB_TYPE and DB_FILE are required in .env');
    }

    $availableDrivers = PDO::getAvailableDrivers();
    if (!in_array($dbType, $availableDrivers, true)) {
        $drivers = implode(', ', $availableDrivers);
        throw new RuntimeException(
            "PDO driver '{$dbType}' is not installed. Available drivers: [{$drivers}]. " .
            "Install php-sqlite3 (for sqlite) and restart PHP."
        );
    }

    $dbFile = __DIR__ . '/' . $dbFileName;
    if (!file_exists($dbFile) && !touch($dbFile)) {
        throw new RuntimeException("Cannot create database file: {$dbFile}");
    }

    $database = new Medoo([
        'type' => $dbType,
        'database' => $dbFile,
        'error' => PDO::ERRMODE_EXCEPTION,
    ]);

    $database->query(/** @lang text */ "CREATE TABLE IF NOT EXISTS flats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url VARCHAR(255) NOT NULL UNIQUE,
            source VARCHAR(255) NOT NULL,
            flatNumber VARCHAR(255) NOT NULL,
            totalArea FLOAT NOT NULL,
            livingArea FLOAT NULL,
            floor INTEGER NOT NULL,
            totalFloors INTEGER NOT NULL,
            queue VARCHAR(255) NULL,
            corpuses INTEGER NULL,
            dueDate DATETIME NULL,
            price INTEGER NULL,
            imageUrl VARCHAR(255) NULL UNIQUE,
            createdAt DATETIME NULL
        );"
    )->execute();
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'request' => false,
        'error' => 'Bootstrap failed',
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$app = new Klein();
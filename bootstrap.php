<?php

require_once __DIR__ . '/vendor/autoload.php';

use Medoo\Medoo;
use Klein\Klein;

try {
    $envPath = __DIR__ . '/.env';
    $env = [];
    if (file_exists($envPath)) {
        $envParsed = parse_ini_file($envPath);
        if ($envParsed === false) {
            throw new RuntimeException("Cannot read .env file at: {$envPath}");
        }
        $env = $envParsed;
    } else {
        // Allow container defaults when .env is not present (useful for local dev).
        $env = [
            'DB_TYPE' => 'sqlite',
            'DB_FILE' => 'app.db',
        ];
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
            countRooms INTEGER NULL,
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

    // Lightweight migration for existing DBs: ensure new columns exist.
    $columns = $database->query("PRAGMA table_info(flats);")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_map(static fn(array $c) => (string)($c['name'] ?? ''), is_array($columns) ? $columns : []);
    $hasCountRooms = in_array('countRooms', $columnNames, true);
    if (!$hasCountRooms) {
        try {
            $database->query("ALTER TABLE flats ADD COLUMN countRooms INTEGER NULL;")->execute();
        } catch (Throwable $e) {
            // If another process already added the column between PRAGMA and ALTER, ignore.
            if (stripos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }
    }

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
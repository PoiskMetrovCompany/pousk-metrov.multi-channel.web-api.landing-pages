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

    $swaggerUrl = trim((string)($env['SWAGGER_URL'] ?? 'http://localhost:8081'));
    if ($swaggerUrl === '') {
        $swaggerUrl = 'http://localhost:8081';
    }
    $appConfig = [
        'swaggerUrl' => rtrim($swaggerUrl, '/'),
    ];

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

    $dbFile = str_starts_with($dbFileName, '/')
        ? $dbFileName
        : __DIR__ . '/' . $dbFileName;

    $dbDir = dirname($dbFile);
    if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
        throw new RuntimeException("Cannot create database directory: {$dbDir}");
    }

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

    // Ensure 'room' string column exists for canonical room labels (e.g., "Студия", "1 комната").
    $hasRoom = in_array('room', $columnNames, true);
    if (!$hasRoom) {
        try {
            $database->query("ALTER TABLE flats ADD COLUMN room VARCHAR(255) NULL;")->execute();
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }
    }

    // Create index for faster room filtering (no-op if already exists).
    $database->query("CREATE INDEX IF NOT EXISTS idx_flats_room ON flats(room);")->execute();

    // Backfill 'room' based on existing 'countRooms' where room is NULL.
    // 0 => 'Студия', 1 => '1 комната', 2 => '2 комнаты', 3 => '3 комнаты', 4 => '4 комнаты', else NULL.
    // This uses a simple CASE expression supported by SQLite.
    $database->query(/** @lang text */ "
        UPDATE flats
        SET room = CASE
            WHEN countRooms = 0 THEN 'Студия'
            WHEN countRooms = 1 THEN '1 комната'
            WHEN countRooms = 2 THEN '2 комнаты'
            WHEN countRooms = 3 THEN '3 комнаты'
            WHEN countRooms = 4 THEN '4 комнаты'
            WHEN countRooms = 5 THEN '5 комнат'
            ELSE NULL
        END
        WHERE room IS NULL AND countRooms IS NOT NULL
    ")->execute();

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
<?php
declare(strict_types=1);

global $app;
global $database;
require __DIR__ . '/bootstrap.php';


function metaResponse($request): array
{
    return [
        'request_detail' => [
            'method' => $request->method(),
            'path' => $request->pathname(),
            'request' => $request->body() ?: $request->params(),
        ]
    ];
}

$app->respond('GET', '/', function ($request) use ($database) {
    return [
        'request' => true,
        'data' => 'Hello World',
        ...metaResponse($request)
    ];
});

$app->respond('POST', '/store', function ($request) use ($database) {
    /**
     * This endpoint is used by the Node.js parser (`axios.post(..., flatObject)`).
     * Klein's `$request->body()` returns a raw string, so we must `json_decode`
     * (and only then map values to the `flats` table schema).
     */
    $rawBody = $request->body();
    $decoded = null;
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decodedCandidate = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedCandidate)) {
            $decoded = $decodedCandidate;
        }
    }

    // Fallback: allow x-www-form-urlencoded or any query params.
    if (!is_array($decoded)) {
        $decoded = $request->params();
        if (!is_array($decoded)) {
            $decoded = [];
        }
    }

    $parseNullableInt = static function ($value): ?int {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        // Keep only digits and optional leading minus.
        $s = preg_replace('/(?!^-)[^\d]+/', '', $s);
        if ($s === '' || $s === '-') {
            return null;
        }

        return (int) $s;
    };

    $parseNullableFloat = static function ($value): ?float {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        // Normalize decimal separator and remove everything else.
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9\.\-]+/', '', $s);
        if ($s === '' || $s === '-' || $s === '.') {
            return null;
        }

        return (float) $s;
    };

    $parseNullableDateTime = static function ($value): ?string {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        try {
            $dt = new \DateTime($s);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    };

    // Map only fields that exist in `flats` table.
    $data = [];
    $data['source'] = isset($decoded['source']) ? (string) $decoded['source'] : null;
    $data['url'] = isset($decoded['url']) ? (string) $decoded['url'] : null;
    $data['flatNumber'] = isset($decoded['flatNumber']) ? (string) $decoded['flatNumber'] : null;

    $data['totalArea'] = $parseNullableFloat($decoded['totalArea'] ?? null);
    $data['livingArea'] = $parseNullableFloat($decoded['livingArea'] ?? null);
    $data['floor'] = $parseNullableInt($decoded['floor'] ?? null);
    $data['totalFloors'] = $parseNullableInt($decoded['totalFloors'] ?? null);

    $queue = $decoded['queue'] ?? null;
    $data['queue'] = is_string($queue) ? (trim($queue) !== '' ? $queue : null) : null;

    $data['corpuses'] = $parseNullableInt($decoded['corpuses'] ?? null);
    $data['dueDate'] = $parseNullableDateTime($decoded['dueDate'] ?? null);
    $data['price'] = $parseNullableInt($decoded['price'] ?? null);

    $imageUrl = $decoded['imageUrl'] ?? null;
    $data['imageUrl'] = is_string($imageUrl) ? (trim($imageUrl) !== '' ? $imageUrl : null) : null;

    $data['createdAt'] = $parseNullableDateTime($decoded['createdAt'] ?? null) ?? (new \DateTime())->format('Y-m-d H:i:s');

    $required = ['source', 'url', 'flatNumber', 'totalArea', 'floor', 'totalFloors'];
    $missing = [];
    foreach ($required as $key) {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        return [
            'request' => false,
            'error' => 'Invalid payload for /store',
            'missing_fields' => $missing,
        ];
    }

    $flat = $database->insert('flats', $data);
    return [
        'request' => true,
        'data' => $flat,
        ...metaResponse($request)
    ];
});

$app->respond('GET', '/[:source]', function ($request) use ($database) {
    $flats = $database->select('flats', '*', ['source' => $request->source]);
    return [
        'request' => true,
        'data' => $flats,
        ...metaResponse($request)
    ];
});

$app->respond('GET', '/[:source]/filter', function ($request) use ($database) {
    $flats = $database->select('flats', '*', ['source' => $request->source]);
    return [
        'request' => true,
        'data' => $flats,
        ...metaResponse($request)
    ];
});

$app->dispatch();

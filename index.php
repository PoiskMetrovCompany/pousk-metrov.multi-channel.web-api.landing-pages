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

$jsonResponse = static function ($request, array $payload, int $statusCode = 200): string {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    $payload = $payload + metaResponse($request);
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$parseCsvOrArray = static function ($value): array {
    if ($value === null) {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', array_map('strval', $value)), static fn($s) => $s !== ''));
    }
    $s = trim((string)$value);
    if ($s === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $s)), static fn($x) => $x !== ''));
};

$toNullableInt = static function ($value): ?int {
    if ($value === null) return null;
    if (is_int($value)) return $value;
    if (is_float($value)) return (int)$value;
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = preg_replace('/(?!^-)[^\d]+/', '', $s);
    if ($s === '' || $s === '-') return null;
    return (int)$s;
};

$toNullableFloat = static function ($value): ?float {
    if ($value === null) return null;
    if (is_int($value) || is_float($value)) return (float)$value;
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9\.\-]+/', '', $s);
    if ($s === '' || $s === '-' || $s === '.') return null;
    return (float)$s;
};

$normalizeRoomLabel = static function (string $label): ?int {
    $s = mb_strtolower(trim($label));
    if ($s === '') return null;
    if ($s === 'студия' || $s === 'studio') return 0;
    if (preg_match('/^(\d+)/u', $s, $m)) return (int)$m[1];
    return null;
};

$app->respond('GET', '/', function ($request) use ($database) {
    global $jsonResponse;
    return $jsonResponse($request, [
        'request' => true,
        'data' => 'Hello World',
    ]);
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

    global $jsonResponse, $toNullableInt, $toNullableFloat;

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

    $data['countRooms'] = $toNullableInt($decoded['countRooms'] ?? null);
    $data['totalArea'] = $toNullableFloat($decoded['totalArea'] ?? null);
    $data['livingArea'] = $toNullableFloat($decoded['livingArea'] ?? null);
    $data['floor'] = $toNullableInt($decoded['floor'] ?? null);
    $data['totalFloors'] = $toNullableInt($decoded['totalFloors'] ?? null);

    $queue = $decoded['queue'] ?? null;
    $data['queue'] = is_string($queue) ? (trim($queue) !== '' ? $queue : null) : null;

    $data['corpuses'] = $toNullableInt($decoded['corpuses'] ?? null);
    $data['dueDate'] = $parseNullableDateTime($decoded['dueDate'] ?? null);
    $data['price'] = $toNullableInt($decoded['price'] ?? null);

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
        return $jsonResponse($request, [
            'request' => false,
            'error' => 'Invalid payload for /store',
            'missing_fields' => $missing,
        ], 400);
    }

    try {
        $flat = $database->insert('flats', $data);
        return $jsonResponse($request, [
            'request' => true,
            'data' => $flat,
        ]);
    } catch (\Throwable $e) {
        // Common case for repeated scrapes: UNIQUE constraint on url / imageUrl.
        $msg = $e->getMessage();
        $looksLikeUnique =
            stripos($msg, 'UNIQUE constraint failed') !== false ||
            stripos($msg, 'duplicate') !== false;

        if ($looksLikeUnique) {
            // Try to return the existing record by url.
            $existing = null;
            if (isset($data['url']) && is_string($data['url']) && $data['url'] !== '') {
                $existing = $database->get('flats', '*', ['url' => $data['url']]);
            }

            return $jsonResponse($request, [
                'request' => true,
                'duplicate' => true,
                'data' => $existing,
            ]);
        }

        return $jsonResponse($request, [
            'request' => false,
            'error' => 'Insert failed',
            'message' => $msg,
        ], 500);
    }
});

$app->respond('GET', '/[:source]', function ($request) use ($database) {
    $flats = $database->select('flats', '*', ['source' => $request->source]);
    global $jsonResponse;
    return $jsonResponse($request, [
        'request' => true,
        'data' => $flats,
    ]);
});

$app->respond('GET', '/[:source]/corpuses', function ($request) use ($database) {
    global $jsonResponse;
    $rows = $database->select('flats', ['corpuses'], [
        'AND' => [
            'source' => $request->source,
            'corpuses[!]' => null,
        ],
        'GROUP' => 'corpuses',
        'ORDER' => ['corpuses' => 'ASC'],
    ]);
    $corpuses = [];
    foreach ($rows as $row) {
        if (is_array($row) && array_key_exists('corpuses', $row) && $row['corpuses'] !== null && $row['corpuses'] !== '') {
            $corpuses[] = (int)$row['corpuses'];
        }
    }
    $corpuses = array_values(array_unique($corpuses));
    sort($corpuses);

    return $jsonResponse($request, [
        'request' => true,
        'data' => $corpuses,
    ]);
});

$app->respond('GET', '/[:source]/filter', function ($request) use ($database) {
    global $jsonResponse, $parseCsvOrArray, $toNullableInt, $toNullableFloat, $normalizeRoomLabel;

    $params = $request->params() ?: [];

    // Inputs:
    // - countRooms: "Студия,1 комната,2 комнаты" or repeated params
    // - priceFrom/priceTo
    // - squareFrom/squareTo (maps to totalArea)
    // - floorFrom/floorTo
    // - corpuses (single, csv, or repeated)
    $roomLabels = $parseCsvOrArray($params['countRooms'] ?? null);
    $rooms = [];
    foreach ($roomLabels as $label) {
        $n = $normalizeRoomLabel((string)$label);
        if ($n !== null) $rooms[] = $n;
    }
    $rooms = array_values(array_unique($rooms));

    $priceFrom = $toNullableInt($params['priceFrom'] ?? ($params['price_from'] ?? null));
    $priceTo = $toNullableInt($params['priceTo'] ?? ($params['price_to'] ?? null));
    $squareFrom = $toNullableFloat($params['squareFrom'] ?? ($params['square_from'] ?? null));
    $squareTo = $toNullableFloat($params['squareTo'] ?? ($params['square_to'] ?? null));
    $floorFrom = $toNullableInt($params['floorFrom'] ?? ($params['floor_from'] ?? null));
    $floorTo = $toNullableInt($params['floorTo'] ?? ($params['floor_to'] ?? null));

    $corpusesRaw = $parseCsvOrArray($params['corpuses'] ?? ($params['corpuses[]'] ?? null));
    $corpuses = [];
    foreach ($corpusesRaw as $c) {
        $n = $toNullableInt($c);
        if ($n !== null) $corpuses[] = $n;
    }
    $corpuses = array_values(array_unique($corpuses));

    $where = ['AND' => ['source' => $request->source]];

    if ($rooms !== []) {
        // Requires `countRooms` column to be populated; old rows may not match.
        $where['AND']['countRooms'] = $rooms;
    }
    if ($priceFrom !== null) $where['AND']['price[>=]'] = $priceFrom;
    if ($priceTo !== null) $where['AND']['price[<=]'] = $priceTo;
    if ($squareFrom !== null) $where['AND']['totalArea[>=]'] = $squareFrom;
    if ($squareTo !== null) $where['AND']['totalArea[<=]'] = $squareTo;
    if ($floorFrom !== null) $where['AND']['floor[>=]'] = $floorFrom;
    if ($floorTo !== null) $where['AND']['floor[<=]'] = $floorTo;
    if ($corpuses !== []) $where['AND']['corpuses'] = $corpuses;

    $flats = $database->select('flats', '*', $where);
    return $jsonResponse($request, [
        'request' => true,
        'data' => $flats,
        'filters' => [
            'countRooms' => $rooms,
            'priceFrom' => $priceFrom,
            'priceTo' => $priceTo,
            'squareFrom' => $squareFrom,
            'squareTo' => $squareTo,
            'floorFrom' => $floorFrom,
            'floorTo' => $floorTo,
            'corpuses' => $corpuses,
        ],
    ]);
});

$app->dispatch();

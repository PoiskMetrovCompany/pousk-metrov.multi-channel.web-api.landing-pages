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

$toIntClamped = static function ($value, int $default, int $min, int $max): int {
    if ($value === null) return $default;

    if (is_int($value)) {
        $n = $value;
    } elseif (is_float($value)) {
        $n = (int)$value;
    } else {
        $s = trim((string)$value);
        if ($s === '') return $default;
        $s = preg_replace('/(?!^-)[^\d]+/', '', $s);
        if ($s === '' || $s === '-') return $default;
        $n = (int)$s;
    }

    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
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

$app->respond('GET', '/openapi.json', function ($request) {
    header('Content-Type: application/json; charset=utf-8');

    $spec = [
        'openapi' => '3.0.3',
        'info' => [
            'title' => 'Landing Pages Flats API',
            'version' => '1.0.0',
        ],
        'servers' => [
            ['url' => 'http://localhost:8081'],
        ],
        'paths' => [
            '/' => [
                'get' => [
                    'summary' => 'Healthcheck',
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
            '/store' => [
                'post' => [
                    'summary' => 'Store flat payload (insert or duplicate)',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/FlatPayload'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Inserted or duplicate'],
                        '400' => ['description' => 'Invalid payload'],
                        '500' => ['description' => 'Insert failed'],
                    ],
                ],
            ],
            '/{source}' => [
                'get' => [
                    'summary' => 'List flats by source',
                    'parameters' => [
                        [
                            'name' => 'source',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                        [
                            'name' => 'page',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Page number (1-based)',
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        ],
                        [
                            'name' => 'pageSize',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Items per page',
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/FlatsListResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/{source}/filter' => [
                'get' => [
                    'summary' => 'Filter flats by source',
                    'parameters' => [
                        [
                            'name' => 'source',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                        [
                            'name' => 'countRooms',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'CSV or repeated. Examples: "Студия,1 комната,2 комнаты"',
                            'schema' => ['type' => 'string'],
                        ],
                        ['name' => 'priceFrom', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'priceTo', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'squareFrom', 'in' => 'query', 'schema' => ['type' => 'number']],
                        ['name' => 'squareTo', 'in' => 'query', 'schema' => ['type' => 'number']],
                        ['name' => 'floorFrom', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'floorTo', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        [
                            'name' => 'corpuses',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'CSV or repeated корпуса',
                            'schema' => ['type' => 'string'],
                        ],
                        [
                            'name' => 'page',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Page number (1-based)',
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        ],
                        [
                            'name' => 'pageSize',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Items per page',
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'OK',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/FlatsFilterResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/{source}/corpuses' => [
                'get' => [
                    'summary' => 'Unique corpuses by source',
                    'parameters' => [
                        [
                            'name' => 'source',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'FlatPayload' => [
                    'type' => 'object',
                    'required' => ['source', 'url', 'flatNumber', 'totalArea', 'floor', 'totalFloors'],
                    'properties' => [
                        'source' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'flatNumber' => ['type' => 'string'],
                        'countRooms' => ['type' => 'integer', 'nullable' => true, 'description' => '0=studio, 1..5'],
                        'totalArea' => ['type' => 'number'],
                        'livingArea' => ['type' => 'number', 'nullable' => true],
                        'floor' => ['type' => 'integer'],
                        'totalFloors' => ['type' => 'integer'],
                        'queue' => ['type' => 'string', 'nullable' => true],
                        'corpuses' => ['type' => 'integer', 'nullable' => true],
                        'dueDate' => ['type' => 'string', 'nullable' => true, 'description' => 'DateTime string'],
                        'price' => ['type' => 'integer', 'nullable' => true],
                        'imageUrl' => ['type' => 'string', 'nullable' => true],
                        'createdAt' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'Flat' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'source' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'flatNumber' => ['type' => 'string'],
                        'countRooms' => ['type' => 'integer', 'nullable' => true, 'description' => '0=studio, 1..5'],
                        'totalArea' => ['type' => 'number'],
                        'livingArea' => ['type' => 'number', 'nullable' => true],
                        'floor' => ['type' => 'integer'],
                        'totalFloors' => ['type' => 'integer'],
                        'queue' => ['type' => 'string', 'nullable' => true],
                        'corpuses' => ['type' => 'integer', 'nullable' => true],
                        'dueDate' => ['type' => 'string', 'nullable' => true, 'description' => 'DateTime string'],
                        'price' => ['type' => 'integer', 'nullable' => true],
                        'imageUrl' => ['type' => 'string', 'nullable' => true],
                        'createdAt' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'Pagination' => [
                    'type' => 'object',
                    'required' => ['page', 'pageSize', 'total', 'totalPages'],
                    'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1],
                        'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        'total' => ['type' => 'integer', 'minimum' => 0],
                        'totalPages' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ],
                'RequestDetail' => [
                    'type' => 'object',
                    'required' => ['method', 'path', 'request'],
                    'properties' => [
                        'method' => ['type' => 'string'],
                        'path' => ['type' => 'string'],
                        'request' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
                'FlatsListResponse' => [
                    'type' => 'object',
                    'required' => ['request', 'data', 'pagination', 'request_detail'],
                    'properties' => [
                        'request' => ['type' => 'boolean'],
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/Flat'],
                        ],
                        'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                        'request_detail' => ['$ref' => '#/components/schemas/RequestDetail'],
                    ],
                ],
                'FlatFilters' => [
                    'type' => 'object',
                    'required' => [
                        'countRooms',
                        'priceFrom',
                        'priceTo',
                        'squareFrom',
                        'squareTo',
                        'floorFrom',
                        'floorTo',
                        'corpuses',
                    ],
                    'properties' => [
                        'countRooms' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'priceFrom' => ['type' => 'integer', 'nullable' => true],
                        'priceTo' => ['type' => 'integer', 'nullable' => true],
                        'squareFrom' => ['type' => 'number', 'nullable' => true],
                        'squareTo' => ['type' => 'number', 'nullable' => true],
                        'floorFrom' => ['type' => 'integer', 'nullable' => true],
                        'floorTo' => ['type' => 'integer', 'nullable' => true],
                        'corpuses' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                ],
                'FlatsFilterResponse' => [
                    'type' => 'object',
                    'required' => ['request', 'data', 'filters', 'pagination', 'request_detail'],
                    'properties' => [
                        'request' => ['type' => 'boolean'],
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/Flat'],
                        ],
                        'filters' => ['$ref' => '#/components/schemas/FlatFilters'],
                        'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                        'request_detail' => ['$ref' => '#/components/schemas/RequestDetail'],
                    ],
                ],
            ],
        ],
    ];

    // Klein can dispatch multiple matching routes; hard-exit to avoid fallthrough into `/:source`.
    echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

$app->respond('GET', '/docs', function () {
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/swaggerUi.php';
    echo renderSwaggerUiHtml('/openapi.json');
    exit;
});

$app->respond('GET', '/', function ($request) use ($database) {
    global $jsonResponse;
    return $jsonResponse($request, [
        'request' => true,
        'data' => 'Hello World',
    ]);
});

$app->respond('POST', '/store', function ($request) use ($database) {

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
        $msg = $e->getMessage();
        $looksLikeUnique =
            stripos($msg, 'UNIQUE constraint failed') !== false ||
            stripos($msg, 'duplicate') !== false;

        if ($looksLikeUnique) {
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
    global $jsonResponse, $toIntClamped;

    $params = $request->params() ?: [];
    $page = $toIntClamped($params['page'] ?? null, 1, 1, 1000);
    $pageSize = $toIntClamped($params['pageSize'] ?? ($params['page_size'] ?? null), 20, 1, 100);
    $offset = ($page - 1) * $pageSize;

    $baseWhere = ['source' => $request->source];
    $total = $database->count('flats', null, null, $baseWhere);
    if ($total === null) $total = 0;

    $where = $baseWhere;
    $where['ORDER'] = ['id' => 'ASC'];
    $where['LIMIT'] = [$offset, $pageSize];
    $flats = $database->select('flats', '*', $where);

    $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;

    return $jsonResponse($request, [
        'request' => true,
        'data' => $flats,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $totalPages,
        ],
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
    global $jsonResponse, $parseCsvOrArray, $toNullableInt, $toNullableFloat, $normalizeRoomLabel, $toIntClamped;

    $params = $request->params() ?: [];

    $page = $toIntClamped($params['page'] ?? null, 1, 1, 1000);
    $pageSize = $toIntClamped($params['pageSize'] ?? ($params['page_size'] ?? null), 20, 1, 100);
    $offset = ($page - 1) * $pageSize;

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
        $where['AND']['countRooms'] = $rooms;
    }
    if ($priceFrom !== null) $where['AND']['price[>=]'] = $priceFrom;
    if ($priceTo !== null) $where['AND']['price[<=]'] = $priceTo;
    if ($squareFrom !== null) $where['AND']['totalArea[>=]'] = $squareFrom;
    if ($squareTo !== null) $where['AND']['totalArea[<=]'] = $squareTo;
    if ($floorFrom !== null) $where['AND']['floor[>=]'] = $floorFrom;
    if ($floorTo !== null) $where['AND']['floor[<=]'] = $floorTo;
    if ($corpuses !== []) $where['AND']['corpuses'] = $corpuses;

    $total = $database->count('flats', null, null, $where);
    if ($total === null) $total = 0;

    $whereWithPagination = $where;
    $whereWithPagination['ORDER'] = ['id' => 'ASC'];
    $whereWithPagination['LIMIT'] = [$offset, $pageSize];
    $flats = $database->select('flats', '*', $whereWithPagination);

    $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;
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
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $totalPages,
        ],
    ]);
});

$app->dispatch();

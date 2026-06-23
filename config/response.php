<?php
/**
 * Centralized API response helpers.
 * Every API endpoint should use these instead of bare echo json_encode().
 */

declare(strict_types=1);

function jsonOk(mixed $data = null, int $status = 200, array $meta = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['success' => true];
    if ($meta)  $body = array_merge($body, $meta);
    if ($data !== null) $body['data'] = $data;
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonErr(string $message, int $status = 400, ?string $code = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['success' => false, 'error' => $message];
    if ($code) $body['code'] = $code;
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonPaged(array $rows, int $total, int $page, int $limit): never
{
    jsonOk($rows, 200, [
        'pagination' => [
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int)ceil($total / $limit),
            'has_more'=> ($page * $limit) < $total,
        ],
    ]);
}

/**
 * Assert a condition or abort with 400.
 */
function assert400(bool $condition, string $message): void
{
    if (!$condition) jsonErr($message, 400);
}

/**
 * Require specific HTTP method(s).
 */
function requireMethod(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        jsonErr('Método não permitido', 405);
    }
}

/**
 * Parse and return JSON body, aborting on invalid JSON.
 */
function jsonBody(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) jsonErr('JSON inválido no corpo da requisição', 400);
    return $data;
}

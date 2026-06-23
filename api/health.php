<?php
/**
 * Health check endpoint.
 * GET /api/health.php
 *
 * Returns 200 OK with system status, or 503 if DB is unreachable.
 * Safe to expose publicly — reveals no sensitive data.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$start = microtime(true);

$status = [
    'status'  => 'ok',
    'ts'      => date('c'),
    'version' => '2.0.0',
    'checks'  => [],
];

// ── DB check ──────────────────────────────────────────────────────────
try {
    require_once '../config/db.php';
    $db = getDB();
    $db->query('SELECT 1');

    $cnt = $db->query("SELECT COUNT(*) FROM totem_pedidos WHERE DATE(criado_em) = CURRENT_DATE AND status != 'cancelado'")->fetchColumn();

    $status['checks']['database'] = [
        'ok'             => true,
        'pedidos_hoje'   => (int)$cnt,
    ];
} catch (Throwable $e) {
    $status['status']             = 'degraded';
    $status['checks']['database'] = ['ok' => false, 'error' => 'connection failed'];
    http_response_code(503);
}

// ── Disk check ────────────────────────────────────────────────────────
$freeBytes = disk_free_space(__DIR__ . '/..');
$status['checks']['disk'] = [
    'ok'         => $freeBytes > 50 * 1024 * 1024,
    'free_mb'    => $freeBytes ? round($freeBytes / 1024 / 1024) : null,
];
if (!$status['checks']['disk']['ok']) $status['status'] = 'degraded';

// ── PHP check ─────────────────────────────────────────────────────────
$status['checks']['php'] = [
    'ok'       => true,
    'version'  => PHP_VERSION,
    'memory_mb'=> round(memory_get_usage(true) / 1024 / 1024, 1),
];

// ── Response time ─────────────────────────────────────────────────────
$status['latency_ms'] = round((microtime(true) - $start) * 1000, 2);

echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

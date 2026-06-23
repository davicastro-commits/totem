<?php
/**
 * Server-Sent Events endpoint.
 *
 * GET /api/sse.php?topic=kds|caixa
 *
 * Topics:
 *   kds   — streams all active orders (aguardando, preparando, pronto)
 *   caixa — streams only 'pronto' orders for pickup notifications
 *
 * Both require a valid admin session.
 * The session lock is released immediately after reading credentials
 * so other concurrent requests are not blocked.
 */

declare(strict_types=1);

$topic = $_GET['topic'] ?? 'kds';
if (!in_array($topic, ['kds', 'caixa', 'painel'], true)) {
    http_response_code(400);
    exit;
}

// 'painel' is public (display screen) — no auth required
session_start();
$adminId = $_SESSION['admin_id'] ?? null;
session_write_close(); // Release lock — CRITICAL for SSE

if ($topic !== 'painel' && !$adminId) {
    http_response_code(401);
    exit;
}

require_once '../config/db.php';

// ── SSE Headers ────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');   // Disable nginx/proxy buffering
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

if (ob_get_level()) {
    ob_end_clean();
}
ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');
ini_set('zlib.output_compression', '0');

// Increase max execution time for long-running stream
set_time_limit(0);
ignore_user_abort(false);

// ── Helpers ────────────────────────────────────────────────────────────
function sseEvent(string $event, mixed $data, ?int $id = null): void
{
    if ($id !== null) echo "id: {$id}\n";
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

function sseComment(string $text = 'heartbeat'): void
{
    echo ": {$text}\n\n";
    flush();
}

// ── Queries ────────────────────────────────────────────────────────────
function queryKds(PDO $db): array
{
    $stmt = $db->query("
        SELECT p.id, p.numero_pedido, p.status, p.tipo_consumo,
               p.criado_em, p.iniciado_em, p.concluido_em,
               COALESCE(p.obs, '') AS obs,
               json_agg(
                 json_build_object(
                   'nome', i.nome_produto,
                   'qtd',  i.quantidade,
                   'obs',  COALESCE(i.obs,'')
                 ) ORDER BY i.id
               ) AS itens
          FROM totem_pedidos p
          JOIN totem_itens_pedido i ON i.pedido_id = p.id
         WHERE p.status NOT IN ('entregue','cancelado','aguardando_pagamento')
         GROUP BY p.id
         ORDER BY
           CASE p.status
             WHEN 'aguardando' THEN 0
             WHEN 'preparando' THEN 1
             WHEN 'pronto'     THEN 2
           END,
           p.criado_em ASC
    ");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['itens'] = json_decode($r['itens'], true);
    }
    return $rows;
}

function queryCaixa(PDO $db): array
{
    $stmt = $db->query("
        SELECT id, numero_pedido, status, tipo_consumo, criado_em
          FROM totem_pedidos
         WHERE status = 'pronto'
         ORDER BY criado_em DESC
         LIMIT 20
    ");
    return $stmt->fetchAll();
}

function queryPainel(PDO $db): array
{
    // Pedidos prontos + entregues nos últimos 15 minutos (para histórico lateral)
    $stmt = $db->query("
        SELECT id, numero_pedido, status, tipo_consumo, criado_em, concluido_em
          FROM totem_pedidos
         WHERE status = 'pronto'
            OR (status = 'entregue' AND concluido_em > NOW() - INTERVAL '15 minutes')
         ORDER BY
           CASE status WHEN 'pronto' THEN 0 ELSE 1 END,
           criado_em DESC
         LIMIT 20
    ");
    return $stmt->fetchAll();
}

// ── Stream loop ────────────────────────────────────────────────────────
try {
    $db = getDB();
} catch (Throwable $e) {
    sseEvent('error', ['message' => 'Database unavailable']);
    exit;
}

// Send initial connection event
sseEvent('connected', ['topic' => $topic, 'ts' => time()]);

$lastHash    = '';
$pollSecs    = match($topic) { 'kds' => 2, 'caixa' => 4, 'painel' => 3, default => 4 };
$heartbeatAt = time();

while (true) {
    if (connection_aborted()) break;

    try {
        $rows = match($topic) {
            'kds'    => queryKds($db),
            'caixa'  => queryCaixa($db),
            'painel' => queryPainel($db),
            default  => [],
        };
        $hash = md5(json_encode($rows));

        if ($hash !== $lastHash) {
            $lastHash = $hash;
            $evtName  = match($topic) { 'kds' => 'orders', 'caixa' => 'ready', 'painel' => 'painel', default => 'data' };
            sseEvent($evtName, [
                'data' => $rows,
                'ts'   => time(),
            ]);
        }

        // Heartbeat every 15s to keep proxies alive
        if (time() - $heartbeatAt >= 15) {
            sseComment();
            $heartbeatAt = time();
        }

    } catch (Throwable $e) {
        sseEvent('error', ['message' => 'Query failed, retrying...']);
        // Try to reconnect DB on next loop
        try { $db = getDB(); } catch (Throwable $_) {}
    }

    sleep($pollSecs);
}

<?php
/**
 * GET /api/pedido_status.php?id=123
 *
 * Lightweight endpoint — returns only the current status of a given order.
 * Used by the totem to poll for payment confirmation.
 * Public (no auth) — returns only non-sensitive status field.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

require_once '../config/db.php';

try {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, status FROM totem_pedidos WHERE id = ?");
    $stmt->execute([$id]);
    $row  = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => (int)$row['id'], 'status' => $row['status']]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

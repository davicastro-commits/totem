<?php
/**
 * Endpoint de backup chamado pelo admin/index.php via AJAX.
 * Requer sessão admin com role=admin.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();

if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

// Delega ao script de backup
$_SESSION['admin_id']; // manter sessão ativa
require_once __DIR__ . '/../scripts/backup.php';

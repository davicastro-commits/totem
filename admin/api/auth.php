<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/csrf.php';

function requireAdmin(): void {
    if (empty($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }
    // Validar CSRF em todas as requisições de escrita
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'])) {
        csrfVerify();
    }
}

function requireAdminRole(string $minRole = 'operador'): void {
    requireAdmin();
    requireRole($minRole);
}

function adminId(): int    { return (int)($_SESSION['admin_id']   ?? 0); }
function adminRole(): string { return $_SESSION['admin_role'] ?? 'operador'; }
function isAdmin(): bool   { return adminRole() === 'admin'; }

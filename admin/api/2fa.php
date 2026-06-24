<?php
/**
 * API 2FA — gerencia TOTP para o admin logado.
 *
 * GET  ?action=status        → {ativo: bool, secret_preview: 'XXXX****'}
 * POST {action:'gerar'}      → gera novo secret, salva (totp_ativo=false), retorna {secret, uri}
 * POST {action:'ativar', code}   → verifica code, seta totp_ativo=true
 * POST {action:'desativar', code} → verifica code, seta totp_ativo=false e limpa secret
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/totp.php';

header('Content-Type: application/json; charset=utf-8');

requireAdmin();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ── GET status ──────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $stmt = $db->prepare("SELECT totp_secret, totp_ativo FROM totem_admin WHERE id = ?");
    $stmt->execute([adminId()]);
    $row = $stmt->fetch();

    $preview = null;
    if (!empty($row['totp_secret'])) {
        $s       = $row['totp_secret'];
        $preview = substr($s, 0, 4) . str_repeat('*', max(0, strlen($s) - 4));
    }

    echo json_encode([
        'success'        => true,
        'ativo'          => (bool)($row['totp_ativo'] ?? false),
        'secret_preview' => $preview,
    ]);
    exit;
}

// ── POST actions ─────────────────────────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não suportado.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── gerar ────────────────────────────────────────────────────────────────
if ($action === 'gerar') {
    $secret = totpGenerateSecret();

    $stmt = $db->prepare("UPDATE totem_admin SET totp_secret = ?, totp_ativo = false WHERE id = ?");
    $stmt->execute([$secret, adminId()]);

    // Busca e-mail para montar URI legível
    $stmtE = $db->prepare("SELECT email FROM totem_admin WHERE id = ?");
    $stmtE->execute([adminId()]);
    $email = $stmtE->fetchColumn() ?: ($_SESSION['admin_email'] ?? 'admin');

    $uri = totpGetUri($secret, $email);

    auditLog($db, '2fa_gerar', 'auth', adminId(), '2FA secret gerado (ainda inativo)');

    echo json_encode(['success' => true, 'secret' => $secret, 'uri' => $uri]);
    exit;
}

// ── ativar ────────────────────────────────────────────────────────────────
if ($action === 'ativar') {
    $code = trim($body['code'] ?? '');

    $stmt = $db->prepare("SELECT totp_secret FROM totem_admin WHERE id = ?");
    $stmt->execute([adminId()]);
    $row = $stmt->fetch();

    if (empty($row['totp_secret'])) {
        echo json_encode(['success' => false, 'error' => 'Gere um secret antes de ativar.']);
        exit;
    }

    if (!totpVerify($row['totp_secret'], $code)) {
        echo json_encode(['success' => false, 'error' => 'Código inválido. Tente novamente.']);
        exit;
    }

    $db->prepare("UPDATE totem_admin SET totp_ativo = true WHERE id = ?")->execute([adminId()]);
    auditLog($db, '2fa_ativar', 'auth', adminId(), '2FA ativado com sucesso');

    echo json_encode(['success' => true, 'message' => '2FA ativado com sucesso!']);
    exit;
}

// ── desativar ─────────────────────────────────────────────────────────────
if ($action === 'desativar') {
    $code = trim($body['code'] ?? '');

    $stmt = $db->prepare("SELECT totp_secret, totp_ativo FROM totem_admin WHERE id = ?");
    $stmt->execute([adminId()]);
    $row = $stmt->fetch();

    if (empty($row['totp_ativo'])) {
        echo json_encode(['success' => false, 'error' => '2FA já está inativo.']);
        exit;
    }

    if (!totpVerify($row['totp_secret'], $code)) {
        echo json_encode(['success' => false, 'error' => 'Código inválido. Confirme pelo app.']);
        exit;
    }

    $db->prepare("UPDATE totem_admin SET totp_ativo = false, totp_secret = NULL WHERE id = ?")->execute([adminId()]);
    auditLog($db, '2fa_desativar', 'auth', adminId(), '2FA desativado');

    echo json_encode(['success' => true, 'message' => '2FA desativado.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida.']);

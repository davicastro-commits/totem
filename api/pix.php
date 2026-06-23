<?php
/**
 * GET /api/pix.php?total=25.90&ref=ABC1
 *
 * Returns the PIX EMV payload for a given amount.
 * Public endpoint (no auth) — payload itself is harmless to expose.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$total = round((float)($_GET['total'] ?? 0), 2);
$ref   = preg_replace('/[^A-Za-z0-9]/', '', $_GET['ref'] ?? 'PED');

if ($total <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valor inválido']);
    exit;
}

require_once '../config/db.php';
require_once '../config/pix.php';

try {
    $db = getDB();
    $cfgStmt = $db->query("SELECT chave, valor FROM totem_configuracoes WHERE chave IN ('pix_chave','pix_beneficiario','pix_cidade','loja_nome','loja_cnpj')");
    $cfg = $cfgStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $chave       = trim($cfg['pix_chave']       ?? '');
    $beneficiario= trim($cfg['pix_beneficiario'] ?? $cfg['loja_nome'] ?? 'Loja');
    $cidade      = trim($cfg['pix_cidade']       ?? 'Brasil');

    if (empty($chave)) {
        echo json_encode(['success' => false, 'error' => 'Chave PIX não configurada. Configure em Admin → Configurações → PIX.']);
        exit;
    }

    $payload = pixBuildPayload($chave, $total, $beneficiario, $cidade, 'PED' . $ref);

    echo json_encode([
        'success' => true,
        'payload' => $payload,
        'total'   => $total,
        'ref'     => $ref,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno ao gerar PIX.']);
}

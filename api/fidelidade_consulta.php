<?php
/**
 * API pública — Consulta de pontos de fidelidade pelo CPF.
 * Usada pelo totem em status/fidelidade.php.
 *
 * POST {cpf}
 *   → {encontrado:true, nome, pontos_atual, total_gasto, total_pedidos}
 *   → {encontrado:false}
 *
 * Rate limit: 10 req/min por IP (arquivo tmp, usa config/rate_limit_api.php).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit_api.php';

rateLimit('fidelidade', 10, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

$cpfRaw = preg_replace('/\D/', '', (string)($body['cpf'] ?? ''));

if (strlen($cpfRaw) !== 11) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'CPF inválido']);
    exit;
}

// Validação de dígito do CPF
function validarCPFFid(string $cpf): bool
{
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int)$cpf[$i] * ($t + 1 - $i);
        $r = ((10 * $sum) % 11) % 10;
        if ((int)$cpf[$t] !== $r) return false;
    }
    return true;
}

if (!validarCPFFid($cpfRaw)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'CPF inválido']);
    exit;
}

try {
    $db = getDB();

    // Busca cliente — suporta tanto pontos_atual quanto pontos_saldo (compatibilidade com schema)
    $stmt = $db->prepare("
        SELECT id,
               nome,
               COALESCE(pontos_saldo, pontos_atual, 0) AS pontos_atual,
               COALESCE(total_gasto, 0)                AS total_gasto,
               COALESCE(total_pedidos, 0)              AS total_pedidos
          FROM totem_clientes
         WHERE cpf = ?
           AND ativo = TRUE
         LIMIT 1
    ");
    $stmt->execute([$cpfRaw]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        echo json_encode(['success' => true, 'encontrado' => false]);
        exit;
    }

    // Busca configuração de pontos para calcular próximo nível/recompensa
    $cfg = null;
    try {
        $cfg = $db->query(
            "SELECT pontos_por_real, real_por_ponto FROM totem_pontos_config WHERE ativo = TRUE ORDER BY id DESC LIMIT 1"
        )->fetch();
    } catch (Throwable) {}

    echo json_encode([
        'success'       => true,
        'encontrado'    => true,
        'nome'          => $cliente['nome'],
        'pontos_atual'  => (int)$cliente['pontos_atual'],
        'total_gasto'   => (float)$cliente['total_gasto'],
        'total_pedidos' => (int)$cliente['total_pedidos'],
        'real_por_ponto'=> $cfg ? (float)$cfg['real_por_ponto'] : 0.05,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[fidelidade_consulta] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

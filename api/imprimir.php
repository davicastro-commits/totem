<?php
/**
 * POST /api/imprimir.php
 * Body: { "pedido_id": 123 }   OR   { "pedido": { ...complete order object... } }
 *
 * Sends an ESC/POS receipt to the configured thermal printer.
 * Requires valid admin session (cashier/kds can trigger it).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

require_once '../config/db.php';
require_once '../config/impressora.php';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $db = getDB();

    // ── Carregar configurações da impressora ──────────────────────────
    $cfgStmt = $db->query("SELECT chave, valor FROM totem_configuracoes");
    $cfgRows = $cfgStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $impressoraAtiva  = filter_var($cfgRows['impressora_ativa'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $impressoraIp     = trim($cfgRows['impressora_ip']    ?? '');
    $impressoraPorta  = (int)($cfgRows['impressora_porta'] ?? 9100);
    $impressoraLargura= (int)($cfgRows['impressora_largura'] ?? 42);

    if (!$impressoraAtiva) {
        echo json_encode(['success' => false, 'error' => 'Impressora térmica não está ativa nas configurações.']);
        exit;
    }

    // ── Buscar pedido ─────────────────────────────────────────────────
    $pedido = null;

    // Se recebeu pedido completo (direto do totem/caixa)
    if (!empty($body['pedido']) && is_array($body['pedido'])) {
        $pedido = $body['pedido'];
    }

    // Se recebeu pedido_id, busca do banco
    if (!$pedido && !empty($body['pedido_id'])) {
        $id   = (int)$body['pedido_id'];
        $stmt = $db->prepare("
            SELECT p.*, a.nome AS operador_nome
              FROM totem_pedidos p
              LEFT JOIN totem_admin a ON a.id = p.operador_id
             WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            echo json_encode(['success' => false, 'error' => 'Pedido não encontrado.']);
            exit;
        }

        // Buscar itens
        $itens = $db->prepare("SELECT * FROM totem_itens_pedido WHERE pedido_id = ? ORDER BY id");
        $itens->execute([$id]);
        $pedido['itens'] = $itens->fetchAll();
        $pedido['numero'] = $pedido['numero_pedido'];
    }

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'pedido_id ou pedido é obrigatório.']);
        exit;
    }

    // ── Construir e enviar ESC/POS ────────────────────────────────────
    $esc = buildEscPosReceipt($pedido, $cfgRows, $impressoraLargura);
    $esc->send($impressoraIp, $impressoraPorta);

    echo json_encode(['success' => true, 'message' => 'Impresso com sucesso.']);

} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de banco de dados.']);
}

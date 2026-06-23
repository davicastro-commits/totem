<?php
/**
 * API de cupons de desconto.
 * Ações públicas (totem): validar, usar
 * Ações admin (requer session): listar, criar, atualizar
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── Helpers ──────────────────────────────────────────────────────────

function calcularDesconto(array $cupom, float $totalPedido): float
{
    switch ($cupom['tipo']) {
        case 'percentual':
            return round($totalPedido * ((float)$cupom['valor'] / 100), 2);
        case 'fixo':
            return min((float)$cupom['valor'], $totalPedido);
        case 'frete_gratis':
            return 0.0; // frete grátis — sem desconto monetário direto no totem
        default:
            return 0.0;
    }
}

function isAdminSession(): bool
{
    return !empty($_SESSION['admin_id']);
}

try {
    $db = getDB();

    // ── GET — listar cupons (admin) ──────────────────────────────────
    if ($method === 'GET') {
        if (!isAdminSession()) jsonErr('Não autenticado', 401);

        $stmt = $db->query(
            "SELECT c.*, cl.nome AS cliente_nome
               FROM totem_cupons c
               LEFT JOIN totem_clientes cl ON cl.id = c.cliente_id
              ORDER BY c.criado_em DESC"
        );
        $cupons = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $cupons], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($method, ['POST', 'PUT'], true)) jsonErr('Método não permitido', 405);

    // ── PUT — atualizar cupom (admin) ────────────────────────────────
    if ($method === 'PUT') {
        if (!isAdminSession()) jsonErr('Não autenticado', 401);
        csrfVerify();

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) jsonErr('JSON inválido', 400);

        $id   = (int)($body['id']   ?? 0);
        $ativo = isset($body['ativo']) ? (bool)$body['ativo'] : null;

        if ($id <= 0) jsonErr('id obrigatório', 400);

        // Campos editáveis
        $sets  = [];
        $params = [];

        if ($ativo !== null) { $sets[] = 'ativo = ?'; $params[] = $ativo; }

        // Campos opcionais
        foreach (['uso_maximo', 'validade'] as $campo) {
            if (array_key_exists($campo, $body)) {
                $sets[]   = $campo . ' = ?';
                $params[] = $body[$campo] !== '' && $body[$campo] !== null ? $body[$campo] : null;
            }
        }

        if (empty($sets)) jsonErr('Nenhum campo para atualizar', 400);

        $params[] = $id;
        $db->prepare("UPDATE totem_cupons SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        auditLog($db, 'editar', 'cupons', $id, 'Cupom atualizado');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST ─────────────────────────────────────────────────────────
    $body   = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) jsonErr('JSON inválido', 400);
    $action = $body['action'] ?? '';

    // ── validar (público) ────────────────────────────────────────────
    if ($action === 'validar') {
        $codigo      = strtoupper(trim($body['codigo'] ?? ''));
        $totalPedido = (float)($body['total_pedido'] ?? 0);

        if (!$codigo) jsonErr('Código obrigatório', 400);

        $stmt = $db->prepare(
            "SELECT * FROM totem_cupons
              WHERE UPPER(codigo) = ?
                AND ativo = true"
        );
        $stmt->execute([$codigo]);
        $cupom = $stmt->fetch();

        if (!$cupom) {
            echo json_encode(['success' => true, 'valido' => false, 'cupom' => null, 'erro' => 'Cupom não encontrado ou inativo'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar validade
        if ($cupom['validade'] && $cupom['validade'] < date('Y-m-d')) {
            echo json_encode(['success' => true, 'valido' => false, 'cupom' => null, 'erro' => 'Cupom expirado'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar limite de usos
        if ($cupom['uso_maximo'] !== null && (int)$cupom['usos_atuais'] >= (int)$cupom['uso_maximo']) {
            echo json_encode(['success' => true, 'valido' => false, 'cupom' => null, 'erro' => 'Limite de usos atingido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar valor mínimo
        if ($totalPedido > 0 && $totalPedido < (float)$cupom['valor_minimo']) {
            echo json_encode([
                'success' => true,
                'valido'  => false,
                'cupom'   => null,
                'erro'    => 'Pedido mínimo de R$ ' . number_format((float)$cupom['valor_minimo'], 2, ',', '.') . ' não atingido',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $desconto = calcularDesconto($cupom, $totalPedido);

        echo json_encode([
            'success' => true,
            'valido'  => true,
            'cupom'   => [
                'id'                  => (int)$cupom['id'],
                'codigo'              => $cupom['codigo'],
                'tipo'                => $cupom['tipo'],
                'valor'               => (float)$cupom['valor'],
                'desconto_calculado'  => $desconto,
            ],
            'erro' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── usar (público) ───────────────────────────────────────────────
    if ($action === 'usar') {
        $cupomId  = (int)($body['cupom_id']  ?? 0);
        $pedidoId = (int)($body['pedido_id'] ?? 0);

        if ($cupomId <= 0) jsonErr('cupom_id obrigatório', 400);

        $db->prepare(
            "UPDATE totem_cupons
                SET usos_atuais = usos_atuais + 1
              WHERE id = ?"
        )->execute([$cupomId]);

        if ($pedidoId > 0) {
            $db->prepare("UPDATE totem_pedidos SET cupom_id = ? WHERE id = ?")
               ->execute([$cupomId, $pedidoId]);
        }

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── criar (admin) ────────────────────────────────────────────────
    if ($action === 'criar') {
        if (!isAdminSession()) jsonErr('Não autenticado', 401);
        csrfVerify();

        $codigo     = strtoupper(trim($body['codigo'] ?? ''));
        $tipo       = $body['tipo'] ?? '';
        $valor      = (float)($body['valor'] ?? 0);
        $valMin     = (float)($body['valor_minimo'] ?? 0);
        $usoMax     = isset($body['uso_maximo']) && $body['uso_maximo'] !== '' ? (int)$body['uso_maximo'] : null;
        $clienteId  = isset($body['cliente_id']) && $body['cliente_id'] !== '' ? (int)$body['cliente_id'] : null;
        $validade   = !empty($body['validade']) ? $body['validade'] : null;

        if (!$codigo) jsonErr('Código obrigatório', 400);
        if (!in_array($tipo, ['percentual', 'fixo', 'frete_gratis'], true)) jsonErr('Tipo inválido', 400);
        if ($valor <= 0) jsonErr('Valor deve ser positivo', 400);

        // Checar código duplicado
        $chk = $db->prepare("SELECT id FROM totem_cupons WHERE UPPER(codigo) = ?");
        $chk->execute([strtoupper($codigo)]);
        if ($chk->fetch()) jsonErr('Código já existe', 409);

        $ins = $db->prepare(
            "INSERT INTO totem_cupons (codigo, tipo, valor, valor_minimo, uso_maximo, cliente_id, validade)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             RETURNING id"
        );
        $ins->execute([$codigo, $tipo, $valor, $valMin, $usoMax, $clienteId, $validade]);
        $id = (int)$ins->fetchColumn();

        auditLog($db, 'criar', 'cupons', $id, "Cupom criado: $codigo");
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    jsonErr('Ação não reconhecida', 400);

} catch (PDOException $e) {
    error_log('[cupons.php] PDO: ' . $e->getMessage());
    jsonErr('Erro de banco de dados', 500);
} catch (Throwable $e) {
    error_log('[cupons.php] ' . $e->getMessage());
    jsonErr('Erro interno', 500);
}

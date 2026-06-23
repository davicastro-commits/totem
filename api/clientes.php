<?php
/**
 * API pública de clientes/fidelidade — usada pelo totem.
 * Não requer autenticação administrativa.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

// ── Helpers ──────────────────────────────────────────────────────────

function sanitizeCpf(string $cpf): string
{
    return preg_replace('/\D/', '', $cpf);
}

function formatCpf(string $cpf): string
{
    $c = sanitizeCpf($cpf);
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $c);
}

function validarCpfDigitos(string $cpf): bool
{
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int)$cpf[$i] * ($t + 1 - $i);
        $r = ((10 * $sum) % 11) % 10;
        if ((int)$cpf[$t] !== $r) return false;
    }
    return true;
}

function getPontosConfig(PDO $db): array
{
    $cfg = $db->query("SELECT * FROM totem_pontos_config WHERE ativo = true ORDER BY id DESC LIMIT 1")->fetch();
    return $cfg ?: ['pontos_por_real' => 1.0, 'real_por_ponto' => 0.05, 'validade_dias' => 365];
}

function clienteParaTotem(array $row, array $cfg): array
{
    $desconto = round((float)$row['pontos_saldo'] * (float)$cfg['real_por_ponto'], 2);
    return [
        'id'                  => (int)$row['id'],
        'nome'                => $row['nome'],
        'cpf_formatado'       => formatCpf($row['cpf']),
        'pontos_saldo'        => (int)$row['pontos_saldo'],
        'desconto_disponivel' => $desconto,
        'total_gasto'         => (float)$row['total_gasto'],
        'total_pedidos'       => (int)$row['total_pedidos'],
    ];
}

// ── Roteamento ───────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // GET ?id=X — perfil completo + histórico
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) jsonErr('Parâmetro id obrigatório', 400);

        $stmt = $db->prepare("SELECT * FROM totem_clientes WHERE id = ? AND ativo = true");
        $stmt->execute([$id]);
        $cliente = $stmt->fetch();
        if (!$cliente) jsonErr('Cliente não encontrado', 404);

        $cfg = getPontosConfig($db);

        $hist = $db->prepare(
            "SELECT tipo, pontos, descricao, expira_em, criado_em
               FROM totem_pontos_historico
              WHERE cliente_id = ?
              ORDER BY criado_em DESC
              LIMIT 10"
        );
        $hist->execute([$id]);
        $historico = $hist->fetchAll();

        echo json_encode([
            'success'   => true,
            'cliente'   => clienteParaTotem($cliente, $cfg),
            'historico' => $historico,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') jsonErr('Método não permitido', 405);

    $body   = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) jsonErr('JSON inválido', 400);
    $action = $body['action'] ?? '';

    // ── identificar ─────────────────────────────────────────────────
    if ($action === 'identificar') {
        $cpfRaw = sanitizeCpf($body['cpf'] ?? '');
        if (strlen($cpfRaw) !== 11) jsonErr('CPF inválido', 400);

        $cfg  = getPontosConfig($db);
        $stmt = $db->prepare("SELECT * FROM totem_clientes WHERE cpf = ? AND ativo = true");
        $stmt->execute([$cpfRaw]);
        $cliente = $stmt->fetch();

        if (!$cliente) {
            echo json_encode([
                'success'       => true,
                'cliente'       => null,
                'pode_cadastrar'=> true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'cliente' => clienteParaTotem($cliente, $cfg),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── cadastrar ────────────────────────────────────────────────────
    if ($action === 'cadastrar') {
        $cpfRaw = sanitizeCpf($body['cpf'] ?? '');
        if (strlen($cpfRaw) !== 11) jsonErr('CPF deve ter 11 dígitos', 400);
        if (!validarCpfDigitos($cpfRaw))  jsonErr('CPF inválido (dígitos verificadores)', 400);

        $nome      = trim(substr($body['nome'] ?? '', 0, 100));
        $telefone  = trim(substr($body['telefone'] ?? '', 0, 20));
        $email     = trim(substr($body['email'] ?? '', 0, 100));
        $nascimento = $body['data_nascimento'] ?? null;
        $lgpd      = !empty($body['consentimento_lgpd']);

        if (!$nome)  jsonErr('Nome é obrigatório', 400);
        if (!$lgpd)  jsonErr('Consentimento LGPD é obrigatório', 400);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('E-mail inválido', 400);
        if ($nascimento && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nascimento)) $nascimento = null;

        // Verificar CPF duplicado
        $chk = $db->prepare("SELECT id FROM totem_clientes WHERE cpf = ?");
        $chk->execute([$cpfRaw]);
        if ($chk->fetch()) jsonErr('CPF já cadastrado', 409);

        $ins = $db->prepare(
            "INSERT INTO totem_clientes (nome, cpf, telefone, email, data_nascimento, consentimento_lgpd)
             VALUES (?, ?, ?, ?, ?, ?)
             RETURNING id, nome, pontos_saldo"
        );
        $ins->execute([$nome, $cpfRaw, $telefone ?: null, $email ?: null, $nascimento, $lgpd]);
        $novo = $ins->fetch();

        echo json_encode([
            'success' => true,
            'cliente' => [
                'id'           => (int)$novo['id'],
                'nome'         => $novo['nome'],
                'pontos_saldo' => 0,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── acumular_pontos ──────────────────────────────────────────────
    if ($action === 'acumular_pontos') {
        $clienteId = (int)($body['cliente_id'] ?? 0);
        $pedidoId  = (int)($body['pedido_id']  ?? 0);
        $total     = (float)($body['total']     ?? 0);

        if ($clienteId <= 0) jsonErr('cliente_id obrigatório', 400);
        if ($total <= 0)     jsonErr('total deve ser positivo', 400);

        $cfg = getPontosConfig($db);
        $pontosGanhos = (int)floor($total * (float)$cfg['pontos_por_real']);

        if ($pontosGanhos <= 0) {
            echo json_encode(['success' => true, 'pontos_ganhos' => 0, 'novo_saldo' => 0], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $expira = (new DateTime())->modify('+' . (int)$cfg['validade_dias'] . ' days')->format('Y-m-d');

        $db->beginTransaction();
        try {
            // Registrar no histórico
            $ins = $db->prepare(
                "INSERT INTO totem_pontos_historico (cliente_id, pedido_id, tipo, pontos, descricao, expira_em)
                 VALUES (?, ?, 'ganho', ?, ?, ?)"
            );
            $ins->execute([
                $clienteId,
                $pedidoId ?: null,
                $pontosGanhos,
                'Pontos acumulados — pedido #' . ($pedidoId ?: 'avulso'),
                $expira,
            ]);

            // Atualizar saldo e totais
            $upd = $db->prepare(
                "UPDATE totem_clientes
                    SET pontos_saldo   = pontos_saldo + ?,
                        total_gasto    = total_gasto + ?,
                        total_pedidos  = total_pedidos + 1,
                        atualizado_em  = NOW()
                  WHERE id = ?
                  RETURNING pontos_saldo"
            );
            $upd->execute([$pontosGanhos, $total, $clienteId]);
            $novo = $upd->fetch();

            // Atualizar pontos_ganhos no pedido (se informado)
            if ($pedidoId > 0) {
                $db->prepare("UPDATE totem_pedidos SET pontos_ganhos = ? WHERE id = ?")
                   ->execute([$pontosGanhos, $pedidoId]);
            }

            $db->commit();

            echo json_encode([
                'success'       => true,
                'pontos_ganhos' => $pontosGanhos,
                'novo_saldo'    => (int)$novo['pontos_saldo'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── resgatar_pontos ──────────────────────────────────────────────
    if ($action === 'resgatar_pontos') {
        $clienteId = (int)($body['cliente_id'] ?? 0);
        $pedidoId  = (int)($body['pedido_id']  ?? 0);
        $pontos    = (int)($body['pontos']      ?? 0);

        if ($clienteId <= 0) jsonErr('cliente_id obrigatório', 400);
        if ($pontos <= 0)    jsonErr('pontos deve ser positivo', 400);

        $cfg = getPontosConfig($db);

        $db->beginTransaction();
        try {
            // Buscar saldo atual com lock
            $sel = $db->prepare("SELECT pontos_saldo FROM totem_clientes WHERE id = ? AND ativo = true FOR UPDATE");
            $sel->execute([$clienteId]);
            $cliente = $sel->fetch();
            if (!$cliente) { $db->rollBack(); jsonErr('Cliente não encontrado', 404); }
            if ((int)$cliente['pontos_saldo'] < $pontos) { $db->rollBack(); jsonErr('Saldo insuficiente', 400); }

            $desconto = round($pontos * (float)$cfg['real_por_ponto'], 2);

            // Registrar no histórico
            $ins = $db->prepare(
                "INSERT INTO totem_pontos_historico (cliente_id, pedido_id, tipo, pontos, descricao)
                 VALUES (?, ?, 'resgatado', ?, ?)"
            );
            $ins->execute([
                $clienteId,
                $pedidoId ?: null,
                -$pontos,
                'Pontos resgatados — desconto R$ ' . number_format($desconto, 2, ',', '.'),
            ]);

            // Debitar saldo
            $upd = $db->prepare(
                "UPDATE totem_clientes
                    SET pontos_saldo  = pontos_saldo - ?,
                        atualizado_em = NOW()
                  WHERE id = ?
                  RETURNING pontos_saldo"
            );
            $upd->execute([$pontos, $clienteId]);
            $novo = $upd->fetch();

            $db->commit();

            echo json_encode([
                'success'        => true,
                'desconto_valor' => $desconto,
                'novo_saldo'     => (int)$novo['pontos_saldo'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    jsonErr('Ação não reconhecida', 400);

} catch (PDOException $e) {
    error_log('[clientes.php] PDO: ' . $e->getMessage());
    jsonErr('Erro de banco de dados', 500);
} catch (Throwable $e) {
    error_log('[clientes.php] ' . $e->getMessage());
    jsonErr('Erro interno', 500);
}

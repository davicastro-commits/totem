<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

// Parse JSON body for POST
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    $action = $body['action'] ?? $action;
}

try {
    $db = getDB();

    // ── GET: turno atual do admin logado ──────────────────────────────────
    if ($method === 'GET' && $action === 'atual') {
        $stmt = $db->prepare("
            SELECT t.*,
                   COALESCE((
                       SELECT SUM(p.total)
                       FROM material.totem_pedidos p
                       WHERE p.criado_em BETWEEN t.abertura_em AND COALESCE(t.fechamento_em, NOW())
                         AND p.status NOT IN ('cancelado','aguardando_pagamento')
                   ), 0) AS faturamento_no_turno,
                   COALESCE((
                       SELECT SUM(p.total)
                       FROM material.totem_pedidos p
                       WHERE p.criado_em BETWEEN t.abertura_em AND COALESCE(t.fechamento_em, NOW())
                         AND p.status NOT IN ('cancelado','aguardando_pagamento')
                         AND p.forma_pagamento = 'dinheiro'
                   ), 0) AS faturamento_dinheiro,
                   COALESCE((
                       SELECT COUNT(*)
                       FROM material.totem_pedidos p
                       WHERE p.criado_em BETWEEN t.abertura_em AND COALESCE(t.fechamento_em, NOW())
                         AND p.status NOT IN ('cancelado','aguardando_pagamento')
                   ), 0) AS qtd_pedidos
            FROM material.totem_turnos t
            WHERE t.admin_id = ? AND t.status = 'aberto'
            ORDER BY t.abertura_em DESC
            LIMIT 1
        ");
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $turno = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'turno'   => $turno ?: null,
        ]);
        exit;
    }

    // ── GET: histórico de turnos ──────────────────────────────────────────
    if ($method === 'GET' && $action === 'historico') {
        $dataIni = $_GET['data_ini'] ?? null;
        $dataFim = $_GET['data_fim'] ?? null;

        $where  = [];
        $params = [];

        if ($dataIni) {
            $where[]  = 'DATE(t.abertura_em) >= ?';
            $params[] = $dataIni;
        }
        if ($dataFim) {
            $where[]  = 'DATE(t.abertura_em) <= ?';
            $params[] = $dataFim;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT t.*,
                   COALESCE((
                       SELECT SUM(p.total)
                       FROM material.totem_pedidos p
                       WHERE p.criado_em BETWEEN t.abertura_em AND COALESCE(t.fechamento_em, NOW())
                         AND p.status NOT IN ('cancelado','aguardando_pagamento')
                   ), 0) AS faturamento_no_turno,
                   COALESCE((
                       SELECT SUM(p.total)
                       FROM material.totem_pedidos p
                       WHERE p.criado_em BETWEEN t.abertura_em AND COALESCE(t.fechamento_em, NOW())
                         AND p.status NOT IN ('cancelado','aguardando_pagamento')
                         AND p.forma_pagamento = 'dinheiro'
                   ), 0) AS faturamento_dinheiro,
                   CASE
                     WHEN t.valor_fechamento IS NOT NULL
                       THEN t.valor_fechamento
                            - t.valor_abertura
                            - t.total_sangrias
                            + COALESCE((
                                SELECT SUM(p.total)
                                FROM material.totem_pedidos p
                                WHERE p.criado_em BETWEEN t.abertura_em AND COALESCE(t.fechamento_em, NOW())
                                  AND p.status NOT IN ('cancelado','aguardando_pagamento')
                                  AND p.forma_pagamento = 'dinheiro'
                              ), 0)
                     ELSE NULL
                   END AS diferenca_caixa
            FROM material.totem_turnos t
            {$whereSQL}
            ORDER BY t.abertura_em DESC
            LIMIT 50
        ");
        $stmt->execute($params);
        $turnos = $stmt->fetchAll();

        echo json_encode(['success' => true, 'turnos' => $turnos]);
        exit;
    }

    // ── POST: abrir turno ─────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'abrir') {
        $adminId = (int)$_SESSION['admin_id'];

        // Verifica se já existe turno aberto para este operador
        $check = $db->prepare("
            SELECT id FROM material.totem_turnos
            WHERE admin_id = ? AND status = 'aberto'
            LIMIT 1
        ");
        $check->execute([$adminId]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Você já possui um turno aberto. Feche o turno atual antes de abrir um novo.']);
            exit;
        }

        $valorAbertura = (float)($body['valor_abertura'] ?? 0);
        $obs           = trim($body['obs'] ?? '');
        $adminNome     = $_SESSION['admin_nome'] ?? '';

        $stmt = $db->prepare("
            INSERT INTO material.totem_turnos (admin_id, admin_nome, valor_abertura, obs_abertura, status)
            VALUES (?, ?, ?, ?, 'aberto')
            RETURNING *
        ");
        $stmt->execute([$adminId, $adminNome, $valorAbertura, $obs ?: null]);
        $turno = $stmt->fetch();

        auditLog($db, 'abrir_turno', 'caixa', (int)$turno['id'],
            "Turno aberto — fundo R$ " . number_format($valorAbertura, 2, ',', '.'));

        echo json_encode(['success' => true, 'turno' => $turno]);
        exit;
    }

    // ── POST: sangria ─────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'sangria') {
        $turnoId = (int)($body['turno_id'] ?? 0);
        $valor   = (float)($body['valor']   ?? 0);
        $motivo  = trim($body['motivo'] ?? '');
        $adminId = (int)$_SESSION['admin_id'];

        if ($turnoId <= 0 || $valor <= 0) {
            echo json_encode(['success' => false, 'error' => 'Informe um turno e um valor válido.']);
            exit;
        }

        // Verifica se o turno pertence ao operador e está aberto
        $checkStmt = $db->prepare("
            SELECT id FROM material.totem_turnos
            WHERE id = ? AND admin_id = ? AND status = 'aberto'
        ");
        $checkStmt->execute([$turnoId, $adminId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Turno não encontrado ou não pertence a você.']);
            exit;
        }

        $db->beginTransaction();
        try {
            $ins = $db->prepare("
                INSERT INTO material.totem_sangrias (turno_id, valor, motivo, admin_id)
                VALUES (?, ?, ?, ?)
                RETURNING *
            ");
            $ins->execute([$turnoId, $valor, $motivo ?: null, $adminId]);
            $sangria = $ins->fetch();

            $db->prepare("
                UPDATE material.totem_turnos
                SET total_sangrias = total_sangrias + ?
                WHERE id = ?
            ")->execute([$valor, $turnoId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        echo json_encode(['success' => true, 'sangria' => $sangria]);
        exit;
    }

    // ── POST: fechar turno ────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'fechar') {
        $turnoId        = (int)($body['turno_id']        ?? 0);
        $valorFechamento = (float)($body['valor_fechamento'] ?? 0);
        $obs            = trim($body['obs'] ?? '');
        $adminId        = (int)$_SESSION['admin_id'];

        if ($turnoId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Turno inválido.']);
            exit;
        }

        // Busca o turno para validação e cálculo
        $turnoStmt = $db->prepare("
            SELECT * FROM material.totem_turnos
            WHERE id = ? AND admin_id = ? AND status = 'aberto'
        ");
        $turnoStmt->execute([$turnoId, $adminId]);
        $turno = $turnoStmt->fetch();

        if (!$turno) {
            echo json_encode(['success' => false, 'error' => 'Turno não encontrado ou já foi fechado.']);
            exit;
        }

        // Calcula faturamento em dinheiro no período do turno
        $fatDinheiroStmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) AS fat_dinheiro
            FROM material.totem_pedidos
            WHERE criado_em BETWEEN ? AND NOW()
              AND status NOT IN ('cancelado','aguardando_pagamento')
              AND forma_pagamento = 'dinheiro'
        ");
        $fatDinheiroStmt->execute([$turno['abertura_em']]);
        $fatDinheiro = (float)$fatDinheiroStmt->fetchColumn();

        // Esperado na gaveta = fundo abertura - sangrias + dinheiro vendido
        $esperado  = (float)$turno['valor_abertura'] - (float)$turno['total_sangrias'] + $fatDinheiro;
        $diferenca = $valorFechamento - $esperado;

        $db->prepare("
            UPDATE material.totem_turnos
            SET fechamento_em    = NOW(),
                status           = 'fechado',
                valor_fechamento = ?,
                obs_fechamento   = ?
            WHERE id = ?
        ")->execute([$valorFechamento, $obs ?: null, $turnoId]);

        // Busca turno atualizado
        $updated = $db->prepare("SELECT * FROM material.totem_turnos WHERE id = ?");
        $updated->execute([$turnoId]);
        $turnoFechado = $updated->fetch();

        auditLog($db, 'fechar_turno', 'caixa', $turnoId,
            "Turno fechado — contado R$ " . number_format($valorFechamento, 2, ',', '.') .
            " | diferença R$ " . number_format($diferenca, 2, ',', '.'));

        echo json_encode([
            'success'            => true,
            'turno'              => $turnoFechado,
            'faturamento_dinheiro' => $fatDinheiro,
            'esperado'           => $esperado,
            'diferenca'          => $diferenca,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação inválida.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

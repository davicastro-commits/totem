<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once '../../config/audit.php';
require_once '../../config/estoque_helper.php';
require_once '../../config/fidelidade_helper.php';
require_once '../../config/webhook.php';
require_once 'auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        // ── Detalhe de um pedido ──────────────────────────────────────
        if (isset($_GET['id'])) {
            $id   = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT p.*, a.nome AS operador_nome
                  FROM totem_pedidos p
                  LEFT JOIN totem_admin a ON a.id = p.operador_id
                 WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $pedido = $stmt->fetch();
            if (!$pedido) { echo json_encode(['success' => false, 'error' => 'Pedido nao encontrado']); exit; }

            $itensStmt = $db->prepare("SELECT * FROM totem_itens_pedido WHERE pedido_id = ? ORDER BY id");
            $itensStmt->execute([$id]);
            $pedido['itens'] = $itensStmt->fetchAll();
            echo json_encode(['success' => true, 'pedido' => $pedido]);
            exit;
        }

        // ── Listagem com filtros ──────────────────────────────────────
        $status  = $_GET['status']  ?? null;
        $dataIni = $_GET['data_ini'] ?? null;
        $dataFim = $_GET['data_fim'] ?? null;
        $busca   = trim($_GET['busca'] ?? '');
        $origem  = $_GET['origem']  ?? null;
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = 50;
        $offset  = ($page - 1) * $limit;

        $allowed = ['aguardando_pagamento','aguardando','preparando','pronto','entregue','cancelado'];

        $where  = [];
        $params = [];

        if ($status === 'ativos') {
            $where[] = "p.status NOT IN ('entregue','cancelado')";
        } elseif ($status && in_array($status, $allowed)) {
            $where[] = "p.status = ?";
            $params[] = $status;
        }
        if ($dataIni) { $where[] = "DATE(p.criado_em) >= ?"; $params[] = $dataIni; }
        if ($dataFim) { $where[] = "DATE(p.criado_em) <= ?"; $params[] = $dataFim; }
        if ($busca)   {
            $where[] = "(p.numero_pedido ILIKE ? OR p.cpf ILIKE ?)";
            $params[] = "%{$busca}%";
            $params[] = "%{$busca}%";
        }
        if ($origem)  { $where[] = "p.origem = ?"; $params[] = $origem; }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM totem_pedidos p {$whereSQL}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $paged = $params;
        $paged[] = $limit;
        $paged[] = $offset;

        $stmt = $db->prepare("
            SELECT p.id, p.numero_pedido, p.tipo_consumo, p.total, p.forma_pagamento,
                   p.status, p.criado_em, p.cpf, p.obs, p.origem,
                   p.iniciado_em, p.concluido_em,
                   a.nome AS operador_nome,
                   json_agg(
                     json_build_object(
                       'nome', i.nome_produto, 'qtd', i.quantidade,
                       'sub', i.subtotal, 'obs', i.obs
                     )
                     ORDER BY i.id
                   ) AS itens
              FROM totem_pedidos p
              LEFT JOIN totem_admin a ON a.id = p.operador_id
              JOIN totem_itens_pedido i ON i.pedido_id = p.id
              {$whereSQL}
             GROUP BY p.id, a.nome
             ORDER BY p.criado_em DESC
             LIMIT ? OFFSET ?
        ");
        $stmt->execute($paged);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['itens'] = json_decode($r['itens'], true);
        }

        echo json_encode([
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'pages'   => ceil($total / $limit),
            'page'    => $page,
        ]);

    } elseif ($method === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true);
        $id     = (int)($body['id'] ?? 0);
        $status = $body['status'] ?? '';
        $motivo = trim($body['motivo'] ?? '');

        $allowed = ['aguardando_pagamento','aguardando','preparando','pronto','entregue','cancelado'];
        if (!$id || !in_array($status, $allowed)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dados invalidos']);
            exit;
        }

        $current = $db->prepare("SELECT status, numero_pedido FROM totem_pedidos WHERE id = ?");
        $current->execute([$id]);
        $ped = $current->fetch();

        if ($status === 'cancelado') {
            $stmt = $db->prepare("
                UPDATE totem_pedidos
                   SET status = ?, cancelado_por = ?, cancelado_em = NOW(), cancelado_motivo = ?
                 WHERE id = ?
            ");
            $stmt->execute([$status, adminId(), $motivo ?: null, $id]);
            $acao = 'pedido_cancelado';
        } elseif ($status === 'preparando') {
            $stmt = $db->prepare("UPDATE totem_pedidos SET status = ?, iniciado_em = NOW() WHERE id = ? AND iniciado_em IS NULL");
            $stmt->execute([$status, $id]);
            if ($stmt->rowCount() === 0) {
                // Já tinha iniciado_em, só atualiza status
                $db->prepare("UPDATE totem_pedidos SET status = ? WHERE id = ?")->execute([$status, $id]);
            }
            $acao = 'pedido_status_alterado';
        } elseif ($status === 'pronto') {
            $stmt = $db->prepare("UPDATE totem_pedidos SET status = ?, concluido_em = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);
            $acao = 'pedido_status_alterado';
        } else {
            $stmt = $db->prepare("UPDATE totem_pedidos SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $acao = 'pedido_status_alterado';
        }

        // Baixa insumos e acumula pontos quando pagamento eletrônico é confirmado
        if ($status === 'aguardando' && $ped['status'] === 'aguardando_pagamento') {
            try { baixarEstoquePorPedido($db, $id); } catch (Throwable) {}
            try { acumularPontosPorPedido($db, $id); } catch (Throwable) {}
        }

        auditLog($db, $acao, 'pedidos', $id,
            "Pedido #{$ped['numero_pedido']}: {$ped['status']} -> {$status}" . ($motivo ? " | Motivo: {$motivo}" : ''),
            ['status' => $ped['status']],
            ['status' => $status, 'motivo' => $motivo]);

        // Disparar webhook de mudança de status
        try {
            triggerN8n('status_pedido', [
                'pedido_id'     => $id,
                'numero'        => $ped['numero_pedido'],
                'status_antes'  => $ped['status'],
                'status_novo'   => $status,
                'motivo'        => $motivo ?: null,
            ]);
        } catch (Throwable) {}

        // ── Hook NFC-e: sincronizar status da nota com o pedido ───────────
        try {
            $nfceRow = $db->prepare("SELECT id, status FROM totem_nfce WHERE pedido_id=?");
            $nfceRow->execute([$id]);
            $nfce = $nfceRow->fetch();
            $nfceStatus = match($status) {
                'cancelado'  => 'cancelada',
                'entregue'   => 'autorizada',
                'pronto'     => 'autorizada',
                'preparando' => 'transmitindo',
                'aguardando' => 'transmitindo',
                default      => null,
            };
            if ($nfce && $nfceStatus) {
                if ($nfceStatus === 'autorizada') {
                    $db->prepare("UPDATE totem_nfce SET status=?, autorizado_em=COALESCE(autorizado_em,NOW()) WHERE id=?")->execute([$nfceStatus,$nfce['id']]);
                } elseif ($nfceStatus === 'cancelada') {
                    $db->prepare("UPDATE totem_nfce SET status=?, cancelado_em=NOW() WHERE id=?")->execute([$nfceStatus,$nfce['id']]);
                } else {
                    $db->prepare("UPDATE totem_nfce SET status=? WHERE id=?")->execute([$nfceStatus,$nfce['id']]);
                }
            } elseif (!$nfce && $nfceStatus) {
                // Criar NFC-e se ainda não existe
                $numStmt = $db->query("SELECT COALESCE(valor,'0') FROM totem_configuracoes WHERE chave='nfce_numero_atual'");
                $numAtual = (int)($numStmt->fetchColumn() ?: 0) + 1;
                $db->prepare("INSERT INTO totem_configuracoes (chave,valor) VALUES ('nfce_numero_atual',?) ON CONFLICT (chave) DO UPDATE SET valor=EXCLUDED.valor")->execute([$numAtual]);
                $serieStmt = $db->query("SELECT COALESCE(valor,'001') FROM totem_configuracoes WHERE chave='nfce_serie'");
                $serie = $serieStmt->fetchColumn() ?: '001';
                $ambStmt = $db->query("SELECT COALESCE(valor,'homologacao') FROM totem_configuracoes WHERE chave='nfce_ambiente'");
                $amb = $ambStmt->fetchColumn() ?: 'homologacao';
                $authEm = in_array($nfceStatus, ['autorizada']) ? date('Y-m-d H:i:s') : null;
                $db->prepare("INSERT INTO totem_nfce (pedido_id,numero,serie,status,total,forma_pagamento,ambiente,autorizado_em) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$id,$numAtual,$serie,$nfceStatus,$ped['total'],$ped['forma_pagamento'],$amb,$authEm]);
            }
        } catch (Throwable) {} // Não bloquear o pedido se houver erro fiscal

        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

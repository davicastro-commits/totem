<?php
/**
 * API — Mesas e Comandas (Module 13)
 *
 * GET  ?            → lista todas mesas com status e comanda_id aberta
 * GET  ?id=X        → detalhe da mesa + comanda aberta + itens
 * POST { action, ... } → diversas ações
 * PUT  { id, ... }  → editar mesa (requer admin)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/audit.php';

// ─── Helper: recalcula totais da comanda ──────────────────────────────
function recalcularComanda(PDO $db, int $comanda_id): array
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(subtotal), 0) AS subtotal
           FROM totem_itens_comanda
          WHERE comanda_id = ?"
    );
    $stmt->execute([$comanda_id]);
    $subtotal = (float)$stmt->fetchColumn();

    // Busca taxa e desconto atuais
    $c = $db->prepare("SELECT taxa_servico, desconto FROM totem_comandas WHERE id = ?");
    $c->execute([$comanda_id]);
    $row = $c->fetch();

    $taxa     = (float)($row['taxa_servico'] ?? 0);
    $desconto = (float)($row['desconto'] ?? 0);
    $total    = $subtotal + $taxa - $desconto;

    $db->prepare(
        "UPDATE totem_comandas SET subtotal = ?, total = ? WHERE id = ?"
    )->execute([$subtotal, $total, $comanda_id]);

    return ['subtotal' => $subtotal, 'total' => $total];
}

// ─── GET ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db = getDB();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($id) {
            // Detalhe da mesa
            $stmt = $db->prepare(
                "SELECT m.*, a.nome AS garcom_nome
                   FROM totem_mesas m
                   LEFT JOIN totem_admin a ON a.id = m.garcom_id
                  WHERE m.id = ?"
            );
            $stmt->execute([$id]);
            $mesa = $stmt->fetch();
            if (!$mesa) jsonErr('Mesa não encontrada', 404);

            // Comanda aberta
            $cStmt = $db->prepare(
                "SELECT c.*, a.nome AS garcom_nome
                   FROM totem_comandas c
                   LEFT JOIN totem_admin a ON a.id = c.garcom_id
                  WHERE c.mesa_id = ? AND c.status = 'aberta'
                  ORDER BY c.aberta_em DESC
                  LIMIT 1"
            );
            $cStmt->execute([$id]);
            $comanda = $cStmt->fetch() ?: null;

            $itens = [];
            if ($comanda) {
                $iStmt = $db->prepare(
                    "SELECT ic.*, p.nome AS produto_nome, p.imagem AS produto_imagem
                       FROM totem_itens_comanda ic
                       JOIN totem_produtos p ON p.id = ic.produto_id
                      WHERE ic.comanda_id = ?
                      ORDER BY ic.criado_em ASC"
                );
                $iStmt->execute([$comanda['id']]);
                $itens = $iStmt->fetchAll();
            }

            jsonOk(['mesa' => $mesa, 'comanda' => $comanda, 'itens' => $itens]);
        }

        // Lista todas mesas
        $stmt = $db->query(
            "SELECT m.*,
                    a.nome AS garcom_nome,
                    c.id   AS comanda_id,
                    c.subtotal AS comanda_subtotal,
                    c.total    AS comanda_total,
                    c.aberta_em AS comanda_aberta_em,
                    (SELECT COUNT(*) FROM totem_itens_comanda ic WHERE ic.comanda_id = c.id) AS qtd_itens
               FROM totem_mesas m
               LEFT JOIN totem_admin a ON a.id = m.garcom_id
               LEFT JOIN totem_comandas c ON c.mesa_id = m.id AND c.status = 'aberta'
              WHERE m.ativa = true
              ORDER BY m.numero ASC"
        );
        jsonOk($stmt->fetchAll());

    } catch (PDOException $e) {
        jsonErr('Erro ao consultar mesas: ' . $e->getMessage(), 500);
    }
}

// ─── POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = jsonBody();
    $action = $body['action'] ?? '';

    try {
        $db = getDB();

        // ── abrir_comanda ────────────────────────────────────────────
        if ($action === 'abrir_comanda') {
            $mesa_id    = (int)($body['mesa_id'] ?? 0);
            $garcom_id  = !empty($body['garcom_id']) ? (int)$body['garcom_id'] : null;
            $observacao = trim($body['observacao'] ?? '');

            if (!$mesa_id) jsonErr('mesa_id é obrigatório');

            // Verificar se já existe comanda aberta
            $check = $db->prepare("SELECT id FROM totem_comandas WHERE mesa_id = ? AND status = 'aberta'");
            $check->execute([$mesa_id]);
            if ($check->fetch()) jsonErr('Esta mesa já possui uma comanda aberta');

            $db->beginTransaction();

            $stmt = $db->prepare(
                "INSERT INTO totem_comandas (mesa_id, garcom_id, observacao)
                 VALUES (?, ?, ?)
                 RETURNING id"
            );
            $stmt->execute([$mesa_id, $garcom_id, $observacao ?: null]);
            $comanda_id = (int)$stmt->fetchColumn();

            $db->prepare(
                "UPDATE totem_mesas SET status = 'ocupada', garcom_id = ? WHERE id = ?"
            )->execute([$garcom_id, $mesa_id]);

            $db->commit();
            jsonOk(['comanda_id' => $comanda_id], 201);
        }

        // ── adicionar_item ───────────────────────────────────────────
        if ($action === 'adicionar_item') {
            $comanda_id = (int)($body['comanda_id'] ?? 0);
            $produto_id = (int)($body['produto_id'] ?? 0);
            $quantidade = max(1, (int)($body['quantidade'] ?? 1));
            $obs        = trim($body['obs'] ?? '');

            if (!$comanda_id || !$produto_id) jsonErr('comanda_id e produto_id são obrigatórios');

            // Verificar comanda aberta
            $cStmt = $db->prepare("SELECT id FROM totem_comandas WHERE id = ? AND status = 'aberta'");
            $cStmt->execute([$comanda_id]);
            if (!$cStmt->fetch()) jsonErr('Comanda não encontrada ou não está aberta');

            // Buscar preço do produto
            $pStmt = $db->prepare("SELECT id, preco FROM totem_produtos WHERE id = ? AND disponivel = true");
            $pStmt->execute([$produto_id]);
            $produto = $pStmt->fetch();
            if (!$produto) jsonErr('Produto não encontrado ou indisponível');

            $preco    = (float)$produto['preco'];
            $subtotal = $preco * $quantidade;

            $stmt = $db->prepare(
                "INSERT INTO totem_itens_comanda (comanda_id, produto_id, quantidade, preco_unitario, subtotal, obs)
                 VALUES (?, ?, ?, ?, ?, ?)
                 RETURNING id"
            );
            $stmt->execute([$comanda_id, $produto_id, $quantidade, $preco, $subtotal, $obs ?: null]);
            $item_id = (int)$stmt->fetchColumn();

            $totais = recalcularComanda($db, $comanda_id);

            jsonOk([
                'item_id'         => $item_id,
                'subtotal_item'   => $subtotal,
                'subtotal_comanda' => $totais['subtotal'],
                'total_comanda'   => $totais['total'],
            ]);
        }

        // ── remover_item ─────────────────────────────────────────────
        if ($action === 'remover_item') {
            $item_id = (int)($body['item_id'] ?? 0);
            if (!$item_id) jsonErr('item_id é obrigatório');

            // Buscar comanda_id antes de deletar
            $iStmt = $db->prepare(
                "SELECT ic.comanda_id FROM totem_itens_comanda ic
                  JOIN totem_comandas c ON c.id = ic.comanda_id
                 WHERE ic.id = ? AND c.status = 'aberta'"
            );
            $iStmt->execute([$item_id]);
            $row = $iStmt->fetch();
            if (!$row) jsonErr('Item não encontrado ou comanda já fechada');

            $comanda_id = (int)$row['comanda_id'];

            $db->prepare("DELETE FROM totem_itens_comanda WHERE id = ?")->execute([$item_id]);
            $totais = recalcularComanda($db, $comanda_id);

            jsonOk([
                'removed'         => true,
                'subtotal_comanda' => $totais['subtotal'],
                'total_comanda'   => $totais['total'],
            ]);
        }

        // ── enviar_kds ───────────────────────────────────────────────
        if ($action === 'enviar_kds') {
            $comanda_id = (int)($body['comanda_id'] ?? 0);
            if (!$comanda_id) jsonErr('comanda_id é obrigatório');

            // Verificar comanda
            $cStmt = $db->prepare("SELECT id, mesa_id FROM totem_comandas WHERE id = ? AND status = 'aberta'");
            $cStmt->execute([$comanda_id]);
            $comanda = $cStmt->fetch();
            if (!$comanda) jsonErr('Comanda não encontrada ou não está aberta');

            // Marcar itens aguardando como enviados para KDS
            $stmt = $db->prepare(
                "UPDATE totem_itens_comanda
                    SET enviado_kds = true, status = 'preparando'
                  WHERE comanda_id = ? AND status = 'aguardando' AND enviado_kds = false
                  RETURNING id"
            );
            $stmt->execute([$comanda_id]);
            $enviados = $stmt->rowCount();

            // Registrar evento no banco para o KDS ler (poll)
            // O KDS lê totem_itens_comanda filtrando enviado_kds=true e status=preparando
            // Poderia disparar SSE via api/sse.php se necessário

            jsonOk(['itens_enviados' => $enviados, 'mesa_id' => $comanda['mesa_id']]);
        }

        // ── fechar_conta ─────────────────────────────────────────────
        if ($action === 'fechar_conta') {
            $comanda_id          = (int)($body['comanda_id'] ?? 0);
            $aplicar_taxa        = !empty($body['aplicar_taxa_servico']);
            $desconto            = max(0, (float)($body['desconto'] ?? 0));
            $forma_pagamento     = trim($body['forma_pagamento'] ?? '');

            if (!$comanda_id) jsonErr('comanda_id é obrigatório');

            $cStmt = $db->prepare("SELECT * FROM totem_comandas WHERE id = ? AND status = 'aberta'");
            $cStmt->execute([$comanda_id]);
            $comanda = $cStmt->fetch();
            if (!$comanda) jsonErr('Comanda não encontrada ou não está aberta');

            $subtotal    = (float)$comanda['subtotal'];
            $taxa        = $aplicar_taxa ? round($subtotal * 0.10, 2) : 0.0;
            $total       = $subtotal + $taxa - $desconto;

            $db->prepare(
                "UPDATE totem_comandas
                    SET status = 'fechada',
                        taxa_servico = ?,
                        desconto = ?,
                        total = ?,
                        forma_pagamento = ?,
                        fechada_em = NOW()
                  WHERE id = ?"
            )->execute([$taxa, $desconto, $total, $forma_pagamento ?: null, $comanda_id]);

            jsonOk([
                'subtotal'       => $subtotal,
                'taxa_servico'   => $taxa,
                'desconto'       => $desconto,
                'total'          => $total,
                'forma_pagamento' => $forma_pagamento,
            ]);
        }

        // ── pagar ────────────────────────────────────────────────────
        if ($action === 'pagar') {
            $comanda_id      = (int)($body['comanda_id'] ?? 0);
            $forma_pagamento = trim($body['forma_pagamento'] ?? '');

            if (!$comanda_id) jsonErr('comanda_id é obrigatório');

            $cStmt = $db->prepare("SELECT * FROM totem_comandas WHERE id = ? AND status IN ('aberta','fechada')");
            $cStmt->execute([$comanda_id]);
            $comanda = $cStmt->fetch();
            if (!$comanda) jsonErr('Comanda não encontrada');

            $db->beginTransaction();

            // Se ainda estava aberta, calcula subtotal
            if ($comanda['status'] === 'aberta') {
                $totais   = recalcularComanda($db, $comanda_id);
                $subtotal = $totais['subtotal'];
                $total    = $totais['total'];
            } else {
                $subtotal = (float)$comanda['subtotal'];
                $total    = (float)$comanda['total'];
            }

            if ($forma_pagamento) {
                $db->prepare(
                    "UPDATE totem_comandas SET forma_pagamento = ? WHERE id = ?"
                )->execute([$forma_pagamento, $comanda_id]);
            }

            $db->prepare(
                "UPDATE totem_comandas SET status = 'paga', paga_em = NOW() WHERE id = ?"
            )->execute([$comanda_id]);

            $db->prepare(
                "UPDATE totem_mesas SET status = 'livre', garcom_id = NULL WHERE id = ?"
            )->execute([$comanda['mesa_id']]);

            $db->commit();
            jsonOk(['pago' => true, 'total' => $total]);
        }

        // ── cancelar ─────────────────────────────────────────────────
        if ($action === 'cancelar') {
            $comanda_id = (int)($body['comanda_id'] ?? 0);
            $motivo     = trim($body['motivo'] ?? '');

            if (!$comanda_id) jsonErr('comanda_id é obrigatório');

            $cStmt = $db->prepare("SELECT * FROM totem_comandas WHERE id = ? AND status IN ('aberta','fechada')");
            $cStmt->execute([$comanda_id]);
            $comanda = $cStmt->fetch();
            if (!$comanda) jsonErr('Comanda não encontrada');

            $db->beginTransaction();

            $db->prepare(
                "UPDATE totem_comandas
                    SET status = 'cancelada',
                        observacao = CASE WHEN observacao IS NULL OR observacao = '' THEN ? ELSE observacao || ' | Motivo: ' || ? END,
                        fechada_em = NOW()
                  WHERE id = ?"
            )->execute([$motivo ?: 'Cancelada', $motivo ?: 'Cancelada', $comanda_id]);

            $db->prepare(
                "UPDATE totem_mesas SET status = 'livre', garcom_id = NULL WHERE id = ?"
            )->execute([$comanda['mesa_id']]);

            $db->commit();
            jsonOk(['cancelado' => true]);
        }

        jsonErr('Ação desconhecida: ' . $action);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        jsonErr('Erro de banco: ' . $e->getMessage(), 500);
    }
}

// ─── PUT — Editar mesa (admin) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Requer sessão admin
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_id'])) jsonErr('Não autenticado', 401);

    $body = jsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonErr('id é obrigatório');

    $numero      = trim($body['numero'] ?? '');
    $capacidade  = (int)($body['capacidade'] ?? 4);
    $localizacao = trim($body['localizacao'] ?? '');
    $ativa       = isset($body['ativa']) ? (bool)$body['ativa'] : true;

    if (!$numero) jsonErr('numero é obrigatório');

    try {
        $db = getDB();
        $stmt = $db->prepare(
            "UPDATE totem_mesas
                SET numero = ?, capacidade = ?, localizacao = ?, ativa = ?
              WHERE id = ?
              RETURNING id"
        );
        $stmt->execute([$numero, $capacidade, $localizacao ?: null, $ativa ? 'true' : 'false', $id]);
        if (!$stmt->fetch()) jsonErr('Mesa não encontrada', 404);

        auditLog($db, 'editar_mesa', 'mesas', $id, "Mesa #{$numero} editada");
        jsonOk(['updated' => true, 'id' => $id]);

    } catch (PDOException $e) {
        jsonErr('Erro ao atualizar mesa: ' . $e->getMessage(), 500);
    }
}

jsonErr('Método não suportado', 405);

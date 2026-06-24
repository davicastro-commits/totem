<?php
/**
 * Comanda Digital — API para o garçom gerenciar itens de pedidos ativos.
 * Sem autenticação de admin: uso na rede interna / PIN opcional.
 *
 * Endpoints GET:
 *   ?action=pedidos_ativos   → pedidos do dia com status aguardando|preparando|pronto
 *   ?action=produtos         → produtos disponíveis agrupados por categoria
 *
 * Endpoints POST (JSON body):
 *   {action:'adicionar_item', pedido_id, produto_id, quantidade}
 *   {action:'remover_item',   item_id, pedido_id}
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/response.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET'
    ? trim($_GET['action'] ?? '')
    : '';

// ── GET handlers ────────────────────────────────────────────────────────────

if ($method === 'GET') {

    if ($action === 'pedidos_ativos') {
        try {
            $db = getDB();

            $pedidos = $db->query("
                SELECT p.id, p.numero_pedido, p.status, p.total,
                       p.tipo_consumo, p.criado_em,
                       m.numero AS mesa_numero
                  FROM totem_pedidos p
                  LEFT JOIN totem_mesas m ON m.id = p.mesa_id
                 WHERE p.status IN ('aguardando','preparando','pronto')
                   AND DATE(p.criado_em) = CURRENT_DATE
                 ORDER BY p.criado_em ASC
            ")->fetchAll();

            $stmtItens = $db->prepare("
                SELECT id, produto_id, nome_produto, quantidade, preco_unitario, subtotal
                  FROM totem_itens_pedido
                 WHERE pedido_id = ?
                 ORDER BY id
            ");

            $result = [];
            foreach ($pedidos as $p) {
                $stmtItens->execute([(int)$p['id']]);
                $itens = $stmtItens->fetchAll();
                $result[] = [
                    'id'            => (int)$p['id'],
                    'numero_pedido' => $p['numero_pedido'],
                    'status'        => $p['status'],
                    'total'         => (float)$p['total'],
                    'tipo_consumo'  => $p['tipo_consumo'],
                    'criado_em'     => $p['criado_em'],
                    'mesa_numero'   => $p['mesa_numero'],
                    'total_itens'   => count($itens),
                    'itens'         => array_map(fn($i) => [
                        'id'            => (int)$i['id'],
                        'produto_id'    => (int)$i['produto_id'],
                        'nome_produto'  => $i['nome_produto'],
                        'quantidade'    => (int)$i['quantidade'],
                        'preco_unitario'=> (float)$i['preco_unitario'],
                        'subtotal'      => (float)$i['subtotal'],
                    ], $itens),
                ];
            }

            jsonOk($result);
        } catch (Throwable $e) {
            error_log('[comanda] pedidos_ativos: ' . $e->getMessage());
            jsonErr('Erro ao buscar pedidos', 500);
        }
    }

    if ($action === 'produtos') {
        try {
            $db = getDB();

            $rows = $db->query("
                SELECT p.id, p.nome, p.preco, p.descricao,
                       c.id   AS cat_id,
                       c.nome AS cat_nome,
                       c.icone AS cat_icone
                  FROM totem_produtos p
                  LEFT JOIN totem_categorias c ON c.id = p.categoria_id
                 WHERE p.disponivel = TRUE
                 ORDER BY c.nome NULLS LAST, p.nome
            ")->fetchAll();

            $cats = [];
            foreach ($rows as $r) {
                $catId   = $r['cat_id'] ?? 0;
                $catNome = $r['cat_nome'] ?? 'Sem categoria';
                if (!isset($cats[$catId])) {
                    $cats[$catId] = [
                        'id'     => (int)$catId,
                        'nome'   => $catNome,
                        'icone'  => $r['cat_icone'] ?? '🍽️',
                        'produtos' => [],
                    ];
                }
                $cats[$catId]['produtos'][] = [
                    'id'    => (int)$r['id'],
                    'nome'  => $r['nome'],
                    'preco' => (float)$r['preco'],
                    'descricao' => $r['descricao'] ?? '',
                ];
            }

            jsonOk(array_values($cats));
        } catch (Throwable $e) {
            error_log('[comanda] produtos: ' . $e->getMessage());
            jsonErr('Erro ao buscar produtos', 500);
        }
    }

    jsonErr('Ação inválida', 400);
}

// ── POST handlers ────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $body   = jsonBody();
    $action = trim($body['action'] ?? '');

    // ── adicionar_item ───────────────────────────────────────────────────────
    if ($action === 'adicionar_item') {
        $pedidoId  = (int)($body['pedido_id']  ?? 0);
        $produtoId = (int)($body['produto_id'] ?? 0);
        $qtd       = max(1, (int)($body['quantidade'] ?? 1));

        if (!$pedidoId || !$produtoId) jsonErr('pedido_id e produto_id são obrigatórios', 400);

        try {
            $db = getDB();

            // Valida pedido existe e não está finalizado
            $stmtP = $db->prepare("SELECT id, status, total FROM totem_pedidos WHERE id = ?");
            $stmtP->execute([$pedidoId]);
            $pedido = $stmtP->fetch();

            if (!$pedido) jsonErr('Pedido não encontrado', 404);
            if (in_array($pedido['status'], ['entregue', 'cancelado'], true)) {
                jsonErr('Pedido já está ' . $pedido['status'] . ' e não pode ser alterado', 422);
            }

            // Busca produto
            $stmtProd = $db->prepare("SELECT id, nome, preco FROM totem_produtos WHERE id = ? AND disponivel = TRUE");
            $stmtProd->execute([$produtoId]);
            $produto = $stmtProd->fetch();

            if (!$produto) jsonErr('Produto não encontrado ou indisponível', 404);

            $precoUnit = (float)$produto['preco'];
            $subtotal  = round($precoUnit * $qtd, 2);

            $db->beginTransaction();

            $db->prepare("
                INSERT INTO totem_itens_pedido (pedido_id, produto_id, nome_produto, quantidade, preco_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$pedidoId, $produtoId, $produto['nome'], $qtd, $precoUnit, $subtotal]);

            $db->prepare("
                UPDATE totem_pedidos SET total = total + ? WHERE id = ?
            ")->execute([$subtotal, $pedidoId]);

            $db->commit();

            // Retorna pedido atualizado
            $pedidoAtual = $db->prepare("SELECT id, numero_pedido, status, total FROM totem_pedidos WHERE id = ?");
            $pedidoAtual->execute([$pedidoId]);
            $pedAtual = $pedidoAtual->fetch();

            $stmtItens = $db->prepare("
                SELECT id, produto_id, nome_produto, quantidade, preco_unitario, subtotal
                  FROM totem_itens_pedido WHERE pedido_id = ? ORDER BY id
            ");
            $stmtItens->execute([$pedidoId]);
            $itens = $stmtItens->fetchAll();

            jsonOk([
                'pedido' => [
                    'id'            => (int)$pedAtual['id'],
                    'numero_pedido' => $pedAtual['numero_pedido'],
                    'status'        => $pedAtual['status'],
                    'total'         => (float)$pedAtual['total'],
                ],
                'itens' => array_map(fn($i) => [
                    'id'             => (int)$i['id'],
                    'produto_id'     => (int)$i['produto_id'],
                    'nome_produto'   => $i['nome_produto'],
                    'quantidade'     => (int)$i['quantidade'],
                    'preco_unitario' => (float)$i['preco_unitario'],
                    'subtotal'       => (float)$i['subtotal'],
                ], $itens),
            ], 201);

        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log('[comanda] adicionar_item: ' . $e->getMessage());
            jsonErr('Erro ao adicionar item', 500);
        }
    }

    // ── remover_item ─────────────────────────────────────────────────────────
    if ($action === 'remover_item') {
        $itemId   = (int)($body['item_id']   ?? 0);
        $pedidoId = (int)($body['pedido_id'] ?? 0);

        if (!$itemId || !$pedidoId) jsonErr('item_id e pedido_id são obrigatórios', 400);

        try {
            $db = getDB();

            // Valida que item pertence ao pedido
            $stmtItem = $db->prepare("SELECT id, subtotal FROM totem_itens_pedido WHERE id = ? AND pedido_id = ?");
            $stmtItem->execute([$itemId, $pedidoId]);
            $item = $stmtItem->fetch();

            if (!$item) jsonErr('Item não encontrado neste pedido', 404);

            // Valida pedido não finalizado
            $stmtP = $db->prepare("SELECT status FROM totem_pedidos WHERE id = ?");
            $stmtP->execute([$pedidoId]);
            $pedido = $stmtP->fetch();

            if (!$pedido) jsonErr('Pedido não encontrado', 404);
            if (in_array($pedido['status'], ['entregue', 'cancelado'], true)) {
                jsonErr('Pedido já está ' . $pedido['status'] . ' e não pode ser alterado', 422);
            }

            $subtotalItem = (float)$item['subtotal'];

            $db->beginTransaction();

            $db->prepare("DELETE FROM totem_itens_pedido WHERE id = ?")->execute([$itemId]);

            // Recalcula total do pedido a partir dos itens restantes
            $novoTotal = $db->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM totem_itens_pedido WHERE pedido_id = ?");
            $novoTotal->execute([$pedidoId]);
            $total = (float)$novoTotal->fetchColumn();

            $db->prepare("UPDATE totem_pedidos SET total = ? WHERE id = ?")->execute([$total, $pedidoId]);

            $db->commit();

            // Retorna pedido atualizado
            $stmtItens = $db->prepare("
                SELECT id, produto_id, nome_produto, quantidade, preco_unitario, subtotal
                  FROM totem_itens_pedido WHERE pedido_id = ? ORDER BY id
            ");
            $stmtItens->execute([$pedidoId]);
            $itens = $stmtItens->fetchAll();

            jsonOk([
                'pedido' => ['id' => $pedidoId, 'total' => $total],
                'itens'  => array_map(fn($i) => [
                    'id'             => (int)$i['id'],
                    'produto_id'     => (int)$i['produto_id'],
                    'nome_produto'   => $i['nome_produto'],
                    'quantidade'     => (int)$i['quantidade'],
                    'preco_unitario' => (float)$i['preco_unitario'],
                    'subtotal'       => (float)$i['subtotal'],
                ], $itens),
            ]);

        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log('[comanda] remover_item: ' . $e->getMessage());
            jsonErr('Erro ao remover item', 500);
        }
    }

    jsonErr('Ação inválida', 400);
}

jsonErr('Método não permitido', 405);

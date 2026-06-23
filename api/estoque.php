<?php
/**
 * API de Estoque — Movimentações e Ficha Técnica
 *
 * GET  ?action=movimentacoes&insumo_id=X  → histórico de movimentações
 * GET  ?action=ficha&produto_id=X         → ficha técnica do produto
 * POST { action:'entrada'|'saida'|'ajuste', insumo_id, quantidade, custo_unitario, motivo }
 * POST { action:'salvar_ficha', produto_id, itens:[{insumo_id, quantidade}] }
 * POST { action:'baixar_pedido', pedido_id }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../admin/api/auth.php';
require_once __DIR__ . '/../config/audit.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // ── GET ──────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'movimentacoes') {
            $insumo_id = filter_input(INPUT_GET, 'insumo_id', FILTER_VALIDATE_INT);
            assert400($insumo_id > 0, 'insumo_id inválido.');

            $limit  = min((int)($_GET['limit']  ?? 50), 200);
            $offset = (int)($_GET['offset'] ?? 0);

            $stmt = $db->prepare("
                SELECT m.*,
                       i.nome  AS insumo_nome,
                       i.unidade,
                       a.nome  AS usuario_nome
                  FROM totem_movimentacoes_estoque m
                  JOIN totem_insumos i ON i.id = m.insumo_id
                  LEFT JOIN totem_admin a ON a.id = m.usuario_id
                 WHERE m.insumo_id = ?
                 ORDER BY m.criado_em DESC
                 LIMIT ? OFFSET ?
            ");
            $stmt->execute([$insumo_id, $limit, $offset]);
            jsonOk($stmt->fetchAll());
        }

        if ($action === 'ficha') {
            $produto_id = filter_input(INPUT_GET, 'produto_id', FILTER_VALIDATE_INT);
            assert400($produto_id > 0, 'produto_id inválido.');

            $stmt = $db->prepare("
                SELECT ft.id, ft.produto_id, ft.insumo_id, ft.quantidade,
                       i.nome     AS insumo_nome,
                       i.unidade,
                       i.estoque_atual,
                       i.custo_medio
                  FROM totem_ficha_tecnica ft
                  JOIN totem_insumos i ON i.id = ft.insumo_id
                 WHERE ft.produto_id = ?
                 ORDER BY i.nome ASC
            ");
            $stmt->execute([$produto_id]);
            jsonOk($stmt->fetchAll());
        }

        if ($action === 'relatorio') {
            $dataIni = $_GET['data_ini'] ?? date('Y-m-01');
            $dataFim = $_GET['data_fim'] ?? date('Y-m-d');

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) $dataIni = date('Y-m-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim))  $dataFim = date('Y-m-d');

            $stmt = $db->prepare("
                SELECT i.id,
                       i.nome,
                       i.unidade,
                       i.estoque_atual,
                       i.custo_medio,
                       COALESCE(SUM(CASE WHEN m.tipo = 'saida'   THEN m.quantidade ELSE 0 END), 0) AS total_saida,
                       COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.quantidade ELSE 0 END), 0) AS total_entrada
                  FROM totem_insumos i
                  LEFT JOIN totem_movimentacoes_estoque m
                         ON m.insumo_id = i.id
                        AND m.criado_em BETWEEN :ini AND (:fim::date + INTERVAL '1 day')
                 GROUP BY i.id, i.nome, i.unidade, i.estoque_atual, i.custo_medio
                 ORDER BY total_saida DESC
            ");
            $stmt->execute([':ini' => $dataIni, ':fim' => $dataFim]);
            $rows = $stmt->fetchAll();

            // Cast numeric fields
            foreach ($rows as &$r) {
                $r['estoque_atual']  = (float)$r['estoque_atual'];
                $r['custo_medio']    = (float)$r['custo_medio'];
                $r['total_saida']    = (float)$r['total_saida'];
                $r['total_entrada']  = (float)$r['total_entrada'];
                $r['custo_total_saida'] = round($r['total_saida'] * $r['custo_medio'], 4);
            }
            unset($r);

            $totalCustoSaidas = array_sum(array_column($rows, 'custo_total_saida'));

            jsonOk([
                'periodo'             => ['ini' => $dataIni, 'fim' => $dataFim],
                'insumos'             => $rows,
                'total_custo_saidas'  => round($totalCustoSaidas, 4),
            ]);
        }

        jsonErr('action inválida. Use: movimentacoes, ficha ou relatorio', 400);
    }

    // ── Operações de escrita — exige admin ───────────────────────────────
    requireAdmin();

    if ($method === 'POST') {
        $body   = jsonBody();
        $action = $body['action'] ?? '';

        // ── Entrada / Saída / Ajuste ──────────────────────────────────────
        if (in_array($action, ['entrada', 'saida', 'ajuste'])) {
            $insumo_id      = (int)($body['insumo_id'] ?? 0);
            $quantidade     = (float)($body['quantidade'] ?? 0);
            $custo_unitario = (float)($body['custo_unitario'] ?? 0);
            $motivo         = trim($body['motivo'] ?? '');

            assert400($insumo_id > 0,  'insumo_id inválido.');
            assert400($quantidade > 0, 'A quantidade deve ser maior que zero.');

            $db->beginTransaction();

            // Buscar insumo atual
            $iStmt = $db->prepare("SELECT * FROM totem_insumos WHERE id = ? FOR UPDATE");
            $iStmt->execute([$insumo_id]);
            $insumo = $iStmt->fetch();
            if (!$insumo) { $db->rollBack(); jsonErr('Insumo não encontrado', 404); }

            // Calcular novo estoque
            if ($action === 'entrada') {
                $estAtual    = (float)$insumo['estoque_atual'];
                $custoAtual  = (float)$insumo['custo_medio'];
                // Média ponderada
                $novoCusto   = ($estAtual + $quantidade) > 0
                    ? ($estAtual * $custoAtual + $quantidade * $custo_unitario) / ($estAtual + $quantidade)
                    : $custo_unitario;
                $novoEstoque = $estAtual + $quantidade;

                $db->prepare("
                    UPDATE totem_insumos
                       SET estoque_atual = ?, custo_medio = ?, atualizado_em = NOW()
                     WHERE id = ?
                ")->execute([$novoEstoque, $novoCusto, $insumo_id]);

            } elseif ($action === 'saida') {
                $novoEstoque = (float)$insumo['estoque_atual'] - $quantidade;
                $db->prepare("
                    UPDATE totem_insumos
                       SET estoque_atual = ?, atualizado_em = NOW()
                     WHERE id = ?
                ")->execute([$novoEstoque, $insumo_id]);

            } else { // ajuste
                // Para ajuste, quantidade é o novo valor absoluto
                $novoEstoque = $quantidade;
                $db->prepare("
                    UPDATE totem_insumos
                       SET estoque_atual = ?, atualizado_em = NOW()
                     WHERE id = ?
                ")->execute([$novoEstoque, $insumo_id]);
                // A quantidade registrada na movimentação é a diferença
                $quantidade = $quantidade - (float)$insumo['estoque_atual'];
            }

            // Registrar movimentação
            $mStmt = $db->prepare("
                INSERT INTO totem_movimentacoes_estoque
                    (insumo_id, tipo, quantidade, custo_unitario, motivo, usuario_id)
                VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $mStmt->execute([
                $insumo_id, $action, $quantidade, $custo_unitario,
                $motivo ?: null, $_SESSION['admin_id'] ?? null,
            ]);
            $movId = $mStmt->fetchColumn();

            $db->commit();

            auditLog($db, $action, 'estoque_movimentacao', (int)$movId,
                "Movimentação {$action} — insumo #{$insumo_id} — qty {$quantidade}");

            jsonOk(['id' => (int)$movId, 'estoque_atual' => $novoEstoque]);
        }

        // ── Salvar ficha técnica ──────────────────────────────────────────
        if ($action === 'salvar_ficha') {
            $produto_id = (int)($body['produto_id'] ?? 0);
            $itens      = $body['itens'] ?? [];

            assert400($produto_id > 0, 'produto_id inválido.');
            assert400(is_array($itens), 'itens deve ser um array.');

            $db->beginTransaction();

            // Remover ficha anterior
            $db->prepare("DELETE FROM totem_ficha_tecnica WHERE produto_id = ?")
               ->execute([$produto_id]);

            // Inserir novos itens
            $ins = $db->prepare("
                INSERT INTO totem_ficha_tecnica (produto_id, insumo_id, quantidade)
                VALUES (?, ?, ?)
            ");
            foreach ($itens as $item) {
                $insumo_id = (int)($item['insumo_id'] ?? 0);
                $qty       = (float)($item['quantidade'] ?? 0);
                if ($insumo_id <= 0 || $qty <= 0) continue;
                $ins->execute([$produto_id, $insumo_id, $qty]);
            }

            $db->commit();

            auditLog($db, 'salvar_ficha', 'estoque_ficha', $produto_id,
                "Ficha técnica salva para produto #{$produto_id} — " . count($itens) . " insumo(s)");

            jsonOk(['produto_id' => $produto_id, 'itens_salvos' => count($itens)]);
        }

        // ── Baixar pedido (dar saída automática) ──────────────────────────
        if ($action === 'baixar_pedido') {
            $pedido_id = (int)($body['pedido_id'] ?? 0);
            assert400($pedido_id > 0, 'pedido_id inválido.');

            $db->beginTransaction();

            // Buscar itens do pedido
            $pedStmt = $db->prepare("
                SELECT ip.produto_id, ip.quantidade AS qtd_pedido
                  FROM totem_itens_pedido ip
                 WHERE ip.pedido_id = ?
            ");
            $pedStmt->execute([$pedido_id]);
            $itensPedido = $pedStmt->fetchAll();

            if (empty($itensPedido)) {
                $db->rollBack();
                jsonErr('Pedido não encontrado ou sem itens', 404);
            }

            $baixas = [];

            foreach ($itensPedido as $item) {
                // Buscar ficha técnica do produto
                $fichaStmt = $db->prepare("
                    SELECT ft.insumo_id, ft.quantidade AS consumo_unit
                      FROM totem_ficha_tecnica ft
                     WHERE ft.produto_id = ?
                ");
                $fichaStmt->execute([$item['produto_id']]);
                $fichaItens = $fichaStmt->fetchAll();

                foreach ($fichaItens as $fi) {
                    $consumoTotal = (float)$fi['consumo_unit'] * (float)$item['qtd_pedido'];

                    // Atualizar estoque
                    $db->prepare("
                        UPDATE totem_insumos
                           SET estoque_atual = estoque_atual - ?, atualizado_em = NOW()
                         WHERE id = ?
                    ")->execute([$consumoTotal, $fi['insumo_id']]);

                    // Registrar saída
                    $movStmt = $db->prepare("
                        INSERT INTO totem_movimentacoes_estoque
                            (insumo_id, tipo, quantidade, motivo, pedido_id, usuario_id)
                        VALUES (?, 'saida', ?, ?, ?, ?)
                        RETURNING id
                    ");
                    $movStmt->execute([
                        $fi['insumo_id'],
                        $consumoTotal,
                        "Baixa automática pedido #{$pedido_id}",
                        $pedido_id,
                        $_SESSION['admin_id'] ?? null,
                    ]);

                    $baixas[] = [
                        'insumo_id'    => (int)$fi['insumo_id'],
                        'consumo'      => $consumoTotal,
                    ];
                }
            }

            $db->commit();

            auditLog($db, 'baixar_pedido', 'estoque_movimentacao', $pedido_id,
                "Baixa de estoque para pedido #{$pedido_id} — " . count($baixas) . " movimentação(ões)");

            jsonOk(['pedido_id' => $pedido_id, 'baixas' => $baixas]);
        }

        jsonErr("action '{$action}' inválida.", 400);
    }

    jsonErr('Método não permitido', 405);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[estoque.php] PDOException: ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[estoque.php] Error: ' . $e->getMessage());
    jsonErr('Erro interno do servidor', 500);
}

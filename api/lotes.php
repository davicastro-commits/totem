<?php
/**
 * API de Lotes de Insumos
 * GET  ?insumo_id=X          → lotes ativos do insumo
 * GET  ?action=proximos_vencer&dias=30 → lotes vencendo em X dias
 * GET  ?action=vencidos       → lotes já vencidos
 * POST                        → criar lote (entrada com rastreabilidade)
 * PUT                         → atualizar lote
 * DELETE ?id=X                → desativar lote
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../admin/api/auth.php';
require_once __DIR__ . '/../config/audit.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // Lotes próximos do vencimento
        if ($action === 'proximos_vencer') {
            $dias = max(1, (int)($_GET['dias'] ?? 30));
            $stmt = $db->prepare("
                SELECT l.*, i.nome AS insumo_nome, i.unidade
                  FROM totem_lotes_insumo l
                  JOIN totem_insumos i ON i.id = l.insumo_id
                 WHERE l.ativo = true
                   AND l.data_validade IS NOT NULL
                   AND l.data_validade BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '{$dias} days'
                   AND l.quantidade_atual > 0
                 ORDER BY l.data_validade ASC
            ");
            $stmt->execute();
            jsonOk($stmt->fetchAll());
        }

        // Lotes vencidos com estoque
        if ($action === 'vencidos') {
            $stmt = $db->query("
                SELECT l.*, i.nome AS insumo_nome, i.unidade
                  FROM totem_lotes_insumo l
                  JOIN totem_insumos i ON i.id = l.insumo_id
                 WHERE l.ativo = true
                   AND l.data_validade IS NOT NULL
                   AND l.data_validade < CURRENT_DATE
                   AND l.quantidade_atual > 0
                 ORDER BY l.data_validade ASC
            ");
            jsonOk($stmt->fetchAll());
        }

        // Lotes de um insumo
        $insumo_id = filter_input(INPUT_GET, 'insumo_id', FILTER_VALIDATE_INT);
        if ($insumo_id) {
            $stmt = $db->prepare("
                SELECT l.*,
                       CASE
                           WHEN l.data_validade IS NULL THEN 'indeterminado'
                           WHEN l.data_validade < CURRENT_DATE THEN 'vencido'
                           WHEN l.data_validade <= CURRENT_DATE + 7 THEN 'critico'
                           WHEN l.data_validade <= CURRENT_DATE + 30 THEN 'atencao'
                           ELSE 'ok'
                       END AS status_validade,
                       l.data_validade - CURRENT_DATE AS dias_para_vencer
                  FROM totem_lotes_insumo l
                 WHERE l.insumo_id = ? AND l.ativo = true
                 ORDER BY l.data_validade ASC NULLS LAST, l.criado_em DESC
            ");
            $stmt->execute([$insumo_id]);
            jsonOk($stmt->fetchAll());
        }

        // Resumo geral (sem filtro)
        $stmt = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE data_validade IS NOT NULL AND data_validade < CURRENT_DATE AND quantidade_atual > 0) AS vencidos,
                COUNT(*) FILTER (WHERE data_validade BETWEEN CURRENT_DATE AND CURRENT_DATE + 7 AND quantidade_atual > 0) AS criticos,
                COUNT(*) FILTER (WHERE data_validade BETWEEN CURRENT_DATE + 8 AND CURRENT_DATE + 30 AND quantidade_atual > 0) AS atencao,
                COUNT(*) FILTER (WHERE ativo = true AND quantidade_atual > 0) AS total_ativos
            FROM totem_lotes_insumo
        ");
        jsonOk($stmt->fetch());
    }

    requireAdmin();

    if ($method === 'POST') {
        $body = jsonBody();

        $insumo_id         = (int)($body['insumo_id']        ?? 0);
        $numero_lote       = trim($body['numero_lote']        ?? '');
        $fornecedor        = trim($body['fornecedor']         ?? '');
        $data_entrada      = $body['data_entrada']             ?? date('Y-m-d');
        $data_validade     = ($body['data_validade'] ?? '') ?: null;
        $quantidade        = (float)($body['quantidade']       ?? 0);
        $custo_unitario    = (float)($body['custo_unitario']   ?? 0);
        $observacoes       = trim($body['observacoes']         ?? '');

        assert400($insumo_id > 0, 'insumo_id inválido.');
        assert400($quantidade > 0, 'Quantidade deve ser maior que zero.');

        $db->beginTransaction();

        // Inserir lote
        $stmt = $db->prepare("
            INSERT INTO totem_lotes_insumo
                (insumo_id, numero_lote, fornecedor, data_entrada, data_validade,
                 quantidade_inicial, quantidade_atual, custo_unitario, observacoes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$insumo_id, $numero_lote, $fornecedor, $data_entrada, $data_validade,
                        $quantidade, $quantidade, $custo_unitario, $observacoes]);
        $loteId = $stmt->fetchColumn();

        // Registrar entrada no estoque principal (média ponderada)
        $iStmt = $db->prepare("SELECT estoque_atual, custo_medio FROM totem_insumos WHERE id = ?");
        $iStmt->execute([$insumo_id]);
        $ins = $iStmt->fetch();

        $estoqueAtual = (float)$ins['estoque_atual'];
        $custoAtual   = (float)$ins['custo_medio'];
        $novoEstoque  = $estoqueAtual + $quantidade;
        $novoCusto    = $novoEstoque > 0
            ? (($estoqueAtual * $custoAtual) + ($quantidade * $custo_unitario)) / $novoEstoque
            : $custo_unitario;

        $db->prepare("UPDATE totem_insumos SET estoque_atual = ?, custo_medio = ?, atualizado_em = NOW() WHERE id = ?")
           ->execute([$novoEstoque, round($novoCusto, 4), $insumo_id]);

        // Movimentação
        $db->prepare("INSERT INTO totem_movimentacoes_estoque (insumo_id,tipo,quantidade,custo_unitario,motivo,usuario_id) VALUES (?,?,?,?,?,?)")
           ->execute([$insumo_id, 'entrada', $quantidade, $custo_unitario,
                      'Entrada por lote' . ($numero_lote ? " #{$numero_lote}" : ''),
                      $_SESSION['admin_id'] ?? null]);

        $db->commit();
        auditLog($db, 'criar', 'lotes_insumo', (int)$loteId, "Lote criado para insumo #{$insumo_id}: {$quantidade} un.");
        jsonOk(['id' => (int)$loteId], 201);
    }

    if ($method === 'PUT') {
        $body = jsonBody();
        $id   = (int)($body['id'] ?? 0);
        assert400($id > 0, 'ID inválido.');

        $stmt = $db->prepare("
            UPDATE totem_lotes_insumo
               SET numero_lote    = COALESCE(?, numero_lote),
                   fornecedor     = COALESCE(?, fornecedor),
                   data_validade  = COALESCE(?, data_validade),
                   observacoes    = COALESCE(?, observacoes)
             WHERE id = ?
        ");
        $stmt->execute([
            isset($body['numero_lote'])  ? trim($body['numero_lote'])  : null,
            isset($body['fornecedor'])   ? trim($body['fornecedor'])   : null,
            isset($body['data_validade'])? ($body['data_validade']?:null): null,
            isset($body['observacoes'])  ? trim($body['observacoes'])  : null,
            $id,
        ]);
        jsonOk(['id' => $id]);
    }

    if ($method === 'DELETE') {
        requireRole('admin');
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        assert400($id > 0, 'ID inválido.');
        $db->prepare("UPDATE totem_lotes_insumo SET ativo = false WHERE id = ?")->execute([$id]);
        auditLog($db, 'excluir', 'lotes_insumo', $id, 'Lote desativado');
        jsonOk(['id' => $id]);
    }

    jsonErr('Método não permitido', 405);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[lotes.php] ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[lotes.php] ' . $e->getMessage());
    jsonErr('Erro interno', 500);
}

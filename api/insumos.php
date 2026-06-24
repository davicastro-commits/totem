<?php
/**
 * API de Insumos — CRUD
 * GET  (sem id) → lista todos os insumos com alertas de estoque
 * GET  ?id=X    → detalhe do insumo + últimas 20 movimentações
 * POST           → criar insumo (requer admin)
 * PUT            → atualizar insumo (requer admin)
 * DELETE ?id=X  → soft-delete, ativo=false (requer admin)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../admin/api/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/estoque_inteligente.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // ── GET ──────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($id) {
            // Detalhe do insumo + histórico de movimentações
            $stmt = $db->prepare("
                SELECT i.*,
                       CASE WHEN i.estoque_minimo > 0
                            THEN (i.estoque_atual / i.estoque_minimo * 100)
                            ELSE 100 END AS percentual_estoque,
                       (i.estoque_atual <= i.estoque_minimo AND i.estoque_minimo > 0) AS abaixo_minimo
                  FROM totem_insumos i
                 WHERE i.id = ?
            ");
            $stmt->execute([$id]);
            $insumo = $stmt->fetch();
            if (!$insumo) jsonErr('Insumo não encontrado', 404);

            // Últimas 20 movimentações
            $mStmt = $db->prepare("
                SELECT m.*, a.nome AS usuario_nome
                  FROM totem_movimentacoes_estoque m
                  LEFT JOIN totem_admin a ON a.id = m.usuario_id
                 WHERE m.insumo_id = ?
                 ORDER BY m.criado_em DESC
                 LIMIT 20
            ");
            $mStmt->execute([$id]);
            $insumo['movimentacoes'] = $mStmt->fetchAll();
            $insumo['nivel_alerta']  = nivelAlerta($insumo);
            $insumo['dias_cobertura'] = diasCobertura($insumo);

            jsonOk($insumo);
        }

        // Lista geral
        $stmt = $db->query("
            SELECT i.*,
                   CASE WHEN i.estoque_minimo > 0
                        THEN ROUND((i.estoque_atual / i.estoque_minimo * 100)::numeric, 1)
                        ELSE 100 END AS percentual_estoque,
                   (i.estoque_atual <= i.estoque_minimo AND i.estoque_minimo > 0) AS abaixo_minimo
              FROM totem_insumos i
             WHERE i.ativo = true
             ORDER BY i.nome ASC
        ");
        $lista = $stmt->fetchAll();
        foreach ($lista as &$row) {
            $row['nivel_alerta']  = nivelAlerta($row);
            $row['dias_cobertura'] = diasCobertura($row);
        }
        unset($row);
        jsonOk($lista);
    }

    // ── Operações de escrita — exige admin ───────────────────────────────
    requireAdmin();

    // ── POST — criar ─────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body = jsonBody();

        $nome              = trim($body['nome']             ?? '');
        $unidade           = trim($body['unidade']          ?? 'UN');
        $custo_medio       = (float)($body['custo_medio']   ?? 0);
        $estoque_atual     = (float)($body['estoque_atual'] ?? 0);
        $estoque_minimo    = (float)($body['estoque_minimo'] ?? 0);
        $codigo            = trim($body['codigo']            ?? '');
        $fornecedor        = trim($body['fornecedor']        ?? '');
        $categoria_insumo  = trim($body['categoria_insumo'] ?? 'alimento');
        $armazenamento     = trim($body['armazenamento']     ?? 'ambiente');
        $validade_dias     = isset($body['validade_dias']) && $body['validade_dias'] !== '' ? (int)$body['validade_dias'] : null;
        $estoque_maximo    = isset($body['estoque_maximo'])  && $body['estoque_maximo'] !== '' ? (float)$body['estoque_maximo'] : null;
        $alergenos         = trim($body['alergenos']         ?? '');
        $observacoes       = trim($body['observacoes']       ?? '');

        assert400($nome !== '', 'O campo nome é obrigatório.');
        assert400(in_array($unidade, ['UN','KG','L','G','ML']), 'Unidade inválida. Use: UN, KG, L, G, ML.');
        assert400(in_array($armazenamento, ['ambiente','refrigerado','congelado','seco']), 'Condição de armazenamento inválida.');

        $stmt = $db->prepare("
            INSERT INTO totem_insumos
                (nome, unidade, custo_medio, estoque_atual, estoque_minimo,
                 codigo, fornecedor, categoria_insumo, armazenamento,
                 validade_dias, estoque_maximo, alergenos, observacoes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$nome, $unidade, $custo_medio, $estoque_atual, $estoque_minimo,
                        $codigo, $fornecedor, $categoria_insumo, $armazenamento,
                        $validade_dias, $estoque_maximo, $alergenos, $observacoes]);
        $newId = $stmt->fetchColumn();

        // Registrar entrada inicial se houver estoque
        if ($estoque_atual > 0) {
            $mStmt = $db->prepare("
                INSERT INTO totem_movimentacoes_estoque
                    (insumo_id, tipo, quantidade, custo_unitario, motivo, usuario_id)
                VALUES (?, 'entrada', ?, ?, 'Estoque inicial', ?)
            ");
            $mStmt->execute([$newId, $estoque_atual, $custo_medio, $_SESSION['admin_id'] ?? null]);
        }

        auditLog($db, 'criar', 'estoque_insumos', (int)$newId, "Insumo criado: {$nome}");
        jsonOk(['id' => (int)$newId], 201);
    }

    // ── PUT — atualizar ───────────────────────────────────────────────────
    if ($method === 'PUT') {
        $body = jsonBody();

        $id                = (int)($body['id'] ?? 0);
        $nome              = trim($body['nome']             ?? '');
        $unidade           = trim($body['unidade']          ?? 'UN');
        $custo_medio       = (float)($body['custo_medio']   ?? 0);
        $estoque_minimo    = (float)($body['estoque_minimo'] ?? 0);
        $codigo            = trim($body['codigo']            ?? '');
        $fornecedor        = trim($body['fornecedor']        ?? '');
        $categoria_insumo  = trim($body['categoria_insumo'] ?? 'alimento');
        $armazenamento     = trim($body['armazenamento']     ?? 'ambiente');
        $validade_dias     = isset($body['validade_dias'])    && $body['validade_dias']    !== '' ? (int)$body['validade_dias']    : null;
        $estoque_maximo    = isset($body['estoque_maximo'])   && $body['estoque_maximo']   !== '' ? (float)$body['estoque_maximo']  : null;
        $alergenos         = trim($body['alergenos']          ?? '');
        $observacoes       = trim($body['observacoes']        ?? '');
        $lead_time_days    = isset($body['lead_time_days'])   && $body['lead_time_days']   !== '' ? max(1,(int)$body['lead_time_days'])   : null;
        $custo_por_pedido  = isset($body['custo_por_pedido']) && $body['custo_por_pedido'] !== '' ? max(0,(float)$body['custo_por_pedido']) : null;
        $dias_estoque_alvo = isset($body['dias_estoque_alvo'])&& $body['dias_estoque_alvo']!== '' ? max(1,(int)$body['dias_estoque_alvo']) : null;

        assert400($id > 0, 'ID inválido.');
        assert400($nome !== '', 'O campo nome é obrigatório.');
        assert400(in_array($unidade, ['UN','KG','L','G','ML']), 'Unidade inválida.');
        assert400(in_array($armazenamento, ['ambiente','refrigerado','congelado','seco']), 'Condição de armazenamento inválida.');

        $before = $db->prepare("SELECT * FROM totem_insumos WHERE id = ?");
        $before->execute([$id]);
        $antes = $before->fetch();
        if (!$antes) jsonErr('Insumo não encontrado', 404);

        $stmt = $db->prepare("
            UPDATE totem_insumos
               SET nome              = ?,
                   unidade           = ?,
                   custo_medio       = ?,
                   estoque_minimo    = ?,
                   codigo            = ?,
                   fornecedor        = ?,
                   categoria_insumo  = ?,
                   armazenamento     = ?,
                   validade_dias     = ?,
                   estoque_maximo    = ?,
                   alergenos         = ?,
                   observacoes       = ?,
                   lead_time_days    = COALESCE(?, lead_time_days),
                   custo_por_pedido  = COALESCE(?, custo_por_pedido),
                   dias_estoque_alvo = COALESCE(?, dias_estoque_alvo),
                   atualizado_em     = NOW()
             WHERE id = ?
        ");
        $stmt->execute([
            $nome, $unidade, $custo_medio, $estoque_minimo,
            $codigo, $fornecedor, $categoria_insumo, $armazenamento,
            $validade_dias, $estoque_maximo, $alergenos, $observacoes,
            $lead_time_days, $custo_por_pedido, $dias_estoque_alvo,
            $id,
        ]);

        $novos = compact('nome','unidade','custo_medio','estoque_minimo','codigo','fornecedor',
                         'categoria_insumo','armazenamento','validade_dias','estoque_maximo');
        auditLog($db, 'editar', 'estoque_insumos', $id, "Insumo editado: {$nome}", $antes, $novos);

        // Alerta de custo de prato se custo_medio mudou
        $alertas_pratos = [];
        if (abs((float)$antes['custo_medio'] - $custo_medio) > 0.0001) {
            $stmt = $db->prepare("
                SELECT p.id, p.nome AS prato_nome, p.preco,
                       SUM(ft.quantidade * ?) AS custo_insumo_novo,
                       SUM(ft.quantidade * ?) AS custo_insumo_ant
                  FROM totem_ficha_tecnica ft
                  JOIN totem_produtos p ON p.id = ft.produto_id
                 WHERE ft.insumo_id = ?
                 GROUP BY p.id, p.nome, p.preco
            ");
            $stmt->execute([$custo_medio, (float)$antes['custo_medio'], $id]);
            foreach ($stmt->fetchAll() as $prato) {
                $delta = (float)$prato['custo_insumo_novo'] - (float)$prato['custo_insumo_ant'];
                $alertas_pratos[] = [
                    'produto_id'   => (int)$prato['id'],
                    'prato_nome'   => $prato['prato_nome'],
                    'preco_venda'  => (float)$prato['preco'],
                    'variacao_custo' => round($delta, 4),
                ];
            }
        }

        jsonOk(['id' => $id, 'alertas_pratos' => $alertas_pratos]);
    }

    // ── DELETE — soft delete ──────────────────────────────────────────────
    if ($method === 'DELETE') {
        requireRole('admin');
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        assert400($id > 0, 'ID inválido.');

        $stmt = $db->prepare("UPDATE totem_insumos SET ativo = false, atualizado_em = NOW() WHERE id = ?");
        $stmt->execute([$id]);

        auditLog($db, 'excluir', 'estoque_insumos', $id, "Insumo desativado (soft-delete)");
        jsonOk(['id' => $id]);
    }

    jsonErr('Método não permitido', 405);

} catch (PDOException $e) {
    error_log('[insumos.php] PDOException: ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    error_log('[insumos.php] Error: ' . $e->getMessage());
    jsonErr('Erro interno do servidor', 500);
}

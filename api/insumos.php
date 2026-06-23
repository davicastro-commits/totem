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
        jsonOk($stmt->fetchAll());
    }

    // ── Operações de escrita — exige admin ───────────────────────────────
    requireAdmin();

    // ── POST — criar ─────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body = jsonBody();

        $nome            = trim($body['nome'] ?? '');
        $unidade         = trim($body['unidade'] ?? 'UN');
        $custo_medio     = (float)($body['custo_medio'] ?? 0);
        $estoque_atual   = (float)($body['estoque_atual'] ?? 0);
        $estoque_minimo  = (float)($body['estoque_minimo'] ?? 0);

        assert400($nome !== '', 'O campo nome é obrigatório.');
        assert400(in_array($unidade, ['UN','KG','L','G','ML']), 'Unidade inválida. Use: UN, KG, L, G, ML.');

        $stmt = $db->prepare("
            INSERT INTO totem_insumos (nome, unidade, custo_medio, estoque_atual, estoque_minimo)
            VALUES (?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$nome, $unidade, $custo_medio, $estoque_atual, $estoque_minimo]);
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

        $id             = (int)($body['id'] ?? 0);
        $nome           = trim($body['nome'] ?? '');
        $unidade        = trim($body['unidade'] ?? 'UN');
        $custo_medio    = (float)($body['custo_medio'] ?? 0);
        $estoque_minimo = (float)($body['estoque_minimo'] ?? 0);

        assert400($id > 0, 'ID inválido.');
        assert400($nome !== '', 'O campo nome é obrigatório.');
        assert400(in_array($unidade, ['UN','KG','L','G','ML']), 'Unidade inválida.');

        // Buscar dados anteriores para auditoria
        $before = $db->prepare("SELECT * FROM totem_insumos WHERE id = ?");
        $before->execute([$id]);
        $antes = $before->fetch();
        if (!$antes) jsonErr('Insumo não encontrado', 404);

        $stmt = $db->prepare("
            UPDATE totem_insumos
               SET nome = ?, unidade = ?, custo_medio = ?, estoque_minimo = ?, atualizado_em = NOW()
             WHERE id = ?
        ");
        $stmt->execute([$nome, $unidade, $custo_medio, $estoque_minimo, $id]);

        auditLog($db, 'editar', 'estoque_insumos', $id, "Insumo editado: {$nome}", $antes, compact('nome','unidade','custo_medio','estoque_minimo'));
        jsonOk(['id' => $id]);
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

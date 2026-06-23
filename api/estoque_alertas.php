<?php
/**
 * API pública de alertas de estoque.
 * GET → { success: true, data: { alertas: [...] } }
 * Retorna insumos com estoque_atual <= estoque_minimo.
 * Usado pelo dashboard e pelo totem.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

try {
    $db = getDB();

    $stmt = $db->query("
        SELECT id          AS insumo_id,
               nome,
               unidade,
               estoque_atual,
               estoque_minimo,
               CASE WHEN estoque_minimo > 0
                    THEN ROUND((estoque_atual / estoque_minimo * 100)::numeric, 1)
                    ELSE 0 END AS percentual_estoque
          FROM totem_insumos
         WHERE ativo = true
           AND estoque_minimo > 0
           AND estoque_atual <= estoque_minimo
         ORDER BY (estoque_atual / NULLIF(estoque_minimo, 0)) ASC, nome ASC
    ");

    $alertas = $stmt->fetchAll();

    jsonOk(['alertas' => $alertas, 'total' => count($alertas)]);

} catch (PDOException $e) {
    error_log('[estoque_alertas.php] PDOException: ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    error_log('[estoque_alertas.php] Error: ' . $e->getMessage());
    jsonErr('Erro interno do servidor', 500);
}

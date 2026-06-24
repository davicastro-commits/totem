<?php
/**
 * Endpoint: Recalcular Indicadores de Estoque Inteligente
 * POST /api/recalcular_indicadores.php
 *
 * Grava snapshot do dia, recalcula ROP/EOQ/SS e roda ABC.
 * Requer autenticação de admin.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/estoque_inteligente.php';
require_once __DIR__ . '/../admin/api/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonErr('Método não permitido', 405);
}

requireAdmin();

try {
    $db = getDB();

    // 1. Snapshot diário
    $snapshots = snapshotEstoqueDiario($db);

    // 2. Calcular indicadores por insumo
    $stmt   = $db->query("SELECT * FROM totem_insumos WHERE ativo = true ORDER BY nome ASC");
    $insumos = $stmt->fetchAll();

    $resultados = [];
    $erros      = [];

    foreach ($insumos as $insumo) {
        try {
            $r = calcularIndicadores($db, $insumo);
            $resultados[] = [
                'id'           => (int)$insumo['id'],
                'nome'         => $insumo['nome'],
                'safety_stock' => $r['safety_stock'],
                'rop'          => $r['rop'],
                'eoq'          => $r['eoq'],
            ];
        } catch (Throwable $e) {
            $erros[] = $insumo['nome'] . ': ' . $e->getMessage();
            error_log('[recalcular_indicadores] Erro em ' . $insumo['nome'] . ': ' . $e->getMessage());
        }
    }

    // 3. Classificação ABC
    classificarABC($db);

    jsonOk([
        'snapshots_gravados'    => $snapshots,
        'insumos_processados'   => count($resultados),
        'erros'                 => $erros,
        'resultados'            => $resultados,
    ]);

} catch (PDOException $e) {
    error_log('[recalcular_indicadores] PDOException: ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    error_log('[recalcular_indicadores] Error: ' . $e->getMessage());
    jsonErr('Erro interno do servidor', 500);
}

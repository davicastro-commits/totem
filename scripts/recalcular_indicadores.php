<?php
/**
 * CLI: Recalcular indicadores de estoque inteligente
 *
 * Uso:
 *   php scripts/recalcular_indicadores.php
 *   php scripts/recalcular_indicadores.php --apenas-snapshot
 *   php scripts/recalcular_indicadores.php --apenas-abc
 *
 * Ideal para rodar via cron diariamente, após o fechamento do expediente.
 * Exemplo de cron (23:55 todo dia):
 *   55 23 * * * php /caminho/para/totem/scripts/recalcular_indicadores.php >> /tmp/estoque.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acesso negado — este script é apenas para uso via CLI.\n");
}

define('CLI_RUN', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/estoque_inteligente.php';

$args          = array_slice($argv ?? [], 1);
$apenasSnap    = in_array('--apenas-snapshot', $args);
$apenasAbc     = in_array('--apenas-abc',      $args);
$semSnapshot   = in_array('--sem-snapshot',     $args);

$inicio = microtime(true);
echo "=== Estoque Inteligente — Recálculo de Indicadores ===\n";
echo date('Y-m-d H:i:s') . "\n\n";

try {
    $db = getDB();

    // ── 1. Snapshot diário ──────────────────────────────────────────────
    if (!$apenasAbc && !$semSnapshot) {
        $n = snapshotEstoqueDiario($db);
        echo "Snapshots gravados hoje: {$n}\n\n";
    }

    if ($apenasSnap) {
        echo "Concluído (apenas snapshot).\n";
        exit(0);
    }

    // ── 2. Calcular indicadores por insumo ──────────────────────────────
    if (!$apenasAbc) {
        $stmt    = $db->query("SELECT * FROM totem_insumos WHERE ativo = true ORDER BY nome ASC");
        $insumos = $stmt->fetchAll();
        $total   = count($insumos);

        echo "Calculando indicadores para {$total} insumos...\n";

        $erros = 0;
        foreach ($insumos as $idx => $insumo) {
            $num = $idx + 1;
            try {
                $r = calcularIndicadores($db, $insumo);
                printf(
                    "[%d/%d] %-30s  SS=%-8.3f  ROP=%-8.3f  EOQ=%-8.3f\n",
                    $num, $total,
                    mb_substr($insumo['nome'], 0, 30),
                    $r['safety_stock'], $r['rop'], $r['eoq']
                );
            } catch (Throwable $e) {
                printf("[%d/%d] ERRO — %s: %s\n", $num, $total, $insumo['nome'], $e->getMessage());
                $erros++;
            }
        }

        echo "\nInsumos processados : {$total}\n";
        if ($erros > 0) {
            echo "Erros               : {$erros}\n";
        }
    }

    // ── 3. Classificação ABC ─────────────────────────────────────────────
    echo "\nClassificando ABC...\n";
    classificarABC($db);
    echo "Classificação ABC atualizada.\n";

    $elapsed = round(microtime(true) - $inicio, 2);
    echo "\nTempo total: {$elapsed}s\n";
    echo "Concluído.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "ERRO FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

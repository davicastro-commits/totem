<?php
/**
 * API de Análise de Desperdício
 * Compara consumo teórico (fichas × vendas) vs consumo real (movimentações)
 *
 * GET ?data_ini=YYYY-MM-DD&data_fim=YYYY-MM-DD
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../admin/api/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonErr('Método não permitido', 405);

requireAdmin();

$data_ini = $_GET['data_ini'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');

try {
    $db = getDB();

    // ── 1. Consumo TEÓRICO por insumo no período ─────────────────────────────
    // Fichas técnicas × quantidade vendida de cada produto no período
    $teorico = $db->prepare("
        SELECT
            ft.insumo_id,
            i.nome          AS insumo_nome,
            i.unidade,
            CAST(i.custo_medio AS FLOAT) AS custo_medio,
            SUM(ft.quantidade * ip.quantidade) AS consumo_teorico
          FROM totem_itens_pedido ip
          JOIN totem_pedidos p       ON p.id = ip.pedido_id
          JOIN totem_ficha_tecnica ft ON ft.produto_id = ip.produto_id
          JOIN totem_insumos i       ON i.id = ft.insumo_id
         WHERE p.criado_em::date BETWEEN ? AND ?
           AND p.status NOT IN ('cancelado')
         GROUP BY ft.insumo_id, i.nome, i.unidade, i.custo_medio
         ORDER BY i.nome
    ");
    $teorico->execute([$data_ini, $data_fim]);
    $rowsTeorico = $teorico->fetchAll();

    // ── 2. Consumo REAL por insumo (saídas no período) ───────────────────────
    $real = $db->prepare("
        SELECT
            insumo_id,
            SUM(ABS(CAST(quantidade AS FLOAT))) AS consumo_real
          FROM totem_movimentacoes_estoque
         WHERE tipo = 'saida'
           AND criado_em::date BETWEEN ? AND ?
         GROUP BY insumo_id
    ");
    $real->execute([$data_ini, $data_fim]);
    $mapReal = [];
    foreach ($real->fetchAll() as $r) {
        $mapReal[$r['insumo_id']] = (float)$r['consumo_real'];
    }

    // ── 3. Cruzar os dados ───────────────────────────────────────────────────
    $resultado    = [];
    $totalDesperd = 0;
    $totalTeorico = 0;
    $totalReal    = 0;

    foreach ($rowsTeorico as $row) {
        $iid     = (int)$row['insumo_id'];
        $teorico = round((float)$row['consumo_teorico'], 4);
        $realVal = round($mapReal[$iid] ?? 0.0, 4);
        $desp    = round(max(0, $realVal - $teorico), 4);
        $pct     = $teorico > 0 ? round(($desp / $teorico) * 100, 1) : 0;
        $custo   = $desp * (float)$row['custo_medio'];

        $totalDesperd += $custo;
        $totalTeorico += $teorico;
        $totalReal    += $realVal;

        $resultado[] = [
            'insumo_id'        => $iid,
            'insumo_nome'      => $row['insumo_nome'],
            'unidade'          => $row['unidade'],
            'custo_medio'      => round((float)$row['custo_medio'], 4),
            'consumo_teorico'  => $teorico,
            'consumo_real'     => $realVal,
            'desperdicio'      => $desp,
            'percentual'       => $pct,
            'custo_desperdicio'=> round($custo, 2),
            'nivel'            => $pct <= 5 ? 'ok' : ($pct <= 15 ? 'atencao' : 'critico'),
        ];
    }

    // Ordenar por custo de desperdício (maior primeiro)
    usort($resultado, fn($a, $b) => $b['custo_desperdicio'] <=> $a['custo_desperdicio']);

    jsonOk([
        'periodo'            => ['ini' => $data_ini, 'fim' => $data_fim],
        'insumos'            => $resultado,
        'total_desperdicio'  => round($totalDesperd, 2),
        'total_teorico'      => round($totalTeorico, 4),
        'total_real'         => round($totalReal, 4),
        'percentual_geral'   => $totalTeorico > 0
            ? round((($totalReal - $totalTeorico) / $totalTeorico) * 100, 1)
            : 0,
    ]);

} catch (PDOException $e) {
    error_log('[desperdicio.php] ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    error_log('[desperdicio.php] ' . $e->getMessage());
    jsonErr('Erro interno', 500);
}

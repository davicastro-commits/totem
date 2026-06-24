<?php
declare(strict_types=1);

/**
 * Serviço de Estoque Inteligente
 * Calcula ROP, EOQ, Safety Stock e classifica insumos por ABC.
 */

/**
 * Retorna o nível de alerta hierárquico de um insumo.
 *
 * Hierarquia:
 *   CRITICO  — estoque zerado ou negativo
 *   URGENTE  — abaixo do Safety Stock (risco de ruptura imediata)
 *   ATENCAO  — abaixo do ROP (pedido deve ser feito agora)
 *   FRIO     — cobertura > 60 dias e mais de 2× o ROP (excesso)
 *   OK       — dentro dos limites saudáveis
 *
 * @param array $insumo Row completo do banco
 * @return string
 */
function nivelAlerta(array $insumo): string
{
    $atual = (float)($insumo['estoque_atual'] ?? 0);

    if ($atual <= 0) return 'CRITICO';

    $ss      = (float)($insumo['safety_stock']  ?? 0);
    $rop     = (float)($insumo['rop']           ?? 0);
    $minimo  = (float)($insumo['estoque_minimo'] ?? 0);

    // Fallback para estoque_minimo quando ROP ainda não foi calculado
    if ($rop <= 0) $rop = $minimo;

    // O ROP nunca pode ser menor que o estoque_minimo definido manualmente
    // (o usuário sabe melhor que o algoritmo qual é o piso seguro)
    $rop = max($rop, $minimo);

    if ($ss > 0 && $atual <= $ss) return 'URGENTE';
    if ($rop > 0 && $atual <= $rop) return 'ATENCAO';

    // Estoque frio: cobertura > 60 dias com mais de 2× ROP disponível
    $cmdio = (float)($insumo['consumo_medio_diario'] ?? 0);
    if ($cmdio > 0 && $rop > 0) {
        $diasCobertura = $atual / $cmdio;
        if ($diasCobertura > 60 && $atual > $rop * 2) return 'FRIO';
    }

    return 'OK';
}

/**
 * Calcula dias de cobertura do estoque atual.
 */
function diasCobertura(array $insumo): ?float
{
    $cmdio = (float)($insumo['consumo_medio_diario'] ?? 0);
    if ($cmdio <= 0) return null;
    return round((float)($insumo['estoque_atual'] ?? 0) / $cmdio, 1);
}

/**
 * Recalcula safety_stock, rop e eoq de um insumo com base no histórico.
 * Persiste os valores calculados diretamente no banco.
 *
 * @param PDO   $db
 * @param array $insumo Row completo do insumo (precisa ter id e os campos de parâmetro)
 * @return array{safety_stock:float, rop:float, eoq:float}
 */
function calcularIndicadores(PDO $db, array $insumo): array
{
    $id = (int)$insumo['id'];

    // Histórico dos últimos 30 dias
    $stmt = $db->prepare("
        SELECT CAST(consumo_dia AS FLOAT)
          FROM totem_historico_estoque
         WHERE insumo_id = ?
         ORDER BY data DESC
         LIMIT 30
    ");
    $stmt->execute([$id]);
    $historico = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $historico  = array_map('floatval', $historico);

    if (count($historico) >= 3) {
        $consumoMedio = array_sum($historico) / count($historico);
        $consumoMax   = max($historico);
        $n    = count($historico);
        $mean = $consumoMedio;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $historico)) / ($n - 1);
        $desvio = sqrt(max(0.0, $variance));
    } else {
        // Fallback: usar consumo_medio_diario cadastrado ou estimativa via estoque_minimo
        $consumoMedio = (float)($insumo['consumo_medio_diario'] ?? 0);
        if ($consumoMedio <= 0) {
            $consumoMedio = (float)($insumo['estoque_minimo'] ?? 0) / 7;
        }
        $consumoMax = $consumoMedio * 1.5;
        $desvio     = $consumoMedio * 0.2;
    }

    $leadTime = max(1, (int)($insumo['lead_time_days'] ?? 2));
    $z        = 1.65; // 95% de nível de serviço

    $safetyStock = $z * $desvio * sqrt($leadTime);
    $rop         = $consumoMedio * $leadTime + $safetyStock;

    // EOQ (Economic Order Quantity)
    $demandaAnual    = $consumoMedio * 365;
    $custoPedido     = max(0.01, (float)($insumo['custo_por_pedido'] ?? 25.0));
    $custoMedio      = max(0.01, (float)($insumo['custo_medio']      ?? 1.0));
    $custoManutencao = $custoMedio * 0.20;  // 20% do custo unitário ao ano

    if ($custoManutencao > 0 && $demandaAnual > 0) {
        $eoq = sqrt((2 * $demandaAnual * $custoPedido) / $custoManutencao);
    } else {
        $eoq = (float)($insumo['estoque_minimo'] ?? 0) * 3;
    }

    // Persistir no banco
    $upd = $db->prepare("
        UPDATE totem_insumos
           SET consumo_medio_diario  = ?,
               consumo_max_diario    = ?,
               desvio_padrao_demanda = ?,
               safety_stock          = ?,
               rop                   = ?,
               eoq                   = ?,
               atualizado_em         = NOW()
         WHERE id = ?
    ");
    $upd->execute([
        round($consumoMedio, 4),
        round($consumoMax,   4),
        round($desvio,       4),
        round($safetyStock,  4),
        round($rop,          4),
        round($eoq,          4),
        $id,
    ]);

    return [
        'safety_stock' => round($safetyStock, 2),
        'rop'          => round($rop, 2),
        'eoq'          => round($eoq, 2),
    ];
}

/**
 * Classifica todos os insumos ativos em A, B ou C pelo valor em estoque.
 *   A → top 80% do valor total
 *   B → 80–95%
 *   C → 95–100%
 *
 * @param PDO $db
 */
function classificarABC(PDO $db): void
{
    $stmt = $db->query("
        SELECT id,
               CAST(estoque_atual AS FLOAT) AS estoque_atual,
               CAST(custo_medio   AS FLOAT) AS custo_medio
          FROM totem_insumos
         WHERE ativo = true
    ");
    $insumos = $stmt->fetchAll();

    // Ordenar por valor decrescente
    usort($insumos, fn($a, $b) =>
        ($b['estoque_atual'] * $b['custo_medio']) <=> ($a['estoque_atual'] * $a['custo_medio'])
    );

    $total = array_sum(array_map(fn($i) => $i['estoque_atual'] * $i['custo_medio'], $insumos));
    if ($total <= 0) return;

    $upd       = $db->prepare("UPDATE totem_insumos SET classe_abc = ? WHERE id = ?");
    $acumulado = 0.0;

    foreach ($insumos as $i) {
        $acumulado += $i['estoque_atual'] * $i['custo_medio'];
        $pct    = $acumulado / $total;
        $classe = $pct <= 0.80 ? 'A' : ($pct <= 0.95 ? 'B' : 'C');
        $upd->execute([$classe, (int)$i['id']]);
    }
}

/**
 * Grava snapshot diário do estoque de todos os insumos ativos.
 * Calcula automaticamente o consumo do dia com base nas saídas registradas.
 * Seguro para rodar múltiplas vezes no mesmo dia (ON CONFLICT DO NOTHING).
 *
 * @param PDO $db
 * @return int Número de snapshots novos gravados
 */
function snapshotEstoqueDiario(PDO $db): int
{
    $stmt = $db->query("SELECT id, CAST(estoque_atual AS FLOAT) AS estoque_atual FROM totem_insumos WHERE ativo = true");
    $insumos = $stmt->fetchAll();
    $today   = date('Y-m-d');
    $n       = 0;

    $ins = $db->prepare("
        INSERT INTO totem_historico_estoque (insumo_id, data, estoque_snapshot, consumo_dia)
        SELECT ?, ?, ?,
               COALESCE((
                   SELECT SUM(ABS(CAST(quantidade AS FLOAT)))
                     FROM totem_movimentacoes_estoque
                    WHERE insumo_id = ?
                      AND tipo = 'saida'
                      AND criado_em::date = ?
               ), 0)
        ON CONFLICT (insumo_id, data) DO NOTHING
    ");

    foreach ($insumos as $i) {
        $ins->execute([$i['id'], $today, $i['estoque_atual'], $i['id'], $today]);
        $n += $ins->rowCount();
    }

    return $n;
}

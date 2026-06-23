<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once 'auth.php';
requireAdmin();

try {
    $db = getDB();

    // ── Faturamento hoje e ontem ───────────────────────────────────────
    $row = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATE(criado_em)=CURRENT_DATE AND status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS fat_hoje,
            COUNT(CASE WHEN DATE(criado_em)=CURRENT_DATE AND status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END)               AS ped_hoje,
            COALESCE(SUM(CASE WHEN DATE(criado_em)=CURRENT_DATE-1 AND status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS fat_ontem,
            COUNT(CASE WHEN DATE(criado_em)=CURRENT_DATE-1 AND status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END)               AS ped_ontem
        FROM material.totem_pedidos
        WHERE criado_em >= CURRENT_DATE - 1
    ")->fetch();

    // ── Margem bruta hoje ─────────────────────────────────────────────
    $custo = (float)$db->query("
        SELECT COALESCE(SUM(ip.quantidade * ft.quantidade * ins.custo_medio), 0)
        FROM material.totem_itens_pedido ip
        JOIN material.totem_pedidos p     ON p.id = ip.pedido_id
        JOIN material.totem_ficha_tecnica ft ON ft.produto_id = ip.produto_id
        JOIN material.totem_insumos ins   ON ins.id = ft.insumo_id
        WHERE DATE(p.criado_em) = CURRENT_DATE
          AND p.status NOT IN ('cancelado','aguardando_pagamento')
    ")->fetchColumn();

    // ── Projeção do mês ────────────────────────────────────────────────
    $mes = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS fat_mes,
            EXTRACT(DAY FROM CURRENT_DATE)::int AS dias_passados,
            EXTRACT(DAY FROM (DATE_TRUNC('month',CURRENT_DATE)+INTERVAL '1 month'-INTERVAL '1 day'))::int AS dias_mes
        FROM material.totem_pedidos
        WHERE DATE_TRUNC('month',criado_em) = DATE_TRUNC('month',CURRENT_DATE)
    ")->fetch();

    $fatMesAnt = (float)$db->query("
        SELECT COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0)
        FROM material.totem_pedidos
        WHERE DATE_TRUNC('month',criado_em) = DATE_TRUNC('month',CURRENT_DATE) - INTERVAL '1 month'
    ")->fetchColumn();

    // ── Heatmap últimos 30 dias ────────────────────────────────────────
    $heatmap = $db->query("
        SELECT EXTRACT(DOW FROM criado_em)::int AS dow,
               EXTRACT(HOUR FROM criado_em)::int AS hora,
               COUNT(*) AS cnt
        FROM material.totem_pedidos
        WHERE criado_em >= CURRENT_DATE - 29
          AND status NOT IN ('cancelado','aguardando_pagamento')
        GROUP BY dow, hora
        ORDER BY dow, hora
    ")->fetchAll();

    // ── Previsão de estoque acabar ─────────────────────────────────────
    $previsao = $db->query("
        SELECT i.nome, i.estoque_atual, i.unidade,
            COALESCE(
                SUM(m.quantidade) FILTER (WHERE m.tipo='saida' AND m.criado_em >= CURRENT_DATE - 7) / 7.0,
                0
            ) AS consumo_dia
        FROM material.totem_insumos i
        LEFT JOIN material.totem_movimentacoes_estoque m ON m.insumo_id = i.id
        WHERE i.ativo = true AND i.estoque_atual > 0
        GROUP BY i.id, i.nome, i.estoque_atual, i.unidade
        HAVING COALESCE(
            SUM(m.quantidade) FILTER (WHERE m.tipo='saida' AND m.criado_em >= CURRENT_DATE - 7) / 7.0, 0
        ) > 0
        ORDER BY (i.estoque_atual / NULLIF(
            COALESCE(SUM(m.quantidade) FILTER (WHERE m.tipo='saida' AND m.criado_em >= CURRENT_DATE - 7)/7.0,0),0
        )) ASC
        LIMIT 8
    ")->fetchAll();

    echo json_encode([
        'success'      => true,
        'fat_hoje'     => (float)$row['fat_hoje'],
        'ped_hoje'     => (int)$row['ped_hoje'],
        'fat_ontem'    => (float)$row['fat_ontem'],
        'ped_ontem'    => (int)$row['ped_ontem'],
        'custo_hoje'   => $custo,
        'fat_mes'      => (float)$mes['fat_mes'],
        'dias_passados'=> (int)$mes['dias_passados'],
        'dias_mes'     => (int)$mes['dias_mes'],
        'fat_mes_ant'  => $fatMesAnt,
        'heatmap'      => $heatmap,
        'previsao'     => $previsao,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

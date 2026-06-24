<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once 'auth.php';
requireAdmin();

try {
    $db = getDB();
    $hoje    = date('Y-m-d');
    $ontem   = date('Y-m-d', strtotime('-1 day'));
    $ini7    = date('Y-m-d', strtotime('-6 days'));
    $ini30   = date('Y-m-d', strtotime('-29 days'));

    // ── Resumo do dia ─────────────────────────────────────────────────
    $stmtHoje = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status!='cancelado' THEN total END),0)  AS faturamento,
            COUNT(CASE WHEN status!='cancelado' THEN 1 END)                AS pedidos,
            COALESCE(AVG(CASE WHEN status!='cancelado' THEN total END),0)  AS ticket_medio,
            COUNT(CASE WHEN status IN ('aguardando','preparando') THEN 1 END) AS em_aberto,
            COUNT(CASE WHEN status='cancelado' THEN 1 END)                 AS cancelados
        FROM totem_pedidos WHERE DATE(criado_em) = ?
    ");
    $stmtHoje->execute([$hoje]);
    $resumoHoje = $stmtHoje->fetch();

    $stmtOntem = $db->prepare("
        SELECT COALESCE(SUM(CASE WHEN status!='cancelado' THEN total END),0) AS faturamento,
               COUNT(CASE WHEN status!='cancelado' THEN 1 END) AS pedidos,
               COALESCE(AVG(CASE WHEN status!='cancelado' THEN total END),0) AS ticket_medio
        FROM totem_pedidos WHERE DATE(criado_em) = ?
    ");
    $stmtOntem->execute([$ontem]);
    $resumoOntem = $stmtOntem->fetch();

    // Hora de pico hoje (bloco de 2h com mais pedidos)
    $stmtHora = $db->prepare("
        SELECT EXTRACT(HOUR FROM criado_em)::int AS hora, COUNT(*) AS qtd
        FROM totem_pedidos WHERE DATE(criado_em) = ? AND status!='cancelado'
        GROUP BY hora ORDER BY qtd DESC LIMIT 1
    ");
    $stmtHora->execute([$hoje]);
    $horaPico = $stmtHora->fetch();

    // ── Meta do ticket médio ──────────────────────────────────────────
    $metaTicket = (float)($db->query("SELECT COALESCE(valor,'0') FROM totem_configuracoes WHERE chave='meta_ticket_medio'")->fetchColumn() ?: 0);

    // ── Combos inteligentes (cross-sell últimos 30 dias) ──────────────
    $stmtCs = $db->prepare("
        SELECT a.nome_produto AS prod_a, b.nome_produto AS prod_b,
               COUNT(*) AS ocorrencias,
               ROUND(AVG(a.preco_unitario + b.preco_unitario)::numeric, 2) AS preco_conjunto,
               ROUND(AVG(a.preco_unitario + b.preco_unitario) * 0.9::numeric, 2) AS preco_combo
        FROM totem_itens_pedido a
        JOIN totem_itens_pedido b ON a.pedido_id=b.pedido_id AND a.produto_id<b.produto_id
        JOIN totem_pedidos p ON p.id=a.pedido_id
        WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
        GROUP BY a.nome_produto, b.nome_produto
        HAVING COUNT(*)>=2
        ORDER BY ocorrencias DESC LIMIT 5
    ");
    $stmtCs->execute([$ini30, $hoje]);
    $combos = $stmtCs->fetchAll();

    // Total de pedidos no período (para calcular % co-ocorrência)
    $totalPedidos30 = (int)$db->query("
        SELECT COUNT(*) FROM totem_pedidos
        WHERE DATE(criado_em) BETWEEN '$ini30' AND '$hoje' AND status!='cancelado'
    ")->fetchColumn();

    foreach ($combos as &$c) {
        $c['pct'] = $totalPedidos30 > 0 ? round(($c['ocorrencias'] / $totalPedidos30) * 100) : 0;
        $c['ganho'] = round((float)$c['preco_conjunto'] - (float)$c['preco_combo'], 2);
    }
    unset($c);

    // ── Análise de horários (força por hora, últimos 30 dias) ─────────
    $stmtHoraAll = $db->prepare("
        SELECT EXTRACT(HOUR FROM criado_em)::int AS hora, COUNT(*) AS total_pedidos
        FROM totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
        GROUP BY hora ORDER BY hora
    ");
    $stmtHoraAll->execute([$ini30, $hoje]);
    $horas = $stmtHoraAll->fetchAll();

    $mediaHora = count($horas) > 0
        ? array_sum(array_column($horas, 'total_pedidos')) / count($horas)
        : 0;

    $horarios = array_map(function ($h) use ($mediaHora) {
        $qtd = (int)$h['total_pedidos'];
        $ratio = $mediaHora > 0 ? $qtd / $mediaHora : 0;
        return [
            'hora'   => (int)$h['hora'],
            'qtd'    => $qtd,
            'status' => $ratio >= 1.3 ? 'forte' : ($ratio <= 0.6 ? 'fraco' : 'normal'),
        ];
    }, $horas);

    // Agrupar em faixas de 3h para exibir
    $faixas = [];
    for ($h = 6; $h <= 22; $h += 3) {
        $slotQtd = array_sum(array_column(
            array_filter($horarios, fn($x) => $x['hora'] >= $h && $x['hora'] < $h + 3),
            'qtd'
        ));
        $mediaSlot = $mediaHora * 3;
        $ratio = $mediaSlot > 0 ? $slotQtd / $mediaSlot : 0;
        $faixas[] = [
            'label'  => sprintf('%02dh–%02dh', $h, $h + 3),
            'qtd'    => $slotQtd,
            'status' => $ratio >= 1.2 ? 'forte' : ($ratio <= 0.7 ? 'fraco' : 'normal'),
        ];
    }

    // ── Fidelização ───────────────────────────────────────────────────
    $fidelizacao = ['frequentes_semana' => 0, 'retorno_7dias_pct' => 0, 'programa_ativo' => false];
    try {
        // Clientes com 2+ pedidos nos últimos 7 dias
        $freq = (int)$db->query("
            SELECT COUNT(DISTINCT cliente_id)
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN '$ini7' AND '$hoje'
              AND status!='cancelado' AND cliente_id IS NOT NULL
            GROUP BY cliente_id HAVING COUNT(*)>=2
        ")->rowCount();
        // Alternativa: subquery count
        $freq = (int)$db->query("
            SELECT COUNT(*) FROM (
                SELECT cliente_id FROM totem_pedidos
                WHERE DATE(criado_em) BETWEEN '$ini7' AND '$hoje'
                  AND status!='cancelado' AND cliente_id IS NOT NULL
                GROUP BY cliente_id HAVING COUNT(*)>=2
            ) sub
        ")->fetchColumn();

        // % clientes que retornaram em 7 dias (de 8-14 dias atrás)
        $ini14 = date('Y-m-d', strtotime('-14 days'));
        $ini8  = date('Y-m-d', strtotime('-8 days'));
        $clientesAntes = $db->query("
            SELECT DISTINCT cliente_id FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN '$ini14' AND '$ini8'
              AND status!='cancelado' AND cliente_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);
        $retornaram = 0;
        if (count($clientesAntes) > 0) {
            $in = implode(',', array_map('intval', $clientesAntes));
            $retornaram = (int)$db->query("
                SELECT COUNT(DISTINCT cliente_id) FROM totem_pedidos
                WHERE DATE(criado_em) BETWEEN '$ini7' AND '$hoje'
                  AND status!='cancelado' AND cliente_id IN ($in)
            ")->fetchColumn();
        }

        $pontosAtivo = $db->query("SELECT COALESCE(valor,'false') FROM totem_configuracoes WHERE chave='pontos_ativo'")->fetchColumn();

        $fidelizacao = [
            'frequentes_semana'  => $freq,
            'retorno_7dias_pct'  => count($clientesAntes) > 0
                ? round(($retornaram / count($clientesAntes)) * 100) : 0,
            'programa_ativo'     => in_array(strtolower((string)$pontosAtivo), ['true','1','on']),
        ];
    } catch (Throwable) {}

    // ── Produtos parados (sem venda há 7 dias mas venderam antes) ─────
    $stmtParados = $db->prepare("
        SELECT p.nome, p.id,
               COALESCE(r30.qtd, 0) AS qtd_30d,
               COALESCE(r7.qtd, 0)  AS qtd_7d
        FROM totem_produtos p
        LEFT JOIN (
            SELECT ip.produto_id, SUM(ip.quantidade) AS qtd
            FROM totem_itens_pedido ip
            JOIN totem_pedidos ped ON ped.id=ip.pedido_id
            WHERE DATE(ped.criado_em) BETWEEN ? AND ? AND ped.status!='cancelado'
            GROUP BY ip.produto_id
        ) r30 ON r30.produto_id = p.id
        LEFT JOIN (
            SELECT ip.produto_id, SUM(ip.quantidade) AS qtd
            FROM totem_itens_pedido ip
            JOIN totem_pedidos ped ON ped.id=ip.pedido_id
            WHERE DATE(ped.criado_em) BETWEEN ? AND ? AND ped.status!='cancelado'
            GROUP BY ip.produto_id
        ) r7 ON r7.produto_id = p.id
        WHERE p.disponivel = true
          AND COALESCE(r30.qtd, 0) > 0
          AND COALESCE(r7.qtd, 0) = 0
        ORDER BY r30.qtd ASC
        LIMIT 5
    ");
    $stmtParados->execute([$ini30, $hoje, $ini7, $hoje]);
    $produtosParados = $stmtParados->fetchAll();

    // ── Gerar insights automáticos ────────────────────────────────────
    $insights = [];

    // Insight 1: Combo de maior conversão
    if (!empty($combos)) {
        $top = $combos[0];
        $insights[] = [
            'cor'    => 'green',
            'titulo' => "Combo de maior conversão: {$top['prod_a']} + {$top['prod_b']}",
            'texto'  => "Pedido junto {$top['pct']}% das vezes — vale destacar no cardápio. Combo sugerido: R$ " . number_format((float)$top['preco_combo'], 2, ',', '.'),
        ];
    }

    // Insight 2: Ticket médio vs meta
    $ticketHoje = (float)$resumoHoje['ticket_medio'];
    if ($metaTicket > 0 && $ticketHoje < $metaTicket) {
        $diff = number_format($metaTicket - $ticketHoje, 2, ',', '.');
        $insights[] = [
            'cor'    => 'yellow',
            'titulo' => "Ticket médio abaixo da meta em R$ {$diff}",
            'texto'  => 'Sugerir add-ons no checkout pode resolver: "Adicionar sobremesa por +R$ 5?"',
        ];
    } elseif ($ticketHoje > 0) {
        $insights[] = [
            'cor'    => 'yellow',
            'titulo' => 'Ticket médio: R$ ' . number_format($ticketHoje, 2, ',', '.'),
            'texto'  => 'Configure uma meta de ticket médio em Configurações para acompanhar a evolução.',
        ];
    }

    // Insight 3: Hora de pico
    if ($horaPico && $horaPico['qtd'] > 0) {
        $h  = (int)$horaPico['hora'];
        $h2 = $h + 2;
        // Top produtos no pico
        $topPicoProd = $db->prepare("
            SELECT ip.nome_produto, SUM(ip.quantidade) AS qtd
            FROM totem_itens_pedido ip
            JOIN totem_pedidos p ON p.id=ip.pedido_id
            WHERE DATE(p.criado_em) BETWEEN ? AND ?
              AND EXTRACT(HOUR FROM p.criado_em) BETWEEN ? AND ?
              AND p.status!='cancelado'
            GROUP BY ip.nome_produto ORDER BY qtd DESC LIMIT 3
        ");
        $topPicoProd->execute([$ini30, $hoje, $h, $h+1]);
        $prodsPico = $topPicoProd->fetchAll(PDO::FETCH_COLUMN);
        $listaPico = implode(', ', $prodsPico) ?: 'sem dados';
        $insights[] = [
            'cor'    => 'blue',
            'titulo' => "Pico às {$h}h–{$h2}h: prepare estoque antes desse horário",
            'texto'  => "Os produtos mais pedidos nesse intervalo: {$listaPico}.",
        ];
    }

    // Insight 4: Fidelização
    if (!$fidelizacao['programa_ativo']) {
        $insights[] = [
            'cor'    => 'red',
            'titulo' => 'Programa de pontos inativo = oportunidade',
            'texto'  => 'A cada 10 pedidos, ofereça 1 gratuito — clientes com programa de fidelidade retornam 2× mais.',
        ];
    } else {
        $insights[] = [
            'cor'    => 'green',
            'titulo' => "Programa de pontos ativo — {$fidelizacao['frequentes_semana']} clientes frequentes esta semana",
            'texto'  => "Taxa de retorno em 7 dias: {$fidelizacao['retorno_7dias_pct']}%. Continue engajando!",
        ];
    }

    // Insight 5: Produtos parados
    if (!empty($produtosParados)) {
        $nomes = implode(', ', array_column($produtosParados, 'nome'));
        $insights[] = [
            'cor'    => 'red',
            'titulo' => count($produtosParados) . ' produto(s) sem venda nos últimos 7 dias',
            'texto'  => "Considere promoção relâmpago ou combo: {$nomes}.",
        ];
    }

    // Insight 6: Cancelamentos
    $cancelHoje = (int)$resumoHoje['cancelados'];
    $totalHoje  = (int)$resumoHoje['pedidos'] + $cancelHoje;
    if ($cancelHoje > 0 && $totalHoje > 0) {
        $txCancel = round(($cancelHoje / $totalHoje) * 100);
        $insights[] = [
            'cor'    => $txCancel > 8 ? 'red' : 'yellow',
            'titulo' => "{$cancelHoje} cancelamento(s) hoje ({$txCancel}% dos pedidos)",
            'texto'  => $txCancel > 8
                ? 'Taxa alta de cancelamento — verifique problemas no totem ou no pagamento.'
                : 'Investigue o motivo dos cancelamentos para reduzir perdas.',
        ];
    }

    // Deltas para o resumo
    $fatOntem  = (float)$resumoOntem['faturamento'];
    $fatDelta  = $fatOntem > 0 ? round((((float)$resumoHoje['faturamento'] - $fatOntem) / $fatOntem) * 100) : null;
    $pedDelta  = (int)$resumoOntem['pedidos'] > 0
        ? round((((int)$resumoHoje['pedidos'] - (int)$resumoOntem['pedidos']) / (int)$resumoOntem['pedidos']) * 100) : null;

    echo json_encode([
        'success'          => true,
        'resumo'           => array_merge($resumoHoje, [
            'fat_delta_pct'  => $fatDelta,
            'ped_delta_pct'  => $pedDelta,
            'hora_pico_ini'  => $horaPico ? (int)$horaPico['hora'] : null,
            'hora_pico_qtd'  => $horaPico ? (int)$horaPico['qtd']  : null,
            'meta_ticket'    => $metaTicket,
        ]),
        'combos'           => $combos,
        'faixas_horario'   => $faixas,
        'fidelizacao'      => $fidelizacao,
        'produtos_parados' => $produtosParados,
        'insights'         => $insights,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

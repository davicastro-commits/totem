<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once 'auth.php';
requireAdmin();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $dataIni = $_GET['data_ini'] ?? date('Y-m-d');
    $dataFim = $_GET['data_fim'] ?? date('Y-m-d');

    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT p.numero_pedido AS num, p.criado_em AS data_hora,
                   p.tipo_consumo, p.forma_pagamento, p.status,
                   p.subtotal, p.total, p.cpf, p.origem,
                   string_agg(i.nome_produto || ' x' || i.quantidade, ' | ' ORDER BY i.id) AS itens
              FROM totem_pedidos p
              JOIN totem_itens_pedido i ON i.pedido_id = p.id
             WHERE DATE(p.criado_em) BETWEEN ? AND ?
             GROUP BY p.id
             ORDER BY p.criado_em DESC
        ");
        $stmt->execute([$dataIni, $dataFim]);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="pedidos_' . $dataIni . '_' . $dataFim . '.csv"');
        header('Cache-Control: no-cache');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Numero','Data/Hora','Consumo','Pagamento','Status','Subtotal','Total','CPF','Origem','Itens'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['num'],
                (new DateTime($r['data_hora']))->format('d/m/Y H:i'),
                $r['tipo_consumo'],
                $r['forma_pagamento'],
                $r['status'],
                number_format((float)$r['subtotal'], 2, ',', '.'),
                number_format((float)$r['total'], 2, ',', '.'),
                $r['cpf'] ?? '',
                $r['origem'],
                $r['itens'],
            ], ';');
        }
        fclose($out);
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Erro: ' . $e->getMessage();
    }
    exit;
}

$dataIni = $_GET['data_ini'] ?? date('Y-m-d');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$action  = $_GET['action']   ?? '';

try {
    $db = getDB();

    // ── Fechamento de caixa ───────────────────────────────────────────
    if ($action === 'fechamento') {
        $data = $_GET['data'] ?? date('Y-m-d');

        $pagStmt = $db->prepare("
            SELECT forma_pagamento,
                   COUNT(*)       AS pedidos,
                   SUM(total)     AS total
              FROM totem_pedidos
             WHERE DATE(criado_em) = ?
               AND status NOT IN ('cancelado','aguardando_pagamento')
             GROUP BY forma_pagamento
             ORDER BY total DESC
        ");
        $pagStmt->execute([$data]);
        $porPagamento = $pagStmt->fetchAll();

        $totStmt = $db->prepare("
            SELECT COUNT(*)   AS pedidos,
                   COALESCE(SUM(total),0) AS total,
                   COALESCE(AVG(total),0) AS ticket_medio,
                   COALESCE(SUM(CASE WHEN tipo_consumo='local'   THEN 1 ELSE 0 END),0) AS local,
                   COALESCE(SUM(CASE WHEN tipo_consumo='viagem'  THEN 1 ELSE 0 END),0) AS viagem,
                   (SELECT COUNT(*) FROM totem_pedidos
                     WHERE DATE(criado_em) = ? AND status = 'cancelado') AS cancelados,
                   (SELECT COALESCE(SUM(total),0) FROM totem_pedidos
                     WHERE DATE(criado_em) = ? AND status = 'cancelado') AS total_cancelado
              FROM totem_pedidos
             WHERE DATE(criado_em) = ?
               AND status NOT IN ('cancelado','aguardando_pagamento')
        ");
        $totStmt->execute([$data, $data, $data]);
        $totais = $totStmt->fetch();

        $itensStmt = $db->prepare("
            SELECT COALESCE(SUM(ip.quantidade),0) AS itens
              FROM totem_itens_pedido ip
              JOIN totem_pedidos p ON p.id = ip.pedido_id
             WHERE DATE(p.criado_em) = ?
               AND p.status NOT IN ('cancelado','aguardando_pagamento')
        ");
        $itensStmt->execute([$data]);
        $totalItens = (int)$itensStmt->fetchColumn();

        $listaStmt = $db->prepare("
            SELECT p.numero_pedido, p.criado_em, p.forma_pagamento,
                   p.tipo_consumo, p.total, p.status, p.origem, p.cpf,
                   (SELECT string_agg(nome_produto || ' x' || quantidade, ', ' ORDER BY id)
                      FROM totem_itens_pedido WHERE pedido_id = p.id) AS itens
              FROM totem_pedidos p
             WHERE DATE(p.criado_em) = ?
             ORDER BY p.criado_em ASC
        ");
        $listaStmt->execute([$data]);
        $lista = $listaStmt->fetchAll();

        echo json_encode([
            'success'        => true,
            'data'           => date('d/m/Y', strtotime($data)),
            'por_pagamento'  => $porPagamento,
            'totais'         => $totais,
            'total_itens'    => $totalItens,
            'pedidos'        => $lista,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Tempo médio de preparo por faixa horária ──────────────────────
    if ($action === 'preparo') {
        $dataIniP = $_GET['data_ini'] ?? date('Y-m-d');
        $dataFimP = $_GET['data_fim'] ?? date('Y-m-d');

        $stmt = $db->prepare("
            SELECT
                FLOOR(EXTRACT(HOUR FROM iniciado_em) / 2) * 2  AS faixa_inicio,
                COUNT(*)                                         AS pedidos,
                ROUND(AVG(
                    EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) / 60.0
                )::numeric, 1)                                   AS tempo_medio_min,
                ROUND(MIN(
                    EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) / 60.0
                )::numeric, 1)                                   AS tempo_min,
                ROUND(MAX(
                    EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) / 60.0
                )::numeric, 1)                                   AS tempo_max
              FROM totem_pedidos
             WHERE DATE(criado_em) BETWEEN ? AND ?
               AND iniciado_em IS NOT NULL
               AND concluido_em IS NOT NULL
               AND concluido_em > iniciado_em
               AND EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) < 3600
             GROUP BY faixa_inicio
             ORDER BY faixa_inicio
        ");
        $stmt->execute([$dataIniP, $dataFimP]);
        $faixas = $stmt->fetchAll();

        $geralStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_com_tempo,
                ROUND(AVG(EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) / 60.0)::numeric, 1) AS media_geral,
                ROUND(MIN(EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) / 60.0)::numeric, 1) AS mais_rapido,
                ROUND(MAX(EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) / 60.0)::numeric, 1) AS mais_lento,
                (SELECT COUNT(*) FROM totem_pedidos
                  WHERE DATE(criado_em) BETWEEN ? AND ?
                    AND status NOT IN ('cancelado','aguardando_pagamento')) AS total_pedidos
              FROM totem_pedidos
             WHERE DATE(criado_em) BETWEEN ? AND ?
               AND iniciado_em IS NOT NULL
               AND concluido_em IS NOT NULL
               AND concluido_em > iniciado_em
               AND EXTRACT(EPOCH FROM (concluido_em - iniciado_em)) < 3600
        ");
        $geralStmt->execute([$dataIniP, $dataFimP, $dataIniP, $dataFimP]);
        $geral = $geralStmt->fetch();

        echo json_encode([
            'success' => true,
            'faixas'  => $faixas,
            'geral'   => $geral,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── KPIs ──────────────────────────────────────────────────────────
    $kpi = $db->prepare("
        SELECT
            COALESCE(SUM(total),0)            AS faturamento,
            COUNT(*)                           AS pedidos,
            COALESCE(AVG(total),0)             AS ticket_medio,
            COALESCE(SUM(CASE WHEN status='cancelado' THEN 1 ELSE 0 END),0) AS cancelados
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
    ");
    $kpi->execute([$dataIni, $dataFim]);
    $kpiData = $kpi->fetch();

    // ── Itens vendidos ────────────────────────────────────────────────
    $itens = $db->prepare("
        SELECT COALESCE(SUM(ip.quantidade),0)
          FROM totem_itens_pedido ip
          JOIN totem_pedidos p ON p.id = ip.pedido_id
         WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status != 'cancelado'
    ");
    $itens->execute([$dataIni, $dataFim]);
    $totalItens = (int)$itens->fetchColumn();

    // ── Por pagamento ─────────────────────────────────────────────────
    $pag = $db->prepare("
        SELECT forma_pagamento, COUNT(*) AS qtd, SUM(total) AS total
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY forma_pagamento ORDER BY total DESC
    ");
    $pag->execute([$dataIni, $dataFim]);
    $porPagamento = $pag->fetchAll();

    // ── Por origem (totem / caixa) ────────────────────────────────────
    $ori = $db->prepare("
        SELECT COALESCE(origem,'totem') AS origem, COUNT(*) AS qtd, SUM(total) AS total
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY origem ORDER BY total DESC
    ");
    $ori->execute([$dataIni, $dataFim]);
    $porOrigem = $ori->fetchAll();

    // ── Top produtos ──────────────────────────────────────────────────
    $top = $db->prepare("
        SELECT ip.nome_produto, SUM(ip.quantidade) AS qtd, SUM(ip.subtotal) AS total
          FROM totem_itens_pedido ip
          JOIN totem_pedidos p ON p.id = ip.pedido_id
         WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status != 'cancelado'
         GROUP BY ip.nome_produto
         ORDER BY qtd DESC LIMIT 15
    ");
    $top->execute([$dataIni, $dataFim]);
    $topProdutos = $top->fetchAll();

    // ── Faturamento por dia (no período) ─────────────────────────────
    $dias = $db->prepare("
        SELECT DATE(criado_em) AS dia, COUNT(*) AS pedidos, SUM(total) AS total
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY DATE(criado_em) ORDER BY dia ASC
    ");
    $dias->execute([$dataIni, $dataFim]);
    $porDia = $dias->fetchAll();

    // ── Lista de pedidos do dia (ou período curto) ────────────────────
    $lista = $db->prepare("
        SELECT p.numero_pedido AS numero, p.criado_em, p.tipo_consumo,
               p.forma_pagamento, p.total, p.status, p.origem,
               (SELECT COUNT(*) FROM totem_itens_pedido WHERE pedido_id = p.id) AS total_itens
          FROM totem_pedidos p
         WHERE DATE(p.criado_em) BETWEEN ? AND ?
         ORDER BY p.criado_em DESC
         LIMIT 200
    ");
    $lista->execute([$dataIni, $dataFim]);
    $pedidosLista = $lista->fetchAll();

    // ── Hora pico ────────────────────────────────────────────────────
    $hora = $db->prepare("
        SELECT EXTRACT(HOUR FROM criado_em) AS hora, COUNT(*) AS qtd
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY hora ORDER BY hora
    ");
    $hora->execute([$dataIni, $dataFim]);
    $horaPico = $hora->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'faturamento'    => $kpiData['faturamento'],
            'pedidos_total'  => (int)$kpiData['pedidos'],
            'ticket_medio'   => $kpiData['ticket_medio'],
            'cancelados'     => (int)$kpiData['cancelados'],
            'itens_total'    => $totalItens,
            'por_pagamento'  => $porPagamento,
            'por_origem'     => $porOrigem,
            'top_produtos'   => $topProdutos,
            'por_dia'        => $porDia,
            'hora_pico'      => $horaPico,
            'pedidos_lista'  => $pedidosLista,
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

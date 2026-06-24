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

    // ── Projeção de faturamento (15 dias) ────────────────────────────
    if ($action === 'projecao') {
        $dias = (int)($_GET['dias'] ?? 15);

        // Média por dia da semana (últimos 60 dias)
        $stmtDow = $db->query("
            SELECT EXTRACT(DOW FROM criado_em)::int AS dow,
                   AVG(total_dia) AS media
            FROM (
                SELECT DATE(criado_em) AS d,
                       EXTRACT(DOW FROM criado_em)::int AS dow_d,
                       SUM(total) AS total_dia
                FROM totem_pedidos
                WHERE criado_em >= CURRENT_DATE - 60
                  AND status != 'cancelado'
                GROUP BY DATE(criado_em)
            ) sub
            GROUP BY dow
            ORDER BY dow
        ");
        $dowRows = $stmtDow->fetchAll();
        $dowAvg = array_fill(0, 7, 0);
        foreach ($dowRows as $r) {
            $dowAvg[(int)$r['dow']] = round((float)$r['media'], 2);
        }

        // Montar projeção dos próximos N dias
        $projecao = [];
        for ($i = 1; $i <= $dias; $i++) {
            $dt  = new DateTime();
            $dt->modify("+{$i} days");
            $dow = (int)$dt->format('w');
            $projecao[] = [
                'dia'      => $dt->format('Y-m-d'),
                'dow'      => $dow,
                'projetado'=> $dowAvg[$dow],
            ];
        }

        echo json_encode([
            'success'   => true,
            'dow_avg'   => $dowAvg,
            'projecao'  => $projecao,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Analytics avançado (com comparação de período anterior) ──────
    if ($action === 'analytics') {
        $d1       = new DateTime($dataIni);
        $d2       = new DateTime($dataFim);
        $diffDays = $d1->diff($d2)->days + 1;
        $antFim   = (clone $d1)->modify('-1 day')->format('Y-m-d');
        $antIni   = (clone $d1)->modify("-{$diffDays} days")->format('Y-m-d');

        // KPIs do período atual e anterior
        $stmtKpi = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status!='cancelado' THEN total END),0)  AS faturamento,
                COUNT(CASE WHEN status!='cancelado' THEN 1 END)                AS pedidos,
                COALESCE(AVG(CASE WHEN status!='cancelado' THEN total END),0)  AS ticket_medio,
                COUNT(CASE WHEN status='cancelado'  THEN 1 END)                AS cancelados,
                COUNT(*)                                                        AS total_todos,
                COUNT(DISTINCT DATE(criado_em))                                 AS dias_com_pedidos
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ?
        ");
        $stmtKpi->execute([$dataIni, $dataFim]); $kpiCur = $stmtKpi->fetch();
        $stmtKpi->execute([$antIni,  $antFim]);  $kpiAnt = $stmtKpi->fetch();

        // Itens
        $stmtIt = $db->prepare("
            SELECT COALESCE(SUM(ip.quantidade),0)
            FROM totem_itens_pedido ip
            JOIN totem_pedidos p ON p.id=ip.pedido_id
            WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
        ");
        $stmtIt->execute([$dataIni,$dataFim]); $itensCur = (int)$stmtIt->fetchColumn();
        $stmtIt->execute([$antIni,$antFim]);   $itensAnt = (int)$stmtIt->fetchColumn();

        // KPIs por dia (gráfico)
        $stmtDias = $db->prepare("
            SELECT DATE(criado_em) AS dia, COUNT(*) AS pedidos, SUM(total) AS total
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
            GROUP BY DATE(criado_em) ORDER BY dia
        ");
        $stmtDias->execute([$dataIni,$dataFim]); $porDia = $stmtDias->fetchAll();

        // Hora pico
        $stmtHora = $db->prepare("
            SELECT EXTRACT(HOUR FROM criado_em)::int AS hora, COUNT(*) AS qtd
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
            GROUP BY hora ORDER BY hora
        ");
        $stmtHora->execute([$dataIni,$dataFim]); $horaPico = $stmtHora->fetchAll();

        // Pagamento + Origem
        $stmtPag = $db->prepare("
            SELECT forma_pagamento, COUNT(*) AS qtd, SUM(total) AS total
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
            GROUP BY forma_pagamento ORDER BY total DESC
        ");
        $stmtPag->execute([$dataIni,$dataFim]); $porPag = $stmtPag->fetchAll();

        $stmtOri = $db->prepare("
            SELECT COALESCE(canal, origem, 'totem') AS origem, COUNT(*) AS qtd, SUM(total) AS total
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
            GROUP BY COALESCE(canal, origem, 'totem') ORDER BY total DESC
        ");
        $stmtOri->execute([$dataIni,$dataFim]); $porOrigem = $stmtOri->fetchAll();

        // Ranking de produtos com score
        $stmtProd = $db->prepare("
            SELECT ip.nome_produto, ip.produto_id,
                   SUM(ip.quantidade) AS qtd,
                   SUM(ip.subtotal)   AS receita,
                   AVG(ip.preco_unitario) AS preco_medio
            FROM totem_itens_pedido ip
            JOIN totem_pedidos p ON p.id=ip.pedido_id
            WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
            GROUP BY ip.nome_produto, ip.produto_id
            ORDER BY qtd DESC
        ");
        $stmtProd->execute([$dataIni,$dataFim]); $produtos = $stmtProd->fetchAll();
        $stmtProd->execute([$antIni,$antFim]);   $prodAntAll = $stmtProd->fetchAll();
        $prodAntMap = [];
        foreach ($prodAntAll as $p) $prodAntMap[$p['nome_produto']] = (int)$p['qtd'];

        $maxQtd = max(array_column($produtos,'qtd') ?: [1]);
        $maxRec = max(array_column($produtos,'receita') ?: [1]);
        foreach ($produtos as &$p) {
            $sQtd = $maxQtd > 0 ? ((float)$p['qtd']/$maxQtd)*100 : 0;
            $sRec = $maxRec > 0 ? ((float)$p['receita']/$maxRec)*100 : 0;
            $p['score']   = (int)round($sQtd*0.5+$sRec*0.5);
            $p['qtd_ant'] = $prodAntMap[$p['nome_produto']] ?? 0;
            $qa = $p['qtd_ant'];
            $p['delta']   = $qa > 0 ? (int)round((((float)$p['qtd']-$qa)/$qa)*100) : null;
        }
        unset($p);

        // Cross-sell (co-ocorrência)
        $stmtCs = $db->prepare("
            SELECT a.nome_produto AS prod_a, b.nome_produto AS prod_b,
                   COUNT(*) AS ocorrencias,
                   AVG(a.preco_unitario+b.preco_unitario) AS preco_combo
            FROM totem_itens_pedido a
            JOIN totem_itens_pedido b ON a.pedido_id=b.pedido_id AND a.produto_id<b.produto_id
            JOIN totem_pedidos p ON p.id=a.pedido_id
            WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
            GROUP BY a.nome_produto, b.nome_produto
            HAVING COUNT(*)>=2
            ORDER BY ocorrencias DESC LIMIT 5
        ");
        $stmtCs->execute([$dataIni,$dataFim]); $crossSell = $stmtCs->fetchAll();

        // Heatmap (hora x dia da semana)
        $stmtHm = $db->prepare("
            SELECT EXTRACT(DOW FROM criado_em)::int AS dow,
                   EXTRACT(HOUR FROM criado_em)::int AS hora,
                   COUNT(*) AS cnt
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
            GROUP BY dow, hora ORDER BY dow, hora
        ");
        $stmtHm->execute([$dataIni,$dataFim]); $heatmap = $stmtHm->fetchAll();

        // Alertas
        $aguardLentos = (int)$db->query("
            SELECT COUNT(*) FROM totem_pedidos
            WHERE status='aguardando' AND criado_em < NOW()-INTERVAL '20 minutes'
        ")->fetchColumn();
        $taxaCancelHoje = 0;
        $pedHoje = (int)$db->query("SELECT COUNT(*) FROM totem_pedidos WHERE DATE(criado_em)=CURRENT_DATE")->fetchColumn();
        if ($pedHoje > 0) {
            $canHoje = (int)$db->query("SELECT COUNT(*) FROM totem_pedidos WHERE DATE(criado_em)=CURRENT_DATE AND status='cancelado'")->fetchColumn();
            $taxaCancelHoje = round(($canHoje/$pedHoje)*100,1);
        }

        // Score de saúde
        $diasPer   = max(1,$diffDays);
        $diasOp    = (int)$kpiCur['dias_com_pedidos'];
        $sConsis   = min(100,($diasOp/$diasPer)*100);
        $sCancel   = max(0,100 - ((float)$kpiCur['cancelados']/max(1,(float)$kpiCur['total_todos'])*100*5));
        $tickA     = (float)$kpiAnt['ticket_medio'];
        $sTicket   = $tickA>0 ? min(120,((float)$kpiCur['ticket_medio']/$tickA)*100) : 60;
        $pedA      = (float)$kpiAnt['pedidos'];
        $sFat      = $pedA>0 ? min(120,((float)$kpiCur['pedidos']/$pedA)*100) : 60;
        $sDiv      = min(100,count($produtos)*10);
        $score     = (int)round($sConsis*0.15+$sCancel*0.20+$sTicket*0.20+$sFat*0.25+$sDiv*0.20);

        // Lista pedidos
        $stmtLista = $db->prepare("
            SELECT p.numero_pedido AS numero, p.criado_em, p.tipo_consumo,
                   p.forma_pagamento, p.total, p.status,
                   COALESCE(p.canal, p.origem, 'totem') AS origem,
                   (SELECT COUNT(*) FROM totem_itens_pedido WHERE pedido_id=p.id) AS total_itens
            FROM totem_pedidos p
            WHERE DATE(p.criado_em) BETWEEN ? AND ?
            ORDER BY p.criado_em DESC LIMIT 200
        ");
        $stmtLista->execute([$dataIni,$dataFim]); $lista = $stmtLista->fetchAll();

        // ── Metas mensais ─────────────────────────────────────────────
        $metaFat = (float)($db->query("SELECT COALESCE(valor,'0') FROM totem_configuracoes WHERE chave='meta_fat_mes'")->fetchColumn() ?: 0);
        $metaPed = (int)($db->query("SELECT COALESCE(valor,'0') FROM totem_configuracoes WHERE chave='meta_pedidos_mes'")->fetchColumn() ?: 0);
        $taxaCred = (float)($db->query("SELECT COALESCE(valor,'2.5') FROM totem_configuracoes WHERE chave='taxa_credito'")->fetchColumn() ?: 2.5);
        $taxaDeb  = (float)($db->query("SELECT COALESCE(valor,'1.5') FROM totem_configuracoes WHERE chave='taxa_debito'")->fetchColumn() ?: 1.5);

        // Progresso mês atual
        $mesIni   = date('Y-m-01');
        $mesFim   = date('Y-m-d');
        $diaAtual = (int)date('j');
        $diasMes  = (int)date('t');
        $stmtMes  = $db->prepare("SELECT COALESCE(SUM(total),0) AS fat, COUNT(*) AS ped FROM totem_pedidos WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'");
        $stmtMes->execute([$mesIni, $mesFim]);
        $mes       = $stmtMes->fetch();
        $mediaDia  = $diaAtual > 0 ? (float)$mes['fat'] / $diaAtual : 0;
        $projecaoFat = round($mediaDia * $diasMes, 2);
        $mediaPedDia = $diaAtual > 0 ? (float)$mes['ped'] / $diaAtual : 0;
        $projecaoPed = (int)round($mediaPedDia * $diasMes);

        // Custos por pagamento
        $custosArr = [];
        foreach ($porPag as $p) {
            $taxa = match($p['forma_pagamento']) {
                'credito' => $taxaCred,
                'debito'  => $taxaDeb,
                default   => 0.0
            };
            $custo = round((float)$p['total'] * $taxa / 100, 2);
            $custosArr[] = array_merge($p, [
                'taxa'    => $taxa,
                'custo'   => $custo,
                'liquido' => round((float)$p['total'] - $custo, 2),
            ]);
        }
        $totalCusto = array_sum(array_column($custosArr, 'custo'));

        // Análise por turno
        $stmtTurno = $db->prepare("
            SELECT
                CASE
                    WHEN EXTRACT(HOUR FROM criado_em) BETWEEN 6 AND 11  THEN 'manha'
                    WHEN EXTRACT(HOUR FROM criado_em) BETWEEN 12 AND 14 THEN 'almoco'
                    WHEN EXTRACT(HOUR FROM criado_em) BETWEEN 15 AND 18 THEN 'tarde'
                    ELSE 'noite'
                END AS turno,
                COUNT(*)                           AS pedidos,
                COALESCE(SUM(total), 0)            AS faturamento,
                COALESCE(AVG(total), 0)            AS ticket_medio
            FROM totem_pedidos
            WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
            GROUP BY turno
            ORDER BY MIN(EXTRACT(HOUR FROM criado_em))
        ");
        $stmtTurno->execute([$dataIni, $dataFim]);
        $porTurno = $stmtTurno->fetchAll();

        // Top 5 clientes do período
        $topClientes = [];
        try {
            $stmtTopCli = $db->prepare("
                SELECT c.nome, c.cpf, COALESCE(c.pontos_saldo,0) AS pontos_saldo,
                       COUNT(p.id) AS pedidos,
                       COALESCE(SUM(p.total), 0) AS total_gasto,
                       MAX(p.criado_em) AS ultima_visita
                FROM totem_clientes c
                JOIN totem_pedidos p ON p.cliente_id = c.id
                WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status != 'cancelado'
                GROUP BY c.id, c.nome, c.cpf, c.pontos_saldo
                ORDER BY total_gasto DESC LIMIT 5
            ");
            $stmtTopCli->execute([$dataIni, $dataFim]);
            $topClientes = $stmtTopCli->fetchAll();
        } catch (Throwable) {}

        // Recordes históricos (all-time)
        $recordeDia = null; $recordePedidos = null;
        try {
            $recordeDia = $db->query("
                SELECT DATE(criado_em) AS dia, SUM(total) AS total
                FROM totem_pedidos WHERE status != 'cancelado'
                GROUP BY DATE(criado_em) ORDER BY total DESC LIMIT 1
            ")->fetch();
            $recordePedidos = $db->query("
                SELECT DATE(criado_em) AS dia, COUNT(*) AS qtd
                FROM totem_pedidos WHERE status != 'cancelado'
                GROUP BY DATE(criado_em) ORDER BY qtd DESC LIMIT 1
            ")->fetch();
        } catch (Throwable) {}

        echo json_encode([
            'success'   => true,
            'periodo'   => ['ini'=>$dataIni,'fim'=>$dataFim,'dias'=>$diffDays],
            'anterior'  => ['ini'=>$antIni,'fim'=>$antFim],
            'kpi'       => ['faturamento'=>(float)$kpiCur['faturamento'],'pedidos'=>(int)$kpiCur['pedidos'],'ticket_medio'=>(float)$kpiCur['ticket_medio'],'cancelados'=>(int)$kpiCur['cancelados'],'itens_total'=>$itensCur],
            'kpi_ant'   => ['faturamento'=>(float)$kpiAnt['faturamento'],'pedidos'=>(int)$kpiAnt['pedidos'],'ticket_medio'=>(float)$kpiAnt['ticket_medio'],'cancelados'=>(int)$kpiAnt['cancelados'],'itens_total'=>$itensAnt],
            'por_dia'   => $porDia,
            'hora_pico' => $horaPico,
            'por_pagamento' => $porPag,
            'por_origem'    => $porOrigem,
            'produtos'  => $produtos,
            'crosssell' => $crossSell,
            'heatmap'   => $heatmap,
            'alertas'   => ['aguard_lentos'=>$aguardLentos,'taxa_cancel_hoje'=>$taxaCancelHoje],
            'score'     => min(100,max(0,$score)),
            'pedidos_lista' => $lista,
            'metas'     => [
                'fat_meta'    => $metaFat,
                'fat_atual'   => (float)$mes['fat'],
                'fat_projecao'=> $projecaoFat,
                'ped_meta'    => $metaPed,
                'ped_atual'   => (int)$mes['ped'],
                'ped_projecao'=> $projecaoPed,
                'dia_atual'   => $diaAtual,
                'dias_mes'    => $diasMes,
            ],
            'custos_pagamento'    => $custosArr,
            'total_custo_periodo' => $totalCusto,
            'taxas'               => ['credito'=>$taxaCred,'debito'=>$taxaDeb],
            'por_turno'           => $porTurno,
            'top_clientes'        => $topClientes,
            'records'             => [
                'dia_maior_fat' => $recordeDia,
                'dia_mais_ped'  => $recordePedidos,
            ],
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

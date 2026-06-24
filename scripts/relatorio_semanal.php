<?php
/**
 * scripts/relatorio_semanal.php
 *
 * Gera e envia o relatório semanal da cafeteria.
 *
 * Modos de execução:
 *   CLI  : php scripts/relatorio_semanal.php
 *   Web  : GET /scripts/relatorio_semanal.php?token=SEU_TOKEN
 *   Prévia: GET /scripts/relatorio_semanal.php?token=SEU_TOKEN&preview=1
 */

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('IS_CLI', PHP_SAPI === 'cli');

require_once ROOT . '/config/db.php';
require_once ROOT . '/config/mailer.php';

// ── Helpers ──────────────────────────────────────────────────────────────────
function brl(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function pctDiff(float $novo, float $ant): ?float
{
    if ($ant <= 0) return null;
    return round((($novo - $ant) / $ant) * 100, 1);
}

function _getConfigValue(string $chave, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $db = getDB();
            $rows = $db->query("SELECT chave, valor FROM material.totem_configuracoes")->fetchAll();
            $cache = [];
            foreach ($rows as $r) {
                $cache[$r['chave']] = $r['valor'];
            }
        } catch (Throwable) {
            $cache = [];
        }
    }
    return $cache[$chave] ?? $default;
}

// ── Período: semana anterior seg–dom ─────────────────────────────────────────
function calcularPeriodo(): array
{
    // "Semana anterior": segunda a domingo da semana passada
    $hoje = new DateTimeImmutable('today');
    // Recua até a última segunda-feira, depois vai uma semana para trás
    $diaSemana = (int)$hoje->format('N'); // 1=seg … 7=dom
    $inicioSemanaAtual = $hoje->modify('-' . ($diaSemana - 1) . ' days');
    $ini = $inicioSemanaAtual->modify('-7 days');
    $fim = $ini->modify('+6 days');
    return [$ini->format('Y-m-d'), $fim->format('Y-m-d')];
}

// ── Autenticação (web) ────────────────────────────────────────────────────────
if (!IS_CLI) {
    $tokenEsperado = _getConfigValue('relatorio_token_secreto', '');
    $tokenRecebido = $_GET['token'] ?? '';
    if (empty($tokenEsperado) || !hash_equals($tokenEsperado, $tokenRecebido)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

[$iniSemana, $fimSemana] = calcularPeriodo();

// Semana anterior à anterior (para comparativo)
$iniAnt = date('Y-m-d', strtotime($iniSemana . ' -7 days'));
$fimAnt = date('Y-m-d', strtotime($fimSemana . ' -7 days'));

try {
    $db = getDB();

    // ── 1. Faturamento e pedidos da semana atual ──────────────────────────────
    $kpi = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END), 0) AS faturamento,
            COUNT(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END)               AS total_pedidos
        FROM material.totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ?
    ");
    $kpi->execute([$iniSemana, $fimSemana]);
    $kpiRow = $kpi->fetch();

    $faturamento   = (float)$kpiRow['faturamento'];
    $totalPedidos  = (int)$kpiRow['total_pedidos'];
    $ticketMedio   = $totalPedidos > 0 ? $faturamento / $totalPedidos : 0;

    // ── 2. Comparativo semana anterior ───────────────────────────────────────
    $kpiAnt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END), 0) AS faturamento,
            COUNT(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END)               AS total_pedidos
        FROM material.totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ?
    ");
    $kpiAnt->execute([$iniAnt, $fimAnt]);
    $kpiAntRow = $kpiAnt->fetch();

    $faturamentoAnt  = (float)$kpiAntRow['faturamento'];
    $totalPedidosAnt = (int)$kpiAntRow['total_pedidos'];

    $varFat = pctDiff($faturamento, $faturamentoAnt);
    $varPed = pctDiff($totalPedidos, $totalPedidosAnt);

    // ── 3. Custo estimado e margem bruta ─────────────────────────────────────
    $custoEstimado = 0;
    try {
        $custoStmt = $db->prepare("
            SELECT COALESCE(SUM(ip.quantidade * ft.quantidade * ins.custo_medio), 0) AS custo
            FROM material.totem_itens_pedido ip
            JOIN material.totem_pedidos p           ON p.id = ip.pedido_id
            JOIN material.totem_ficha_tecnica ft    ON ft.produto_id = ip.produto_id
            JOIN material.totem_insumos ins         ON ins.id = ft.insumo_id
            WHERE DATE(p.criado_em) BETWEEN ? AND ?
              AND p.status NOT IN ('cancelado','aguardando_pagamento')
        ");
        $custoStmt->execute([$iniSemana, $fimSemana]);
        $custoEstimado = (float)$custoStmt->fetchColumn();
    } catch (Throwable) {}

    $margemValor = max(0, $faturamento - $custoEstimado);
    $margemPct   = $faturamento > 0 ? round(($margemValor / $faturamento) * 100, 1) : 0;

    // ── 4. Faturamento por dia da semana ─────────────────────────────────────
    $diasStmt = $db->prepare("
        SELECT
            DATE(criado_em)                                                               AS dia,
            TO_CHAR(criado_em, 'Day')                                                     AS nome_dia,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END), 0) AS fat,
            COUNT(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END) AS pedidos
        FROM material.totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ?
        GROUP BY DATE(criado_em), TO_CHAR(criado_em, 'Day')
        ORDER BY dia
    ");
    $diasStmt->execute([$iniSemana, $fimSemana]);
    $diasRows = $diasStmt->fetchAll();

    // ── 5. Top 5 produtos ────────────────────────────────────────────────────
    $topStmt = $db->prepare("
        SELECT
            p.nome,
            SUM(ip.quantidade)                     AS qtd,
            SUM(ip.quantidade * ip.preco_unitario) AS receita
        FROM material.totem_itens_pedido ip
        JOIN material.totem_pedidos ped  ON ped.id = ip.pedido_id
        JOIN material.totem_produtos p   ON p.id = ip.produto_id
        WHERE DATE(ped.criado_em) BETWEEN ? AND ?
          AND ped.status NOT IN ('cancelado','aguardando_pagamento')
        GROUP BY p.nome
        ORDER BY qtd DESC
        LIMIT 5
    ");
    $topStmt->execute([$iniSemana, $fimSemana]);
    $topProdutos = $topStmt->fetchAll();

    // ── 6. Faturamento por forma de pagamento ────────────────────────────────
    $pagStmt = $db->prepare("
        SELECT
            COALESCE(pagamento, 'Não informado') AS pagamento,
            COUNT(*)                             AS pedidos,
            COALESCE(SUM(total), 0)              AS total
        FROM material.totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ?
          AND status NOT IN ('cancelado','aguardando_pagamento')
        GROUP BY pagamento
        ORDER BY total DESC
    ");
    $pagStmt->execute([$iniSemana, $fimSemana]);
    $pagamentos = $pagStmt->fetchAll();

    // ── 7. Alertas de estoque ────────────────────────────────────────────────
    $alertasEstoque = [];
    try {
        $alertaStmt = $db->query("
            SELECT nome, estoque_atual, estoque_minimo, unidade
            FROM material.totem_insumos
            WHERE estoque_atual <= estoque_minimo
              AND estoque_minimo > 0
            ORDER BY (estoque_atual / NULLIF(estoque_minimo, 0)) ASC
            LIMIT 20
        ");
        $alertasEstoque = $alertaStmt->fetchAll();
    } catch (Throwable) {}

    // ── 8. Previsão de esgotamento (top 5 críticos) ──────────────────────────
    $previsaoEstoque = [];
    try {
        $previsaoStmt = $db->prepare("
            SELECT
                ins.nome,
                ins.estoque_atual,
                ins.unidade,
                COALESCE(SUM(ip.quantidade * ft.quantidade), 0) AS consumo_semana
            FROM material.totem_insumos ins
            LEFT JOIN material.totem_ficha_tecnica ft ON ft.insumo_id = ins.id
            LEFT JOIN material.totem_itens_pedido ip  ON ip.produto_id = ft.produto_id
            LEFT JOIN material.totem_pedidos ped       ON ped.id = ip.pedido_id
                AND DATE(ped.criado_em) BETWEEN ? AND ?
                AND ped.status NOT IN ('cancelado','aguardando_pagamento')
            WHERE ins.estoque_atual > 0
            GROUP BY ins.id, ins.nome, ins.estoque_atual, ins.unidade
            HAVING COALESCE(SUM(ip.quantidade * ft.quantidade), 0) > 0
            ORDER BY (ins.estoque_atual / NULLIF(SUM(ip.quantidade * ft.quantidade), 0)) ASC
            LIMIT 5
        ");
        $previsaoStmt->execute([$iniSemana, $fimSemana]);
        $previsaoEstoque = $previsaoStmt->fetchAll();
        foreach ($previsaoEstoque as &$pv) {
            $pv['dias_restantes'] = $pv['consumo_semana'] > 0
                ? round(($pv['estoque_atual'] / $pv['consumo_semana']) * 7, 1)
                : null;
        }
        unset($pv);
    } catch (Throwable) {}

} catch (Throwable $e) {
    $msg = 'Erro ao coletar dados: ' . $e->getMessage();
    if (IS_CLI) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    exit($msg);
}

// ── Funções de formatação de variação ────────────────────────────────────────
function varBadge(?float $v): string
{
    if ($v === null) return '';
    $cor   = $v >= 0 ? '#22c55e' : '#ef4444';
    $arrow = $v >= 0 ? '↑' : '↓';
    return "<span style=\"color:{$cor};font-weight:700;font-size:13px;margin-left:6px\">{$arrow} " . abs($v) . "%</span>";
}

function nomesDias(array $rows): array
{
    $mapa = [
        'Monday' => 'Segunda', 'Tuesday' => 'Terça',    'Wednesday' => 'Quarta',
        'Thursday' => 'Quinta', 'Friday' => 'Sexta',    'Saturday' => 'Sábado',
        'Sunday' => 'Domingo',
    ];
    foreach ($rows as &$r) {
        $eng = trim($r['nome_dia']);
        $r['nome_dia_pt'] = $mapa[$eng] ?? $eng;
    }
    unset($r);
    return $rows;
}

$diasRows = nomesDias($diasRows);

// ── Gera o HTML do e-mail ─────────────────────────────────────────────────────
$periodoExibicao = date('d/m', strtotime($iniSemana)) . ' a ' . date('d/m/Y', strtotime($fimSemana));
$dataGeracao = date('d/m/Y \à\s H:i');

// Linha do faturamento por dia
ob_start();
foreach ($diasRows as $d): ?>
<tr style="border-bottom:1px solid #2a2d3e">
  <td style="padding:9px 14px;color:#f0f2f8;font-weight:600"><?= htmlspecialchars($d['nome_dia_pt']) ?></td>
  <td style="padding:9px 14px;color:#9ca3af;font-size:12px"><?= date('d/m', strtotime($d['dia'])) ?></td>
  <td style="padding:9px 14px;color:#f59e0b;font-weight:700;text-align:right"><?= brl((float)$d['fat']) ?></td>
  <td style="padding:9px 14px;color:#9ca3af;text-align:right"><?= $d['pedidos'] ?></td>
</tr>
<?php endforeach;
$diasHtml = ob_get_clean();

// Linha dos top produtos
ob_start();
$rank = 1;
foreach ($topProdutos as $p): ?>
<tr style="border-bottom:1px solid #2a2d3e">
  <td style="padding:9px 14px;color:#6b7280;font-size:12px;font-weight:700">#<?= $rank++ ?></td>
  <td style="padding:9px 14px;color:#f0f2f8;font-weight:600"><?= htmlspecialchars($p['nome']) ?></td>
  <td style="padding:9px 14px;color:#9ca3af;text-align:right"><?= (int)$p['qtd'] ?> un.</td>
  <td style="padding:9px 14px;color:#22c55e;font-weight:700;text-align:right"><?= brl((float)$p['receita']) ?></td>
</tr>
<?php endforeach;
$topHtml = ob_get_clean();

// Pagamentos
ob_start();
$cores = ['#ff5500','#3b82f6','#22c55e','#f59e0b','#8b5cf6','#ec4899'];
$ci = 0;
foreach ($pagamentos as $pg):
    $pct = $faturamento > 0 ? round(($pg['total'] / $faturamento) * 100, 1) : 0;
    $cor = $cores[$ci++ % count($cores)];
?>
<tr style="border-bottom:1px solid #2a2d3e">
  <td style="padding:9px 14px">
    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $cor ?>;margin-right:7px;vertical-align:middle"></span>
    <span style="color:#f0f2f8;font-weight:600"><?= htmlspecialchars(ucfirst($pg['pagamento'])) ?></span>
  </td>
  <td style="padding:9px 14px;color:#9ca3af;text-align:right"><?= $pg['pedidos'] ?> pedidos</td>
  <td style="padding:9px 14px;color:#f0f2f8;font-weight:700;text-align:right"><?= brl((float)$pg['total']) ?></td>
  <td style="padding:9px 14px;color:#6b7280;text-align:right"><?= $pct ?>%</td>
</tr>
<?php endforeach;
$pagHtml = ob_get_clean();

// Alertas de estoque
$alertaHtml = '';
if (!empty($alertasEstoque)) {
    ob_start();
    echo '<div style="background:#1a1221;border:1px solid rgba(239,68,68,.35);border-radius:12px;padding:20px;margin-bottom:24px">';
    echo '<p style="color:#ef4444;font-weight:700;font-size:15px;margin:0 0 14px">⚠️ Alertas de Estoque (' . count($alertasEstoque) . ' insumos abaixo do mínimo)</p>';
    echo '<table width="100%" style="border-collapse:collapse">';
    echo '<tr style="border-bottom:1px solid rgba(239,68,68,.2)">';
    echo '<th style="text-align:left;padding:6px 12px;color:#6b7280;font-size:11px;text-transform:uppercase">Insumo</th>';
    echo '<th style="text-align:right;padding:6px 12px;color:#6b7280;font-size:11px;text-transform:uppercase">Atual</th>';
    echo '<th style="text-align:right;padding:6px 12px;color:#6b7280;font-size:11px;text-transform:uppercase">Mínimo</th>';
    echo '</tr>';
    foreach ($alertasEstoque as $a) {
        echo '<tr style="border-bottom:1px solid rgba(255,255,255,.05)">';
        echo '<td style="padding:8px 12px;color:#f0f2f8">' . htmlspecialchars($a['nome']) . '</td>';
        echo '<td style="padding:8px 12px;color:#ef4444;font-weight:700;text-align:right">' . number_format((float)$a['estoque_atual'], 2, ',', '.') . ' ' . $a['unidade'] . '</td>';
        echo '<td style="padding:8px 12px;color:#9ca3af;text-align:right">' . number_format((float)$a['estoque_minimo'], 2, ',', '.') . ' ' . $a['unidade'] . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
    $alertaHtml = ob_get_clean();
}

// Previsão de esgotamento
$previsaoHtml = '';
if (!empty($previsaoEstoque)) {
    ob_start();
    echo '<div style="background:#1a1c27;border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:20px;margin-bottom:24px">';
    echo '<p style="color:#f59e0b;font-weight:700;font-size:15px;margin:0 0 14px">📦 Previsão de Esgotamento (Top 5 Críticos)</p>';
    echo '<table width="100%" style="border-collapse:collapse">';
    echo '<tr style="border-bottom:1px solid rgba(245,158,11,.15)">';
    echo '<th style="text-align:left;padding:6px 12px;color:#6b7280;font-size:11px;text-transform:uppercase">Insumo</th>';
    echo '<th style="text-align:right;padding:6px 12px;color:#6b7280;font-size:11px;text-transform:uppercase">Estoque</th>';
    echo '<th style="text-align:right;padding:6px 12px;color:#6b7280;font-size:11px;text-transform:uppercase">Dias Estimados</th>';
    echo '</tr>';
    foreach ($previsaoEstoque as $pv) {
        $diasCor = ($pv['dias_restantes'] !== null && $pv['dias_restantes'] <= 7) ? '#ef4444' : '#f59e0b';
        echo '<tr style="border-bottom:1px solid rgba(255,255,255,.04)">';
        echo '<td style="padding:8px 12px;color:#f0f2f8">' . htmlspecialchars($pv['nome']) . '</td>';
        echo '<td style="padding:8px 12px;color:#9ca3af;text-align:right">' . number_format((float)$pv['estoque_atual'], 2, ',', '.') . ' ' . $pv['unidade'] . '</td>';
        $diasLabel = $pv['dias_restantes'] !== null ? $pv['dias_restantes'] . ' dias' : '—';
        echo '<td style="padding:8px 12px;color:' . $diasCor . ';font-weight:700;text-align:right">' . $diasLabel . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
    $previsaoHtml = ob_get_clean();
}

// ── Template HTML final ───────────────────────────────────────────────────────
$html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Relatório Semanal — Café Comunhão</title>
</head>
<body style="margin:0;padding:0;background:#0d0f17;font-family:'Segoe UI',Arial,sans-serif;color:#f0f2f8">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0d0f17;min-height:100vh">
<tr><td align="center" style="padding:32px 16px">

  <!-- CONTAINER -->
  <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%">

    <!-- HEADER -->
    <tr>
      <td style="background:linear-gradient(135deg,#ff5500 0%,#cc3300 100%);border-radius:16px 16px 0 0;padding:36px 40px;text-align:center">
        <div style="font-size:42px;margin-bottom:8px">☕</div>
        <div style="font-size:26px;font-weight:900;color:#fff;letter-spacing:-0.5px">Café Comunhão</div>
        <div style="font-size:15px;color:rgba(255,255,255,.85);margin-top:4px;font-weight:600">Relatório Semanal</div>
        <div style="display:inline-block;background:rgba(0,0,0,.2);border-radius:20px;padding:5px 16px;margin-top:12px;font-size:13px;color:rgba(255,255,255,.9);font-weight:600">
          📅 {$periodoExibicao}
        </div>
      </td>
    </tr>

    <!-- BODY -->
    <tr>
      <td style="background:#13151e;padding:32px 40px;border:1px solid rgba(255,255,255,.07);border-top:none;border-bottom:none">

        <!-- KPI CARDS -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
          <tr>
            <!-- Faturamento -->
            <td width="48%" style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px 16px;vertical-align:top">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px">Faturamento</div>
              <div style="font-size:24px;font-weight:900;color:#ff5500;line-height:1">{$this_faturamento_brl}</div>
              <div style="margin-top:5px;font-size:12px;color:#9ca3af">{$this_fat_ant_brl} sem. ant. {$this_var_fat}</div>
            </td>
            <td width="4%"></td>
            <!-- Pedidos -->
            <td width="48%" style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px 16px;vertical-align:top">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px">Total de Pedidos</div>
              <div style="font-size:24px;font-weight:900;color:#3b82f6;line-height:1">{$this_total_pedidos}</div>
              <div style="margin-top:5px;font-size:12px;color:#9ca3af">{$this_ped_ant} pedidos sem. ant. {$this_var_ped}</div>
            </td>
          </tr>
          <tr><td colspan="3" style="height:12px"></td></tr>
          <tr>
            <!-- Ticket Médio -->
            <td width="48%" style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px 16px;vertical-align:top">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px">Ticket Médio</div>
              <div style="font-size:24px;font-weight:900;color:#f59e0b;line-height:1">{$this_ticket_brl}</div>
              <div style="margin-top:5px;font-size:12px;color:#9ca3af">por pedido na semana</div>
            </td>
            <td width="4%"></td>
            <!-- Margem Bruta -->
            <td width="48%" style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px 16px;vertical-align:top">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px">Margem Bruta Est.</div>
              <div style="font-size:24px;font-weight:900;color:#22c55e;line-height:1">{$this_margem_pct}%</div>
              <div style="margin-top:5px;font-size:12px;color:#9ca3af">{$this_margem_brl} (est. insumos)</div>
            </td>
          </tr>
        </table>

        <!-- FATURAMENTO POR DIA -->
        <div style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;overflow:hidden;margin-bottom:24px">
          <div style="padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07)">
            <span style="font-size:14px;font-weight:700;color:#f0f2f8">📊 Faturamento por Dia</span>
          </div>
          <table width="100%" style="border-collapse:collapse">
            <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
              <th style="text-align:left;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Dia</th>
              <th style="text-align:left;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Data</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Faturamento</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Pedidos</th>
            </tr>
            {$diasHtml}
          </table>
        </div>

        <!-- TOP 5 PRODUTOS -->
        <div style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;overflow:hidden;margin-bottom:24px">
          <div style="padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07)">
            <span style="font-size:14px;font-weight:700;color:#f0f2f8">🏆 Top 5 Produtos</span>
          </div>
          <table width="100%" style="border-collapse:collapse">
            <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
              <th style="text-align:left;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">#</th>
              <th style="text-align:left;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Produto</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Qtd.</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Receita</th>
            </tr>
            {$topHtml}
          </table>
        </div>

        <!-- PAGAMENTOS -->
        <div style="background:#1a1c27;border:1px solid rgba(255,255,255,.07);border-radius:12px;overflow:hidden;margin-bottom:24px">
          <div style="padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07)">
            <span style="font-size:14px;font-weight:700;color:#f0f2f8">💳 Por Forma de Pagamento</span>
          </div>
          <table width="100%" style="border-collapse:collapse">
            <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
              <th style="text-align:left;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Forma</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Pedidos</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">Total</th>
              <th style="text-align:right;padding:9px 14px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase">%</th>
            </tr>
            {$pagHtml}
          </table>
        </div>

        <!-- ALERTAS E PREVISÃO -->
        {$alertaHtml}
        {$previsaoHtml}

      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td style="background:#0d0f17;border:1px solid rgba(255,255,255,.07);border-top:none;border-radius:0 0 16px 16px;padding:20px 40px;text-align:center">
        <p style="color:#6b7280;font-size:12px;margin:0">
          Gerado automaticamente em {$dataGeracao} • Café Comunhão
        </p>
        <p style="color:#4b5563;font-size:11px;margin:6px 0 0">
          Para alterar as configurações de e-mail, acesse o painel administrativo.
        </p>
      </td>
    </tr>

  </table>
</td></tr>
</table>
</body>
</html>
HTML;

// Substitui os placeholders no template
$placeholders = [
    '{$this_faturamento_brl}' => brl($faturamento),
    '{$this_fat_ant_brl}'     => brl($faturamentoAnt),
    '{$this_var_fat}'         => varBadge($varFat),
    '{$this_total_pedidos}'   => (string)$totalPedidos,
    '{$this_ped_ant}'         => (string)$totalPedidosAnt,
    '{$this_var_ped}'         => varBadge($varPed),
    '{$this_ticket_brl}'      => brl($ticketMedio),
    '{$this_margem_pct}'      => (string)$margemPct,
    '{$this_margem_brl}'      => brl($margemValor),
];
$html = str_replace(array_keys($placeholders), array_values($placeholders), $html);

// ── Modo preview: exibe o HTML sem enviar ─────────────────────────────────────
if (!IS_CLI && isset($_GET['preview'])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// ── Envia o e-mail ────────────────────────────────────────────────────────────
$smtpConfig = [
    'host'      => _getConfigValue('email_smtp_host'),
    'port'      => (int)_getConfigValue('email_smtp_port', '587'),
    'user'      => _getConfigValue('email_smtp_user'),
    'pass'      => _getConfigValue('email_smtp_pass'),
    'from'      => _getConfigValue('email_smtp_from'),
    'from_nome' => _getConfigValue('email_smtp_from_nome', 'Café Comunhão'),
];
$destino = _getConfigValue('relatorio_email_destino');

if (empty($smtpConfig['host']) || empty($destino)) {
    $msg = 'SMTP ou destinatário não configurados. Configure em Admin > E-mail.';
    if (IS_CLI) { echo $msg . "\n"; exit(1); }
    http_response_code(500);
    exit($msg);
}

$assunto = '☕ Relatório Semanal — Café Comunhão (' . $periodoExibicao . ')';
$resultado = smtpSend($destino, $assunto, $html, $smtpConfig);

// ── Registra no log ───────────────────────────────────────────────────────────
try {
    $logStmt = $db->prepare("
        INSERT INTO material.totem_email_log
              (destinatario, assunto, status, mensagem, periodo_ini, periodo_fim)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $logStmt->execute([
        $destino,
        $assunto,
        $resultado === true ? 'enviado' : 'erro',
        $resultado === true ? null : (string)$resultado,
        $iniSemana,
        $fimSemana,
    ]);
} catch (Throwable) {}

// ── Saída ─────────────────────────────────────────────────────────────────────
if ($resultado === true) {
    $ok = "Relatório enviado com sucesso para {$destino}";
    if (IS_CLI) { echo $ok . "\n"; exit(0); }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'message' => $ok]);
} else {
    if (IS_CLI) { fwrite(STDERR, "Erro ao enviar: {$resultado}\n"); exit(1); }
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => (string)$resultado]);
}

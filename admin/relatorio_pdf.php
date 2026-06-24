<?php
session_start();
require_once '../config/db.php';
require_once 'api/auth.php';
requireAdmin();

$dataIni = $_GET['data_ini'] ?? date('Y-m-d');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');

function fmtBr($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}
function dataBr($d) {
    return date('d/m/Y', strtotime($d));
}

try {
    $db = getDB();

    // KPIs
    $stmtKpi = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status!='cancelado' THEN total END),0)  AS faturamento,
            COUNT(CASE WHEN status!='cancelado' THEN 1 END)                AS pedidos,
            COALESCE(AVG(CASE WHEN status!='cancelado' THEN total END),0)  AS ticket_medio,
            COUNT(CASE WHEN status='cancelado' THEN 1 END)                 AS cancelados
        FROM totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ?
    ");
    $stmtKpi->execute([$dataIni, $dataFim]);
    $kpi = $stmtKpi->fetch();

    // Itens
    $stmtIt = $db->prepare("
        SELECT COALESCE(SUM(ip.quantidade),0) AS total_itens
        FROM totem_itens_pedido ip
        JOIN totem_pedidos p ON p.id = ip.pedido_id
        WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
    ");
    $stmtIt->execute([$dataIni, $dataFim]);
    $itensRow = $stmtIt->fetch();

    // Faturamento por dia
    $stmtDias = $db->prepare("
        SELECT DATE(criado_em) AS dia, COUNT(*) AS pedidos, SUM(total) AS total
        FROM totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
        GROUP BY DATE(criado_em) ORDER BY dia
    ");
    $stmtDias->execute([$dataIni, $dataFim]);
    $porDia = $stmtDias->fetchAll();

    // Top produtos
    $stmtProd = $db->prepare("
        SELECT ip.nome_produto, SUM(ip.quantidade) AS qtd, SUM(ip.subtotal) AS receita,
               AVG(ip.preco_unitario) AS preco_medio
        FROM totem_itens_pedido ip
        JOIN totem_pedidos p ON p.id = ip.pedido_id
        WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
        GROUP BY ip.nome_produto
        ORDER BY qtd DESC LIMIT 10
    ");
    $stmtProd->execute([$dataIni, $dataFim]);
    $produtos = $stmtProd->fetchAll();

    // Por pagamento
    $stmtPag = $db->prepare("
        SELECT forma_pagamento, COUNT(*) AS qtd, SUM(total) AS total
        FROM totem_pedidos
        WHERE DATE(criado_em) BETWEEN ? AND ? AND status!='cancelado'
        GROUP BY forma_pagamento ORDER BY total DESC
    ");
    $stmtPag->execute([$dataIni, $dataFim]);
    $porPag = $stmtPag->fetchAll();

    // Taxas
    $taxaCred = (float)($db->query("SELECT COALESCE(valor,'2.5') FROM totem_configuracoes WHERE chave='taxa_credito'")->fetchColumn() ?: 2.5);
    $taxaDeb  = (float)($db->query("SELECT COALESCE(valor,'1.5') FROM totem_configuracoes WHERE chave='taxa_debito'")->fetchColumn() ?: 1.5);

    // Nome da loja
    $nomeLoja = $db->query("SELECT COALESCE(valor,'Minha Loja') FROM totem_configuracoes WHERE chave='nome_loja'")->fetchColumn() ?: 'Minha Loja';

    // Meta
    $metaFat = (float)($db->query("SELECT COALESCE(valor,'0') FROM totem_configuracoes WHERE chave='meta_fat_mes'")->fetchColumn() ?: 0);

    // Melhor dia
    $melhorDia = null;
    $melhorVal = 0;
    foreach ($porDia as $d) {
        if ((float)$d['total'] > $melhorVal) {
            $melhorVal = (float)$d['total'];
            $melhorDia = $d['dia'];
        }
    }

    // Cross-sell
    $stmtCs = $db->prepare("
        SELECT a.nome_produto AS prod_a, b.nome_produto AS prod_b, COUNT(*) AS ocorrencias
        FROM totem_itens_pedido a
        JOIN totem_itens_pedido b ON a.pedido_id=b.pedido_id AND a.produto_id<b.produto_id
        JOIN totem_pedidos p ON p.id=a.pedido_id
        WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status!='cancelado'
        GROUP BY a.nome_produto, b.nome_produto
        HAVING COUNT(*)>=2
        ORDER BY ocorrencias DESC LIMIT 3
    ");
    $stmtCs->execute([$dataIni, $dataFim]);
    $crossSell = $stmtCs->fetchAll();

} catch (PDOException $e) {
    die('<p>Erro ao gerar relatorio: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

// Calcular custos
$totalCusto = 0;
$custosArr = [];
foreach ($porPag as $p) {
    $taxa = match($p['forma_pagamento']) { 'credito' => $taxaCred, 'debito' => $taxaDeb, default => 0.0 };
    $custo = round((float)$p['total'] * $taxa / 100, 2);
    $totalCusto += $custo;
    $custosArr[] = array_merge($p, ['taxa' => $taxa, 'custo' => $custo, 'liquido' => round((float)$p['total'] - $custo, 2)]);
}

// Taxa cancelamento
$taxaCancel = (int)$kpi['pedidos'] + (int)$kpi['cancelados'] > 0
    ? round(((int)$kpi['cancelados'] / ((int)$kpi['pedidos'] + (int)$kpi['cancelados'])) * 100, 1)
    : 0;

// Recomendacoes
$recomendacoes = [];
if ($taxaCancel > 5) {
    $recomendacoes[] = ['tipo'=>'warn', 'txt'=>"Taxa de cancelamento de {$taxaCancel}% esta acima do ideal (5%). Investigue as causas mais frequentes."];
}
$econPix = 0;
foreach ($custosArr as $c) {
    if (!in_array($c['forma_pagamento'], ['pix','dinheiro'])) $econPix += $c['custo'];
}
if ($econPix > 0) {
    $recomendacoes[] = ['tipo'=>'info', 'txt'=>"Incentivar pagamentos em PIX economizaria " . fmtBr($econPix) . " em taxas no periodo."];
}
if (!empty($crossSell)) {
    $cs0 = $crossSell[0];
    $recomendacoes[] = ['tipo'=>'info', 'txt'=>"Cross-sell detectado: \"" . htmlspecialchars($cs0['prod_a']) . "\" + \"" . htmlspecialchars($cs0['prod_b']) . "\" aparecem juntos {$cs0['ocorrencias']}x. Considere criar um combo."];
}
if (!empty($produtos)) {
    $top = $produtos[0];
    $recomendacoes[] = ['tipo'=>'ok', 'txt'=>"Produto mais vendido: \"" . htmlspecialchars($top['nome_produto']) . "\" com {$top['qtd']} unidades. Garanta estoque e destaque no totem."];
}
if ($metaFat > 0 && (float)$kpi['faturamento'] < $metaFat * 0.7) {
    $pctM = round(((float)$kpi['faturamento'] / $metaFat) * 100);
    $recomendacoes[] = ['tipo'=>'warn', 'txt'=>"Meta de faturamento: " . fmtBr($metaFat) . " — atingiu {$pctM}% ate o momento. Acoes de marketing podem ajudar."];
}

$totalFat = (float)$kpi['faturamento'];
$diasSemana = ['Dom','Seg','Ter','Qua','Qui','Sex','Sab'];
$pagLabels = ['pix'=>'PIX','credito'=>'Credito','debito'=>'Debito','dinheiro'=>'Dinheiro'];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relatorio Executivo — <?= htmlspecialchars($nomeLoja) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1a1a1a;background:#fff}
@media print{
  body{font-size:11px}
  .no-print{display:none!important}
  .page-break{page-break-before:always;break-before:always}
  @page{size:A4 portrait;margin:15mm}
}
@media screen{
  body{background:#f0f0f0}
  .page{background:#fff;width:210mm;min-height:297mm;margin:20px auto;padding:18mm;box-shadow:0 2px 12px rgba(0,0,0,.15)}
  .print-btn{position:fixed;top:20px;right:20px;z-index:100}
}
.report-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:14px;border-bottom:3px solid #ff5500;margin-bottom:22px}
.report-logo{font-size:22px;font-weight:900;color:#ff5500;letter-spacing:-1px}
.report-meta{text-align:right}
.report-meta h1{font-size:15px;font-weight:700;color:#1a1a1a}
.report-meta p{font-size:10px;color:#666;margin-top:2px}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#ff5500;border-bottom:1px solid #eee;padding-bottom:5px;margin:18px 0 10px}
.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:18px}
.kpi-card{border:1px solid #e5e7eb;border-radius:7px;padding:10px;text-align:center}
.kpi-label{font-size:9px;color:#666;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px}
.kpi-value{font-size:15px;font-weight:900;color:#1a1a1a;line-height:1.1}
.kpi-sub{font-size:9px;color:#999;margin-top:2px}
table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:14px}
th{background:#f9fafb;text-align:left;padding:6px 8px;font-weight:700;font-size:9px;text-transform:uppercase;letter-spacing:.3px;color:#666;border-bottom:2px solid #e5e7eb}
td{padding:6px 8px;border-bottom:1px solid #f3f4f6;color:#1a1a1a}
tr:last-child td{border-bottom:none}
tr.total-row td{font-weight:700;border-top:2px solid #e5e7eb;background:#f9fafb}
.num{text-align:right;font-variant-numeric:tabular-nums}
.bar-wrap{height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden;display:inline-block;width:50px;vertical-align:middle;margin-left:4px}
.bar-fill{height:100%;border-radius:3px;background:#ff5500}
.insight-box{border:1px solid #e5e7eb;border-radius:7px;padding:12px;margin-bottom:10px;background:#fafafa}
.insight-box h4{font-size:11px;font-weight:700;margin-bottom:5px;color:#333}
.rec-list{list-style:none;display:flex;flex-direction:column;gap:6px}
.rec-list li{padding:8px 10px;border-radius:5px;font-size:10px;line-height:1.5;border-left:3px solid}
.rec-list li.ok{background:#f0fdf4;border-left-color:#22c55e}
.rec-list li.warn{background:#fffbeb;border-left-color:#f59e0b}
.rec-list li.info{background:#eff6ff;border-left-color:#3b82f6}
.report-footer{border-top:1px solid #eee;margin-top:20px;padding-top:8px;font-size:9px;color:#999;display:flex;justify-content:space-between}
.print-btn button{background:#ff5500;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer}
.print-btn button:hover{background:#e04500}
</style>
</head>
<body>

<div class="print-btn no-print">
  <button onclick="window.print()">Imprimir / Salvar PDF</button>
</div>

<!-- PAGINA 1 -->
<div class="page">
  <div class="report-header">
    <div>
      <div class="report-logo"><?= htmlspecialchars($nomeLoja) ?></div>
      <div style="font-size:10px;color:#666;margin-top:3px">Relatorio Executivo de Desempenho</div>
    </div>
    <div class="report-meta">
      <h1>Analise de Periodo</h1>
      <p>De <?= dataBr($dataIni) ?> ate <?= dataBr($dataFim) ?></p>
      <p style="margin-top:3px;color:#999">Gerado em <?= date('d/m/Y \a\s H:i') ?></p>
    </div>
  </div>

  <div class="section-title">Indicadores-Chave do Periodo</div>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-label">Faturamento</div>
      <div class="kpi-value" style="font-size:13px;color:#22c55e"><?= fmtBr($kpi['faturamento']) ?></div>
      <div class="kpi-sub">Sem cancelados</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Pedidos</div>
      <div class="kpi-value"><?= number_format((int)$kpi['pedidos']) ?></div>
      <div class="kpi-sub">Confirmados</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Ticket Medio</div>
      <div class="kpi-value" style="font-size:13px;color:#ff5500"><?= fmtBr($kpi['ticket_medio']) ?></div>
      <div class="kpi-sub">Por pedido</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Itens Vendidos</div>
      <div class="kpi-value"><?= number_format((int)$itensRow['total_itens']) ?></div>
      <div class="kpi-sub">Unidades</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Cancelados</div>
      <div class="kpi-value" style="color:<?= (int)$kpi['cancelados']>0?'#ef4444':'#22c55e' ?>"><?= (int)$kpi['cancelados'] ?></div>
      <div class="kpi-sub"><?= $taxaCancel ?>% do total</div>
    </div>
  </div>

  <?php if ($metaFat > 0): ?>
  <?php $pctMeta = min(100, round(((float)$kpi['faturamento'] / $metaFat) * 100)); ?>
  <div class="insight-box">
    <h4>Meta de Faturamento do Mes</h4>
    <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
      <div style="flex:1;height:9px;background:#e5e7eb;border-radius:4px;overflow:hidden">
        <div style="width:<?= $pctMeta ?>%;height:100%;background:<?= $pctMeta>=100?'#22c55e':($pctMeta>=70?'#f59e0b':'#ef4444') ?>;border-radius:4px"></div>
      </div>
      <div style="font-weight:700;font-size:12px"><?= $pctMeta ?>%</div>
      <div style="color:#666;font-size:11px"><?= fmtBr($kpi['faturamento']) ?> de <?= fmtBr($metaFat) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="section-title">Faturamento por Dia</div>
  <table>
    <thead>
      <tr>
        <th>Data</th><th>Dia</th><th class="num">Pedidos</th><th class="num">Faturamento</th><th class="num">% Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($porDia as $d):
        $pctDia = $totalFat > 0 ? round(((float)$d['total'] / $totalFat) * 100, 1) : 0;
        $dow = date('w', strtotime($d['dia']));
      ?>
      <tr>
        <td><?= dataBr($d['dia']) ?></td>
        <td><?= $diasSemana[$dow] ?></td>
        <td class="num"><?= $d['pedidos'] ?></td>
        <td class="num"><strong><?= fmtBr($d['total']) ?></strong></td>
        <td class="num"><?= $pctDia ?>%<span class="bar-wrap"><span class="bar-fill" style="width:<?= $pctDia ?>%"></span></span></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="2"><strong>TOTAL</strong></td>
        <td class="num"><strong><?= $kpi['pedidos'] ?></strong></td>
        <td class="num"><strong><?= fmtBr($kpi['faturamento']) ?></strong></td>
        <td class="num"><strong>100%</strong></td>
      </tr>
    </tbody>
  </table>
  <?php if ($melhorDia): ?>
  <div style="font-size:10px;color:#666;margin-bottom:14px">
    Melhor dia: <strong><?= dataBr($melhorDia) ?></strong> com <strong><?= fmtBr($melhorVal) ?></strong>
  </div>
  <?php endif; ?>

  <div class="section-title">Por Forma de Pagamento</div>
  <table>
    <thead>
      <tr><th>Metodo</th><th class="num">Pedidos</th><th class="num">Receita</th><th class="num">Taxa</th><th class="num">Custo R$</th><th class="num">Liquido</th></tr>
    </thead>
    <tbody>
      <?php $totalLiq = 0; foreach ($custosArr as $c): $totalLiq += $c['liquido']; ?>
      <tr>
        <td><?= $pagLabels[$c['forma_pagamento']] ?? htmlspecialchars($c['forma_pagamento']) ?></td>
        <td class="num"><?= $c['qtd'] ?></td>
        <td class="num"><?= fmtBr($c['total']) ?></td>
        <td class="num"><?= $c['taxa'] > 0 ? $c['taxa'].'%' : '—' ?></td>
        <td class="num" style="color:#ef4444"><?= $c['custo'] > 0 ? '-'.fmtBr($c['custo']) : '—' ?></td>
        <td class="num"><strong><?= fmtBr($c['liquido']) ?></strong></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td><strong>TOTAL</strong></td><td class="num"></td>
        <td class="num"><strong><?= fmtBr($kpi['faturamento']) ?></strong></td><td class="num"></td>
        <td class="num" style="color:#ef4444"><strong>-<?= fmtBr($totalCusto) ?></strong></td>
        <td class="num" style="color:#22c55e"><strong><?= fmtBr($totalLiq) ?></strong></td>
      </tr>
    </tbody>
  </table>

  <div class="report-footer">
    <span><?= htmlspecialchars($nomeLoja) ?> — Relatorio Executivo</span>
    <span>Pagina 1 de 2</span>
  </div>
</div>

<!-- PAGINA 2 -->
<div class="page page-break">
  <div class="report-header">
    <div>
      <div class="report-logo"><?= htmlspecialchars($nomeLoja) ?></div>
      <div style="font-size:10px;color:#666;margin-top:3px">Relatorio Executivo — Produtos e Recomendacoes</div>
    </div>
    <div class="report-meta">
      <h1>Top Produtos &amp; Insights</h1>
      <p>De <?= dataBr($dataIni) ?> ate <?= dataBr($dataFim) ?></p>
    </div>
  </div>

  <div class="section-title">Top 10 Produtos por Volume</div>
  <table>
    <thead>
      <tr><th>#</th><th>Produto</th><th class="num">Qtd</th><th class="num">Preco Medio</th><th class="num">Receita</th><th class="num">% Receita</th></tr>
    </thead>
    <tbody>
      <?php foreach ($produtos as $i => $p):
        $pctRec = $totalFat > 0 ? round(((float)$p['receita'] / $totalFat) * 100, 1) : 0;
      ?>
      <tr>
        <td style="color:#999;font-weight:700"><?= $i+1 ?></td>
        <td><strong><?= htmlspecialchars($p['nome_produto']) ?></strong></td>
        <td class="num"><?= $p['qtd'] ?>x</td>
        <td class="num"><?= fmtBr($p['preco_medio']) ?></td>
        <td class="num"><?= fmtBr($p['receita']) ?></td>
        <td class="num"><?= $pctRec ?>%<span class="bar-wrap"><span class="bar-fill" style="width:<?= $pctRec ?>%;background:#3b82f6"></span></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($crossSell)): ?>
  <div class="section-title">Pares Mais Pedidos Juntos (Cross-sell)</div>
  <table>
    <thead>
      <tr><th>Produto A</th><th>Produto B</th><th class="num">Ocorrencias</th><th>Acao Sugerida</th></tr>
    </thead>
    <tbody>
      <?php foreach ($crossSell as $cs): ?>
      <tr>
        <td><?= htmlspecialchars($cs['prod_a']) ?></td>
        <td><?= htmlspecialchars($cs['prod_b']) ?></td>
        <td class="num"><strong><?= $cs['ocorrencias'] ?>x</strong></td>
        <td style="color:#666">Criar combo com desconto</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="section-title">Recomendacoes Automaticas</div>
  <?php if (!empty($recomendacoes)): ?>
  <ul class="rec-list">
    <?php foreach ($recomendacoes as $r): ?>
    <li class="<?= $r['tipo'] ?>"><?= $r['txt'] ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p style="color:#666;font-size:11px">Nenhuma recomendacao critica para o periodo analisado. Negocio em boa forma!</p>
  <?php endif; ?>

  <div style="margin-top:18px">
    <div class="section-title">Resumo Executivo</div>
    <div class="insight-box">
      <h4>Analise do periodo de <?= dataBr($dataIni) ?> a <?= dataBr($dataFim) ?></h4>
      <p style="margin-top:6px;line-height:1.6;color:#444;font-size:11px">
        No periodo analisado, a loja registrou <strong><?= fmtBr($kpi['faturamento']) ?></strong> em faturamento com
        <strong><?= number_format((int)$kpi['pedidos']) ?> pedidos</strong> confirmados.
        O ticket medio foi de <strong><?= fmtBr($kpi['ticket_medio']) ?></strong> por pedido.
        <?php if ($totalCusto > 0): ?>
        As taxas de cartao representaram <strong><?= fmtBr($totalCusto) ?></strong> em custos operacionais.
        <?php endif; ?>
        <?php if ($melhorDia): ?>
        O melhor dia de faturamento foi <strong><?= dataBr($melhorDia) ?></strong> com <strong><?= fmtBr($melhorVal) ?></strong>.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="report-footer">
    <span><?= htmlspecialchars($nomeLoja) ?> — Relatorio Executivo</span>
    <span>Pagina 2 de 2 · Gerado automaticamente pelo sistema Totem</span>
  </div>
</div>

</body>
</html>

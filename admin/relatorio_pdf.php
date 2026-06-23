<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ./');
    exit;
}
require_once '../config/db.php';

$dataIni = $_GET['data_ini'] ?? date('Y-m-d');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');

// Basic sanity
$dataIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni) ? $dataIni : date('Y-m-d');
$dataFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim) ? $dataFim : date('Y-m-d');

$autoprint = isset($_GET['print']) && $_GET['print'] === '1';

try {
    $db = getDB();

    // Config
    $cfgStmt = $db->query("SELECT chave, valor FROM totem_configuracoes WHERE chave IN ('loja_nome','loja_cnpj','loja_endereco','loja_telefone')");
    $cfgRows = $cfgStmt ? $cfgStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $lojaNome     = $cfgRows['loja_nome']     ?? 'Café Comunhão';
    $lojaCnpj     = $cfgRows['loja_cnpj']     ?? '';
    $lojaEndereco = $cfgRows['loja_endereco'] ?? '';
    $lojaTel      = $cfgRows['loja_telefone'] ?? '';

    // KPIs
    $kpi = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status != 'cancelado' THEN total  ELSE 0 END),0) AS faturamento,
            COALESCE(COUNT(CASE WHEN status != 'cancelado' THEN 1    END),0)        AS pedidos,
            COALESCE(AVG(CASE WHEN status != 'cancelado' THEN total  END),0)        AS ticket_medio,
            COALESCE(SUM(CASE WHEN status = 'cancelado'  THEN 1     ELSE 0 END),0) AS cancelados
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ?
    ");
    $kpi->execute([$dataIni, $dataFim]);
    $kpiData = $kpi->fetch();

    // Itens totais
    $itens = $db->prepare("
        SELECT COALESCE(SUM(ip.quantidade),0) AS qtd
          FROM totem_itens_pedido ip
          JOIN totem_pedidos p ON p.id = ip.pedido_id
         WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status != 'cancelado'
    ");
    $itens->execute([$dataIni, $dataFim]);
    $totalItens = (int)$itens->fetchColumn();

    // Por pagamento
    $pag = $db->prepare("
        SELECT forma_pagamento, COUNT(*) AS qtd, SUM(total) AS total
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY forma_pagamento ORDER BY total DESC
    ");
    $pag->execute([$dataIni, $dataFim]);
    $porPagamento = $pag->fetchAll();

    // Top produtos
    $top = $db->prepare("
        SELECT ip.nome_produto, SUM(ip.quantidade) AS qtd, SUM(ip.subtotal) AS total
          FROM totem_itens_pedido ip
          JOIN totem_pedidos p ON p.id = ip.pedido_id
         WHERE DATE(p.criado_em) BETWEEN ? AND ? AND p.status != 'cancelado'
         GROUP BY ip.nome_produto
         ORDER BY qtd DESC LIMIT 20
    ");
    $top->execute([$dataIni, $dataFim]);
    $topProdutos = $top->fetchAll();

    // Faturamento por dia
    $dias = $db->prepare("
        SELECT DATE(criado_em) AS dia, COUNT(*) AS pedidos, SUM(total) AS total
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY DATE(criado_em) ORDER BY dia ASC
    ");
    $dias->execute([$dataIni, $dataFim]);
    $porDia = $dias->fetchAll();

    // Hora pico
    $horaQ = $db->prepare("
        SELECT EXTRACT(HOUR FROM criado_em)::int AS hora, COUNT(*) AS qtd
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY hora ORDER BY hora
    ");
    $horaQ->execute([$dataIni, $dataFim]);
    $horaPico = $horaQ->fetchAll();

    // Por origem
    $ori = $db->prepare("
        SELECT COALESCE(origem,'totem') AS origem, COUNT(*) AS qtd, SUM(total) AS total
          FROM totem_pedidos
         WHERE DATE(criado_em) BETWEEN ? AND ? AND status != 'cancelado'
         GROUP BY origem ORDER BY total DESC
    ");
    $ori->execute([$dataIni, $dataFim]);
    $porOrigem = $ori->fetchAll();

    // Pedidos completos (para tabela)
    $lista = $db->prepare("
        SELECT p.numero_pedido AS numero, p.criado_em, p.tipo_consumo,
               p.forma_pagamento, p.status, p.total, p.origem, p.cpf,
               string_agg(i.nome_produto || ' x' || i.quantidade, ', ' ORDER BY i.id) AS itens
          FROM totem_pedidos p
          JOIN totem_itens_pedido i ON i.pedido_id = p.id
         WHERE DATE(p.criado_em) BETWEEN ? AND ?
         GROUP BY p.id
         ORDER BY p.criado_em DESC
         LIMIT 500
    ");
    $lista->execute([$dataIni, $dataFim]);
    $pedidosLista = $lista->fetchAll();

} catch (PDOException $e) {
    die('Erro de banco: ' . htmlspecialchars($e->getMessage()));
}

function fmt(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
function fmtDate(string $d): string {
    return (new DateTime($d))->format('d/m/Y');
}
function fmtDT(string $d): string {
    return (new DateTime($d))->format('d/m/Y H:i');
}

$titlePeriodo = $dataIni === $dataFim
    ? 'Relatório de ' . fmtDate($dataIni)
    : 'Relatório de ' . fmtDate($dataIni) . ' a ' . fmtDate($dataFim);

$statusLabel = ['aguardando'=>'Aguardando','preparando'=>'Preparando','pronto'=>'Pronto','entregue'=>'Entregue','cancelado'=>'Cancelado'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($titlePeriodo) ?> — <?= htmlspecialchars($lojaNome) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--accent:#cc3300;--light:#f8f8f8;--border:#d0d0d0}
body{font-family:Arial,sans-serif;font-size:11px;color:#111;background:#fff;line-height:1.4}

/* ── Toolbar (screen only) ── */
.toolbar{background:#1e2029;color:#f0f2f8;padding:10px 20px;display:flex;align-items:center;gap:14px;position:sticky;top:0;z-index:10}
.toolbar h1{font-size:15px;flex:1}
.toolbar button,.toolbar a{background:var(--accent);color:#fff;border:none;border-radius:7px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;font-family:Arial,sans-serif}
.toolbar a.sec{background:#444}
@media print{.toolbar,.no-print{display:none!important}}

/* ── Report body ── */
.report{max-width:940px;margin:0 auto;padding:20px}
@media print{.report{max-width:100%;padding:4mm 6mm}}

.report-header{text-align:center;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid var(--accent)}
.report-header h1{font-size:20px;color:var(--accent);margin-bottom:4px}
.report-header .sub{font-size:11px;color:#555}
.report-period{font-size:14px;font-weight:bold;margin-top:8px}

.section{margin-bottom:22px}
.section-title{font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;color:var(--accent);border-bottom:1px solid var(--accent);padding-bottom:4px;margin-bottom:10px}

/* KPIs */
.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
@media print{.kpi-grid{grid-template-columns:repeat(5,1fr)}}
.kpi-card{border:1px solid var(--border);border-radius:6px;padding:10px;text-align:center;background:var(--light)}
.kpi-label{font-size:9px;text-transform:uppercase;letter-spacing:0.4px;color:#666;margin-bottom:4px}
.kpi-value{font-size:18px;font-weight:bold;color:var(--accent)}
.kpi-sub{font-size:9px;color:#888;margin-top:2px}

/* Tables */
table{width:100%;border-collapse:collapse;font-size:10px}
th{background:#2a2a2a;color:#fff;text-align:left;padding:5px 8px;font-size:10px}
td{padding:4px 8px;border-bottom:1px solid #ebebeb;vertical-align:top}
tr:nth-child(even) td{background:var(--light)}
.num{text-align:right}
.center{text-align:center}

/* Two-col layout for print */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:600px){.two-col{grid-template-columns:1fr}}

/* Bar chart */
.bar-wrap{margin:6px 0}
.bar-row{display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:9px}
.bar-label{min-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-track{flex:1;background:#e8e8e8;border-radius:2px;height:12px;position:relative}
.bar-fill{height:100%;background:var(--accent);border-radius:2px;min-width:2px}
.bar-val{min-width:60px;text-align:right;color:#444}

.badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:bold}
.badge-entregue{background:#d1fae5;color:#065f46}
.badge-pronto{background:#dbeafe;color:#1e3a8a}
.badge-preparando{background:#fef9c3;color:#713f12}
.badge-aguardando{background:#f3f4f6;color:#374151}
.badge-cancelado{background:#fee2e2;color:#991b1b}

.footer-stamp{margin-top:24px;padding-top:10px;border-top:1px solid var(--border);font-size:9px;color:#aaa;text-align:center}
</style>
</head>
<body>

<div class="toolbar no-print">
  <h1><?= htmlspecialchars($titlePeriodo) ?></h1>
  <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
  <a href="./" class="sec">← Voltar ao Admin</a>
</div>

<div class="report">

  <div class="report-header">
    <h1><?= htmlspecialchars($lojaNome) ?></h1>
    <?php if ($lojaCnpj): ?><div class="sub">CNPJ: <?= htmlspecialchars($lojaCnpj) ?></div><?php endif; ?>
    <?php if ($lojaEndereco): ?><div class="sub"><?= htmlspecialchars($lojaEndereco) ?></div><?php endif; ?>
    <?php if ($lojaTel): ?><div class="sub">Tel: <?= htmlspecialchars($lojaTel) ?></div><?php endif; ?>
    <div class="report-period"><?= htmlspecialchars($titlePeriodo) ?></div>
    <div class="sub">Gerado em <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($_SESSION['admin_nome'] ?? 'Sistema') ?></div>
  </div>

  <!-- KPIs -->
  <div class="section">
    <div class="section-title">Resumo do Período</div>
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Faturamento</div>
        <div class="kpi-value"><?= fmt((float)$kpiData['faturamento']) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Pedidos</div>
        <div class="kpi-value"><?= number_format((int)$kpiData['pedidos'], 0, ',', '.') ?></div>
        <?php if ((int)$kpiData['cancelados']): ?><div class="kpi-sub"><?= $kpiData['cancelados'] ?> cancelados</div><?php endif; ?>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Ticket Médio</div>
        <div class="kpi-value"><?= fmt((float)$kpiData['ticket_medio']) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Itens Vendidos</div>
        <div class="kpi-value"><?= number_format($totalItens, 0, ',', '.') ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Médio / Pedido</div>
        <div class="kpi-value"><?= (int)$kpiData['pedidos'] > 0 ? number_format($totalItens / (int)$kpiData['pedidos'], 1, ',', '.') : '—' ?></div>
        <div class="kpi-sub">itens/pedido</div>
      </div>
    </div>
  </div>

  <div class="two-col">

    <!-- Por pagamento -->
    <div class="section">
      <div class="section-title">Por Forma de Pagamento</div>
      <?php if ($porPagamento):
        $maxPag = max(array_column($porPagamento, 'total'));
      ?>
      <?php foreach ($porPagamento as $p): ?>
      <div class="bar-row">
        <div class="bar-label"><?= htmlspecialchars(strtoupper($p['forma_pagamento'])) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= round(($p['total']/$maxPag)*100) ?>%"></div></div>
        <div class="bar-val"><?= fmt((float)$p['total']) ?> (<?= $p['qtd'] ?>)</div>
      </div>
      <?php endforeach; ?>
      <?php else: ?><p style="color:#aaa;font-size:10px">Sem dados</p><?php endif; ?>
    </div>

    <!-- Por origem -->
    <div class="section">
      <div class="section-title">Por Origem</div>
      <?php if ($porOrigem):
        $maxOri = max(array_column($porOrigem, 'total'));
      ?>
      <?php foreach ($porOrigem as $o): ?>
      <div class="bar-row">
        <div class="bar-label"><?= htmlspecialchars(strtoupper($o['origem'])) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= round(($o['total']/$maxOri)*100) ?>%"></div></div>
        <div class="bar-val"><?= fmt((float)$o['total']) ?> (<?= $o['qtd'] ?>)</div>
      </div>
      <?php endforeach; ?>
      <?php else: ?><p style="color:#aaa;font-size:10px">Sem dados</p><?php endif; ?>
    </div>

  </div>

  <!-- Top produtos -->
  <div class="section">
    <div class="section-title">Produtos Mais Vendidos (Top 20)</div>
    <?php if ($topProdutos):
      $maxTop = max(array_column($topProdutos, 'qtd'));
    ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Produto</th>
          <th class="num">Qtd</th>
          <th class="num">Receita</th>
          <th>Volume</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($topProdutos as $i => $p): ?>
        <tr>
          <td class="center" style="color:#888"><?= $i+1 ?></td>
          <td><?= htmlspecialchars($p['nome_produto']) ?></td>
          <td class="num" style="font-weight:bold"><?= $p['qtd'] ?></td>
          <td class="num"><?= fmt((float)$p['total']) ?></td>
          <td style="width:120px">
            <div class="bar-track" style="height:8px">
              <div class="bar-fill" style="width:<?= round(($p['qtd']/$maxTop)*100) ?>%"></div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p style="color:#aaa;font-size:10px">Sem dados</p><?php endif; ?>
  </div>

  <!-- Por dia -->
  <?php if (count($porDia) > 1): ?>
  <div class="section">
    <div class="section-title">Faturamento por Dia</div>
    <?php $maxDia = max(array_column($porDia, 'total')); ?>
    <table>
      <thead><tr><th>Data</th><th class="num">Pedidos</th><th class="num">Faturamento</th><th>Barra</th></tr></thead>
      <tbody>
      <?php foreach ($porDia as $d): ?>
        <tr>
          <td><?= fmtDate($d['dia']) ?></td>
          <td class="num"><?= $d['pedidos'] ?></td>
          <td class="num" style="font-weight:bold"><?= fmt((float)$d['total']) ?></td>
          <td style="width:160px">
            <div class="bar-track" style="height:8px">
              <div class="bar-fill" style="width:<?= round(($d['total']/$maxDia)*100) ?>%"></div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Hora pico -->
  <?php if ($horaPico): ?>
  <div class="section">
    <div class="section-title">Horário de Pico</div>
    <?php $maxHora = max(array_column($horaPico, 'qtd')); ?>
    <div style="display:flex;gap:4px;align-items:flex-end;height:60px;padding-bottom:14px;border-bottom:1px solid #ddd">
      <?php for ($h = 6; $h <= 23; $h++):
        $row = array_filter($horaPico, fn($x) => (int)$x['hora'] === $h);
        $row = reset($row);
        $qtd = $row ? (int)$row['qtd'] : 0;
        $pct = $maxHora > 0 ? round(($qtd/$maxHora)*80) : 0;
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px">
        <div style="width:100%;background:<?= $qtd > 0 ? 'var(--accent)' : '#e8e8e8' ?>;height:<?= $pct ?>px;border-radius:2px 2px 0 0;min-height:<?= $qtd>0?'3':'0' ?>px"></div>
        <div style="font-size:7px;color:#888;writing-mode:vertical-lr;transform:rotate(180deg)"><?= sprintf('%02d', $h) ?>h</div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Lista de pedidos -->
  <?php if ($pedidosLista): ?>
  <div class="section">
    <div class="section-title">Lista de Pedidos (<?= count($pedidosLista) ?>)</div>
    <table>
      <thead>
        <tr>
          <th>Nº</th>
          <th>Data/Hora</th>
          <th>Consumo</th>
          <th>Pagamento</th>
          <th>Status</th>
          <th>Origem</th>
          <th class="num">Total</th>
          <th>Itens</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pedidosLista as $p): ?>
        <tr>
          <td style="font-weight:bold">#<?= htmlspecialchars($p['numero']) ?></td>
          <td><?= fmtDT($p['criado_em']) ?></td>
          <td class="center"><?= $p['tipo_consumo'] === 'local' ? '🍽️' : '🛍️' ?></td>
          <td><?= htmlspecialchars(strtoupper($p['forma_pagamento'])) ?></td>
          <td><span class="badge badge-<?= $p['status'] ?>"><?= $statusLabel[$p['status']] ?? $p['status'] ?></span></td>
          <td><?= htmlspecialchars($p['origem']) ?></td>
          <td class="num" style="font-weight:bold"><?= fmt((float)$p['total']) ?></td>
          <td style="font-size:9px;color:#555;max-width:220px"><?= htmlspecialchars($p['itens']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="footer-stamp">
    Sistema Totem de Pedidos — Relatório gerado em <?= date('d/m/Y \à\s H:i') ?>
    <?php if ($lojaCnpj): ?> — <?= htmlspecialchars($lojaNome) ?> / CNPJ <?= htmlspecialchars($lojaCnpj) ?><?php endif; ?>
  </div>

</div>

<?php if ($autoprint): ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>
</body>
</html>

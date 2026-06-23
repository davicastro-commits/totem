<?php
// ── Auth ──────────────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ../');
    exit;
}

$adminNome = $_SESSION['admin_nome'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'operador';
$csrfToken = csrfToken();

// ── Data ──────────────────────────────────────────────────────────────────
$errors = [];

try {
    $db = getDB();

    // ── KPIs do dia ──────────────────────────────────────────────────────
    $kpi = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN status <> 'cancelado' THEN total ELSE 0 END), 0)  AS faturamento,
            COUNT(CASE WHEN status <> 'cancelado' THEN 1 END)                         AS pedidos,
            COUNT(CASE WHEN status NOT IN ('entregue','cancelado') THEN 1 END)        AS em_aberto
        FROM material.totem_pedidos
        WHERE DATE(criado_em) = CURRENT_DATE
    ")->fetch();

    $fat        = (float)($kpi['faturamento'] ?? 0);
    $qtdPedidos = (int)($kpi['pedidos'] ?? 0);
    $emAberto   = (int)($kpi['em_aberto'] ?? 0);
    $ticketMedio = $qtdPedidos > 0 ? $fat / $qtdPedidos : 0;

    // ── Top 10 produtos da semana ─────────────────────────────────────────
    $top10 = $db->query("
        SELECT
            p.nome,
            SUM(i.quantidade)                           AS qtd,
            SUM(i.quantidade * i.preco_unitario)        AS total
        FROM material.totem_itens_pedido i
        JOIN material.totem_produtos     p ON p.id = i.produto_id
        JOIN material.totem_pedidos      pe ON pe.id = i.pedido_id
        WHERE pe.status <> 'cancelado'
          AND pe.criado_em >= (CURRENT_DATE - INTERVAL '6 days')
          AND pe.criado_em <  (CURRENT_DATE + INTERVAL '1 day')
        GROUP BY p.id, p.nome
        ORDER BY qtd DESC
        LIMIT 10
    ")->fetchAll();

    // ── Faturamento por forma de pagamento hoje ────────────────────────────
    $pagamentos = $db->query("
        SELECT
            COALESCE(forma_pagamento, 'outros') AS metodo,
            COUNT(*)                             AS qtd,
            SUM(total)                           AS total
        FROM material.totem_pedidos
        WHERE DATE(criado_em) = CURRENT_DATE
          AND status <> 'cancelado'
        GROUP BY metodo
        ORDER BY total DESC
    ")->fetchAll();

    $maxPag = 0;
    foreach ($pagamentos as $r) {
        if ((float)$r['total'] > $maxPag) $maxPag = (float)$r['total'];
    }

    // ── Últimos 7 dias ────────────────────────────────────────────────────
    $sete = $db->query("
        SELECT
            DATE(criado_em)                                                           AS dia,
            COUNT(CASE WHEN status <> 'cancelado' THEN 1 END)                        AS pedidos,
            COALESCE(SUM(CASE WHEN status <> 'cancelado' THEN total ELSE 0 END), 0)  AS total
        FROM material.totem_pedidos
        WHERE criado_em >= (CURRENT_DATE - INTERVAL '6 days')
          AND criado_em <  (CURRENT_DATE + INTERVAL '1 day')
        GROUP BY dia
        ORDER BY dia DESC
    ")->fetchAll();

    // ── Alertas de estoque baixo ──────────────────────────────────────────
    $alertas = [];
    try {
        $alertas = $db->query("
            SELECT nome, estoque_atual, estoque_minimo, unidade
            FROM material.totem_insumos
            WHERE ativo = true
              AND estoque_atual <= estoque_minimo
            ORDER BY (estoque_atual / NULLIF(estoque_minimo, 0)) ASC, nome ASC
            LIMIT 20
        ")->fetchAll();
    } catch (Throwable) {
        // table may not exist yet
    }

} catch (Throwable $e) {
    $errors[] = 'Erro ao carregar dados: ' . htmlspecialchars($e->getMessage());
    $fat = $qtdPedidos = $emAberto = $ticketMedio = 0;
    $top10 = $pagamentos = $sete = $alertas = [];
    $maxPag = 0;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function brl(float $v): string {
    return 'R$&nbsp;' . number_format($v, 2, ',', '.');
}

function brls(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

$METODO_LABEL = [
    'pix'     => 'PIX',
    'credito' => 'Crédito',
    'debito'  => 'Débito',
    'dinheiro'=> 'Dinheiro',
    'outros'  => 'Outros',
];
$METODO_COLOR = [
    'pix'     => '#22c55e',
    'credito' => '#3b82f6',
    'debito'  => '#8b5cf6',
    'dinheiro'=> '#f59e0b',
    'outros'  => '#6b7280',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="refresh" content="60">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<title>Dashboard — Café Comunhão</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;--card2:#22253a;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;--acc-gl:rgba(255,85,0,.12);
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;--purple:#8b5cf6;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;--text4:#4b5563;
}
html,body{min-height:100vh;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── TOPBAR ─────────────────────────────────────────────────────── */
.topbar{
  display:flex;align-items:center;gap:16px;
  padding:0 28px;height:58px;
  background:var(--surf);border-bottom:1px solid var(--border);
  position:sticky;top:0;z-index:100;
}
.topbar-back{
  display:flex;align-items:center;gap:7px;
  color:var(--text3);text-decoration:none;font-size:13px;font-weight:500;
  padding:6px 12px;border-radius:8px;
  transition:all .15s;border:1px solid transparent;
}
.topbar-back:hover{color:var(--text);background:var(--card);border-color:var(--border)}
.topbar-divider{width:1px;height:22px;background:var(--border)}
.topbar-title{font-size:16px;font-weight:800;color:var(--text)}
.topbar-badge{
  font-size:11px;font-weight:700;padding:3px 9px;
  background:var(--acc-gl);color:var(--acc);
  border:1px solid rgba(255,85,0,.25);border-radius:999px;
  letter-spacing:.3px;
}
.topbar-right{
  margin-left:auto;display:flex;align-items:center;gap:16px;
  font-size:13px;color:var(--text3);
}
.topbar-refresh{
  display:flex;align-items:center;gap:6px;
  color:var(--text3);text-decoration:none;font-size:12px;font-weight:500;
  padding:5px 11px;border-radius:8px;border:1px solid var(--border2);
  background:var(--card);transition:all .15s;cursor:pointer;
}
.topbar-refresh:hover{color:var(--text);border-color:var(--text4)}
.pulse-dot{
  display:inline-block;width:7px;height:7px;
  background:var(--green);border-radius:50%;
  animation:pulse 2s infinite;
}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.topbar-clock{font-size:13px;font-weight:600;color:var(--text2);font-variant-numeric:tabular-nums}

/* ── MAIN ───────────────────────────────────────────────────────── */
.main{max-width:1440px;margin:0 auto;padding:28px 28px 60px}

/* ── ALERT BANNER ───────────────────────────────────────────────── */
.alert-banner{
  display:flex;align-items:flex-start;gap:14px;
  padding:16px 20px;margin-bottom:24px;
  background:rgba(239,68,68,.08);
  border:1px solid rgba(239,68,68,.25);
  border-radius:14px;
}
.alert-icon{font-size:20px;flex-shrink:0;margin-top:1px}
.alert-content{}
.alert-title{font-size:14px;font-weight:700;color:var(--red);margin-bottom:4px}
.alert-body{font-size:13px;color:var(--text2);line-height:1.6}
.alert-names{color:var(--text);font-weight:500}

/* ── SECTION LABEL ──────────────────────────────────────────────── */
.section-label{
  font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:1px;color:var(--text4);
  margin-bottom:12px;margin-top:4px;
}

/* ── KPI GRID ───────────────────────────────────────────────────── */
.kpi-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:16px;
  margin-bottom:28px;
}
@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.kpi-grid{grid-template-columns:1fr}}

.kpi-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:16px;padding:22px 24px;
  position:relative;overflow:hidden;
  transition:border-color .15s, transform .15s;
}
.kpi-card:hover{border-color:var(--border2);transform:translateY(-1px)}
.kpi-card::before{
  content:'';position:absolute;
  top:-40px;right:-40px;width:100px;height:100px;
  border-radius:50%;
  background:var(--c,var(--acc));opacity:.06;
  pointer-events:none;
}
.kpi-icon{
  font-size:20px;margin-bottom:14px;
  width:40px;height:40px;border-radius:10px;
  background:color-mix(in srgb, var(--c,var(--acc)) 12%, transparent);
  display:flex;align-items:center;justify-content:center;
}
.kpi-label{
  font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:.6px;color:var(--text3);margin-bottom:8px;
}
.kpi-value{
  font-size:30px;font-weight:900;
  color:var(--c,var(--acc));
  line-height:1;margin-bottom:6px;
  font-variant-numeric:tabular-nums;
}
.kpi-sub{font-size:12px;color:var(--text4)}

/* ── GRID LAYOUTS ───────────────────────────────────────────────── */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
.grid-3{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px}
@media(max-width:960px){.grid-2,.grid-3{grid-template-columns:1fr}}

/* ── CARD ───────────────────────────────────────────────────────── */
.card{
  background:var(--card);border:1px solid var(--border);
  border-radius:16px;overflow:hidden;
  margin-bottom:20px;
}
.card-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);
}
.card-head h3{
  font-size:13px;font-weight:700;text-transform:uppercase;
  letter-spacing:.5px;color:var(--text2);
  display:flex;align-items:center;gap:8px;
}
.card-head h3 span.icon{font-size:15px}
.card-meta{font-size:11px;color:var(--text4)}

/* ── TABLE ──────────────────────────────────────────────────────── */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{
  text-align:left;padding:10px 16px;
  color:var(--text3);font-size:11px;font-weight:700;
  text-transform:uppercase;letter-spacing:.5px;
  border-bottom:1px solid var(--border);
  white-space:nowrap;
}
.tbl th.r,.tbl td.r{text-align:right}
.tbl td{
  padding:12px 16px;border-bottom:1px solid var(--border);
  vertical-align:middle;
}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.tbl .val{font-weight:700;color:var(--acc-l);font-variant-numeric:tabular-nums}
.tbl .dim{color:var(--text3);font-size:12px}
.rank{
  display:inline-flex;align-items:center;justify-content:center;
  width:22px;height:22px;border-radius:6px;
  background:var(--card2);color:var(--text3);
  font-size:11px;font-weight:700;
}
.rank.gold{background:rgba(245,158,11,.15);color:var(--gold)}
.rank.silver{background:rgba(156,163,175,.12);color:#9ca3af}
.rank.bronze{background:rgba(180,120,70,.12);color:#b47846}
.rank-pct{
  height:4px;border-radius:2px;
  background:var(--acc);opacity:.6;
  margin-top:4px;
}

/* ── BAR CHART CSS ──────────────────────────────────────────────── */
.bar-chart{padding:18px 20px;display:flex;flex-direction:column;gap:14px}
.bar-row{display:flex;align-items:center;gap:12px}
.bar-label{
  width:80px;font-size:12px;font-weight:600;
  color:var(--text2);text-align:right;
  flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.bar-track{
  flex:1;height:10px;background:var(--card2);
  border-radius:5px;overflow:hidden;
}
.bar-fill{
  height:100%;border-radius:5px;
  transition:width .6s cubic-bezier(.4,0,.2,1);
  min-width:2px;
}
.bar-info{
  display:flex;flex-direction:column;
  min-width:120px;
}
.bar-val{font-size:12px;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums}
.bar-cnt{font-size:11px;color:var(--text4)}

/* ── 7-DAY TABLE ────────────────────────────────────────────────── */
.day-table{}

/* ── EMPTY STATE ────────────────────────────────────────────────── */
.empty{
  text-align:center;padding:40px 20px;
  color:var(--text3);font-size:13px;
}
.empty-icon{font-size:32px;margin-bottom:10px;display:block}

/* ── ERROR BOX ──────────────────────────────────────────────────── */
.err-box{
  background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);
  border-radius:12px;padding:14px 18px;color:var(--red);
  font-size:13px;margin-bottom:20px;
}

/* ── REFRESH NOTICE ─────────────────────────────────────────────── */
.refresh-notice{
  text-align:center;font-size:11px;color:var(--text4);
  margin-top:40px;padding-top:20px;border-top:1px solid var(--border);
}

/* ── BADGE ──────────────────────────────────────────────────────── */
.badge{
  display:inline-flex;align-items:center;
  font-size:11px;font-weight:700;padding:3px 9px;
  border-radius:999px;white-space:nowrap;
}
.badge-low{background:rgba(239,68,68,.12);color:var(--red)}
.badge-ok{background:rgba(34,197,94,.12);color:var(--green)}

/* ── RESPONSIVE tweaks ──────────────────────────────────────────── */
@media(max-width:768px){
  .main{padding:16px}
  .kpi-value{font-size:24px}
  .topbar{padding:0 16px}
}
</style>
</head>
<body>

<!-- ── TOPBAR ─────────────────────────────────────────────────────── -->
<header class="topbar">
  <a href="../" class="topbar-back">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    Admin
  </a>
  <div class="topbar-divider"></div>
  <span class="topbar-title">Dashboard</span>
  <span class="topbar-badge">Café Comunhão</span>

  <div class="topbar-right">
    <a href="" class="topbar-refresh" title="Atualizar agora">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
        <path d="M13.65 2.35A8 8 0 1 0 15 8h-2a6 6 0 1 1-1.05-3.35L10 7h5V2l-1.35.35z" fill="currentColor"/>
      </svg>
      Atualizar
    </a>
    <span><span class="pulse-dot"></span></span>
    <span class="topbar-clock" id="js-clock"></span>
  </div>
</header>

<!-- ── MAIN CONTENT ───────────────────────────────────────────────── -->
<main class="main">

<?php if ($errors): ?>
<?php foreach ($errors as $e): ?>
<div class="err-box">⚠️ <?= $e ?></div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── ESTOQUE BAIXO ALERT ─────────────────────────────────────── -->
<?php if (!empty($alertas)): ?>
<div class="alert-banner">
  <div class="alert-icon">⚠️</div>
  <div class="alert-content">
    <div class="alert-title"><?= count($alertas) ?> insumo<?= count($alertas) > 1 ? 's' : '' ?> com estoque baixo</div>
    <div class="alert-body">
      <span class="alert-names"><?php
        $nomes = array_map(fn($a) => htmlspecialchars($a['nome']), $alertas);
        echo implode(', ', array_slice($nomes, 0, 8));
        if (count($alertas) > 8) echo ' e mais ' . (count($alertas) - 8) . '...';
      ?></span>
      — estoque abaixo do mínimo.
      <a href="../estoque/" style="color:var(--acc);text-decoration:none;font-weight:600;margin-left:6px">
        Gerenciar estoque →
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── KPIs DO DIA ────────────────────────────────────────────── -->
<div class="section-label">KPIs do dia — <?= date('d/m/Y') ?></div>
<div class="kpi-grid">

  <div class="kpi-card" style="--c:var(--green)">
    <div class="kpi-icon">💰</div>
    <div class="kpi-label">Faturamento hoje</div>
    <div class="kpi-value" title="<?= brls($fat) ?>"><?= brl($fat) ?></div>
    <div class="kpi-sub">Pedidos não cancelados</div>
  </div>

  <div class="kpi-card" style="--c:var(--blue)">
    <div class="kpi-icon">📋</div>
    <div class="kpi-label">Pedidos hoje</div>
    <div class="kpi-value"><?= number_format($qtdPedidos) ?></div>
    <div class="kpi-sub">Confirmados no dia</div>
  </div>

  <div class="kpi-card" style="--c:var(--acc)">
    <div class="kpi-icon">🎯</div>
    <div class="kpi-label">Ticket médio</div>
    <div class="kpi-value"><?= brl($ticketMedio) ?></div>
    <div class="kpi-sub">Total ÷ pedidos</div>
  </div>

  <div class="kpi-card" style="--c:<?= $emAberto > 0 ? 'var(--gold)' : 'var(--text3)' ?>">
    <div class="kpi-icon">⏳</div>
    <div class="kpi-label">Em aberto agora</div>
    <div class="kpi-value"><?= number_format($emAberto) ?></div>
    <div class="kpi-sub">Aguardando / preparando / pronto</div>
  </div>

</div>

<!-- ── GRID: Top produtos + Pagamentos ───────────────────────────── -->
<div class="grid-2">

  <!-- Top 10 produtos da semana -->
  <div class="card">
    <div class="card-head">
      <h3><span class="icon">🏆</span> Top 10 produtos — últimos 7 dias</h3>
      <span class="card-meta"><?= date('d/m', strtotime('-6 days')) ?> – <?= date('d/m') ?></span>
    </div>
    <?php if (empty($top10)): ?>
    <div class="empty"><span class="empty-icon">📦</span>Sem vendas no período</div>
    <?php else:
      $maxQtd = (float)($top10[0]['qtd'] ?? 1);
      if ($maxQtd <= 0) $maxQtd = 1;
    ?>
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Produto</th>
          <th class="r">Qtd</th>
          <th class="r">Faturado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($top10 as $idx => $row):
          $pct = ((float)$row['qtd'] / $maxQtd) * 100;
          $rankClass = $idx === 0 ? 'gold' : ($idx === 1 ? 'silver' : ($idx === 2 ? 'bronze' : ''));
        ?>
        <tr>
          <td><span class="rank <?= $rankClass ?>"><?= $idx + 1 ?></span></td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($row['nome']) ?></div>
            <div class="rank-pct" style="width:<?= round($pct) ?>%"></div>
          </td>
          <td class="r val"><?= number_format((float)$row['qtd']) ?></td>
          <td class="r val"><?= brl((float)$row['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Faturamento por forma de pagamento hoje -->
  <div class="card">
    <div class="card-head">
      <h3><span class="icon">💳</span> Pagamentos — hoje</h3>
      <span class="card-meta"><?= date('d/m/Y') ?></span>
    </div>
    <?php if (empty($pagamentos)): ?>
    <div class="empty"><span class="empty-icon">💳</span>Sem pagamentos hoje</div>
    <?php else: ?>
    <div class="bar-chart">
      <?php foreach ($pagamentos as $r):
        $metodo = strtolower(trim((string)$r['metodo']));
        $label  = $METODO_LABEL[$metodo] ?? ucfirst($metodo);
        $cor    = $METODO_COLOR[$metodo] ?? '#6b7280';
        $pct    = $maxPag > 0 ? ((float)$r['total'] / $maxPag) * 100 : 0;
      ?>
      <div class="bar-row">
        <div class="bar-label"><?= htmlspecialchars($label) ?></div>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?= round($pct) ?>%;background:<?= $cor ?>"></div>
        </div>
        <div class="bar-info">
          <span class="bar-val"><?= brl((float)$r['total']) ?></span>
          <span class="bar-cnt"><?= (int)$r['qtd'] ?> pedido<?= (int)$r['qtd'] != 1 ? 's' : '' ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ── Últimos 7 dias ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px">
  <div class="card-head">
    <h3><span class="icon">📅</span> Faturamento — últimos 7 dias</h3>
    <span class="card-meta">Hoje: <?= date('d/m/Y') ?></span>
  </div>
  <?php if (empty($sete)): ?>
  <div class="empty"><span class="empty-icon">📅</span>Sem dados no período</div>
  <?php else:
    $maxDia = 0;
    foreach ($sete as $r) { if ((float)$r['total'] > $maxDia) $maxDia = (float)$r['total']; }
    if ($maxDia <= 0) $maxDia = 1;
  ?>
  <table class="tbl">
    <thead>
      <tr>
        <th>Data</th>
        <th>Dia</th>
        <th class="r">Pedidos</th>
        <th class="r">Total faturado</th>
        <th style="width:200px">Proporção</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sete as $r):
        $dt  = new DateTime($r['dia']);
        $pct = ((float)$r['total'] / $maxDia) * 100;
        $isToday = ($r['dia'] === date('Y-m-d'));
      ?>
      <tr>
        <td>
          <span style="font-weight:600<?= $isToday ? ';color:var(--acc)' : '' ?>">
            <?= $dt->format('d/m/Y') ?>
          </span>
          <?php if ($isToday): ?>
          <span class="badge badge-ok" style="margin-left:6px;font-size:10px">Hoje</span>
          <?php endif; ?>
        </td>
        <td class="dim"><?= ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][$dt->format('w')] ?></td>
        <td class="r val"><?= number_format((int)$r['pedidos']) ?></td>
        <td class="r val"><?= brl((float)$r['total']) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="bar-track" style="flex:1;height:8px">
              <div class="bar-fill" style="width:<?= round($pct) ?>%;background:var(--acc)"></div>
            </div>
            <span style="font-size:11px;color:var(--text4);width:34px;text-align:right"><?= round($pct) ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── DETALHES ESTOQUE BAIXO ──────────────────────────────────── -->
<?php if (!empty($alertas)): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-head">
    <h3><span class="icon">📦</span> Insumos com estoque baixo</h3>
    <a href="../estoque/" style="font-size:12px;color:var(--acc);text-decoration:none;font-weight:600">
      Gerenciar →
    </a>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>Insumo</th>
        <th class="r">Estoque atual</th>
        <th class="r">Mínimo</th>
        <th class="r">Unidade</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($alertas as $a):
        $atual = (float)$a['estoque_atual'];
        $min   = (float)$a['estoque_minimo'];
        $zero  = ($atual <= 0);
      ?>
      <tr>
        <td style="font-weight:600"><?= htmlspecialchars($a['nome']) ?></td>
        <td class="r" style="color:<?= $zero ? 'var(--red)' : 'var(--gold)' ?>;font-weight:700">
          <?= number_format($atual, 2, ',', '.') ?>
        </td>
        <td class="r dim"><?= number_format($min, 2, ',', '.') ?></td>
        <td class="r dim"><?= htmlspecialchars($a['unidade']) ?></td>
        <td>
          <?php if ($zero): ?>
          <span class="badge badge-low">Zerado</span>
          <?php else: ?>
          <span class="badge badge-low">Abaixo do mínimo</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── REFRESH NOTICE ─────────────────────────────────────────── -->
<div class="refresh-notice">
  <span class="pulse-dot"></span>
  Página atualiza automaticamente a cada 60 segundos.
  Última carga: <?= date('H:i:s') ?>
  &nbsp;·&nbsp;
  <a href="" style="color:var(--text3);text-decoration:none">Atualizar agora</a>
  &nbsp;·&nbsp;
  <a href="../" style="color:var(--text3);text-decoration:none">← Admin</a>
</div>

</main>

<script>
'use strict';

// ── Clock ──────────────────────────────────────────────────────────
const clockEl = document.getElementById('js-clock');
function tickClock() {
  clockEl.textContent = new Date().toLocaleTimeString('pt-BR', {
    hour:'2-digit', minute:'2-digit', second:'2-digit'
  });
}
tickClock();
setInterval(tickClock, 1000);

// ── Countdown to refresh ───────────────────────────────────────────
(function() {
  let secs = 60;
  const notice = document.querySelector('.refresh-notice');
  if (!notice) return;
  setInterval(() => {
    secs--;
    if (secs <= 0) secs = 60;
    const refreshLink = notice.querySelector('a[href=""]');
    if (refreshLink) {
      const txt = secs > 1 ? 'Atualizar agora (' + secs + 's)' : 'Atualizando...';
      refreshLink.textContent = 'Atualizar agora';
    }
  }, 1000);
})();
</script>

</body>
</html>

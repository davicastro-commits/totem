<?php
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = $_SESSION['admin_nome'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'operador';

// ── Helpers PHP ───────────────────────────────────────────────────────────
function brl(float $v): string { return 'R$&nbsp;' . number_format($v,2,',','.'); }
function brls(float $v): string { return 'R$ ' . number_format($v,2,',','.'); }
function pct(float $novo, float $ant): ?float {
    if ($ant <= 0) return null;
    return round((($novo - $ant) / $ant) * 100, 1);
}

$erros = [];
try {
    $db = getDB();

    // ── KPIs hoje ──────────────────────────────────────────────────────
    $kpi = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS fat,
            COUNT(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END)               AS ped,
            COUNT(CASE WHEN status NOT IN ('entregue','cancelado') THEN 1 END)                          AS aberto
        FROM material.totem_pedidos WHERE DATE(criado_em) = CURRENT_DATE
    ")->fetch();
    $fatHoje  = (float)$kpi['fat'];
    $pedHoje  = (int)$kpi['ped'];
    $aberto   = (int)$kpi['aberto'];
    $ticket   = $pedHoje > 0 ? $fatHoje / $pedHoje : 0;

    // ── Comparativo ontem ──────────────────────────────────────────────
    $ontem = $db->query("
        SELECT COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS fat,
               COUNT(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END) AS ped
        FROM material.totem_pedidos WHERE DATE(criado_em) = CURRENT_DATE - 1
    ")->fetch();
    $fatOntem = (float)$ontem['fat'];
    $pedOntem = (int)$ontem['ped'];

    // ── Margem bruta estimada hoje ─────────────────────────────────────
    $custoHoje = 0;
    try {
        $custoHoje = (float)$db->query("
            SELECT COALESCE(SUM(ip.quantidade * ft.quantidade * ins.custo_medio), 0)
            FROM material.totem_itens_pedido ip
            JOIN material.totem_pedidos p     ON p.id = ip.pedido_id
            JOIN material.totem_ficha_tecnica ft ON ft.produto_id = ip.produto_id
            JOIN material.totem_insumos ins   ON ins.id = ft.insumo_id
            WHERE DATE(p.criado_em) = CURRENT_DATE
              AND p.status NOT IN ('cancelado','aguardando_pagamento')
        ")->fetchColumn();
    } catch (Throwable) {}
    $margemValor = max(0, $fatHoje - $custoHoje);
    $margemPct   = $fatHoje > 0 ? round(($margemValor / $fatHoje) * 100, 1) : 0;

    // ── Projeção do mês ────────────────────────────────────────────────
    $mesDados = $db->query("
        SELECT COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS fat,
               EXTRACT(DAY FROM CURRENT_DATE)::int AS dias_passados,
               EXTRACT(DAY FROM (DATE_TRUNC('month',CURRENT_DATE)+INTERVAL '1 month'-INTERVAL '1 day'))::int AS dias_mes
        FROM material.totem_pedidos
        WHERE DATE_TRUNC('month',criado_em) = DATE_TRUNC('month',CURRENT_DATE)
    ")->fetch();
    $fatMes       = (float)$mesDados['fat'];
    $diasPassados = max(1, (int)$mesDados['dias_passados']);
    $diasMes      = (int)$mesDados['dias_mes'];
    $projecaoMes  = ($fatMes / $diasPassados) * $diasMes;

    $fatMesAnt = (float)$db->query("
        SELECT COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0)
        FROM material.totem_pedidos
        WHERE DATE_TRUNC('month',criado_em) = DATE_TRUNC('month',CURRENT_DATE) - INTERVAL '1 month'
    ")->fetchColumn();
    $varMes = pct($projecaoMes, $fatMesAnt);

    // ── Previsão de estoque acabar ─────────────────────────────────────
    $previsao = [];
    try {
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
                SUM(m.quantidade) FILTER (WHERE m.tipo='saida' AND m.criado_em >= CURRENT_DATE - 7) / 7.0,
                0
            ) > 0
            ORDER BY (i.estoque_atual / NULLIF(
                COALESCE(SUM(m.quantidade) FILTER (WHERE m.tipo='saida' AND m.criado_em >= CURRENT_DATE - 7)/7.0,0)
            ,0)) ASC
            LIMIT 8
        ")->fetchAll();
    } catch (Throwable) {}

    // ── Heatmap vendas últimos 30 dias ─────────────────────────────────
    $heatRaw  = [];
    $heatMax  = 1;
    try {
        $rows = $db->query("
            SELECT EXTRACT(DOW FROM criado_em)::int AS dow,
                   EXTRACT(HOUR FROM criado_em)::int AS hora,
                   COUNT(*) AS cnt
            FROM material.totem_pedidos
            WHERE criado_em >= CURRENT_DATE - 29
              AND status NOT IN ('cancelado','aguardando_pagamento')
            GROUP BY dow, hora
        ")->fetchAll();
        foreach ($rows as $r) {
            $heatRaw[(int)$r['dow']][(int)$r['hora']] = (int)$r['cnt'];
            if ((int)$r['cnt'] > $heatMax) $heatMax = (int)$r['cnt'];
        }
    } catch (Throwable) {}

    // ── Top produtos (7 dias) ──────────────────────────────────────────
    $top10 = $db->query("
        SELECT p.nome, SUM(i.quantidade) AS qtd, SUM(i.quantidade*i.preco_unitario) AS total
        FROM material.totem_itens_pedido i
        JOIN material.totem_produtos p ON p.id = i.produto_id
        JOIN material.totem_pedidos pe ON pe.id = i.pedido_id
        WHERE pe.status NOT IN ('cancelado','aguardando_pagamento')
          AND pe.criado_em >= CURRENT_DATE - 6
        GROUP BY p.id, p.nome ORDER BY qtd DESC LIMIT 10
    ")->fetchAll();

    // ── Pagamentos hoje ────────────────────────────────────────────────
    $pagamentos = $db->query("
        SELECT COALESCE(forma_pagamento,'outros') AS metodo,
               COUNT(*) AS qtd, SUM(total) AS total
        FROM material.totem_pedidos
        WHERE DATE(criado_em) = CURRENT_DATE
          AND status NOT IN ('cancelado','aguardando_pagamento')
        GROUP BY metodo ORDER BY total DESC
    ")->fetchAll();
    $maxPag = max(1, (float)($pagamentos[0]['total'] ?? 1));

    // ── Últimos 7 dias ─────────────────────────────────────────────────
    $sete = $db->query("
        SELECT DATE(criado_em) AS dia,
               COUNT(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN 1 END) AS ped,
               COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','aguardando_pagamento') THEN total END),0) AS total
        FROM material.totem_pedidos
        WHERE criado_em >= CURRENT_DATE - 6
        GROUP BY dia ORDER BY dia DESC
    ")->fetchAll();
    $maxDia = max(1, (float)($sete[0]['total'] ?? 1));
    foreach ($sete as $r) { if ((float)$r['total'] > $maxDia) $maxDia = (float)$r['total']; }

    // ── Alertas estoque ────────────────────────────────────────────────
    $alertas = $db->query("
        SELECT nome, estoque_atual, estoque_minimo, unidade
        FROM material.totem_insumos
        WHERE ativo = true AND estoque_atual <= estoque_minimo
        ORDER BY (estoque_atual / NULLIF(estoque_minimo,0)) ASC LIMIT 20
    ")->fetchAll();

} catch (Throwable $e) {
    $erros[] = $e->getMessage();
    $fatHoje=$pedHoje=$aberto=$ticket=$custoHoje=$margemValor=$margemPct=0;
    $fatMes=$projecaoMes=$fatMesAnt=$varMes=$fatOntem=$pedOntem=0;
    $diasPassados=$diasMes=1;
    $top10=$pagamentos=$sete=$alertas=$previsao=$heatRaw=[];
    $heatMax=1; $maxPag=1; $maxDia=1;
}

$PAG_LABEL = ['pix'=>'PIX','credito'=>'Crédito','debito'=>'Débito','dinheiro'=>'Dinheiro','outros'=>'Outros'];
$PAG_COLOR = ['pix'=>'#22c55e','credito'=>'#3b82f6','debito'=>'#8b5cf6','dinheiro'=>'#f59e0b','outros'=>'#6b7280'];
$DIAS_PT   = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
$HORAS_SHOW = range(6, 21); // 6h–21h
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="refresh" content="60">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<title>Dashboard — Café Comunhão</title>
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
html,body{min-height:100vh;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* TOPBAR */
.topbar{display:flex;align-items:center;gap:14px;padding:0 24px;height:56px;background:var(--surf);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar a{color:var(--text3);font-size:13px;font-weight:500;text-decoration:none;padding:5px 10px;border-radius:7px;transition:all .15s}
.topbar a:hover{background:var(--card);color:var(--text)}
.topbar-title{font-size:16px;font-weight:800}
.topbar-badge{font-size:11px;font-weight:700;padding:3px 9px;background:var(--acc-gl);color:var(--acc);border:1px solid rgba(255,85,0,.25);border-radius:999px}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:13px;color:var(--text3)}
.pulse{display:inline-block;width:7px;height:7px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.clock{font-weight:600;font-variant-numeric:tabular-nums}

/* LAYOUT */
.main{max-width:1440px;margin:0 auto;padding:24px 24px 60px}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);margin-bottom:12px;margin-top:4px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.grid-3i{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
@media(max-width:1100px){.grid-4{grid-template-columns:repeat(2,1fr)}.grid-3i{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.grid-4,.grid-3i{grid-template-columns:1fr}.grid-2{grid-template-columns:1fr}}

/* CARD */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.card-head h3{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);display:flex;align-items:center;gap:7px}
.card-meta{font-size:11px;color:var(--text4)}
.card-link{font-size:12px;color:var(--acc);text-decoration:none;font-weight:600}
.card-link:hover{text-decoration:underline}

/* KPI */
.kpi{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px 22px;position:relative;overflow:hidden;transition:border-color .15s,transform .15s}
.kpi:hover{border-color:var(--border2);transform:translateY(-1px)}
.kpi::before{content:'';position:absolute;top:-35px;right:-35px;width:90px;height:90px;border-radius:50%;background:var(--c,var(--acc));opacity:.06;pointer-events:none}
.kpi-icon{font-size:18px;width:38px;height:38px;border-radius:10px;background:color-mix(in srgb,var(--c,var(--acc)) 12%,transparent);display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text3);margin-bottom:6px}
.kpi-value{font-size:28px;font-weight:900;color:var(--c,var(--acc));line-height:1;margin-bottom:6px;font-variant-numeric:tabular-nums}
.kpi-sub{font-size:12px;color:var(--text4)}
.kpi-delta{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px;margin-top:6px}
.delta-up{background:rgba(34,197,94,.12);color:var(--green)}
.delta-dn{background:rgba(239,68,68,.12);color:var(--red)}
.delta-nc{background:rgba(107,114,128,.12);color:var(--text3)}

/* INSIGHT STRIP */
.insight-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
@media(max-width:900px){.insight-strip{grid-template-columns:1fr}}
.insight{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px 22px;display:flex;flex-direction:column;gap:6px}
.insight-header{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3)}
.insight-value{font-size:26px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.insight-sub{font-size:12px;color:var(--text4)}
.insight-bar{height:6px;background:var(--card2);border-radius:3px;overflow:hidden;margin-top:4px}
.insight-bar-fill{height:100%;border-radius:3px}

/* ALERT */
.alert-banner{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;margin-bottom:20px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:14px}
.alert-banner .icon{font-size:18px;flex-shrink:0;margin-top:1px}
.alert-title{font-size:13px;font-weight:700;color:var(--red);margin-bottom:3px}
.alert-body{font-size:13px;color:var(--text2);line-height:1.5}

/* TABLE */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{text-align:left;padding:9px 14px;color:var(--text3);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.tbl th.r,.tbl td.r{text-align:right}
.tbl td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.val{font-weight:700;color:var(--acc-l);font-variant-numeric:tabular-nums}
.dim{color:var(--text3);font-size:12px}
.rank{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:var(--card2);color:var(--text3);font-size:11px;font-weight:700}
.rank.g{background:rgba(245,158,11,.15);color:var(--gold)}
.rank.s{background:rgba(156,163,175,.12);color:#9ca3af}
.rank.b{background:rgba(180,120,70,.12);color:#b47846}
.rank-bar{height:4px;border-radius:2px;background:var(--acc);opacity:.5;margin-top:3px}

/* BAR CHART */
.bar-chart{padding:16px 18px;display:flex;flex-direction:column;gap:12px}
.bar-row{display:flex;align-items:center;gap:10px}
.bar-lbl{width:72px;font-size:12px;font-weight:600;color:var(--text2);text-align:right;flex-shrink:0}
.bar-track{flex:1;height:10px;background:var(--card2);border-radius:5px;overflow:hidden}
.bar-fill{height:100%;border-radius:5px;transition:width .5s}
.bar-info{min-width:110px;display:flex;flex-direction:column}
.bar-val{font-size:12px;font-weight:700;font-variant-numeric:tabular-nums}
.bar-cnt{font-size:11px;color:var(--text4)}

/* HEATMAP */
.heatmap-wrap{padding:16px 18px;overflow-x:auto}
.heatmap{border-collapse:collapse;font-size:11px;width:100%}
.heatmap th{padding:3px 5px;color:var(--text3);font-weight:600;text-align:center;white-space:nowrap}
.heatmap td{padding:2px}
.hm-cell{width:100%;height:28px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:transparent;transition:all .2s;cursor:default;min-width:28px}
.hm-cell:hover{color:#fff !important}
.hm-day{color:var(--text2);font-weight:600;white-space:nowrap;padding-right:10px !important;text-align:right}
.hm-legend{display:flex;align-items:center;gap:6px;margin-top:10px;font-size:11px;color:var(--text3)}
.hm-legend-bar{display:flex;gap:2px}
.hm-swatch{width:20px;height:12px;border-radius:3px}

/* PREVISAO TABLE */
.days-badge{display:inline-flex;align-items:center;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px}
.days-ok{background:rgba(34,197,94,.12);color:var(--green)}
.days-warn{background:rgba(245,158,11,.12);color:var(--gold)}
.days-crit{background:rgba(239,68,68,.12);color:var(--red)}

/* FOOTER */
.footer{text-align:center;font-size:11px;color:var(--text4);margin-top:32px;padding-top:18px;border-top:1px solid var(--border)}
</style>
</head>
<body>

<header class="topbar">
  <a href="../">← Admin</a>
  <span style="color:var(--border2)">|</span>
  <span class="topbar-title">Dashboard</span>
  <span class="topbar-badge">Café Comunhão</span>
  <div class="topbar-right">
    <span><span class="pulse"></span></span>
    <span class="clock" id="clk"></span>
    <a href="" style="font-size:12px;padding:5px 10px;border:1px solid var(--border2);border-radius:7px;color:var(--text3);text-decoration:none">↻ Atualizar</a>
  </div>
</header>

<main class="main">

<?php foreach ($erros as $e): ?>
<div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;color:var(--red);font-size:13px;margin-bottom:16px">⚠️ <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if (!empty($alertas)): ?>
<div class="alert-banner">
  <div class="icon">⚠️</div>
  <div>
    <div class="alert-title"><?= count($alertas) ?> insumo<?= count($alertas)>1?'s':'' ?> com estoque baixo</div>
    <div class="alert-body">
      <?= implode(', ', array_map(fn($a)=>htmlspecialchars($a['nome']), array_slice($alertas,0,8))) ?>
      <?= count($alertas)>8?' e mais '.(count($alertas)-8).'...':'' ?>
      — <a href="../estoque/" style="color:var(--acc);font-weight:600;text-decoration:none">Gerenciar estoque →</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ─── KPIs do dia ────────────────────────────────────────────────── -->
<div class="section-label">Hoje — <?= date('d/m/Y') ?></div>
<div class="grid-4" style="margin-bottom:24px">

  <?php
  $dFat  = pct($fatHoje, $fatOntem);
  $dPed  = pct($pedHoje, $pedOntem);
  function deltaBadge(?float $d): string {
    if ($d === null) return '';
    $cls = $d >= 0 ? 'delta-up' : 'delta-dn';
    $sgn = $d >= 0 ? '↑' : '↓';
    return '<span class="kpi-delta '.$cls.'">'.$sgn.' '.abs($d).'% vs ontem</span>';
  }
  ?>

  <div class="kpi" style="--c:var(--green)">
    <div class="kpi-icon">💰</div>
    <div class="kpi-label">Faturamento</div>
    <div class="kpi-value"><?= brl($fatHoje) ?></div>
    <?= deltaBadge($dFat) ?>
  </div>

  <div class="kpi" style="--c:var(--blue)">
    <div class="kpi-icon">📋</div>
    <div class="kpi-label">Pedidos</div>
    <div class="kpi-value"><?= $pedHoje ?></div>
    <?= deltaBadge($dPed) ?>
  </div>

  <div class="kpi" style="--c:var(--acc)">
    <div class="kpi-icon">🎯</div>
    <div class="kpi-label">Ticket médio</div>
    <div class="kpi-value"><?= brl($ticket) ?></div>
    <div class="kpi-sub">total ÷ pedidos</div>
  </div>

  <div class="kpi" style="--c:<?= $aberto>0?'var(--gold)':'var(--text3)' ?>">
    <div class="kpi-icon">⏳</div>
    <div class="kpi-label">Em aberto agora</div>
    <div class="kpi-value"><?= $aberto ?></div>
    <div class="kpi-sub">aguardando / preparando / pronto</div>
  </div>

</div>

<!-- ─── INSIGHTS: Margem · Projeção do mês · Comparativo ─────────── -->
<div class="section-label">Insights financeiros</div>
<div class="insight-strip">

  <!-- Margem bruta estimada -->
  <div class="insight">
    <div class="insight-header">📊 Margem bruta estimada — hoje</div>
    <div class="insight-value" style="color:var(--green)"><?= brl($margemValor) ?></div>
    <div class="insight-sub">
      Faturamento <?= brls($fatHoje) ?> − Custo insumos <?= brls($custoHoje) ?>
    </div>
    <div class="insight-bar" style="margin-top:8px" title="Margem <?= $margemPct ?>%">
      <div class="insight-bar-fill" style="width:<?= min(100,$margemPct) ?>%;background:var(--green)"></div>
    </div>
    <div style="font-size:12px;color:var(--green);font-weight:700;margin-top:4px"><?= $margemPct ?>% de margem</div>
    <?php if ($custoHoje <= 0): ?>
    <div style="font-size:11px;color:var(--text3);margin-top:4px">* Adicione custo médio nos insumos para calcular</div>
    <?php endif; ?>
  </div>

  <!-- Projeção do mês -->
  <?php
  $progPct = $diasMes > 0 ? round(($diasPassados / $diasMes) * 100) : 0;
  $varMesCls = $varMes === null ? '' : ($varMes >= 0 ? 'delta-up' : 'delta-dn');
  $varMesSgn = $varMes === null ? '' : ($varMes >= 0 ? '↑' : '↓');
  ?>
  <div class="insight">
    <div class="insight-header">📈 Projeção — <?= date('F/Y', strtotime('first day of this month')) ?></div>
    <div class="insight-value" style="color:var(--blue)"><?= brl($projecaoMes) ?></div>
    <div class="insight-sub">
      Realizado: <?= brls($fatMes) ?> (<?= $diasPassados ?>/<?= $diasMes ?> dias)
    </div>
    <div class="insight-bar" style="margin-top:8px">
      <div class="insight-bar-fill" style="width:<?= $progPct ?>%;background:var(--blue)"></div>
    </div>
    <?php if ($varMes !== null): ?>
    <span class="kpi-delta <?= $varMesCls ?>" style="margin-top:6px;font-size:11px">
      <?= $varMesSgn ?> <?= abs($varMes) ?>% vs mês anterior (<?= brls($fatMesAnt) ?>)
    </span>
    <?php endif; ?>
  </div>

  <!-- Média diária -->
  <?php $mediaDiaria = $diasPassados > 0 ? $fatMes / $diasPassados : 0; ?>
  <div class="insight">
    <div class="insight-header">📅 Média diária — este mês</div>
    <div class="insight-value" style="color:var(--gold)"><?= brl($mediaDiaria) ?></div>
    <div class="insight-sub">Baseado nos últimos <?= $diasPassados ?> dias</div>
    <?php if ($mediaDiaria > 0 && $fatHoje > 0):
      $pctDia = round(($fatHoje / $mediaDiaria - 1) * 100, 1);
      $cls2 = $pctDia >= 0 ? 'delta-up' : 'delta-dn';
      $sgn2 = $pctDia >= 0 ? '↑' : '↓';
    ?>
    <span class="kpi-delta <?= $cls2 ?>" style="margin-top:10px;font-size:11px">
      <?= $sgn2 ?> <?= abs($pctDia) ?>% vs média do mês
    </span>
    <?php endif; ?>
    <div style="margin-top:12px;font-size:12px;color:var(--text3)">
      Se manter: <strong style="color:var(--text)"><?= brl($mediaDiaria * ($diasMes - $diasPassados) + $fatMes) ?></strong> no fechamento
    </div>
  </div>

</div>

<!-- ─── HEATMAP: Vendas por hora × dia da semana ─────────────────── -->
<div class="section-label">Mapa de calor — pedidos por hora (últimos 30 dias)</div>
<div class="card" style="margin-bottom:24px">
  <div class="card-head">
    <h3>🌡️ Quando você vende mais</h3>
    <span class="card-meta">Últimos 30 dias · 6h–21h</span>
  </div>
  <div class="heatmap-wrap">
    <?php if (empty($heatRaw)): ?>
    <div style="text-align:center;padding:30px;color:var(--text3);font-size:13px">Sem dados suficientes ainda</div>
    <?php else: ?>
    <table class="heatmap">
      <thead>
        <tr>
          <th></th>
          <?php foreach ($HORAS_SHOW as $h): ?>
          <th><?= $h ?>h</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        // Reorganiza para começar na Seg (1) e terminar no Dom (0)
        $ordemDias = [1,2,3,4,5,6,0];
        foreach ($ordemDias as $dow):
          $temDados = false;
          foreach ($HORAS_SHOW as $h) { if (isset($heatRaw[$dow][$h])) { $temDados=true; break; } }
        ?>
        <tr>
          <td class="hm-day"><?= $DIAS_PT[$dow] ?></td>
          <?php foreach ($HORAS_SHOW as $h):
            $cnt  = $heatRaw[$dow][$h] ?? 0;
            $int  = $heatMax > 0 ? $cnt / $heatMax : 0;
            // Cor: de azul-escuro (0) até laranja-quente (1)
            if ($int <= 0) {
              $bg = 'rgba(255,255,255,0.04)';
            } elseif ($int < 0.25) {
              $bg = 'rgba(59,130,246,' . round(0.15 + $int*0.8, 2) . ')';
            } elseif ($int < 0.6) {
              $bg = 'rgba(245,158,11,' . round(0.3 + $int*0.7, 2) . ')';
            } else {
              $bg = 'rgba(255,85,0,' . round(0.5 + $int*0.5, 2) . ')';
            }
            $title = $cnt > 0 ? $cnt . ' pedido' . ($cnt!=1?'s':'') . ' às ' . $h . 'h' . ($temDados?' ('.$DIAS_PT[$dow].')':'') : '';
          ?>
          <td title="<?= $title ?>">
            <div class="hm-cell" style="background:<?= $bg ?>;color:<?= $cnt>0?'rgba(255,255,255,.7)':'transparent' ?>">
              <?= $cnt > 0 ? $cnt : '' ?>
            </div>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="hm-legend">
      <span>Menos</span>
      <div class="hm-legend-bar">
        <?php
        $swatches = [
          'rgba(255,255,255,.04)',
          'rgba(59,130,246,.3)',
          'rgba(59,130,246,.6)',
          'rgba(245,158,11,.5)',
          'rgba(245,158,11,.8)',
          'rgba(255,85,0,.7)',
          'rgba(255,85,0,1)',
        ];
        foreach ($swatches as $s): ?>
        <div class="hm-swatch" style="background:<?= $s ?>"></div>
        <?php endforeach; ?>
      </div>
      <span>Mais vendas</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ─── PREVISÃO DE ESTOQUE ACABAR ───────────────────────────────── -->
<?php if (!empty($previsao)): ?>
<div class="section-label">Previsão de estoque</div>
<div class="card" style="margin-bottom:24px">
  <div class="card-head">
    <h3>🔮 Dias restantes com base no consumo atual</h3>
    <a href="../estoque/" class="card-link">Gerenciar →</a>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>Insumo</th>
        <th class="r">Estoque atual</th>
        <th class="r">Consumo/dia</th>
        <th class="r">Dias restantes</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($previsao as $p):
        $dias = $p['consumo_dia'] > 0 ? floor($p['estoque_atual'] / $p['consumo_dia']) : 999;
        $cls  = $dias <= 3 ? 'days-crit' : ($dias <= 7 ? 'days-warn' : 'days-ok');
        $ico  = $dias <= 3 ? '🔴' : ($dias <= 7 ? '⚠️' : '✅');
      ?>
      <tr>
        <td style="font-weight:600"><?= htmlspecialchars($p['nome']) ?></td>
        <td class="r dim"><?= number_format((float)$p['estoque_atual'],2,',','.') ?> <?= htmlspecialchars($p['unidade']) ?></td>
        <td class="r dim"><?= number_format((float)$p['consumo_dia'],2,',','.') ?>/dia</td>
        <td class="r"><strong style="font-size:15px"><?= $dias >= 999 ? '—' : $dias ?></strong></td>
        <td>
          <span class="days-badge <?= $cls ?>"><?= $ico ?>
            <?php if ($dias >= 999): ?>Estável
            <?php elseif ($dias <= 3): ?>Comprar urgente
            <?php elseif ($dias <= 7): ?>Comprar em breve
            <?php else: ?>OK por <?= $dias ?> dias
            <?php endif; ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ─── Top produtos + Pagamentos ────────────────────────────────── -->
<div class="grid-2">

  <div class="card" style="margin-bottom:0">
    <div class="card-head">
      <h3>🏆 Top produtos — 7 dias</h3>
      <span class="card-meta"><?= date('d/m', strtotime('-6 days')) ?>–<?= date('d/m') ?></span>
    </div>
    <?php if (empty($top10)): ?>
    <div style="text-align:center;padding:32px;color:var(--text3);font-size:13px">Sem vendas no período</div>
    <?php else:
      $mxQ = max(1,(float)($top10[0]['qtd']??1));
    ?>
    <table class="tbl">
      <thead><tr><th style="width:32px">#</th><th>Produto</th><th class="r">Qtd</th><th class="r">Total</th></tr></thead>
      <tbody>
        <?php foreach ($top10 as $i => $r):
          $rk = $i===0?'g':($i===1?'s':($i===2?'b':''));
          $pct2 = ((float)$r['qtd']/$mxQ)*100;
        ?>
        <tr>
          <td><span class="rank <?= $rk ?>"><?= $i+1 ?></span></td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($r['nome']) ?></div>
            <div class="rank-bar" style="width:<?= round($pct2) ?>%"></div>
          </td>
          <td class="r val"><?= (int)$r['qtd'] ?></td>
          <td class="r val"><?= brl((float)$r['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-bottom:0">
    <div class="card-head">
      <h3>💳 Pagamentos — hoje</h3>
      <span class="card-meta"><?= date('d/m/Y') ?></span>
    </div>
    <?php if (empty($pagamentos)): ?>
    <div style="text-align:center;padding:32px;color:var(--text3);font-size:13px">Sem pagamentos hoje</div>
    <?php else: ?>
    <div class="bar-chart">
      <?php foreach ($pagamentos as $r):
        $met = strtolower(trim((string)$r['metodo']));
        $lbl = $PAG_LABEL[$met] ?? ucfirst($met);
        $cor = $PAG_COLOR[$met] ?? '#6b7280';
        $pp  = $maxPag > 0 ? ((float)$r['total']/$maxPag)*100 : 0;
      ?>
      <div class="bar-row">
        <div class="bar-lbl"><?= htmlspecialchars($lbl) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= round($pp) ?>%;background:<?= $cor ?>"></div></div>
        <div class="bar-info">
          <span class="bar-val"><?= brl((float)$r['total']) ?></span>
          <span class="bar-cnt"><?= (int)$r['qtd'] ?> pedido<?= (int)$r['qtd']!=1?'s':'' ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ─── Últimos 7 dias ────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <h3>📅 Faturamento — últimos 7 dias</h3>
    <a href="../relatorios/" class="card-link">Relatório completo →</a>
  </div>
  <?php if (empty($sete)): ?>
  <div style="text-align:center;padding:32px;color:var(--text3)">Sem dados</div>
  <?php else: ?>
  <table class="tbl">
    <thead><tr><th>Data</th><th>Dia</th><th class="r">Pedidos</th><th class="r">Faturado</th><th style="width:180px">Barra</th></tr></thead>
    <tbody>
      <?php foreach ($sete as $r):
        $dt      = new DateTime($r['dia']);
        $isHoje  = $r['dia'] === date('Y-m-d');
        $pp      = ((float)$r['total']/$maxDia)*100;
      ?>
      <tr>
        <td>
          <span style="font-weight:600<?= $isHoje?';color:var(--acc)':'' ?>"><?= $dt->format('d/m/Y') ?></span>
          <?php if ($isHoje): ?><span style="font-size:10px;font-weight:700;padding:2px 7px;background:var(--acc-gl);color:var(--acc);border-radius:999px;margin-left:6px">Hoje</span><?php endif; ?>
        </td>
        <td class="dim"><?= $DIAS_PT[$dt->format('w')] ?></td>
        <td class="r val"><?= (int)$r['ped'] ?></td>
        <td class="r val"><?= brl((float)$r['total']) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px">
            <div class="bar-track" style="flex:1;height:7px"><div class="bar-fill" style="width:<?= round($pp) ?>%;background:var(--acc)"></div></div>
            <span style="font-size:11px;color:var(--text4);width:28px;text-align:right"><?= round($pp) ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="footer">
  <span class="pulse"></span>
  Atualiza automaticamente a cada 60s · Última carga: <?= date('H:i:s') ?>
  &nbsp;·&nbsp;<a href="" style="color:var(--text4);text-decoration:none">Atualizar agora</a>
  &nbsp;·&nbsp;<a href="../" style="color:var(--text4);text-decoration:none">← Admin</a>
</div>

</main>

<script>
const clk = document.getElementById('clk');
function tick() { clk.textContent = new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
tick(); setInterval(tick, 1000);
</script>
</body>
</html>

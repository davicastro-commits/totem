<?php
// ── Auth ─────────────────────────────────────────────────────────────────
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

$adminNome  = $_SESSION['admin_nome']  ?? '';
$adminRole  = $_SESSION['admin_role']  ?? 'operador';
$isAdmin    = $adminRole === 'admin';
$csrfToken  = csrfToken();
$isEmbedded = !empty($_GET['embedded']);

// Carregar produtos para select da ficha técnica
try {
    $db = getDB();
    $prodStmt = $db->query("SELECT id, nome FROM totem_produtos WHERE disponivel = true ORDER BY nome ASC");
    $produtos  = $prodStmt->fetchAll();
} catch (Throwable) {
    $produtos = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<title>Estoque — Café Comunhão</title>
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
<?php if($isEmbedded): ?>
body{background:var(--bg)}
.main{padding:0}
<?php endif; ?>

/* ── TOPBAR ─────────────────────────────────────────────────────────── */
.topbar{display:flex;align-items:center;gap:16px;padding:0 24px;height:56px;background:var(--surf);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar a{color:var(--text2);text-decoration:none;font-size:13px;font-weight:500;display:flex;align-items:center;gap:6px;transition:color .15s}
.topbar a:hover{color:var(--acc)}
.topbar-title{font-size:16px;font-weight:800;color:var(--text);margin-left:8px}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:13px;color:var(--text3)}

/* ── LAYOUT ─────────────────────────────────────────────────────────── */
.main{max-width:1280px;margin:0 auto;padding:24px}

/* ── TABS ───────────────────────────────────────────────────────────── */
.tabs{display:flex;gap:4px;background:var(--surf);border:1px solid var(--border);border-radius:12px;padding:5px;margin-bottom:24px;width:fit-content}
.tab-btn{padding:8px 20px;border-radius:8px;border:none;background:transparent;color:var(--text2);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s}
.tab-btn.active{background:var(--acc);color:#fff}
.tab-btn:hover:not(.active){background:var(--card);color:var(--text)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── KPI GRID ───────────────────────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:24px}
.kpi-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:8px}
.kpi-value{font-size:28px;font-weight:900;color:var(--c,var(--acc))}
.kpi-sub{font-size:12px;color:var(--text3);margin-top:4px}

/* ── TABLE ──────────────────────────────────────────────────────────── */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.card-head h3{font-size:14px;font-weight:700}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:10px 14px;color:var(--text2);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.015)}

/* ── TOOLBAR ────────────────────────────────────────────────────────── */
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.search-box{display:flex;align-items:center;background:var(--card);border:1px solid var(--border2);border-radius:9px;padding:0 12px;gap:8px;height:38px;flex:1;min-width:180px}
.search-box input{background:transparent;border:none;outline:none;color:var(--text);font-size:13px;font-family:inherit;width:100%}
.search-box input::placeholder{color:var(--text3)}
select.filter{background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:8px 12px;outline:none;height:38px;cursor:pointer}
select.filter:focus{border-color:var(--acc)}

/* ── BUTTONS ────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;border:none;height:38px;white-space:nowrap}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{background:var(--acc-l);transform:translateY(-1px)}
.btn-secondary{background:var(--card);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text);border-color:var(--text3)}
.btn-danger{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-sm{padding:5px 12px;font-size:12px;height:28px}
.btn-xs{padding:3px 9px;font-size:11px;height:24px}

/* ── BADGES ─────────────────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.3px}
.badge-ok{background:rgba(34,197,94,.15);color:var(--green)}
.badge-low{background:rgba(245,158,11,.15);color:var(--gold)}
.badge-critical{background:rgba(239,68,68,.15);color:var(--red)}
.badge-entrada{background:rgba(34,197,94,.15);color:var(--green)}
.badge-saida{background:rgba(239,68,68,.15);color:var(--red)}
.badge-ajuste{background:rgba(59,130,246,.15);color:var(--blue)}

/* ── ESTOQUE BAR ─────────────────────────────────────────────────────── */
.stock-bar{width:100%;height:6px;background:var(--card2);border-radius:3px;overflow:hidden;min-width:80px}
.stock-fill{height:100%;border-radius:3px;transition:width .3s}
.fill-ok{background:var(--green)}
.fill-low{background:var(--gold)}
.fill-critical{background:var(--red)}

/* ── MODAL ──────────────────────────────────────────────────────────── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);display:none;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(4px)}
.overlay.open{display:flex}
.modal{background:var(--surf);border:1px solid var(--border2);border-radius:18px;padding:32px;width:520px;max-width:95vw;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;gap:18px;box-shadow:0 32px 120px rgba(0,0,0,.8)}
.modal h3{font-size:18px;font-weight:800}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.field input,.field select,.field textarea{padding:12px 14px;background:var(--card);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:inherit;font-size:14px;outline:none;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--acc)}
.field textarea{resize:vertical;min-height:70px}
.form-row{display:flex;gap:12px}
.form-row .field{flex:1}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:4px}

/* ── FICHA TABLE ────────────────────────────────────────────────────── */
.ficha-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.ficha-item:last-child{border-bottom:none}
.ficha-item-nome{flex:1;font-size:13px;font-weight:600}
.ficha-item-qty{width:90px}
.ficha-item-unit{width:40px;font-size:12px;color:var(--text3)}

/* ── PAGINATION ─────────────────────────────────────────────────────── */
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;border-top:1px solid var(--border);justify-content:center}
.page-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:inherit;font-size:13px;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center}
.page-btn:hover{background:var(--card2);color:var(--text)}
.page-btn.active{background:var(--acc);color:#fff;border-color:var(--acc)}

/* ── TOAST ──────────────────────────────────────────────────────────── */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;background:var(--card2);border:1px solid var(--border2);border-radius:10px;font-size:13px;font-weight:500;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}

/* ── EMPTY ───────────────────────────────────────────────────────────── */
.empty-row td{text-align:center;color:var(--text3);padding:40px!important}

/* ── ALERT BANNER ────────────────────────────────────────────────────── */
.alert-banner{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:13px;color:var(--red)}
.alert-banner.hidden{display:none}

/* ── BADGES DE ALERTA INTELIGENTE ───────────────────────────────────── */
.badge-critico{background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.badge-urgente{background:rgba(249,115,22,.2);color:#fb923c;border:1px solid rgba(249,115,22,.3)}
.badge-atencao{background:rgba(245,158,11,.2);color:var(--gold);border:1px solid rgba(245,158,11,.3)}
.badge-frio{background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.2)}

/* ── PAINEL INTELIGENTE ─────────────────────────────────────────────── */
.ia-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.ia-title{font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px}
.abc-chip{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;font-size:11px;font-weight:800}
.abc-a{background:rgba(239,68,68,.2);color:#f87171}
.abc-b{background:rgba(245,158,11,.2);color:var(--gold)}
.abc-c{background:rgba(107,114,128,.2);color:var(--text3)}

/* ── MODAL IA ────────────────────────────────────────────────────────── */
.ia-result{display:flex;flex-direction:column;gap:10px;max-height:420px;overflow-y:auto}
.ia-item{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:grid;grid-template-columns:1fr auto;gap:4px}
.ia-item-nome{font-weight:700;font-size:14px}
.ia-item-just{font-size:12px;color:var(--text2);grid-column:1/-1}
.ia-item-qty{font-size:15px;font-weight:800;color:var(--acc);white-space:nowrap;align-self:start}
.ia-obs{background:var(--card2);border-radius:10px;padding:12px 14px;font-size:13px;color:var(--text2);border-left:3px solid var(--acc);margin-top:4px}
.spin{animation:spin .8s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── RELATÓRIO ───────────────────────────────────────────────────────── */
.rel-topbar{background:var(--surf);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between}
.rel-presets{display:flex;gap:6px;flex-wrap:wrap}
.preset-btn{padding:6px 14px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.preset-btn:hover{background:var(--card2);color:var(--text)}
.preset-btn.active{background:var(--acc);color:#fff;border-color:var(--acc)}
.rel-date-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rel-date-field{display:flex;flex-direction:column;gap:3px}
.rel-date-label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px}
.rel-date-input{background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:7px 11px;outline:none;height:36px;transition:border-color .15s}
.rel-date-input:focus{border-color:var(--acc)}

/* KPI cards para relatório (variante compacta) */
.rel-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px}
.rel-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;gap:14px;align-items:center}
.rel-kpi-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.rel-kpi-info{display:flex;flex-direction:column}
.rel-kpi-label{font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.rel-kpi-value{font-size:20px;font-weight:900;line-height:1}
.rel-kpi-sub{font-size:11px;color:var(--text3);margin-top:2px}

/* Mini barra de giro */
.giro-bar{height:6px;border-radius:3px;background:var(--card2);overflow:hidden;margin-top:5px}
.giro-fill{height:100%;border-radius:3px;transition:width .4s ease}
.giro-label{font-size:11px;color:var(--text3);margin-bottom:2px}

/* Linha da tabela de relatório */
#rel-table td{vertical-align:middle}
.rel-nome-cell{display:flex;flex-direction:column;gap:2px}
.rel-nome-main{font-weight:700;font-size:13px}
.rel-nome-abc{font-size:10px;color:var(--text3)}

/* ── ABA INSUMOS MELHORADA ──────────────────────────────────────────── */
.ins-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.ins-kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px}
.ins-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.ins-kpi-icon{font-size:20px;width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ins-kpi-txt{display:flex;flex-direction:column;gap:1px}
.ins-kpi-val{font-size:18px;font-weight:900;line-height:1}
.ins-kpi-lbl{font-size:10px;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.cobertura-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;background:var(--card2);color:var(--text3)}

/* ── ABA MOVIMENTAÇÕES MELHORADA ────────────────────────────────────── */
.mov-row-entrada td:first-child{border-left:3px solid var(--green)}
.mov-row-saida   td:first-child{border-left:3px solid var(--red)}
.mov-row-ajuste  td:first-child{border-left:3px solid var(--blue)}
.mov-tipo-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;font-size:14px;margin-right:6px}
.mov-tipo-entrada .mov-tipo-icon{background:rgba(34,197,94,.15)}
.mov-tipo-saida   .mov-tipo-icon{background:rgba(239,68,68,.15)}
.mov-tipo-ajuste  .mov-tipo-icon{background:rgba(59,130,246,.15)}
.mov-summary{display:flex;gap:20px;padding:14px 18px;border-top:1px solid var(--border);font-size:13px;flex-wrap:wrap}
.mov-summary-item{display:flex;flex-direction:column;gap:2px}
.mov-summary-label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.3px}
.mov-summary-value{font-size:16px;font-weight:800}

/* ── ABA FICHAS TÉCNICAS MELHORADA ─────────────────────────────────── */
.ficha-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;padding:16px}
.ficha-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px;transition:border-color .15s}
.ficha-card:hover{border-color:var(--border2)}
.ficha-card-nome{font-weight:700;font-size:14px;display:flex;align-items:center;gap:6px}
.ficha-card-qty{font-size:22px;font-weight:900;color:var(--acc)}
.ficha-card-unit{font-size:11px;color:var(--text3);margin-left:2px}
.ficha-card-meta{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);margin-top:2px}
.ficha-card-stock{display:flex;align-items:center;gap:6px;font-size:12px;padding:6px 8px;border-radius:8px;background:var(--card)}
.ficha-footer{padding:14px 16px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}

/* ── ABA LOTES ───────────────────────────────────────────────────────── */
.lote-row-ok      td:first-child{border-left:3px solid var(--green)}
.lote-row-atencao td:first-child{border-left:3px solid var(--gold)}
.lote-row-critico td:first-child{border-left:3px solid #fb923c}
.lote-row-vencido td:first-child{border-left:3px solid var(--red)}
.validade-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
.vb-ok{background:rgba(34,197,94,.15);color:var(--green)}
.vb-atencao{background:rgba(245,158,11,.15);color:var(--gold)}
.vb-critico{background:rgba(249,115,22,.2);color:#fb923c}
.vb-vencido{background:rgba(239,68,68,.2);color:var(--red)}
.vb-nd{background:var(--card2);color:var(--text3)}
.lote-alert-bar{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:var(--red);display:flex;align-items:center;gap:8px}

/* ── ABA DESPERDÍCIO ────────────────────────────────────────────────── */
.desp-ok{color:var(--green)}
.desp-atencao{color:var(--gold)}
.desp-critico{color:var(--red);font-weight:700}

/* ── ABA COMPRAS ────────────────────────────────────────────────────── */
.status-flow{display:flex;gap:0;background:var(--surf);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px}
.sf-step{flex:1;padding:12px 10px;text-align:center;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;border-right:1px solid var(--border);color:var(--text3)}
.sf-step:last-child{border-right:none}
.sf-step.active{background:var(--acc);color:#fff}
.sf-step:hover:not(.active){background:var(--card2);color:var(--text)}
.sf-count{display:block;font-size:22px;font-weight:900;margin-bottom:2px}
.compra-row-alta   td:first-child{border-left:3px solid var(--red)}
.compra-row-media  td:first-child{border-left:3px solid var(--gold)}
.compra-row-baixa  td:first-child{border-left:3px solid var(--blue)}
.status-badge-pendente{background:rgba(107,114,128,.15);color:var(--text2)}
.status-badge-aprovado{background:rgba(59,130,246,.15);color:var(--blue)}
.status-badge-pedido{background:rgba(245,158,11,.15);color:var(--gold)}
.status-badge-recebido{background:rgba(34,197,94,.15);color:var(--green)}
.status-badge-cancelado{background:rgba(239,68,68,.1);color:var(--red)}

/* ── ABA INTELIGENTE MELHORADA ──────────────────────────────────────── */
.int-kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:20px}
.int-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.int-kpi-num{font-size:28px;font-weight:900;line-height:1.1}
.int-kpi-lbl{font-size:11px;color:var(--text3);margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.nivel-row-critico{background:rgba(239,68,68,.04)}
.nivel-row-urgente{background:rgba(249,115,22,.04)}
.nivel-row-atencao{background:rgba(245,158,11,.04)}
.int-metric{display:flex;flex-direction:column;gap:2px}
.int-metric-val{font-size:13px;font-weight:700}
.int-metric-bar{height:4px;border-radius:2px;background:var(--card2);overflow:hidden;margin-top:3px}
.int-metric-fill{height:100%;border-radius:2px;background:var(--acc)}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<?php if (!$isEmbedded): ?>
<!-- ── TOPBAR ──────────────────────────────────────────────────────────── -->
<header class="topbar">
  <a href="../">← Painel Admin</a>
  <span class="topbar-title">Gestão de Estoque</span>
  <div class="topbar-right">
    <span><?= htmlspecialchars($adminNome) ?></span>
    <span style="color:var(--border2)">|</span>
    <span><?= htmlspecialchars($adminRole) ?></span>
  </div>
</header>
<?php endif; ?>

<!-- ── MAIN ────────────────────────────────────────────────────────────── -->
<main class="main">

  <!-- Alerta global de estoque baixo -->
  <div class="alert-banner hidden" id="alerta-banner">
    ⚠️ <strong id="alerta-count">0</strong> insumo(s) com estoque abaixo do mínimo.
    <button class="btn btn-xs btn-danger" style="margin-left:8px" onclick="switchTab('insumos')">Ver insumos →</button>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid" id="kpi-grid">
    <div class="kpi-card"><div class="kpi-label">Carregando...</div><div class="kpi-value" style="color:var(--text3)">—</div></div>
  </div>

<?php if (!$isEmbedded): ?>
  <!-- TABS — ocultas quando embutido no painel admin -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="insumos">Insumos</button>
    <button class="tab-btn" data-tab="movimentacoes">Movimentações</button>
    <button class="tab-btn" data-tab="fichas">Fichas Técnicas</button>
    <button class="tab-btn" data-tab="relatorio">🖨️ Relatório</button>
    <button class="tab-btn" data-tab="inteligente">🧠 Inteligente</button>
    <button class="tab-btn" data-tab="lotes">📋 Lotes</button>
    <button class="tab-btn" data-tab="compras">🛒 Compras</button>
  </div>
<?php endif; ?>

  <!-- ── ABA: INSUMOS ──────────────────────────────────────────────────── -->
  <div class="tab-panel active" id="panel-insumos">

    <!-- KPIs rápidos -->
    <div class="ins-kpi-row" id="ins-kpi-row"></div>

    <!-- Toolbar -->
    <div class="ins-toolbar">
      <div class="search-box" style="flex:1;min-width:180px">
        <span style="color:var(--text3)">🔍</span>
        <input type="text" id="ins-busca" placeholder="Buscar insumo...">
      </div>
      <select class="filter" id="ins-filtro-status">
        <option value="">Todos os níveis</option>
        <option value="critico">🔴 Crítico</option>
        <option value="urgente">🟠 Urgente</option>
        <option value="atencao">🟡 Atenção</option>
        <option value="frio">🔵 Estoque Frio</option>
        <option value="ok">🟢 OK</option>
      </select>
      <select class="filter" id="ins-filtro-abc">
        <option value="">Classe ABC</option>
        <option value="A">Classe A</option>
        <option value="B">Classe B</option>
        <option value="C">Classe C</option>
      </select>
      <button class="btn btn-primary" onclick="openNovoInsumo()">+ Novo Insumo</button>
    </div>

    <div class="card">
      <div class="card-head">
        <h3 id="ins-count">Insumos</h3>
        <span style="font-size:12px;color:var(--text3)" id="ins-valor-total"></span>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Insumo</th>
              <th style="text-align:center">ABC</th>
              <th>Estoque Atual</th>
              <th style="text-align:right">Mínimo</th>
              <th style="text-align:right">Custo Médio</th>
              <th style="text-align:right">Valor em Estoque</th>
              <th style="text-align:center">Cobertura</th>
              <th style="text-align:center">Status</th>
              <th style="width:130px;text-align:center">Ações</th>
            </tr>
          </thead>
          <tbody id="ins-tbody">
            <tr class="empty-row"><td colspan="9">Carregando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── ABA: MOVIMENTAÇÕES ────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-movimentacoes">

    <div class="rel-topbar" style="margin-bottom:16px">
      <div style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
        <select class="filter" id="mov-filtro-insumo" style="flex:1;min-width:180px;max-width:320px">
          <option value="">Todos os insumos</option>
        </select>
        <select class="filter" id="mov-filtro-tipo">
          <option value="">Todos os tipos</option>
          <option value="entrada">📈 Entrada</option>
          <option value="saida">📉 Saída</option>
          <option value="ajuste">🔧 Ajuste</option>
        </select>
      </div>
      <button class="btn btn-secondary" onclick="loadMovimentacoes(1)">↻ Atualizar</button>
    </div>

    <!-- Loading -->
    <div id="mov-loading" style="display:none;text-align:center;padding:40px;color:var(--text3)">
      <span class="spin" style="font-size:24px">⟳</span>
    </div>

    <div class="card" id="mov-card">
      <div class="card-head">
        <h3 id="mov-count">Movimentações</h3>
        <div id="mov-kpis-inline" style="display:flex;gap:16px;font-size:12px"></div>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th style="width:150px">Data / Hora</th>
              <th>Insumo</th>
              <th style="text-align:center">Tipo</th>
              <th style="text-align:right">Quantidade</th>
              <th style="text-align:right">Custo Unit.</th>
              <th>Motivo</th>
              <th style="text-align:center">Usuário</th>
            </tr>
          </thead>
          <tbody id="mov-tbody">
            <tr class="empty-row"><td colspan="7">Selecione um insumo para ver as movimentações.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="mov-summary" id="mov-summary" style="display:none"></div>
      <div class="pagination" id="mov-pagination"></div>
    </div>
  </div>

  <!-- ── ABA: RELATÓRIO ───────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-relatorio">

    <!-- Barra de período -->
    <div class="rel-topbar">
      <div class="rel-presets">
        <button class="preset-btn" data-p="hoje">Hoje</button>
        <button class="preset-btn" data-p="semana">7 dias</button>
        <button class="preset-btn active" data-p="mes">Este mês</button>
        <button class="preset-btn" data-p="mes_ant">Mês anterior</button>
        <button class="preset-btn" data-p="trim">Trimestre</button>
      </div>
      <div class="rel-date-row">
        <div class="rel-date-field">
          <label class="rel-date-label">De</label>
          <input type="date" id="rel-ini" class="rel-date-input">
        </div>
        <span style="color:var(--text4);font-size:18px">→</span>
        <div class="rel-date-field">
          <label class="rel-date-label">Até</label>
          <input type="date" id="rel-fim" class="rel-date-input">
        </div>
        <button class="btn btn-primary" onclick="gerarRelatorio()" id="btn-gerar-rel">
          <span>📊</span> Gerar
        </button>
        <button class="btn btn-secondary" id="btn-export-csv" onclick="exportarCSV()" style="display:none">
          ⬇ CSV
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div id="rel-loading" style="display:none;text-align:center;padding:60px 0;color:var(--text3)">
      <div style="font-size:32px;margin-bottom:12px"><span class="spin">⟳</span></div>
      <div style="font-size:14px">Gerando relatório...</div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid" id="rel-kpis" style="display:none;margin-bottom:20px"></div>

    <!-- Estado vazio -->
    <div id="rel-empty" style="display:none;text-align:center;padding:60px 0;color:var(--text3)">
      <div style="font-size:40px;margin-bottom:12px">📭</div>
      <div style="font-size:15px;font-weight:600;color:var(--text2)">Nenhuma movimentação no período</div>
      <div style="font-size:13px;margin-top:6px">Tente um intervalo de datas diferente</div>
    </div>

    <!-- Tabela principal -->
    <div class="card" id="rel-card" style="display:none">
      <div class="card-head" style="flex-wrap:wrap;gap:12px">
        <div>
          <h3 id="rel-titulo" style="margin-bottom:2px">Consumo de Insumos</h3>
          <div id="rel-periodo" style="font-size:12px;color:var(--text3)"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div class="search-box" style="min-width:160px;max-width:220px">
            <span style="color:var(--text3)">🔍</span>
            <input type="text" id="rel-busca" placeholder="Filtrar insumo..." oninput="renderRelatorio()">
          </div>
          <select class="filter" id="rel-ordem" onchange="renderRelatorio()" style="height:36px">
            <option value="custo_total_saida_desc">↓ Maior custo</option>
            <option value="total_saida_desc">↓ Maior saída</option>
            <option value="total_entrada_desc">↓ Maior entrada</option>
            <option value="nome_asc">A → Z</option>
            <option value="estoque_atual_asc">↑ Menor saldo</option>
          </select>
        </div>
      </div>

      <div style="overflow-x:auto">
        <table id="rel-table">
          <thead>
            <tr>
              <th>Insumo</th>
              <th style="text-align:center">Un.</th>
              <th style="text-align:right">Saídas</th>
              <th style="text-align:right">Entradas</th>
              <th style="text-align:right">Saldo Atual</th>
              <th style="text-align:right">Custo Médio</th>
              <th style="text-align:right">Custo das Saídas</th>
              <th style="width:120px">Giro</th>
            </tr>
          </thead>
          <tbody id="rel-tbody">
            <tr class="empty-row"><td colspan="8">Clique em "Gerar" para carregar.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Rodapé -->
      <div id="rel-summary" style="padding:16px 18px;border-top:1px solid var(--border);display:flex;flex-wrap:wrap;justify-content:space-between;gap:16px;font-size:13px"></div>
    </div>

    <!-- ── Seção Desperdício ──────────────────────────────────────────── -->
    <div style="margin-top:28px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-size:16px;font-weight:800">📊 Análise de Desperdício</div>
          <div style="font-size:12px;color:var(--text3);margin-top:2px">Compara consumo teórico (fichas × vendas) vs consumo real (movimentações)</div>
        </div>
        <button class="btn btn-secondary" onclick="gerarDesperdicio()" id="btn-desperdicio">📊 Analisar período</button>
      </div>
      <div id="desp-loading" style="display:none;text-align:center;padding:30px;color:var(--text3)"><span class="spin">⟳</span> Analisando...</div>
      <div id="desp-kpis" style="display:none" class="rel-kpi-grid"></div>
      <div class="card" id="desp-card" style="display:none">
        <div class="card-head">
          <h3>Insumos com Desperdício</h3>
          <span style="font-size:11px;color:var(--text3)">Desperdício = saídas reais − consumo teórico esperado pelas vendas</span>
        </div>
        <div style="overflow-x:auto">
          <table>
            <thead>
              <tr>
                <th>Insumo</th>
                <th style="text-align:center">Un.</th>
                <th style="text-align:right">Cons. Teórico</th>
                <th style="text-align:right">Cons. Real</th>
                <th style="text-align:right">Desperdício</th>
                <th style="text-align:right">%</th>
                <th style="text-align:right">Custo</th>
                <th style="text-align:center">Nível</th>
              </tr>
            </thead>
            <tbody id="desp-tbody"></tbody>
          </table>
        </div>
        <div id="desp-summary" style="padding:14px 18px;border-top:1px solid var(--border)"></div>
      </div>
    </div>
  </div>

  <!-- ── ABA: FICHAS TÉCNICAS ──────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-fichas">

    <div class="rel-topbar" style="margin-bottom:16px">
      <div style="display:flex;gap:8px;align-items:center;flex:1;flex-wrap:wrap">
        <div class="search-box" style="flex:1;min-width:200px;max-width:360px">
          <span style="color:var(--text3)">🍽️</span>
          <select id="ficha-produto-sel" style="background:transparent;border:none;outline:none;color:var(--text);font-family:inherit;font-size:13px;width:100%;cursor:pointer" onchange="loadFicha()">
            <option value="">Selecione um produto...</option>
            <?php foreach ($produtos as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="ficha-custo-venda" style="display:none;background:var(--acc-gl);border:1px solid rgba(255,85,0,.2);border-radius:9px;padding:7px 14px;font-size:13px;font-weight:700;color:var(--acc-l)"></div>
      </div>
      <button class="btn btn-primary" onclick="openEditarFicha()">✏️ Editar Ficha</button>
    </div>

    <!-- Estado vazio -->
    <div id="ficha-empty" style="text-align:center;padding:60px 0;color:var(--text3)">
      <div style="font-size:40px;margin-bottom:12px">🍽️</div>
      <div style="font-size:15px;font-weight:600;color:var(--text2)">Selecione um produto acima</div>
      <div style="font-size:13px;margin-top:6px">A ficha técnica mostra os ingredientes e custos por venda</div>
    </div>

    <!-- Cards dos insumos -->
    <div id="ficha-cards-wrap" style="display:none">
      <div class="ficha-cards" id="ficha-cards"></div>
      <div class="card" style="margin-top:0;border-radius:0 0 14px 14px;border-top:none">
        <div class="ficha-footer" id="ficha-footer"></div>
      </div>
    </div>
  </div>

  <!-- ── ABA: INTELIGENTE ─────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-inteligente">

    <div class="ia-header">
      <div class="ia-title">🧠 Estoque Inteligente</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-secondary" id="btn-recalcular" onclick="recalcularIndicadores()">↻ Recalcular</button>
        <button class="btn btn-primary" onclick="openSugestaoIA()">🤖 Sugestão de Compras IA</button>
      </div>
    </div>

    <!-- KPIs por nível -->
    <div class="int-kpi-row" id="int-kpi-row"></div>

    <div class="card">
      <div class="card-head" style="flex-wrap:wrap;gap:10px">
        <h3 id="int-count">Indicadores</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div class="search-box" style="min-width:150px;max-width:220px">
            <span style="color:var(--text3)">🔍</span>
            <input type="text" id="int-busca" placeholder="Filtrar..." oninput="renderizarInteligente()">
          </div>
          <select class="filter" id="int-filtro-nivel" onchange="renderizarInteligente()" style="height:36px">
            <option value="">Todos os níveis</option>
            <option value="CRITICO">🔴 Crítico</option>
            <option value="URGENTE">🟠 Urgente</option>
            <option value="ATENCAO">🟡 Atenção</option>
            <option value="FRIO">🔵 Frio</option>
            <option value="OK">🟢 OK</option>
          </select>
          <span style="font-size:11px;color:var(--text3)">SS = Safety Stock &nbsp;|&nbsp; ROP = Ponto de Pedido &nbsp;|&nbsp; EOQ = Lote Econômico</span>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Insumo</th>
              <th style="text-align:center">ABC</th>
              <th style="text-align:center">Nível</th>
              <th style="text-align:right">Estoque</th>
              <th style="text-align:center">Cobertura</th>
              <th style="text-align:right">Safety Stock</th>
              <th style="text-align:right">ROP</th>
              <th style="text-align:right">EOQ</th>
              <th style="text-align:center">Lead</th>
            </tr>
          </thead>
          <tbody id="int-tbody">
            <tr class="empty-row"><td colspan="9">Carregando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Gráficos (dentro da aba Inteligente) ──────────────────────── -->
  <div id="int-graficos" style="display:none;margin-top:24px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <div class="card" style="padding:16px">
        <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:var(--text2)">Top 10 — Valor em Estoque</div>
        <canvas id="chart-abc" height="200"></canvas>
      </div>
      <div class="card" style="padding:16px">
        <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:var(--text2)">Distribuição por Nível de Alerta</div>
        <canvas id="chart-nivel" height="200"></canvas>
      </div>
    </div>
    <div class="card" style="padding:16px">
      <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:var(--text2)">Top 10 — Cobertura em Dias</div>
      <canvas id="chart-cobertura" height="100"></canvas>
    </div>
  </div>

  <!-- ── ABA: LOTES ──────────────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-lotes">
    <div class="rel-topbar" style="margin-bottom:16px">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1">
        <select class="filter" id="lotes-filtro-insumo" style="flex:1;min-width:200px;max-width:340px" onchange="loadLotes()">
          <option value="">Selecione um insumo...</option>
        </select>
        <select class="filter" id="lotes-filtro-status" onchange="filtrarLotes()">
          <option value="">Todos</option>
          <option value="ok">✅ Válidos</option>
          <option value="atencao">⚠️ Atenção (≤30d)</option>
          <option value="critico">🔴 Crítico (≤7d)</option>
          <option value="vencido">💀 Vencidos</option>
        </select>
      </div>
      <button class="btn btn-primary" onclick="openNovoLote()">+ Registrar Lote</button>
    </div>

    <div id="lotes-alerta-vencidos" class="lote-alert-bar" style="display:none">
      ⚠️ <span id="lotes-alerta-txt"></span>
    </div>

    <div class="card" id="lotes-card">
      <div class="card-head">
        <h3 id="lotes-count">Lotes</h3>
        <span style="font-size:12px;color:var(--text3)">FIFO — use primeiro os lotes com vencimento mais próximo</span>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Nº Lote / Fornecedor</th>
              <th>Entrada</th>
              <th style="text-align:center">Validade</th>
              <th style="text-align:right">Qtd Inicial</th>
              <th style="text-align:right">Qtd Atual</th>
              <th style="text-align:right">Custo Unit.</th>
              <th>Observações</th>
              <th style="text-align:center">Ações</th>
            </tr>
          </thead>
          <tbody id="lotes-tbody">
            <tr class="empty-row"><td colspan="8">Selecione um insumo acima.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── ABA: COMPRAS ─────────────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-compras">

    <!-- Status flow -->
    <div class="status-flow" id="compras-flow">
      <div class="sf-step active" data-s="pendente" onclick="switchComprasStatus('pendente')">
        <span class="sf-count" id="sf-n-pendente">—</span>Pendente
      </div>
      <div class="sf-step" data-s="aprovado" onclick="switchComprasStatus('aprovado')">
        <span class="sf-count" id="sf-n-aprovado">—</span>Aprovado
      </div>
      <div class="sf-step" data-s="pedido" onclick="switchComprasStatus('pedido')">
        <span class="sf-count" id="sf-n-pedido">—</span>Pedido
      </div>
      <div class="sf-step" data-s="recebido" onclick="switchComprasStatus('recebido')">
        <span class="sf-count" id="sf-n-recebido">—</span>Recebido
      </div>
    </div>

    <div class="ins-toolbar">
      <div class="search-box" style="flex:1;min-width:180px">
        <span style="color:var(--text3)">🔍</span>
        <input type="text" id="compras-busca" placeholder="Buscar insumo..." oninput="renderCompras()">
      </div>
      <select class="filter" id="compras-prioridade" onchange="renderCompras()">
        <option value="">Todas prioridades</option>
        <option value="ALTA">🔴 Alta</option>
        <option value="MEDIA">🟡 Média</option>
        <option value="BAIXA">🔵 Baixa</option>
      </select>
      <button class="btn btn-secondary" onclick="importarDaIA()" title="Importar sugestões da IA">🤖 Importar da IA</button>
      <button class="btn btn-primary" onclick="openNovaCompra()">+ Nova Solicitação</button>
    </div>

    <div class="card">
      <div class="card-head">
        <h3 id="compras-count">Solicitações</h3>
        <span id="compras-total-val" style="font-size:13px;font-weight:700;color:var(--acc)"></span>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Insumo</th>
              <th style="text-align:center">Prioridade</th>
              <th style="text-align:right">Quantidade</th>
              <th style="text-align:right">Custo Est.</th>
              <th style="text-align:right">Total Est.</th>
              <th>Fornecedor</th>
              <th>Necessidade</th>
              <th>Solicitante</th>
              <th style="text-align:center">Ações</th>
            </tr>
          </thead>
          <tbody id="compras-tbody">
            <tr class="empty-row"><td colspan="9">Carregando...</td></tr>
          </tbody>
        </table>
      </div>
      <div id="compras-summary" style="padding:14px 18px;border-top:1px solid var(--border);font-size:13px;display:none"></div>
    </div>
  </div>

</main>

<!-- ═══════════════ MODALS ═══════════════════════════════════════════════ -->

<!-- Modal: Novo/Editar Insumo -->
<div class="overlay" id="modal-insumo">
  <div class="modal" style="width:620px">
    <h3 id="m-ins-title">Novo Insumo</h3>
    <form id="form-insumo" style="display:flex;flex-direction:column;gap:0">
      <input type="hidden" id="m-ins-id">

      <!-- ── Seção 1: Identificação ── -->
      <div style="display:flex;flex-direction:column;gap:12px;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--border)">
        <div style="font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.6px">📋 Identificação</div>
        <div class="form-row">
          <div class="field" style="flex:3">
            <label>Nome do Insumo *</label>
            <input type="text" id="m-ins-nome" required placeholder="Ex: Café Arábica Moído">
          </div>
          <div class="field" style="flex:1">
            <label>Código Interno</label>
            <input type="text" id="m-ins-codigo" placeholder="SKU-001" maxlength="50">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Categoria *</label>
            <select id="m-ins-categoria">
              <option value="alimento">🍽️ Alimento</option>
              <option value="bebida">🥤 Bebida</option>
              <option value="descartavel">🥡 Descartável</option>
              <option value="limpeza">🧹 Limpeza</option>
              <option value="embalagem">📦 Embalagem</option>
              <option value="outro">📌 Outro</option>
            </select>
          </div>
          <div class="field" style="flex:2">
            <label>Fornecedor Principal</label>
            <input type="text" id="m-ins-fornecedor" placeholder="Ex: Distribuidora São Paulo">
          </div>
        </div>
      </div>

      <!-- ── Seção 2: Unidade, Custos e Armazenamento ── -->
      <div style="display:flex;flex-direction:column;gap:12px;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--border)">
        <div style="font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.6px">⚖️ Medidas e Armazenamento</div>
        <div class="form-row">
          <div class="field">
            <label>Unidade de Medida *</label>
            <select id="m-ins-unidade" required>
              <option value="UN">UN — Unidade</option>
              <option value="KG">KG — Quilo</option>
              <option value="G">G — Grama</option>
              <option value="L">L — Litro</option>
              <option value="ML">ML — Mililitro</option>
            </select>
          </div>
          <div class="field">
            <label>Custo Médio (R$)</label>
            <input type="number" id="m-ins-custo" step="0.0001" min="0" value="0">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Condição de Armazenamento *</label>
            <select id="m-ins-armazenamento" required>
              <option value="ambiente">🌡️ Temperatura Ambiente</option>
              <option value="seco">📦 Estoque Seco</option>
              <option value="refrigerado">❄️ Refrigerado (0–8°C)</option>
              <option value="congelado">🧊 Congelado (&lt;0°C)</option>
            </select>
          </div>
          <div class="field">
            <label>Validade Típica (dias)</label>
            <input type="number" id="m-ins-validade" min="1" step="1" placeholder="Ex: 30">
          </div>
        </div>
        <div class="field">
          <label>Alergênicos (se houver)</label>
          <input type="text" id="m-ins-alergenos" placeholder="Ex: Glúten, Lactose, Nozes" maxlength="200">
        </div>
      </div>

      <!-- ── Seção 3: Controle de Estoque ── -->
      <div style="display:flex;flex-direction:column;gap:12px;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--border)">
        <div style="font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.6px">📊 Controle de Estoque</div>
        <div class="form-row">
          <div class="field">
            <label>Estoque Mínimo *</label>
            <input type="number" id="m-ins-minimo" step="0.001" min="0" value="0" placeholder="0">
          </div>
          <div class="field">
            <label>Estoque Máximo</label>
            <input type="number" id="m-ins-maximo" step="0.001" min="0" placeholder="Opcional">
          </div>
          <div class="field" id="m-ins-atual-row">
            <label>Estoque Inicial</label>
            <input type="number" id="m-ins-atual" step="0.001" min="0" value="0">
          </div>
        </div>
      </div>

      <!-- ── Seção 4: Parâmetros de Reabastecimento (só edição) ── -->
      <div id="m-ins-avancado-row" style="display:none;flex-direction:column;gap:12px;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--border)">
        <div style="font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.6px">🧠 Parâmetros de Reabastecimento (IA)</div>
        <div class="form-row">
          <div class="field">
            <label>Lead Time (dias)</label>
            <input type="number" id="m-ins-lead" min="1" step="1" value="2" placeholder="2">
          </div>
          <div class="field">
            <label>Custo Fixo por Pedido (R$)</label>
            <input type="number" id="m-ins-custo-pedido" min="0" step="0.01" value="25.00">
          </div>
          <div class="field">
            <label>Dias de Estoque Alvo</label>
            <input type="number" id="m-ins-dias-alvo" min="1" step="1" value="15">
          </div>
        </div>
      </div>

      <!-- ── Seção 5: Observações ── -->
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
        <div style="font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.6px">📝 Observações</div>
        <textarea id="m-ins-obs" rows="2" placeholder="Anotações internas, fornecedor alternativo, instruções especiais..."></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-insumo')">Cancelar</button>
        <button type="submit" class="btn btn-primary">💾 Salvar Insumo</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Ajuste de Estoque -->
<div class="overlay" id="modal-ajuste">
  <div class="modal" style="width:460px">
    <h3>Movimentação de Estoque</h3>
    <form id="form-ajuste" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" id="aj-insumo-id">
      <div class="field">
        <label>Insumo</label>
        <input type="text" id="aj-insumo-nome" readonly style="background:var(--card2);cursor:default">
      </div>
      <div class="form-row">
        <div class="field">
          <label>Tipo *</label>
          <select id="aj-tipo" required>
            <option value="entrada">Entrada</option>
            <option value="saida">Saída</option>
            <option value="ajuste">Ajuste (definir quantidade)</option>
          </select>
        </div>
        <div class="field">
          <label id="aj-qty-label">Quantidade *</label>
          <input type="number" id="aj-quantidade" step="0.001" min="0.001" required placeholder="0">
        </div>
      </div>
      <div class="field" id="aj-custo-row">
        <label>Custo Unitário (R$) <span style="color:var(--text3);font-weight:400">(apenas para entrada)</span></label>
        <input type="number" id="aj-custo" step="0.0001" min="0" value="0" placeholder="0.0000">
      </div>
      <div class="field">
        <label>Motivo / Observação</label>
        <input type="text" id="aj-motivo" placeholder="Ex: Compra fornecedor, Vencimento...">
      </div>
      <div id="aj-info" style="font-size:12px;color:var(--text3);background:var(--card2);padding:10px 12px;border-radius:8px;display:none"></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-ajuste')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Ficha Técnica -->
<div class="overlay" id="modal-ficha">
  <div class="modal" style="width:580px">
    <h3 id="m-ficha-title">Editar Ficha Técnica</h3>
    <div class="field">
      <label>Produto</label>
      <select id="m-ficha-produto" onchange="carregarFichaNoModal()">
        <option value="">Selecione...</option>
        <?php foreach ($produtos as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="m-ficha-itens" style="display:flex;flex-direction:column;gap:0">
      <!-- preenchido via JS -->
    </div>

    <button type="button" class="btn btn-secondary btn-sm" onclick="adicionarLinhaFicha()" style="width:fit-content">
      + Adicionar insumo
    </button>

    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" onclick="closeModal('modal-ficha')">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="salvarFicha()">Salvar Ficha</button>
    </div>
  </div>
</div>

<!-- Modal: Novo Lote -->
<div class="overlay" id="modal-lote">
  <div class="modal" style="width:520px">
    <h3>📋 Registrar Lote / Entrada</h3>
    <form id="form-lote" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" id="l-insumo-id">
      <div class="field">
        <label>Insumo</label>
        <input type="text" id="l-insumo-nome" readonly style="background:var(--card2)">
      </div>
      <div class="form-row">
        <div class="field">
          <label>Nº do Lote / Nota Fiscal</label>
          <input type="text" id="l-numero" placeholder="Ex: NF-12345 ou LOTE-A">
        </div>
        <div class="field">
          <label>Fornecedor</label>
          <input type="text" id="l-fornecedor" placeholder="Nome do fornecedor">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Data de Entrada *</label>
          <input type="date" id="l-entrada" required>
        </div>
        <div class="field">
          <label>Data de Validade ⚠️</label>
          <input type="date" id="l-validade">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Quantidade *</label>
          <input type="number" id="l-quantidade" step="0.001" min="0.001" required placeholder="0">
        </div>
        <div class="field">
          <label>Custo Unitário (R$)</label>
          <input type="number" id="l-custo" step="0.0001" min="0" value="0">
        </div>
      </div>
      <div class="field">
        <label>Observações</label>
        <input type="text" id="l-obs" placeholder="Ex: Produto congelado, verificar temperatura">
      </div>
      <div id="l-aviso-validade" style="display:none;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:10px 12px;font-size:12px;color:var(--gold)">
        ⚠️ Validade próxima — este lote vence em breve.
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-lote')">Cancelar</button>
        <button type="submit" class="btn btn-primary">✅ Registrar Entrada</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Nova Solicitação de Compra -->
<div class="overlay" id="modal-compra">
  <div class="modal" style="width:540px">
    <h3 id="mc-title">🛒 Nova Solicitação de Compra</h3>
    <form id="form-compra" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" id="mc-id">
      <div class="form-row">
        <div class="field" style="flex:2">
          <label>Insumo *</label>
          <select id="mc-insumo" onchange="preencherDadosCompra()">
            <option value="">Selecione ou deixe em branco para digitar</option>
          </select>
        </div>
        <div class="field">
          <label>Prioridade *</label>
          <select id="mc-prioridade">
            <option value="ALTA">🔴 Alta</option>
            <option value="MEDIA" selected>🟡 Média</option>
            <option value="BAIXA">🔵 Baixa</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field" style="flex:2">
          <label>Nome do Insumo *</label>
          <input type="text" id="mc-nome" required placeholder="Ex: Café Arábica">
        </div>
        <div class="field">
          <label>Unidade</label>
          <select id="mc-unidade">
            <option value="UN">UN</option><option value="KG">KG</option>
            <option value="G">G</option><option value="L">L</option><option value="ML">ML</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Quantidade *</label>
          <input type="number" id="mc-quantidade" step="0.001" min="0.001" required placeholder="0">
        </div>
        <div class="field">
          <label>Custo Estimado (R$)</label>
          <input type="number" id="mc-custo" step="0.0001" min="0" value="0">
        </div>
      </div>
      <div class="field">
        <label>Fornecedor</label>
        <input type="text" id="mc-fornecedor" placeholder="Nome do fornecedor">
      </div>
      <div class="field">
        <label>Data de Necessidade</label>
        <input type="date" id="mc-data-nec">
      </div>
      <div class="field">
        <label>Notas</label>
        <textarea id="mc-notas" rows="2" placeholder="Observações, especificações do produto..."></textarea>
      </div>
      <div id="mc-total-preview" style="background:var(--card2);border-radius:8px;padding:10px 14px;font-size:13px;display:none">
        Total estimado: <strong id="mc-total-val" style="color:var(--acc)"></strong>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-compra')">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="mc-submit">💾 Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Alerta de Custo de Prato -->
<div class="overlay" id="modal-alerta-custo">
  <div class="modal" style="width:500px">
    <h3>💰 Custo de Pratos Afetado</h3>
    <p style="font-size:13px;color:var(--text2);margin-bottom:12px">O custo médio deste insumo mudou. Os seguintes pratos foram impactados:</p>
    <div id="alerta-custo-lista" style="display:flex;flex-direction:column;gap:8px;max-height:300px;overflow-y:auto;margin-bottom:16px"></div>
    <div class="modal-actions">
      <button class="btn btn-primary" onclick="closeModal('modal-alerta-custo')">Entendido</button>
    </div>
  </div>
</div>

<!-- Modal: Sugestão de Compras IA -->
<div class="overlay" id="modal-ia">
  <div class="modal" style="width:620px">
    <h3>🤖 Sugestão de Compras — IA</h3>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <div class="field" style="flex:1;min-width:160px">
        <label>Orçamento disponível (R$)</label>
        <input type="number" id="ia-orcamento" value="5000" min="0" step="100" style="padding:10px 12px;background:var(--card);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:inherit;font-size:14px;outline:none;width:100%">
      </div>
      <button class="btn btn-primary" onclick="buscarSugestaoIA()" style="margin-top:18px">Gerar Sugestão</button>
    </div>
    <div id="ia-loading" style="display:none;text-align:center;padding:30px;color:var(--text2);font-size:14px">
      <span class="spin">⟳</span>&nbsp; Consultando Claude IA...
    </div>
    <div id="ia-resultado" style="display:none">
      <div style="font-size:12px;color:var(--text3);margin-bottom:8px" id="ia-meta"></div>
      <div class="ia-result" id="ia-itens"></div>
      <div class="ia-obs" id="ia-obs" style="display:none"></div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid var(--border);margin-top:10px">
        <span style="color:var(--text2);font-size:13px">Total estimado:</span>
        <strong id="ia-total" style="color:var(--acc);font-size:18px"></strong>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" onclick="closeModal('modal-ia')">Fechar</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
'use strict';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API_ROOT = '../../api/';

// ── Helpers ───────────────────────────────────────────────────────────
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmt = v => 'R$ ' + parseFloat(v || 0).toFixed(4).replace('.', ',');
const fmtQty = (v, u) => parseFloat(v || 0).toFixed(3).replace('.', ',') + ' ' + (u || '');
const fmtDt = iso => {
  try { return new Date(iso).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
  catch { return iso; }
};

function toast(msg, type='ok') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = 'show ' + type;
  clearTimeout(el._t); el._t = setTimeout(() => el.className = '', 3500);
}

async function api(path, opts = {}) {
  const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, ...(opts.headers || {}) };
  try {
    const res = await fetch(API_ROOT + path, { ...opts, headers });
    return await res.json();
  } catch (e) {
    return { success: false, error: 'Erro de conexão' };
  }
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(o =>
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); })
);

// ── Tabs ─────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
  if (name === 'movimentacoes') loadMovimentacoes(1);
  if (name === 'insumos') loadInsumos();
  if (name === 'relatorio') { initRelatorio(); gerarRelatorio(); }
  if (name === 'inteligente') { renderizarInteligente(); renderGraficos(); }
  if (name === 'lotes') { popularSelectLotes(); loadLotes(); }
  if (name === 'compras') loadCompras();
}
document.querySelectorAll('.tab-btn').forEach(b =>
  b.addEventListener('click', () => switchTab(b.dataset.tab))
);

// ═══════════════════════════════════════════════════════════════════════
// INSUMOS
// ═══════════════════════════════════════════════════════════════════════
let todosInsumos = [];

async function loadInsumos() {
  const res = await api('insumos.php');
  if (!res.success) { toast(res.error || 'Erro ao carregar insumos', 'err'); return; }

  todosInsumos = res.data || [];
  renderizarInsumos();
  atualizarKPIs();
  atualizarAlerta();
  popularSelectInsumos();
}

function renderizarInsumos() {
  const busca  = document.getElementById('ins-busca').value.toLowerCase();
  const filtro = document.getElementById('ins-filtro-status').value.toLowerCase();
  const abc    = document.getElementById('ins-filtro-abc').value;

  const lista = todosInsumos.filter(i => {
    if (busca && !i.nome.toLowerCase().includes(busca)) return false;
    const nivel = (i.nivel_alerta || 'OK').toLowerCase();
    if (filtro && nivel !== filtro) return false;
    if (abc && (i.classe_abc || '') !== abc) return false;
    return true;
  });

  document.getElementById('ins-count').textContent = lista.length + ' insumo(s)';

  const valorTotal = todosInsumos.reduce((s, i) => s + parseFloat(i.estoque_atual||0)*parseFloat(i.custo_medio||0), 0);
  document.getElementById('ins-valor-total').textContent =
    'Valor total em estoque: R$ ' + valorTotal.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});

  if (!lista.length) {
    document.getElementById('ins-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="9">Nenhum insumo encontrado.</td></tr>';
    return;
  }

  const nivelMap = {
    CRITICO: { cls:'badge-critico', lbl:'Crítico', fill:'fill-critical' },
    URGENTE: { cls:'badge-urgente', lbl:'Urgente', fill:'fill-critical' },
    ATENCAO: { cls:'badge-atencao', lbl:'Atenção', fill:'fill-low'      },
    FRIO:    { cls:'badge-frio',    lbl:'Frio',    fill:'fill-ok'       },
    OK:      { cls:'badge-ok',      lbl:'OK',      fill:'fill-ok'       },
  };
  const abcCls = { A:'abc-a', B:'abc-b', C:'abc-c' };

  const armazMap = {
    refrigerado:{ icon:'❄️', color:'var(--blue)' },
    congelado:  { icon:'🧊', color:'#22d3ee'     },
    seco:       { icon:'📦', color:'var(--gold)'  },
    ambiente:   { icon:'🌡️', color:'var(--text3)' },
  };

  document.getElementById('ins-tbody').innerHTML = lista.map(i => {
    const pct   = Math.min(parseFloat(i.percentual_estoque ?? 100), 150);
    const nivel = i.nivel_alerta || 'OK';
    const { cls, lbl, fill } = nivelMap[nivel] || nivelMap.OK;
    const fillW  = Math.min(Math.max(pct,0),100).toFixed(0);
    const valor  = parseFloat(i.estoque_atual||0) * parseFloat(i.custo_medio||0);
    const abc    = i.classe_abc || '';
    const dias   = i.dias_cobertura != null ? i.dias_cobertura : null;
    const diasHtml = dias != null
      ? `<span class="cobertura-pill" style="${dias<=3?'background:rgba(239,68,68,.15);color:var(--red)':dias<=7?'background:rgba(245,158,11,.15);color:var(--gold)':''}">${dias}d</span>`
      : '<span style="color:var(--text4);font-size:12px">—</span>';
    const arm = armazMap[i.armazenamento] || armazMap.ambiente;

    return `<tr>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <span title="${esc(i.armazenamento||'ambiente')}" style="font-size:15px">${arm.icon}</span>
          <div>
            <div style="font-weight:700;font-size:13px">${esc(i.nome)}</div>
            ${i.fornecedor ? `<div style="font-size:11px;color:var(--text3)">${esc(i.fornecedor)}</div>` : ''}
          </div>
        </div>
        <div style="margin-top:5px"><div class="stock-bar"><div class="stock-fill ${fill}" style="width:${fillW}%"></div></div></div>
      </td>
      <td style="text-align:center">${abc ? `<span class="abc-chip ${abcCls[abc]||''}">${abc}</span>` : '<span style="color:var(--text4)">—</span>'}</td>
      <td>
        <div style="font-weight:600;font-size:13px">${fmtQty(i.estoque_atual, i.unidade)}</div>
        <div style="font-size:11px;color:var(--text3)">mín: ${fmtQty(i.estoque_minimo, i.unidade)}</div>
      </td>
      <td style="text-align:right;color:var(--text3);font-size:12px">${fmtQty(i.estoque_minimo, i.unidade)}</td>
      <td style="text-align:right;color:var(--text2);font-size:12px">${fmt(i.custo_medio)}</td>
      <td style="text-align:right;font-weight:600;color:var(--acc-l)">R$ ${valor.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
      <td style="text-align:center">${diasHtml}</td>
      <td style="text-align:center"><span class="badge ${cls}">${lbl}</span></td>
      <td style="text-align:center">
        <button class="btn btn-secondary btn-xs" onclick="openAjusteEstoque(${i.id})" title="Movimentar">⇅</button>
        <button class="btn btn-secondary btn-xs" onclick="openEditarInsumo(${i.id})" title="Editar" style="margin-left:4px">✏️</button>
      </td>
    </tr>`;
  }).join('');
}

function atualizarKPIs() {
  const total      = todosInsumos.length;
  const criticos   = todosInsumos.filter(i => i.nivel_alerta === 'CRITICO').length;
  const urgentes   = todosInsumos.filter(i => i.nivel_alerta === 'URGENTE').length;
  const atencao    = todosInsumos.filter(i => i.nivel_alerta === 'ATENCAO').length;
  const valorTotal = todosInsumos.reduce((s, i) => s + parseFloat(i.estoque_atual||0)*parseFloat(i.custo_medio||0), 0);

  // KPIs globais topo
  document.getElementById('kpi-grid').innerHTML = [
    { label:'Total de Insumos', value:total,     color:'var(--blue)',  sub:'ativos' },
    { label:'Críticos',         value:criticos,  color:'var(--red)',   sub:'estoque zerado' },
    { label:'Atenção/Urgente',  value:urgentes+atencao, color:'var(--gold)', sub:'abaixo do ROP' },
    { label:'Valor em Estoque', value:'R$ '+valorTotal.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}), color:'var(--green)', sub:'custo × quantidade' },
  ].map(k =>
    `<div class="kpi-card" style="--c:${k.color}">
      <div class="kpi-label">${k.label}</div>
      <div class="kpi-value" style="color:${k.color};font-size:${typeof k.value==='string'?'20px':'30px'}">${k.value}</div>
      <div class="kpi-sub">${k.sub}</div>
    </div>`
  ).join('');

  // KPIs da aba insumos
  document.getElementById('ins-kpi-row').innerHTML = [
    { icon:'📦', bg:'rgba(59,130,246,.15)',  color:'var(--blue)',  val:total,                    lbl:'Total' },
    { icon:'🔴', bg:'rgba(239,68,68,.15)',   color:'var(--red)',   val:criticos,                 lbl:'Críticos' },
    { icon:'🟠', bg:'rgba(249,115,22,.15)',  color:'#fb923c',      val:urgentes,                 lbl:'Urgentes' },
    { icon:'🟡', bg:'rgba(245,158,11,.15)',  color:'var(--gold)',  val:atencao,                  lbl:'Atenção' },
    { icon:'🟢', bg:'rgba(34,197,94,.15)',   color:'var(--green)', val:total-criticos-urgentes-atencao, lbl:'OK' },
  ].map(k =>
    `<div class="ins-kpi">
      <div class="ins-kpi-icon" style="background:${k.bg};color:${k.color}">${k.icon}</div>
      <div class="ins-kpi-txt">
        <div class="ins-kpi-val" style="color:${k.color}">${k.val}</div>
        <div class="ins-kpi-lbl">${k.lbl}</div>
      </div>
    </div>`
  ).join('');
}

function atualizarAlerta() {
  const alertas = todosInsumos.filter(i => i.abaixo_minimo === true || i.abaixo_minimo === 't' || i.abaixo_minimo === '1').length;
  const banner = document.getElementById('alerta-banner');
  if (alertas > 0) {
    document.getElementById('alerta-count').textContent = alertas;
    banner.classList.remove('hidden');
  } else {
    banner.classList.add('hidden');
  }
}

function popularSelectInsumos() {
  const sel = document.getElementById('mov-filtro-insumo');
  const cur = sel.value;
  sel.innerHTML = '<option value="">Todos os insumos</option>' +
    todosInsumos.map(i => `<option value="${i.id}">${esc(i.nome)} (${esc(i.unidade)})</option>`).join('');
  sel.value = cur;
}

// ── Busca e filtro ────────────────────────────────────────────────────
document.getElementById('ins-busca').addEventListener('input', renderizarInsumos);
document.getElementById('ins-filtro-status').addEventListener('change', renderizarInsumos);
document.getElementById('ins-filtro-abc').addEventListener('change', renderizarInsumos);

// ── Novo insumo ───────────────────────────────────────────────────────
function resetFormInsumo() {
  ['m-ins-id','m-ins-nome','m-ins-codigo','m-ins-fornecedor','m-ins-alergenos','m-ins-obs'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.getElementById('m-ins-unidade').value      = 'UN';
  document.getElementById('m-ins-categoria').value    = 'alimento';
  document.getElementById('m-ins-armazenamento').value= 'ambiente';
  document.getElementById('m-ins-custo').value        = '0';
  document.getElementById('m-ins-minimo').value       = '0';
  document.getElementById('m-ins-maximo').value       = '';
  document.getElementById('m-ins-validade').value     = '';
  document.getElementById('m-ins-atual').value        = '0';
}

function openNovoInsumo() {
  resetFormInsumo();
  document.getElementById('m-ins-title').textContent  = '+ Novo Insumo';
  document.getElementById('m-ins-atual-row').style.display    = 'block';
  document.getElementById('m-ins-avancado-row').style.display = 'none';
  openModal('modal-insumo');
}

function openEditarInsumo(id) {
  const ins = todosInsumos.find(i => +i.id === +id);
  if (!ins) return;
  resetFormInsumo();

  document.getElementById('m-ins-title').textContent      = '✏️ Editar Insumo';
  document.getElementById('m-ins-id').value               = ins.id;
  document.getElementById('m-ins-nome').value             = ins.nome;
  document.getElementById('m-ins-codigo').value           = ins.codigo          || '';
  document.getElementById('m-ins-fornecedor').value       = ins.fornecedor      || '';
  document.getElementById('m-ins-categoria').value        = ins.categoria_insumo|| 'alimento';
  document.getElementById('m-ins-unidade').value          = ins.unidade;
  document.getElementById('m-ins-armazenamento').value    = ins.armazenamento   || 'ambiente';
  document.getElementById('m-ins-custo').value            = parseFloat(ins.custo_medio   || 0).toFixed(4);
  document.getElementById('m-ins-minimo').value           = parseFloat(ins.estoque_minimo|| 0).toFixed(3);
  document.getElementById('m-ins-maximo').value           = ins.estoque_maximo  != null ? parseFloat(ins.estoque_maximo).toFixed(3) : '';
  document.getElementById('m-ins-validade').value         = ins.validade_dias   || '';
  document.getElementById('m-ins-alergenos').value        = ins.alergenos       || '';
  document.getElementById('m-ins-obs').value              = ins.observacoes     || '';

  document.getElementById('m-ins-atual-row').style.display    = 'none';
  document.getElementById('m-ins-avancado-row').style.display = 'flex';
  document.getElementById('m-ins-lead').value         = ins.lead_time_days    || 2;
  document.getElementById('m-ins-custo-pedido').value = parseFloat(ins.custo_por_pedido || 25).toFixed(2);
  document.getElementById('m-ins-dias-alvo').value    = ins.dias_estoque_alvo || 15;
  openModal('modal-insumo');
}

document.getElementById('form-insumo').addEventListener('submit', async e => {
  e.preventDefault();
  const id     = document.getElementById('m-ins-id').value;
  const nome   = document.getElementById('m-ins-nome').value.trim();
  const method = id ? 'PUT' : 'POST';
  const maxVal   = document.getElementById('m-ins-maximo').value;
  const validVal = document.getElementById('m-ins-validade').value;

  const body = {
    nome,
    unidade:           document.getElementById('m-ins-unidade').value,
    custo_medio:       parseFloat(document.getElementById('m-ins-custo').value) || 0,
    estoque_minimo:    parseFloat(document.getElementById('m-ins-minimo').value) || 0,
    codigo:            document.getElementById('m-ins-codigo').value.trim(),
    fornecedor:        document.getElementById('m-ins-fornecedor').value.trim(),
    categoria_insumo:  document.getElementById('m-ins-categoria').value,
    armazenamento:     document.getElementById('m-ins-armazenamento').value,
    validade_dias:     validVal !== '' ? parseInt(validVal) : '',
    estoque_maximo:    maxVal   !== '' ? parseFloat(maxVal) : '',
    alergenos:         document.getElementById('m-ins-alergenos').value.trim(),
    observacoes:       document.getElementById('m-ins-obs').value.trim(),
  };
  if (id) {
    body.id                = +id;
    body.lead_time_days    = parseInt(document.getElementById('m-ins-lead').value) || 2;
    body.custo_por_pedido  = parseFloat(document.getElementById('m-ins-custo-pedido').value) || 25;
    body.dias_estoque_alvo = parseInt(document.getElementById('m-ins-dias-alvo').value) || 15;
  } else {
    body.estoque_atual = parseFloat(document.getElementById('m-ins-atual').value) || 0;
  }

  const res = await api('insumos.php', { method, body: JSON.stringify(body) });
  if (res.success) {
    toast(id ? 'Insumo atualizado!' : 'Insumo criado!');
    closeModal('modal-insumo');
    loadInsumos();
    // Alertas de custo de prato
    if (res.data?.alertas_pratos?.length) exibirAlertaCusto(res.data.alertas_pratos);
  } else {
    toast(res.error || 'Erro ao salvar', 'err');
  }
});

// ═══════════════════════════════════════════════════════════════════════
// AJUSTE DE ESTOQUE
// ═══════════════════════════════════════════════════════════════════════
let ajusteInsumoAtual = null;

function openAjusteEstoque(id) {
  const ins = todosInsumos.find(i => +i.id === +id);
  if (!ins) return;
  ajusteInsumoAtual = ins;

  document.getElementById('aj-insumo-id').value   = ins.id;
  document.getElementById('aj-insumo-nome').value = ins.nome + ' (' + ins.unidade + ')';
  document.getElementById('aj-tipo').value        = 'entrada';
  document.getElementById('aj-quantidade').value  = '';
  document.getElementById('aj-custo').value       = parseFloat(ins.custo_medio).toFixed(4);
  document.getElementById('aj-motivo').value      = '';

  atualizarInfoAjuste();
  openModal('modal-ajuste');
}

document.getElementById('aj-tipo').addEventListener('change', atualizarInfoAjuste);
document.getElementById('aj-quantidade').addEventListener('input', atualizarInfoAjuste);

function atualizarInfoAjuste() {
  const tipo = document.getElementById('aj-tipo').value;
  const info = document.getElementById('aj-info');
  const custoRow = document.getElementById('aj-custo-row');
  const qtyLabel = document.getElementById('aj-qty-label');

  custoRow.style.display = tipo === 'entrada' ? '' : 'none';

  if (!ajusteInsumoAtual) return;

  const estAtual = parseFloat(ajusteInsumoAtual.estoque_atual || 0);
  const qty      = parseFloat(document.getElementById('aj-quantidade').value || 0);

  if (tipo === 'ajuste') {
    qtyLabel.textContent = 'Novo valor do estoque *';
    info.style.display   = '';
    const diff = qty - estAtual;
    info.innerHTML = `Estoque atual: <strong>${fmtQty(estAtual, ajusteInsumoAtual.unidade)}</strong>
      → Novo: <strong>${fmtQty(qty, ajusteInsumoAtual.unidade)}</strong>
      (${diff >= 0 ? '+' : ''}${fmtQty(diff, ajusteInsumoAtual.unidade)})`;
  } else {
    qtyLabel.textContent = 'Quantidade *';
    if (tipo === 'saida' && qty > 0) {
      info.style.display = '';
      const novo = estAtual - qty;
      info.innerHTML = `Estoque após saída: <strong style="color:${novo < 0 ? 'var(--red)' : 'var(--text)'}">${fmtQty(novo, ajusteInsumoAtual.unidade)}</strong>`;
    } else {
      info.style.display = 'none';
    }
  }
}

document.getElementById('form-ajuste').addEventListener('submit', async e => {
  e.preventDefault();
  const tipo      = document.getElementById('aj-tipo').value;
  const insumo_id = +document.getElementById('aj-insumo-id').value;
  const quantidade = parseFloat(document.getElementById('aj-quantidade').value);
  const custo_unitario = parseFloat(document.getElementById('aj-custo').value) || 0;
  const motivo    = document.getElementById('aj-motivo').value.trim();

  if (!quantidade || quantidade <= 0) { toast('Informe uma quantidade válida', 'err'); return; }

  const res = await api('estoque.php', {
    method: 'POST',
    body: JSON.stringify({ action: tipo, insumo_id, quantidade, custo_unitario, motivo }),
  });

  if (res.success) {
    toast('Movimentação registrada!');
    closeModal('modal-ajuste');
    loadInsumos();
  } else {
    toast(res.error || 'Erro ao registrar movimentação', 'err');
  }
});

// ═══════════════════════════════════════════════════════════════════════
// MOVIMENTAÇÕES
// ═══════════════════════════════════════════════════════════════════════
let movPage = 1, movData = [];

async function loadMovimentacoes(page = 1) {
  movPage = page;
  const insumo_id = document.getElementById('mov-filtro-insumo').value;
  const tipo      = document.getElementById('mov-filtro-tipo').value;

  if (!insumo_id) {
    // Sem filtro de insumo: mostrar mensagem orientativa
    document.getElementById('mov-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="7">Selecione um insumo para ver as movimentações.</td></tr>';
    document.getElementById('mov-count').textContent = 'Movimentações';
    document.getElementById('mov-pagination').innerHTML = '';
    return;
  }

  const limit  = 30;
  const offset = (page - 1) * limit;
  const params = new URLSearchParams({ action: 'movimentacoes', insumo_id, limit, offset });

  const res = await api('estoque.php?' + params.toString());
  if (!res.success) { toast(res.error || 'Erro ao carregar movimentações', 'err'); return; }

  let lista = res.data || [];
  if (tipo) lista = lista.filter(m => m.tipo === tipo);

  movData = lista;
  document.getElementById('mov-count').textContent = lista.length + ' movimentação(ões)';

  if (!lista.length) {
    document.getElementById('mov-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="7">Nenhuma movimentação encontrada.</td></tr>';
    document.getElementById('mov-pagination').innerHTML = '';
    document.getElementById('mov-summary').style.display = 'none';
    return;
  }

  const tipoInfo = {
    entrada: { icon:'📈', cor:'var(--green)', sinal:'+', cls:'mov-row-entrada' },
    saida:   { icon:'📉', cor:'var(--red)',   sinal:'−', cls:'mov-row-saida'   },
    ajuste:  { icon:'🔧', cor:'var(--blue)',  sinal:'≡', cls:'mov-row-ajuste'  },
  };

  document.getElementById('mov-tbody').innerHTML = lista.map(m => {
    const t = tipoInfo[m.tipo] || tipoInfo.ajuste;
    return `<tr class="${t.cls}">
      <td style="color:var(--text3);font-size:12px;white-space:nowrap">${fmtDt(m.criado_em)}</td>
      <td><strong>${esc(m.insumo_nome || '')}</strong></td>
      <td style="text-align:center">
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700">
          <span class="mov-tipo-icon" style="background:${m.tipo==='entrada'?'rgba(34,197,94,.15)':m.tipo==='saida'?'rgba(239,68,68,.15)':'rgba(59,130,246,.15)'}">${t.icon}</span>
          <span class="badge badge-${m.tipo}">${m.tipo}</span>
        </span>
      </td>
      <td style="text-align:right;font-weight:700;color:${t.cor}">${t.sinal} ${fmtQty(Math.abs(m.quantidade), m.unidade || '')}</td>
      <td style="text-align:right;color:var(--text3);font-size:12px">${parseFloat(m.custo_unitario||0)>0 ? fmt(m.custo_unitario) : '—'}</td>
      <td style="color:var(--text2);font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(m.motivo||'')}">${esc(m.motivo || '—')}</td>
      <td style="text-align:center;color:var(--text3);font-size:11px">${esc(m.usuario_nome || '—')}</td>
    </tr>`;
  }).join('');

  // Resumo do período
  const totEnt  = lista.filter(m=>m.tipo==='entrada').reduce((s,m)=>s+parseFloat(m.quantidade||0),0);
  const totSaid = lista.filter(m=>m.tipo==='saida').reduce((s,m)=>s+parseFloat(m.quantidade||0),0);
  const totAdj  = lista.filter(m=>m.tipo==='ajuste').length;
  const custEnt = lista.filter(m=>m.tipo==='entrada').reduce((s,m)=>s+parseFloat(m.quantidade||0)*parseFloat(m.custo_unitario||0),0);
  document.getElementById('mov-summary').style.display = 'flex';
  document.getElementById('mov-summary').innerHTML = [
    { lbl:'Entradas', val: totEnt.toLocaleString('pt-BR',{maximumFractionDigits:3}), color:'var(--green)' },
    { lbl:'Saídas',   val: totSaid.toLocaleString('pt-BR',{maximumFractionDigits:3}), color:'var(--red)' },
    { lbl:'Ajustes',  val: totAdj, color:'var(--blue)' },
    { lbl:'Custo total entradas', val:'R$ '+custEnt.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}), color:'var(--acc-l)' },
  ].map(s=>`<div class="mov-summary-item">
    <span class="mov-summary-label">${s.lbl}</span>
    <span class="mov-summary-value" style="color:${s.color}">${s.val}</span>
  </div>`).join('');

  document.getElementById('mov-pagination').innerHTML = '';
}

document.getElementById('mov-filtro-insumo').addEventListener('change', () => loadMovimentacoes(1));
document.getElementById('mov-filtro-tipo').addEventListener('change', () => loadMovimentacoes(1));

// ═══════════════════════════════════════════════════════════════════════
// FICHAS TÉCNICAS
// ═══════════════════════════════════════════════════════════════════════
let fichaAtualData  = [];
let allInsumosLocal = [];

async function loadFicha() {
  const produto_id = document.getElementById('ficha-produto-sel').value;
  if (!produto_id) { toast('Selecione um produto', 'err'); return; }

  const res = await api('estoque.php?action=ficha&produto_id=' + produto_id);
  if (!res.success) { toast(res.error || 'Erro ao carregar ficha', 'err'); return; }

  fichaAtualData = res.data || [];

  if (!fichaAtualData.length) {
    document.getElementById('ficha-empty').style.display = 'block';
    document.getElementById('ficha-cards-wrap').style.display = 'none';
    document.getElementById('ficha-custo-venda').style.display = 'none';
    return;
  }

  document.getElementById('ficha-empty').style.display = 'none';
  document.getElementById('ficha-cards-wrap').style.display = 'block';

  // Calcular custo total por venda
  const custoTotal = fichaAtualData.reduce((s, f) =>
    s + parseFloat(f.quantidade||0) * parseFloat(f.custo_medio||0), 0);

  document.getElementById('ficha-custo-venda').style.display = 'block';
  document.getElementById('ficha-custo-venda').textContent =
    '💰 Custo por venda: R$ ' + custoTotal.toLocaleString('pt-BR',{minimumFractionDigits:4,maximumFractionDigits:4});

  // Renderizar cards
  document.getElementById('ficha-cards').innerHTML = fichaAtualData.map(f => {
    const saldo   = parseFloat(f.estoque_atual || 0);
    const qty     = parseFloat(f.quantidade || 0);
    const custo   = parseFloat(f.custo_medio || 0);
    const porcoes = qty > 0 ? Math.floor(saldo / qty) : 0;
    const corStock = saldo <= 0 ? 'var(--red)' : saldo < qty * 5 ? 'var(--gold)' : 'var(--green)';
    const iconStock = saldo <= 0 ? '⚠️' : saldo < qty * 5 ? '⚡' : '✅';

    return `<div class="ficha-card">
      <div class="ficha-card-nome">
        <span style="font-size:16px">${saldo<=0?'🔴':saldo<qty*5?'🟡':'🟢'}</span>
        ${esc(f.insumo_nome)}
      </div>
      <div>
        <span class="ficha-card-qty">${qty.toLocaleString('pt-BR',{maximumFractionDigits:4})}</span>
        <span class="ficha-card-unit">${esc(f.unidade)} / venda</span>
      </div>
      <div class="ficha-card-stock" style="border-left:3px solid ${corStock}">
        <span>${iconStock}</span>
        <div>
          <div style="font-weight:600;color:${corStock}">${fmtQty(saldo, f.unidade)} em estoque</div>
          <div style="font-size:11px;color:var(--text3)">${porcoes} porção(ões) possíveis</div>
        </div>
      </div>
      <div class="ficha-card-meta">
        <span>Custo unit.: <strong style="color:var(--text)">R$ ${custo.toLocaleString('pt-BR',{minimumFractionDigits:4,maximumFractionDigits:4})}</strong></span>
        <span>Subtotal: <strong style="color:var(--acc-l)">R$ ${(qty*custo).toLocaleString('pt-BR',{minimumFractionDigits:4,maximumFractionDigits:4})}</strong></span>
      </div>
    </div>`;
  }).join('');

  // Rodapé com totais
  const semEstoque = fichaAtualData.filter(f => parseFloat(f.estoque_atual||0) <= 0).length;
  document.getElementById('ficha-footer').innerHTML = `
    <div style="font-size:13px;color:var(--text2)">
      ${fichaAtualData.length} ingrediente(s)
      ${semEstoque > 0 ? `<span style="color:var(--red);margin-left:10px">⚠️ ${semEstoque} sem estoque</span>` : ''}
    </div>
    <div style="text-align:right">
      <div style="font-size:11px;color:var(--text3);margin-bottom:2px">CUSTO TOTAL POR VENDA</div>
      <div style="font-size:20px;font-weight:900;color:var(--acc)">R$ ${custoTotal.toLocaleString('pt-BR',{minimumFractionDigits:4,maximumFractionDigits:4})}</div>
    </div>`;
}

// ── Modal de edição de ficha ──────────────────────────────────────────
let fichaLinhas = []; // [{insumo_id, quantidade}]

async function openEditarFicha() {
  // Garantir que temos a lista de insumos
  if (!todosInsumos.length) await loadInsumos();
  allInsumosLocal = todosInsumos;

  // Pre-selecionar produto da aba
  const prodSel = document.getElementById('ficha-produto-sel').value;
  document.getElementById('m-ficha-produto').value = prodSel;

  fichaLinhas = fichaAtualData.map(f => ({ insumo_id: f.insumo_id, quantidade: f.quantidade }));
  renderLinhasFicha();
  openModal('modal-ficha');
}

async function carregarFichaNoModal() {
  const produto_id = document.getElementById('m-ficha-produto').value;
  if (!produto_id) { fichaLinhas = []; renderLinhasFicha(); return; }

  const res = await api('estoque.php?action=ficha&produto_id=' + produto_id);
  fichaLinhas = (res.data || []).map(f => ({ insumo_id: f.insumo_id, quantidade: f.quantidade }));
  renderLinhasFicha();
}

function renderLinhasFicha() {
  const container = document.getElementById('m-ficha-itens');
  if (!fichaLinhas.length) {
    container.innerHTML = '<div style="color:var(--text3);font-size:13px;padding:8px 0">Nenhum insumo adicionado.</div>';
    return;
  }
  container.innerHTML = fichaLinhas.map((linha, idx) => {
    const optInsm = allInsumosLocal.map(i =>
      `<option value="${i.id}" ${+i.id === +linha.insumo_id ? 'selected' : ''}>${esc(i.nome)} (${esc(i.unidade)})</option>`
    ).join('');
    return `<div class="ficha-item" data-idx="${idx}">
      <select class="fi-insumo" style="flex:1;background:var(--card);border:1px solid var(--border2);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;padding:7px 10px;outline:none" onchange="fichaLinhas[${idx}].insumo_id=+this.value">
        <option value="">Selecione...</option>${optInsm}
      </select>
      <input class="fi-qty" type="number" step="0.0001" min="0.0001" value="${parseFloat(linha.quantidade).toFixed(4)}"
        style="width:110px;background:var(--card);border:1px solid var(--border2);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;padding:7px 10px;outline:none"
        onchange="fichaLinhas[${idx}].quantidade=+this.value">
      <button type="button" class="btn btn-danger btn-xs" onclick="removerLinhaFicha(${idx})">✕</button>
    </div>`;
  }).join('');
}

function adicionarLinhaFicha() {
  fichaLinhas.push({ insumo_id: 0, quantidade: 0 });
  renderLinhasFicha();
}

function removerLinhaFicha(idx) {
  fichaLinhas.splice(idx, 1);
  renderLinhasFicha();
}

async function salvarFicha() {
  const produto_id = +document.getElementById('m-ficha-produto').value;
  if (!produto_id) { toast('Selecione um produto', 'err'); return; }

  // Coletar valores atuais dos inputs (podem não ter disparado onchange)
  document.querySelectorAll('#m-ficha-itens .ficha-item').forEach((el, idx) => {
    if (fichaLinhas[idx]) {
      fichaLinhas[idx].insumo_id = +el.querySelector('.fi-insumo').value;
      fichaLinhas[idx].quantidade = +el.querySelector('.fi-qty').value;
    }
  });

  const itens = fichaLinhas.filter(l => l.insumo_id > 0 && l.quantidade > 0);

  const res = await api('estoque.php', {
    method: 'POST',
    body: JSON.stringify({ action: 'salvar_ficha', produto_id, itens }),
  });

  if (res.success) {
    toast('Ficha técnica salva!');
    closeModal('modal-ficha');
    // Atualizar a aba de fichas se o produto coincidir
    const fichaSelProd = document.getElementById('ficha-produto-sel');
    fichaSelProd.value = produto_id;
    loadFicha();
  } else {
    toast(res.error || 'Erro ao salvar ficha', 'err');
  }
}

// ═══════════════════════════════════════════════════════════════════════
// RELATÓRIO DE CONSUMO DE INSUMOS — REDESIGN
// ═══════════════════════════════════════════════════════════════════════
let _relInited = false;
let _relDados  = [];   // cache dos dados brutos

function initRelatorio() {
  if (_relInited) return;
  _relInited = true;
  setPreset('mes');

  // Botões de preset
  document.querySelectorAll('.preset-btn').forEach(btn =>
    btn.addEventListener('click', () => {
      document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      setPreset(btn.dataset.p);
      gerarRelatorio();
    })
  );
}

function setPreset(p) {
  const hoje    = new Date();
  const pad     = n => String(n).padStart(2, '0');
  const isoDate = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

  let ini, fim = isoDate(hoje);

  if (p === 'hoje') {
    ini = isoDate(hoje);
  } else if (p === 'semana') {
    const s = new Date(hoje); s.setDate(hoje.getDate() - 6);
    ini = isoDate(s);
  } else if (p === 'mes') {
    ini = `${hoje.getFullYear()}-${pad(hoje.getMonth()+1)}-01`;
  } else if (p === 'mes_ant') {
    const ma = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
    const mf = new Date(hoje.getFullYear(), hoje.getMonth(), 0);
    ini = isoDate(ma); fim = isoDate(mf);
  } else if (p === 'trim') {
    const t = new Date(hoje); t.setDate(hoje.getDate() - 89);
    ini = isoDate(t);
  } else {
    return; // custom — não altera
  }

  document.getElementById('rel-ini').value = ini;
  document.getElementById('rel-fim').value = fim;
}

async function gerarRelatorio() {
  const ini = document.getElementById('rel-ini').value;
  const fim = document.getElementById('rel-fim').value;
  if (!ini || !fim) { toast('Selecione o período', 'err'); return; }

  // Loading
  document.getElementById('rel-loading').style.display = 'block';
  document.getElementById('rel-kpis').style.display    = 'none';
  document.getElementById('rel-card').style.display    = 'none';
  document.getElementById('rel-empty').style.display   = 'none';
  document.getElementById('btn-export-csv').style.display = 'none';

  const res = await api('estoque.php?action=relatorio&data_ini=' + ini + '&data_fim=' + fim);
  document.getElementById('rel-loading').style.display = 'none';

  if (!res.success) { toast(res.error || 'Erro ao gerar relatório', 'err'); return; }

  const { insumos, total_custo_saidas, periodo } = res.data;
  _relDados = insumos || [];

  // ── KPIs ──────────────────────────────────────────────────────────
  const totalSaidas   = _relDados.reduce((s, i) => s + parseFloat(i.total_saida   || 0), 0);
  const totalEntradas = _relDados.reduce((s, i) => s + parseFloat(i.total_entrada || 0), 0);
  const custTotal     = parseFloat(total_custo_saidas || 0);
  const fmtBR        = (v, d=2) => v.toLocaleString('pt-BR', {minimumFractionDigits:d, maximumFractionDigits:d});

  const kpis = [
    { icon:'📦', bg:'rgba(59,130,246,.15)',  color:'var(--blue)',   label:'Insumos',        value: _relDados.length,            sub:'com movimentação' },
    { icon:'📉', bg:'rgba(239,68,68,.12)',   color:'var(--red)',    label:'Total Saídas',   value: fmtBR(totalSaidas, 3),       sub:'unidades consumidas' },
    { icon:'📈', bg:'rgba(34,197,94,.12)',   color:'var(--green)',  label:'Total Entradas', value: fmtBR(totalEntradas, 3),     sub:'unidades recebidas' },
    { icon:'💰', bg:'rgba(245,158,11,.12)',  color:'var(--gold)',   label:'Custo das Saídas', value:'R$ '+fmtBR(custTotal),     sub:'qtd × custo médio' },
  ];

  document.getElementById('rel-kpis').className  = 'rel-kpi-grid';
  document.getElementById('rel-kpis').style.display = '';
  document.getElementById('rel-kpis').innerHTML = kpis.map(k => `
    <div class="rel-kpi">
      <div class="rel-kpi-icon" style="background:${k.bg};color:${k.color}">${k.icon}</div>
      <div class="rel-kpi-info">
        <div class="rel-kpi-label">${k.label}</div>
        <div class="rel-kpi-value" style="color:${k.color}">${k.value}</div>
        <div class="rel-kpi-sub">${k.sub}</div>
      </div>
    </div>`).join('');

  // ── Período label ─────────────────────────────────────────────────
  const fmtDate = d => new Date(d + 'T12:00').toLocaleDateString('pt-BR', {day:'2-digit',month:'short',year:'numeric'});
  document.getElementById('rel-periodo').textContent =
    '📅 ' + fmtDate(periodo.ini) + '  →  ' + fmtDate(periodo.fim);

  // ── Vazio ─────────────────────────────────────────────────────────
  if (!_relDados.length) {
    document.getElementById('rel-empty').style.display = 'block';
    return;
  }

  document.getElementById('rel-card').style.display = '';
  document.getElementById('btn-export-csv').style.display = '';
  renderRelatorio();

  // ── Rodapé ────────────────────────────────────────────────────────
  const comSaida = _relDados.filter(i => parseFloat(i.total_saida) > 0).length;
  document.getElementById('rel-summary').innerHTML = `
    <div style="display:flex;gap:6px;align-items:center;color:var(--text3);font-size:12px">
      <span>${_relDados.length} insumos</span>
      <span style="color:var(--border2)">|</span>
      <span>${comSaida} com saída no período</span>
    </div>
    <div style="display:flex;gap:20px;align-items:center">
      <div style="text-align:right">
        <div style="font-size:11px;color:var(--text3);margin-bottom:2px">CUSTO TOTAL SAÍDAS</div>
        <div style="font-size:20px;font-weight:900;color:var(--acc)">R$ ${fmtBR(custTotal)}</div>
      </div>
    </div>`;
}

function renderRelatorio() {
  const busca  = (document.getElementById('rel-busca')?.value || '').toLowerCase();
  const ordem  = document.getElementById('rel-ordem')?.value || 'custo_total_saida_desc';
  const fmtBR  = (v, d=2) => parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:d, maximumFractionDigits:d});

  let lista = busca
    ? _relDados.filter(i => i.nome.toLowerCase().includes(busca))
    : [..._relDados];

  // Ordenação
  const [campo, dir] = ordem.split('_asc').length > 1
    ? [ordem.replace('_asc',''), 'asc']
    : [ordem.replace('_desc',''), 'desc'];

  lista.sort((a, b) => {
    const va = campo === 'nome' ? a.nome : parseFloat(a[campo] || 0);
    const vb = campo === 'nome' ? b.nome : parseFloat(b[campo] || 0);
    if (va < vb) return dir === 'asc' ? -1 :  1;
    if (va > vb) return dir === 'asc' ?  1 : -1;
    return 0;
  });

  // Max saída para escala das barras
  const maxSaida = Math.max(...lista.map(i => parseFloat(i.total_saida || 0)), 1);

  if (!lista.length) {
    document.getElementById('rel-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="8">Nenhum insumo encontrado.</td></tr>';
    return;
  }

  document.getElementById('rel-tbody').innerHTML = lista.map(i => {
    const saida     = parseFloat(i.total_saida    || 0);
    const entrada   = parseFloat(i.total_entrada  || 0);
    const saldo     = parseFloat(i.estoque_atual  || 0);
    const custo     = parseFloat(i.custo_medio    || 0);
    const custoSaid = parseFloat(i.custo_total_saida || 0);
    const giro      = entrada > 0 ? Math.min((saida / entrada) * 100, 100) : (saida > 0 ? 100 : 0);
    const giroColor = giro >= 80 ? 'var(--red)' : giro >= 50 ? 'var(--gold)' : 'var(--green)';
    const abc       = i.classe_abc || '';
    const abcCls    = { A:'abc-a', B:'abc-b', C:'abc-c' }[abc] || '';
    const barW      = saida > 0 ? Math.max((saida / maxSaida) * 100, 4).toFixed(1) : 0;

    const corSaida  = saida  > 0 ? 'var(--red)'    : 'var(--text4)';
    const corEnt    = entrada > 0 ? 'var(--green)'  : 'var(--text4)';
    const corSaldo  = saldo  <= 0 ? 'var(--red)'    : saldo < custo ? 'var(--gold)' : 'var(--text)';

    return `<tr>
      <td>
        <div class="rel-nome-cell">
          <div style="display:flex;align-items:center;gap:6px">
            ${abc ? `<span class="abc-chip ${abcCls}">${abc}</span>` : ''}
            <span class="rel-nome-main">${esc(i.nome)}</span>
          </div>
          ${saida > 0 ? `<div style="margin-top:5px"><div class="giro-bar"><div class="giro-fill" style="width:${barW}%;background:${giroColor}"></div></div></div>` : ''}
        </div>
      </td>
      <td style="text-align:center;color:var(--text3);font-size:12px">${esc(i.unidade)}</td>
      <td style="text-align:right;font-weight:${saida>0?'700':'400'};color:${corSaida}">
        ${saida > 0 ? `<span style="font-size:11px">−</span> ${fmtBR(saida, 3)}` : '<span style="color:var(--text4)">—</span>'}
      </td>
      <td style="text-align:right;color:${corEnt}">
        ${entrada > 0 ? `<span style="font-size:11px">+</span> ${fmtBR(entrada, 3)}` : '<span style="color:var(--text4)">—</span>'}
      </td>
      <td style="text-align:right;font-weight:600;color:${corSaldo}">
        ${fmtBR(saldo, 3)} <span style="font-size:11px;color:var(--text3)">${esc(i.unidade)}</span>
      </td>
      <td style="text-align:right;color:var(--text3);font-size:12px">R$ ${fmtBR(custo, 4)}</td>
      <td style="text-align:right;font-weight:700;color:${custoSaid>0?'var(--acc-l)':'var(--text4)'}">
        ${custoSaid > 0 ? 'R$ ' + fmtBR(custoSaid) : '—'}
      </td>
      <td>
        ${saida > 0 ? `
          <div class="giro-label">${giro.toFixed(0)}% consumido</div>
          <div class="giro-bar"><div class="giro-fill" style="width:${giro.toFixed(1)}%;background:${giroColor}"></div></div>
        ` : '<span style="color:var(--text4);font-size:12px">sem saída</span>'}
      </td>
    </tr>`;
  }).join('');
}

function exportarCSV() {
  if (!_relDados.length) return;
  const ini = document.getElementById('rel-ini').value;
  const fim = document.getElementById('rel-fim').value;
  const fmtBR = (v, d=2) => parseFloat(v||0).toFixed(d).replace('.', ',');

  const header = ['Insumo','Unidade','ABC','Saídas','Entradas','Saldo Atual','Custo Médio','Custo Saídas'];
  const rows = _relDados.map(i => [
    `"${i.nome}"`, i.unidade, i.classe_abc || '',
    fmtBR(i.total_saida, 3), fmtBR(i.total_entrada, 3),
    fmtBR(i.estoque_atual, 3), fmtBR(i.custo_medio, 4), fmtBR(i.custo_total_saida),
  ]);

  const csv = [header, ...rows].map(r => r.join(';')).join('\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = `relatorio_estoque_${ini}_${fim}.csv`; a.click();
  URL.revokeObjectURL(url);
  toast('CSV exportado!');
}

// ═══════════════════════════════════════════════════════════════════════
// ABA INTELIGENTE
// ═══════════════════════════════════════════════════════════════════════
const nivelMapInt = {
  CRITICO: { cls: 'badge-critico', lbl: 'Crítico'  },
  URGENTE: { cls: 'badge-urgente', lbl: 'Urgente'  },
  ATENCAO: { cls: 'badge-atencao', lbl: 'Atenção'  },
  FRIO:    { cls: 'badge-frio',    lbl: 'Frio'     },
  OK:      { cls: 'badge-ok',      lbl: 'OK'        },
};

function renderizarInteligente() {
  if (!todosInsumos.length) { loadInsumos().then(renderizarInteligente); return; }

  const busca  = (document.getElementById('int-busca')?.value || '').toLowerCase();
  const filtroN = document.getElementById('int-filtro-nivel')?.value || '';

  const ordemNivel = { CRITICO:0, URGENTE:1, ATENCAO:2, OK:3, FRIO:4 };
  let lista = [...todosInsumos]
    .filter(i => {
      if (busca && !i.nome.toLowerCase().includes(busca)) return false;
      if (filtroN && (i.nivel_alerta || 'OK') !== filtroN) return false;
      return true;
    })
    .sort((a, b) =>
      (ordemNivel[a.nivel_alerta ?? 'OK'] ?? 5) - (ordemNivel[b.nivel_alerta ?? 'OK'] ?? 5)
    );

  document.getElementById('int-count').textContent = lista.length + ' insumo(s)';

  // KPIs por nível
  const contagem = { CRITICO:0, URGENTE:0, ATENCAO:0, OK:0, FRIO:0 };
  todosInsumos.forEach(i => { contagem[i.nivel_alerta || 'OK'] = (contagem[i.nivel_alerta || 'OK'] || 0) + 1; });
  document.getElementById('int-kpi-row').innerHTML = [
    { num: contagem.CRITICO, lbl:'Crítico',  color:'var(--red)',   bg:'rgba(239,68,68,.08)', border:'rgba(239,68,68,.2)'  },
    { num: contagem.URGENTE, lbl:'Urgente',  color:'#fb923c',      bg:'rgba(249,115,22,.08)', border:'rgba(249,115,22,.2)' },
    { num: contagem.ATENCAO, lbl:'Atenção',  color:'var(--gold)',  bg:'rgba(245,158,11,.08)', border:'rgba(245,158,11,.2)' },
    { num: contagem.OK,      lbl:'OK',       color:'var(--green)', bg:'rgba(34,197,94,.08)', border:'rgba(34,197,94,.2)'  },
    { num: contagem.FRIO,    lbl:'Frio',     color:'var(--blue)',  bg:'rgba(59,130,246,.08)', border:'rgba(59,130,246,.2)' },
  ].map(k => `
    <div class="int-kpi" style="background:${k.bg};border-color:${k.border}">
      <div class="int-kpi-num" style="color:${k.color}">${k.num}</div>
      <div class="int-kpi-lbl">${k.lbl}</div>
    </div>`).join('');

  // Tabela
  const abcCls = { A:'abc-a', B:'abc-b', C:'abc-c' };
  const rowCls = { CRITICO:'nivel-row-critico', URGENTE:'nivel-row-urgente', ATENCAO:'nivel-row-atencao' };
  const fmtM = v => parseFloat(v||0) > 0
    ? parseFloat(v).toLocaleString('pt-BR',{maximumFractionDigits:3})
    : '<span style="color:var(--text4)">—</span>';

  document.getElementById('int-tbody').innerHTML = lista.map(i => {
    const { cls, lbl } = nivelMapInt[i.nivel_alerta] || nivelMapInt.OK;
    const abc  = i.classe_abc || '';
    const dias = i.dias_cobertura != null
      ? `<span style="font-weight:700;color:${i.dias_cobertura<=3?'var(--red)':i.dias_cobertura<=7?'var(--gold)':'var(--text2)'}">${i.dias_cobertura}d</span>`
      : '—';
    const rowClass = rowCls[i.nivel_alerta] || '';

    return `<tr class="${rowClass}">
      <td>
        <div style="font-weight:700">${esc(i.nome)}</div>
        <div style="font-size:11px;color:var(--text3)">${esc(i.unidade)}</div>
      </td>
      <td style="text-align:center">${abc ? `<span class="abc-chip ${abcCls[abc]||''}">${abc}</span>` : '<span style="color:var(--text4)">—</span>'}</td>
      <td style="text-align:center"><span class="badge ${cls}">${lbl}</span></td>
      <td style="text-align:right;font-weight:600">${fmtQty(i.estoque_atual, i.unidade)}</td>
      <td style="text-align:center">${dias}</td>
      <td style="text-align:right;color:var(--text2)">${fmtM(i.safety_stock)} ${parseFloat(i.safety_stock||0)>0?'<span style="font-size:10px;color:var(--text3)">'+esc(i.unidade)+'</span>':''}</td>
      <td style="text-align:right;color:var(--gold);font-weight:600">${fmtM(i.rop)} ${parseFloat(i.rop||0)>0?'<span style="font-size:10px;color:var(--text3)">'+esc(i.unidade)+'</span>':''}</td>
      <td style="text-align:right;color:var(--blue)">${fmtM(i.eoq)} ${parseFloat(i.eoq||0)>0?'<span style="font-size:10px;color:var(--text3)">'+esc(i.unidade)+'</span>':''}</td>
      <td style="text-align:center;color:var(--text3)">${i.lead_time_days || 2}d</td>
    </tr>`;
  }).join('');
}

async function recalcularIndicadores() {
  const btn = document.getElementById('btn-recalcular');
  btn.disabled = true;
  btn.textContent = '⟳ Calculando...';

  const res = await api('recalcular_indicadores.php', { method: 'POST' });

  btn.disabled = false;
  btn.textContent = '↻ Recalcular';

  if (res.success) {
    toast(`Recalculado: ${res.data.insumos_processados} insumos, ABC atualizado.`);
    await loadInsumos();
    renderizarInteligente();
  } else {
    toast(res.error || 'Erro ao recalcular', 'err');
  }
}

// ═══════════════════════════════════════════════════════════════════════
// SUGESTÃO DE COMPRAS IA
// ═══════════════════════════════════════════════════════════════════════
function openSugestaoIA() {
  document.getElementById('ia-loading').style.display   = 'none';
  document.getElementById('ia-resultado').style.display = 'none';
  openModal('modal-ia');
}

async function buscarSugestaoIA() {
  const orcamento = parseFloat(document.getElementById('ia-orcamento').value) || 5000;

  document.getElementById('ia-loading').style.display   = 'block';
  document.getElementById('ia-resultado').style.display = 'none';

  const res = await api('sugestao_compras_ia.php?orcamento=' + orcamento);

  document.getElementById('ia-loading').style.display = 'none';

  if (!res.success) {
    toast(res.error || 'Erro ao consultar IA', 'err');
    return;
  }

  const { sugestao, insumos_analisados, em_alerta, estoque_frio } = res.data;

  // Mensagem sem alertas
  if (sugestao.mensagem && (!sugestao.itens || !sugestao.itens.length)) {
    document.getElementById('ia-meta').textContent = sugestao.mensagem;
    document.getElementById('ia-itens').innerHTML = '';
    document.getElementById('ia-obs').style.display = 'none';
    document.getElementById('ia-total').textContent = 'R$ 0,00';
    document.getElementById('ia-resultado').style.display = 'block';
    return;
  }

  const priorMap = { ALTA: 'badge-urgente', MEDIA: 'badge-atencao', BAIXA: 'badge-frio' };
  const itens = sugestao.itens || [];

  document.getElementById('ia-meta').textContent =
    `${insumos_analisados} insumos analisados — ${em_alerta ?? '?'} em alerta — ${estoque_frio ?? '?'} com excesso`;

  document.getElementById('ia-itens').innerHTML = itens.map(it => {
    const cls = priorMap[it.prioridade] || 'badge-ok';
    const qty  = parseFloat(it.quantidade_sugerida || 0).toLocaleString('pt-BR', {maximumFractionDigits:3});
    return `<div class="ia-item">
      <span class="ia-item-nome">${esc(it.nome)}</span>
      <span><span class="badge ${cls} badge-sm">${esc(it.prioridade || '')}</span></span>
      <span class="ia-item-just">${esc(it.justificativa || '')}</span>
      <span class="ia-item-qty">× ${qty}</span>
    </div>`;
  }).join('');

  const obs = sugestao.observacoes;
  if (obs) {
    document.getElementById('ia-obs').textContent = obs;
    document.getElementById('ia-obs').style.display = 'block';
  } else {
    document.getElementById('ia-obs').style.display = 'none';
  }

  const total = parseFloat(sugestao.total_estimado || 0);
  document.getElementById('ia-total').textContent =
    'R$ ' + total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  document.getElementById('ia-resultado').style.display = 'block';
}

// ═══════════════════════════════════════════════════════════════════════
// LOTES
// ═══════════════════════════════════════════════════════════════════════
let _lotesData = [];
let _lotesInsumoId = null;

function popularSelectLotes() {
  const sel = document.getElementById('lotes-filtro-insumo');
  const cur = sel.value;
  sel.innerHTML = '<option value="">Selecione um insumo...</option>' +
    todosInsumos.map(i => `<option value="${i.id}">${esc(i.nome)} (${esc(i.unidade)})</option>`).join('');
  if (cur) sel.value = cur;
}

async function loadLotes() {
  const insumo_id = document.getElementById('lotes-filtro-insumo').value;
  if (!insumo_id) { _lotesData = []; renderLotes(); return; }
  _lotesInsumoId = +insumo_id;
  const res = await api(`lotes.php?insumo_id=${insumo_id}`);
  if (!res.success) { toast(res.error || 'Erro ao carregar lotes','err'); return; }
  _lotesData = res.data || [];
  renderLotes();
  // Checar alertas globais de vencimento
  const res2 = await api('lotes.php?action=proximos_vencer&dias=30');
  if (res2.success && res2.data.length) {
    document.getElementById('lotes-alerta-vencidos').style.display = 'flex';
    document.getElementById('lotes-alerta-txt').textContent =
      `${res2.data.length} lote(s) com validade nos próximos 30 dias em todo o estoque.`;
  }
}

function filtrarLotes() { renderLotes(); }

function renderLotes() {
  const filtro = document.getElementById('lotes-filtro-status').value;
  const lista  = filtro ? _lotesData.filter(l => l.status_validade === filtro) : _lotesData;
  document.getElementById('lotes-count').textContent = lista.length + ' lote(s)';

  if (!lista.length) {
    document.getElementById('lotes-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="8">Nenhum lote encontrado.</td></tr>';
    return;
  }

  const stMap = {
    ok:          { cls:'vb-ok',      icon:'✅', lbl:'OK',         row:'lote-row-ok'      },
    atencao:     { cls:'vb-atencao', icon:'⚠️', lbl:'Atenção',   row:'lote-row-atencao' },
    critico:     { cls:'vb-critico', icon:'🔴', lbl:'Crítico',   row:'lote-row-critico' },
    vencido:     { cls:'vb-vencido', icon:'💀', lbl:'Vencido',   row:'lote-row-vencido' },
    indeterminado:{ cls:'vb-nd',     icon:'—',  lbl:'Sem val.',  row:''                 },
  };

  const fmtDate = d => d ? new Date(d+'T12:00').toLocaleDateString('pt-BR') : '—';

  document.getElementById('lotes-tbody').innerHTML = lista.map(l => {
    const sv = stMap[l.status_validade] || stMap.indeterminado;
    const dias = l.dias_para_vencer != null
      ? `(${l.dias_para_vencer > 0 ? l.dias_para_vencer+'d' : 'hoje'})`
      : '';
    return `<tr class="${sv.row}">
      <td>
        <div style="font-weight:700">${l.numero_lote ? esc(l.numero_lote) : '<span style="color:var(--text4)">sem número</span>'}</div>
        <div style="font-size:11px;color:var(--text3)">${esc(l.fornecedor||'—')}</div>
      </td>
      <td style="color:var(--text2);font-size:12px">${fmtDate(l.data_entrada)}</td>
      <td style="text-align:center">
        <span class="validade-badge ${sv.cls}">${sv.icon} ${fmtDate(l.data_validade)} ${dias}</span>
      </td>
      <td style="text-align:right;color:var(--text3)">${parseFloat(l.quantidade_inicial).toLocaleString('pt-BR',{maximumFractionDigits:3})}</td>
      <td style="text-align:right;font-weight:700;color:${parseFloat(l.quantidade_atual)<=0?'var(--text4)':'var(--text)'}">${parseFloat(l.quantidade_atual).toLocaleString('pt-BR',{maximumFractionDigits:3})}</td>
      <td style="text-align:right;color:var(--text3);font-size:12px">R$ ${parseFloat(l.custo_unitario||0).toLocaleString('pt-BR',{minimumFractionDigits:4,maximumFractionDigits:4})}</td>
      <td style="font-size:12px;color:var(--text3)">${esc(l.observacoes||'—')}</td>
      <td style="text-align:center">
        ${sv.lbl!=='Vencido'?`<button class="btn btn-danger btn-xs" onclick="deletarLote(${l.id})" title="Desativar">✕</button>`:''}
      </td>
    </tr>`;
  }).join('');
}

function openNovoLote() {
  if (!todosInsumos.length) { toast('Carregue os insumos primeiro','err'); return; }
  const insumo_id = document.getElementById('lotes-filtro-insumo').value;
  if (!insumo_id) { toast('Selecione um insumo primeiro','err'); return; }
  const ins = todosInsumos.find(i => +i.id === +insumo_id);
  document.getElementById('l-insumo-id').value  = insumo_id;
  document.getElementById('l-insumo-nome').value = ins ? ins.nome + ' (' + ins.unidade + ')' : '';
  document.getElementById('l-numero').value      = '';
  document.getElementById('l-fornecedor').value  = ins?.fornecedor || '';
  document.getElementById('l-entrada').value     = new Date().toISOString().slice(0,10);
  document.getElementById('l-validade').value    = ins?.validade_dias
    ? new Date(Date.now() + ins.validade_dias*864e5).toISOString().slice(0,10) : '';
  document.getElementById('l-quantidade').value  = '';
  document.getElementById('l-custo').value       = parseFloat(ins?.custo_medio||0).toFixed(4);
  document.getElementById('l-obs').value         = '';
  checkAvisoValidade();
  openModal('modal-lote');
}

document.getElementById('l-validade')?.addEventListener('change', checkAvisoValidade);

function checkAvisoValidade() {
  const val = document.getElementById('l-validade').value;
  const aviso = document.getElementById('l-aviso-validade');
  if (!val) { aviso.style.display = 'none'; return; }
  const dias = Math.floor((new Date(val) - new Date()) / 864e5);
  aviso.style.display = dias <= 30 ? 'block' : 'none';
  aviso.textContent   = dias < 0 ? '⚠️ Data de validade já passou!'
    : `⚠️ Este lote vence em ${dias} dia(s).`;
}

document.getElementById('form-lote')?.addEventListener('submit', async e => {
  e.preventDefault();
  const body = {
    insumo_id:     +document.getElementById('l-insumo-id').value,
    numero_lote:    document.getElementById('l-numero').value.trim(),
    fornecedor:     document.getElementById('l-fornecedor').value.trim(),
    data_entrada:   document.getElementById('l-entrada').value,
    data_validade:  document.getElementById('l-validade').value || null,
    quantidade:     parseFloat(document.getElementById('l-quantidade').value),
    custo_unitario: parseFloat(document.getElementById('l-custo').value) || 0,
    observacoes:    document.getElementById('l-obs').value.trim(),
  };
  const res = await api('lotes.php', {method:'POST', body: JSON.stringify(body)});
  if (res.success) {
    toast('Lote registrado! Estoque atualizado.');
    closeModal('modal-lote');
    loadLotes();
    loadInsumos();
  } else { toast(res.error||'Erro ao registrar','err'); }
});

async function deletarLote(id) {
  if (!confirm('Desativar este lote?')) return;
  const res = await api(`lotes.php?id=${id}`, {method:'DELETE'});
  if (res.success) { toast('Lote desativado.'); loadLotes(); }
  else toast(res.error||'Erro','err');
}

// ═══════════════════════════════════════════════════════════════════════
// DESPERDÍCIO
// ═══════════════════════════════════════════════════════════════════════
async function gerarDesperdicio() {
  const ini = document.getElementById('rel-ini')?.value || new Date().toISOString().slice(0,8)+'01';
  const fim = document.getElementById('rel-fim')?.value || new Date().toISOString().slice(0,10);

  document.getElementById('desp-loading').style.display = 'block';
  document.getElementById('desp-kpis').style.display    = 'none';
  document.getElementById('desp-card').style.display    = 'none';

  const res = await api(`desperdicio.php?data_ini=${ini}&data_fim=${fim}`);
  document.getElementById('desp-loading').style.display = 'none';

  if (!res.success) { toast(res.error||'Erro na análise','err'); return; }

  const { insumos, total_desperdicio, percentual_geral, total_teorico, total_real } = res.data;
  const fmtBR = (v,d=2) => parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d});

  document.getElementById('desp-kpis').style.display = '';
  document.getElementById('desp-kpis').innerHTML = [
    { icon:'📉', bg:'rgba(59,130,246,.1)', color:'var(--blue)',  label:'Insumos analisados',  value: insumos.length, sub:'' },
    { icon:'⚖️', bg:'rgba(107,114,128,.1)', color:'var(--text)', label:'Consumo teórico',     value: fmtBR(total_teorico,3), sub:'un. esperadas' },
    { icon:'📊', bg:'rgba(245,158,11,.1)', color:'var(--gold)',  label:'Consumo real',         value: fmtBR(total_real,3), sub:'un. consumidas' },
    { icon:'🗑️', bg:'rgba(239,68,68,.1)', color:'var(--red)',   label:'Custo do Desperdício', value:'R$ '+fmtBR(total_desperdicio), sub:`${percentual_geral}% do teórico` },
  ].map(k=>`<div class="rel-kpi">
    <div class="rel-kpi-icon" style="background:${k.bg};color:${k.color}">${k.icon}</div>
    <div class="rel-kpi-info">
      <div class="rel-kpi-label">${k.label}</div>
      <div class="rel-kpi-value" style="color:${k.color}">${k.value}</div>
      <div class="rel-kpi-sub">${k.sub}</div>
    </div></div>`).join('');

  const comDesp = insumos.filter(i=>i.desperdicio>0);
  if (!comDesp.length) {
    document.getElementById('desp-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="8">✅ Nenhum desperdício detectado no período.</td></tr>';
    document.getElementById('desp-card').style.display = '';
    return;
  }

  document.getElementById('desp-tbody').innerHTML = comDesp.map(i => {
    const cor = i.nivel==='ok'?'desp-ok':i.nivel==='atencao'?'desp-atencao':'desp-critico';
    return `<tr>
      <td><strong>${esc(i.insumo_nome)}</strong></td>
      <td style="text-align:center;color:var(--text3);font-size:12px">${esc(i.unidade)}</td>
      <td style="text-align:right;color:var(--text2)">${fmtBR(i.consumo_teorico,3)}</td>
      <td style="text-align:right">${fmtBR(i.consumo_real,3)}</td>
      <td style="text-align:right;font-weight:700;color:var(--red)">${fmtBR(i.desperdicio,3)}</td>
      <td style="text-align:right"><span class="${cor}">${i.percentual}%</span></td>
      <td style="text-align:right;font-weight:700;color:var(--red)">R$ ${fmtBR(i.custo_desperdicio)}</td>
      <td style="text-align:center"><span class="badge ${i.nivel==='ok'?'badge-ok':i.nivel==='atencao'?'badge-atencao':'badge-critico'}">${i.nivel==='ok'?'OK':i.nivel==='atencao'?'Atenção':'Crítico'}</span></td>
    </tr>`;
  }).join('');

  document.getElementById('desp-summary').innerHTML =
    `<div style="display:flex;justify-content:space-between;font-size:13px;flex-wrap:wrap;gap:8px">
      <span style="color:var(--text2)">${comDesp.length} insumos com desperdício detectado</span>
      <strong style="color:var(--red)">Total perdido: R$ ${fmtBR(total_desperdicio)}</strong>
    </div>`;
  document.getElementById('desp-card').style.display = '';
}

// ═══════════════════════════════════════════════════════════════════════
// COMPRAS
// ═══════════════════════════════════════════════════════════════════════
let _comprasData = [];
let _comprasStatus = 'pendente';

async function loadCompras() {
  const res = await api('lista_compras.php?status=' + _comprasStatus);
  if (!res.success) { toast(res.error||'Erro','err'); return; }
  _comprasData = res.data || [];
  renderCompras();
  carregarResumoCompras();
}

async function carregarResumoCompras() {
  const res = await api('lista_compras.php?action=resumo');
  if (!res.success) return;
  const r = res.data;
  ['pendente','aprovado','pedido','recebido'].forEach(s => {
    const el = document.getElementById('sf-n-'+s);
    if (el) el.textContent = r[s] || 0;
  });
}

function switchComprasStatus(s) {
  _comprasStatus = s;
  document.querySelectorAll('.sf-step').forEach(b => b.classList.toggle('active', b.dataset.s === s));
  loadCompras();
}

function renderCompras() {
  const busca = document.getElementById('compras-busca').value.toLowerCase();
  const prio  = document.getElementById('compras-prioridade').value;

  const lista = _comprasData.filter(c => {
    if (busca && !c.nome_insumo.toLowerCase().includes(busca)) return false;
    if (prio && c.prioridade !== prio) return false;
    return true;
  });

  document.getElementById('compras-count').textContent = lista.length + ' solicitação(ões)';

  const total = lista.reduce((s,c)=>s+parseFloat(c.quantidade||0)*parseFloat(c.custo_estimado||0),0);
  const totEl = document.getElementById('compras-total-val');
  if (total>0) totEl.textContent = 'Total est.: R$ '+total.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  else totEl.textContent = '';

  if (!lista.length) {
    document.getElementById('compras-tbody').innerHTML =
      `<tr class="empty-row"><td colspan="9">Nenhuma solicitação com status "${_comprasStatus}".</td></tr>`;
    document.getElementById('compras-summary').style.display = 'none';
    return;
  }

  const prioMap = { ALTA:{cls:'compra-row-alta',badge:'badge-critico',icon:'🔴'},
                    MEDIA:{cls:'compra-row-media',badge:'badge-atencao',icon:'🟡'},
                    BAIXA:{cls:'compra-row-baixa',badge:'badge-frio',icon:'🔵'} };
  const nextMap = { pendente:[{s:'aprovado',lbl:'✅ Aprovar'},{s:'cancelado',lbl:'❌ Cancelar',cls:'btn-danger'}],
                    aprovado:[{s:'pedido',lbl:'📦 Pedido feito'},{s:'pendente',lbl:'↩ Reverter'}],
                    pedido:  [{s:'recebido',lbl:'✅ Recebido'},{s:'aprovado',lbl:'↩ Reverter'}],
                    recebido:[] };
  const fmtDate = d => d ? new Date(d+'T12:00').toLocaleDateString('pt-BR') : '—';
  const fmtBR = (v,d=2) => parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d});

  document.getElementById('compras-tbody').innerHTML = lista.map(c => {
    const pm = prioMap[c.prioridade] || prioMap.MEDIA;
    const totC = parseFloat(c.quantidade||0)*parseFloat(c.custo_estimado||0);
    const acoes = (nextMap[_comprasStatus]||[]).map(a=>
      `<button class="btn ${a.cls||'btn-secondary'} btn-xs" onclick="mudarStatusCompra(${c.id},'${a.s}')">${a.lbl}</button>`
    ).join(' ');
    return `<tr class="${pm.cls}">
      <td>
        <div style="font-weight:700">${esc(c.nome_insumo)}</div>
        <div style="font-size:11px;color:var(--text3)">${esc(c.notas||'')}</div>
      </td>
      <td style="text-align:center"><span class="badge ${pm.badge}">${pm.icon} ${c.prioridade}</span></td>
      <td style="text-align:right;font-weight:700">${fmtBR(c.quantidade,3)} ${esc(c.unidade)}</td>
      <td style="text-align:right;color:var(--text3);font-size:12px">R$ ${fmtBR(c.custo_estimado,4)}</td>
      <td style="text-align:right;font-weight:600;color:var(--acc-l)">${totC>0?'R$ '+fmtBR(totC):'—'}</td>
      <td style="font-size:12px;color:var(--text2)">${esc(c.fornecedor||'—')}</td>
      <td style="font-size:11px;color:var(--text3)">${fmtDate(c.data_necessidade)}</td>
      <td style="font-size:11px;color:var(--text3)">${esc(c.criado_por_nome||'—')}</td>
      <td style="text-align:center;white-space:nowrap">${acoes}</td>
    </tr>`;
  }).join('');

  document.getElementById('compras-summary').style.display = 'flex';
  document.getElementById('compras-summary').innerHTML =
    `<span style="color:var(--text2)">${lista.length} item(ns)</span>
     <strong style="color:var(--acc)">R$ ${fmtBR(total)}</strong>`;
}

async function mudarStatusCompra(id, status) {
  const res = await api('lista_compras.php', {method:'PUT', body:JSON.stringify({id, status})});
  if (res.success) {
    toast(status === 'recebido' ? '✅ Recebido! Estoque atualizado.' : `Status: ${status}`);
    loadCompras();
    if (status === 'recebido') loadInsumos();
  } else toast(res.error||'Erro','err');
}

function openNovaCompra(prefill) {
  document.getElementById('mc-id').value = '';
  document.getElementById('mc-title').textContent = '🛒 Nova Solicitação de Compra';
  document.getElementById('mc-insumo').innerHTML =
    '<option value="">— Selecione —</option>' +
    todosInsumos.map(i=>`<option value="${i.id}">${esc(i.nome)} (${esc(i.unidade)})</option>`).join('');

  if (prefill) {
    document.getElementById('mc-nome').value       = prefill.nome || '';
    document.getElementById('mc-quantidade').value = prefill.quantidade || '';
    document.getElementById('mc-custo').value      = prefill.custo || 0;
    document.getElementById('mc-prioridade').value = prefill.prioridade || 'MEDIA';
    document.getElementById('mc-notas').value      = prefill.justificativa || '';
    // Tentar encontrar o insumo pelo nome
    const match = todosInsumos.find(i=>i.nome.toLowerCase()===prefill.nome?.toLowerCase());
    if (match) {
      document.getElementById('mc-insumo').value  = match.id;
      document.getElementById('mc-unidade').value = match.unidade;
      document.getElementById('mc-fornecedor').value = match.fornecedor||'';
    }
  } else {
    ['mc-nome','mc-notas','mc-fornecedor'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('mc-insumo').value     = '';
    document.getElementById('mc-quantidade').value = '';
    document.getElementById('mc-custo').value      = '0';
    document.getElementById('mc-prioridade').value = 'MEDIA';
    document.getElementById('mc-unidade').value    = 'UN';
    document.getElementById('mc-data-nec').value   = '';
  }
  atualizarTotalCompra();
  openModal('modal-compra');
}

function preencherDadosCompra() {
  const id  = document.getElementById('mc-insumo').value;
  if (!id) return;
  const ins = todosInsumos.find(i=>+i.id===+id);
  if (!ins) return;
  document.getElementById('mc-nome').value      = ins.nome;
  document.getElementById('mc-unidade').value   = ins.unidade;
  document.getElementById('mc-custo').value     = parseFloat(ins.custo_medio||0).toFixed(4);
  document.getElementById('mc-fornecedor').value= ins.fornecedor||'';
  // Sugerir EOQ como quantidade
  if (parseFloat(ins.eoq||0)>0) document.getElementById('mc-quantidade').value = parseFloat(ins.eoq).toFixed(3);
  atualizarTotalCompra();
}

['mc-quantidade','mc-custo'].forEach(id =>
  document.getElementById(id)?.addEventListener('input', atualizarTotalCompra)
);

function atualizarTotalCompra() {
  const qty  = parseFloat(document.getElementById('mc-quantidade').value)||0;
  const cost = parseFloat(document.getElementById('mc-custo').value)||0;
  const total = qty * cost;
  const wrap = document.getElementById('mc-total-preview');
  if (total > 0) {
    wrap.style.display = 'block';
    document.getElementById('mc-total-val').textContent =
      'R$ '+total.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  } else { wrap.style.display = 'none'; }
}

document.getElementById('form-compra')?.addEventListener('submit', async e => {
  e.preventDefault();
  const id = document.getElementById('mc-id').value;
  const body = {
    insumo_id:       +document.getElementById('mc-insumo').value || null,
    nome_insumo:     document.getElementById('mc-nome').value.trim(),
    unidade:         document.getElementById('mc-unidade').value,
    quantidade:      parseFloat(document.getElementById('mc-quantidade').value),
    custo_estimado:  parseFloat(document.getElementById('mc-custo').value)||0,
    fornecedor:      document.getElementById('mc-fornecedor').value.trim(),
    prioridade:      document.getElementById('mc-prioridade').value,
    notas:           document.getElementById('mc-notas').value.trim(),
    data_necessidade:document.getElementById('mc-data-nec').value||null,
  };
  const res = await api('lista_compras.php', { method: id?'PUT':'POST', body: JSON.stringify({...body, id:id?+id:undefined}) });
  if (res.success) {
    toast(id?'Atualizado!':'Solicitação criada!');
    closeModal('modal-compra');
    loadCompras();
  } else toast(res.error||'Erro','err');
});

// Importar sugestões da IA para lista de compras
async function importarDaIA() {
  const apiKey = ''; // A chave está no .env — chamamos o endpoint existente
  const res = await api('sugestao_compras_ia.php?orcamento=10000');
  if (!res.success) { toast(res.error||'Configure a ANTHROPIC_API_KEY no .env','err'); return; }
  const itens = res.data?.sugestao?.itens || [];
  if (!itens.length) { toast('IA não gerou sugestões no momento.'); return; }
  if (!confirm(`Importar ${itens.length} sugestão(ões) da IA para a lista de compras?`)) return;
  let criados = 0;
  for (const it of itens) {
    const ins = todosInsumos.find(i=>i.nome.toLowerCase()===it.nome?.toLowerCase());
    const body = {
      nome_insumo:   it.nome,
      quantidade:    parseFloat(it.quantidade_sugerida)||0,
      prioridade:    it.prioridade||'MEDIA',
      notas:         it.justificativa||'',
      insumo_id:     ins?.id||null,
      unidade:       ins?.unidade||'UN',
      custo_estimado:ins?.custo_medio||0,
      fornecedor:    ins?.fornecedor||'',
    };
    const r = await api('lista_compras.php',{method:'POST',body:JSON.stringify(body)});
    if (r.success) criados++;
  }
  toast(`${criados} itens importados da IA!`);
  loadCompras();
}

// ═══════════════════════════════════════════════════════════════════════
// GRÁFICOS (Chart.js)
// ═══════════════════════════════════════════════════════════════════════
let _charts = {};

function renderGraficos() {
  if (!todosInsumos.length) return;
  document.getElementById('int-graficos').style.display = 'block';

  const sorted  = [...todosInsumos].sort((a,b)=>
    (parseFloat(b.estoque_atual||0)*parseFloat(b.custo_medio||0)) -
    (parseFloat(a.estoque_atual||0)*parseFloat(a.custo_medio||0))
  ).slice(0,10);

  // Destruir charts anteriores
  Object.values(_charts).forEach(c=>c.destroy());
  _charts = {};

  const dark = '#f0f2f8'; const grid = 'rgba(255,255,255,.07)';

  // 1. Top 10 por valor em estoque (bar horizontal)
  _charts.abc = new Chart(document.getElementById('chart-abc'), {
    type: 'bar',
    data: {
      labels: sorted.map(i=>i.nome.length>18?i.nome.slice(0,18)+'…':i.nome),
      datasets:[{ label:'Valor (R$)',
        data: sorted.map(i=>+(parseFloat(i.estoque_atual||0)*parseFloat(i.custo_medio||0)).toFixed(2)),
        backgroundColor: sorted.map(i=>i.classe_abc==='A'?'rgba(239,68,68,.7)':i.classe_abc==='B'?'rgba(245,158,11,.7)':'rgba(107,114,128,.7)'),
        borderRadius:4 }]
    },
    options:{ indexAxis:'y', plugins:{legend:{display:false}}, scales:{
      x:{ticks:{color:dark},grid:{color:grid}},y:{ticks:{color:dark},grid:{color:'transparent'}}
    }}
  });

  // 2. Distribuição por nível (doughnut)
  const nivelCount = { CRITICO:0,URGENTE:0,ATENCAO:0,OK:0,FRIO:0 };
  todosInsumos.forEach(i=>{ nivelCount[i.nivel_alerta||'OK'] = (nivelCount[i.nivel_alerta||'OK']||0)+1; });
  _charts.nivel = new Chart(document.getElementById('chart-nivel'), {
    type: 'doughnut',
    data: {
      labels:['Crítico','Urgente','Atenção','OK','Frio'],
      datasets:[{ data:[nivelCount.CRITICO,nivelCount.URGENTE,nivelCount.ATENCAO,nivelCount.OK,nivelCount.FRIO],
        backgroundColor:['rgba(239,68,68,.8)','rgba(249,115,22,.8)','rgba(245,158,11,.8)','rgba(34,197,94,.8)','rgba(59,130,246,.8)'],
        borderWidth:0 }]
    },
    options:{ plugins:{ legend:{ labels:{ color:dark, boxWidth:12 }, position:'bottom' } } }
  });

  // 3. Top 10 cobertura em dias (bar)
  const comDias = todosInsumos.filter(i=>i.dias_cobertura!=null)
    .sort((a,b)=>a.dias_cobertura-b.dias_cobertura).slice(0,10);
  _charts.cobertura = new Chart(document.getElementById('chart-cobertura'), {
    type: 'bar',
    data: {
      labels: comDias.map(i=>i.nome.length>20?i.nome.slice(0,20)+'…':i.nome),
      datasets:[{ label:'Dias de cobertura',
        data: comDias.map(i=>i.dias_cobertura),
        backgroundColor: comDias.map(i=>i.dias_cobertura<=3?'rgba(239,68,68,.7)':i.dias_cobertura<=7?'rgba(249,115,22,.7)':'rgba(34,197,94,.7)'),
        borderRadius:4 }]
    },
    options:{ plugins:{legend:{display:false}}, scales:{
      x:{ticks:{color:dark},grid:{color:'transparent'}},
      y:{ticks:{color:dark},grid:{color:grid},title:{display:true,text:'dias',color:dark}}
    }}
  });
}

// ═══════════════════════════════════════════════════════════════════════
// ALERTA DE CUSTO DE PRATO
// ═══════════════════════════════════════════════════════════════════════
function exibirAlertaCusto(alertas) {
  if (!alertas || !alertas.length) return;
  const fmtBR = (v,d=2)=>parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d});
  document.getElementById('alerta-custo-lista').innerHTML = alertas.map(a=>{
    const cor = a.variacao_custo > 0 ? 'var(--red)' : 'var(--green)';
    const sinal = a.variacao_custo > 0 ? '+' : '';
    return `<div style="background:var(--card2);border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
      <div>
        <div style="font-weight:700">${esc(a.prato_nome)}</div>
        <div style="font-size:11px;color:var(--text3)">Preço de venda: R$ ${fmtBR(a.preco_venda)}</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:14px;font-weight:800;color:${cor}">${sinal}R$ ${fmtBR(Math.abs(a.variacao_custo),4)}</div>
        <div style="font-size:11px;color:var(--text3)">variação no custo</div>
      </div>
    </div>`;
  }).join('');
  openModal('modal-alerta-custo');
}

// ═══════════════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════════════
(async () => {
  await loadInsumos();
})();
</script>

</body>
</html>

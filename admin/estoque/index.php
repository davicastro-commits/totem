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

$adminNome = $_SESSION['admin_nome']  ?? '';
$adminRole = $_SESSION['admin_role']  ?? 'operador';
$isAdmin   = $adminRole === 'admin';
$csrfToken = csrfToken();

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
</style>
</head>
<body>

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

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="insumos">Insumos</button>
    <button class="tab-btn" data-tab="movimentacoes">Movimentações</button>
    <button class="tab-btn" data-tab="fichas">Fichas Técnicas</button>
    <button class="tab-btn" data-tab="relatorio">🖨️ Relatório</button>
  </div>

  <!-- ── ABA: INSUMOS ──────────────────────────────────────────────────── -->
  <div class="tab-panel active" id="panel-insumos">
    <div class="toolbar">
      <div class="search-box"><span>🔍</span><input type="text" id="ins-busca" placeholder="Buscar insumo..."></div>
      <select class="filter" id="ins-filtro-status">
        <option value="">Todos</option>
        <option value="ok">Estoque OK</option>
        <option value="baixo">Estoque Baixo</option>
        <option value="critico">Crítico / Zerado</option>
      </select>
      <button class="btn btn-primary" onclick="openNovoInsumo()">+ Novo Insumo</button>
    </div>

    <div class="card">
      <div class="card-head">
        <h3 id="ins-count">Insumos</h3>
      </div>
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th>Unidade</th>
            <th>Estoque Atual</th>
            <th>Mínimo</th>
            <th>Custo Médio</th>
            <th>Status</th>
            <th style="width:140px">Ações</th>
          </tr>
        </thead>
        <tbody id="ins-tbody">
          <tr class="empty-row"><td colspan="7">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── ABA: MOVIMENTAÇÕES ────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-movimentacoes">
    <div class="toolbar">
      <select class="filter" id="mov-filtro-insumo" style="flex:1;max-width:300px">
        <option value="">Todos os insumos</option>
      </select>
      <select class="filter" id="mov-filtro-tipo">
        <option value="">Todos os tipos</option>
        <option value="entrada">Entrada</option>
        <option value="saida">Saída</option>
        <option value="ajuste">Ajuste</option>
      </select>
      <button class="btn btn-secondary" onclick="loadMovimentacoes(1)">↻ Atualizar</button>
    </div>

    <div class="card">
      <div class="card-head">
        <h3 id="mov-count">Movimentações</h3>
      </div>
      <table>
        <thead>
          <tr>
            <th>Data/Hora</th>
            <th>Insumo</th>
            <th>Tipo</th>
            <th>Quantidade</th>
            <th>Custo Unit.</th>
            <th>Motivo</th>
            <th>Usuário</th>
          </tr>
        </thead>
        <tbody id="mov-tbody">
          <tr class="empty-row"><td colspan="7">Selecione um insumo ou carregue todos.</td></tr>
        </tbody>
      </table>
      <div class="pagination" id="mov-pagination"></div>
    </div>
  </div>

  <!-- ── ABA: RELATÓRIO ───────────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-relatorio">
    <div class="toolbar">
      <span style="font-size:13px;color:var(--text2);font-weight:600">Período:</span>
      <input type="date" id="rel-ini" style="background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:8px 12px;outline:none;height:38px">
      <span style="color:var(--text3)">até</span>
      <input type="date" id="rel-fim" style="background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:8px 12px;outline:none;height:38px">
      <button class="btn btn-primary" onclick="gerarRelatorio()">Gerar Relatório</button>
    </div>

    <!-- KPIs do relatório -->
    <div class="kpi-grid" id="rel-kpis" style="display:none"></div>

    <div class="card" id="rel-card" style="display:none">
      <div class="card-head">
        <h3 id="rel-titulo">Consumo de Insumos</h3>
        <span id="rel-periodo" style="font-size:12px;color:var(--text3)"></span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Insumo</th>
            <th>Unidade</th>
            <th>Total Consumido (Saídas)</th>
            <th>Total Entrada</th>
            <th>Saldo Atual</th>
            <th>Custo Médio</th>
            <th>Custo Total Saídas</th>
          </tr>
        </thead>
        <tbody id="rel-tbody">
          <tr class="empty-row"><td colspan="7">Clique em "Gerar Relatório" para carregar.</td></tr>
        </tbody>
      </table>
      <!-- Rodapé resumo -->
      <div id="rel-summary" style="padding:14px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:24px;font-size:13px"></div>
    </div>
  </div>

  <!-- ── ABA: FICHAS TÉCNICAS ──────────────────────────────────────────── -->
  <div class="tab-panel" id="panel-fichas">
    <div class="toolbar">
      <select class="filter" id="ficha-produto-sel" style="flex:1;max-width:360px">
        <option value="">Selecione um produto...</option>
        <?php foreach ($produtos as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary" onclick="loadFicha()">Carregar ficha</button>
      <button class="btn btn-primary" onclick="openEditarFicha()">✏️ Editar Ficha</button>
    </div>

    <div class="card" id="ficha-card">
      <div class="card-head">
        <h3 id="ficha-prod-nome">Selecione um produto</h3>
      </div>
      <table>
        <thead>
          <tr>
            <th>Insumo</th>
            <th>Unidade</th>
            <th>Quantidade por Venda</th>
            <th>Estoque Atual</th>
            <th>Custo Unitário</th>
          </tr>
        </thead>
        <tbody id="ficha-tbody">
          <tr class="empty-row"><td colspan="5">Nenhum produto selecionado.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</main>

<!-- ═══════════════ MODALS ═══════════════════════════════════════════════ -->

<!-- Modal: Novo/Editar Insumo -->
<div class="overlay" id="modal-insumo">
  <div class="modal">
    <h3 id="m-ins-title">Novo Insumo</h3>
    <form id="form-insumo" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" id="m-ins-id">
      <div class="form-row">
        <div class="field" style="flex:2">
          <label>Nome *</label>
          <input type="text" id="m-ins-nome" required placeholder="Ex: Café Arábica">
        </div>
        <div class="field">
          <label>Unidade *</label>
          <select id="m-ins-unidade" required>
            <option value="UN">UN — Unidade</option>
            <option value="KG">KG — Quilo</option>
            <option value="G">G — Grama</option>
            <option value="L">L — Litro</option>
            <option value="ML">ML — Mililitro</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Custo Médio (R$)</label>
          <input type="number" id="m-ins-custo" step="0.0001" min="0" value="0" placeholder="0.0000">
        </div>
        <div class="field">
          <label>Estoque Mínimo</label>
          <input type="number" id="m-ins-minimo" step="0.001" min="0" value="0" placeholder="0">
        </div>
      </div>
      <!-- Campo de estoque inicial apenas na criação -->
      <div class="field" id="m-ins-atual-row">
        <label>Estoque Inicial</label>
        <input type="number" id="m-ins-atual" step="0.001" min="0" value="0" placeholder="0">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-insumo')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
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
  if (name === 'relatorio') initRelatorio();
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
  const busca   = document.getElementById('ins-busca').value.toLowerCase();
  const filtro  = document.getElementById('ins-filtro-status').value;

  let lista = todosInsumos.filter(i => {
    if (busca && !i.nome.toLowerCase().includes(busca)) return false;
    const pct = parseFloat(i.percentual_estoque || 100);
    if (filtro === 'ok'      && pct <= 100) return false;
    if (filtro === 'baixo'   && (pct > 100 || pct <= 20)) return false;
    if (filtro === 'critico' && pct > 20) return false;
    // Sem filtro: incluir tudo
    if (filtro === 'ok'      && pct <= 100) return false;
    return true;
  });

  // Corrijo a lógica do filtro:
  lista = todosInsumos.filter(i => {
    if (busca && !i.nome.toLowerCase().includes(busca)) return false;
    const pct = parseFloat(i.percentual_estoque ?? 100);
    const abaix = i.abaixo_minimo === true || i.abaixo_minimo === 't' || i.abaixo_minimo === '1';
    if (filtro === 'ok'      &&  abaix) return false;
    if (filtro === 'ok'      && !abaix) return true;
    if (filtro === 'baixo'   && (!abaix || pct <= 20)) return false;
    if (filtro === 'critico' && pct > 20) return false;
    return true;
  });

  document.getElementById('ins-count').textContent = lista.length + ' insumo(s)';

  if (!lista.length) {
    document.getElementById('ins-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="7">Nenhum insumo encontrado.</td></tr>';
    return;
  }

  document.getElementById('ins-tbody').innerHTML = lista.map(i => {
    const pct   = Math.min(parseFloat(i.percentual_estoque ?? 100), 150);
    const abaix = i.abaixo_minimo === true || i.abaixo_minimo === 't' || i.abaixo_minimo === '1';
    const critico = parseFloat(i.estoque_atual) <= 0;

    let statusClass, statusLabel, fillClass;
    if (critico)     { statusClass = 'badge-critical'; statusLabel = 'Crítico';   fillClass = 'fill-critical'; }
    else if (abaix)  { statusClass = 'badge-low';      statusLabel = 'Baixo';     fillClass = 'fill-low'; }
    else             { statusClass = 'badge-ok';       statusLabel = 'OK';         fillClass = 'fill-ok'; }

    const fillW = Math.min(Math.max(pct, 0), 100).toFixed(0);

    return `<tr>
      <td><strong>${esc(i.nome)}</strong></td>
      <td style="color:var(--text2)">${esc(i.unidade)}</td>
      <td>
        <div style="margin-bottom:4px;font-weight:600">${fmtQty(i.estoque_atual, i.unidade)}</div>
        <div class="stock-bar"><div class="stock-fill ${fillClass}" style="width:${fillW}%"></div></div>
      </td>
      <td style="color:var(--text3)">${fmtQty(i.estoque_minimo, i.unidade)}</td>
      <td style="color:var(--acc-l)">${fmt(i.custo_medio)}</td>
      <td><span class="badge ${statusClass}">${statusLabel}</span></td>
      <td>
        <button class="btn btn-secondary btn-sm" onclick="openAjusteEstoque(${i.id})" style="margin-right:4px">Ajustar</button>
        <button class="btn btn-secondary btn-sm" onclick="openEditarInsumo(${i.id})">Editar</button>
      </td>
    </tr>`;
  }).join('');
}

function atualizarKPIs() {
  const total   = todosInsumos.length;
  const alertas = todosInsumos.filter(i => i.abaixo_minimo === true || i.abaixo_minimo === 't' || i.abaixo_minimo === '1').length;
  const criticos = todosInsumos.filter(i => parseFloat(i.estoque_atual) <= 0).length;
  const valorTotal = todosInsumos.reduce((s, i) => s + parseFloat(i.estoque_atual) * parseFloat(i.custo_medio), 0);

  document.getElementById('kpi-grid').innerHTML = [
    { label: 'Total de Insumos', value: total,              color: 'var(--blue)',  sub: 'cadastrados' },
    { label: 'Alertas de Estoque', value: alertas,          color: 'var(--gold)',  sub: 'abaixo do mínimo' },
    { label: 'Estoque Crítico',    value: criticos,         color: 'var(--red)',   sub: 'zerado ou negativo' },
    { label: 'Valor em Estoque',   value: 'R$ ' + valorTotal.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}),
      color: 'var(--green)', sub: 'custo médio × qtd' },
  ].map(k =>
    `<div class="kpi-card" style="--c:${k.color}">
      <div class="kpi-label">${k.label}</div>
      <div class="kpi-value" style="color:${k.color};font-size:${typeof k.value === 'string' ? '22px' : '28px'}">${k.value}</div>
      <div class="kpi-sub">${k.sub}</div>
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

// ── Novo insumo ───────────────────────────────────────────────────────
function openNovoInsumo() {
  document.getElementById('m-ins-id').value    = '';
  document.getElementById('m-ins-title').textContent = 'Novo Insumo';
  document.getElementById('m-ins-nome').value  = '';
  document.getElementById('m-ins-unidade').value = 'UN';
  document.getElementById('m-ins-custo').value  = '0';
  document.getElementById('m-ins-minimo').value = '0';
  document.getElementById('m-ins-atual').value  = '0';
  document.getElementById('m-ins-atual-row').style.display = '';
  openModal('modal-insumo');
}

function openEditarInsumo(id) {
  const ins = todosInsumos.find(i => +i.id === +id);
  if (!ins) return;
  document.getElementById('m-ins-id').value    = ins.id;
  document.getElementById('m-ins-title').textContent = 'Editar Insumo';
  document.getElementById('m-ins-nome').value  = ins.nome;
  document.getElementById('m-ins-unidade').value = ins.unidade;
  document.getElementById('m-ins-custo').value  = parseFloat(ins.custo_medio).toFixed(4);
  document.getElementById('m-ins-minimo').value = parseFloat(ins.estoque_minimo).toFixed(3);
  // Esconder campo de estoque inicial na edição
  document.getElementById('m-ins-atual-row').style.display = 'none';
  openModal('modal-insumo');
}

document.getElementById('form-insumo').addEventListener('submit', async e => {
  e.preventDefault();
  const id     = document.getElementById('m-ins-id').value;
  const nome   = document.getElementById('m-ins-nome').value.trim();
  const method = id ? 'PUT' : 'POST';
  const body = {
    nome,
    unidade:         document.getElementById('m-ins-unidade').value,
    custo_medio:     parseFloat(document.getElementById('m-ins-custo').value) || 0,
    estoque_minimo:  parseFloat(document.getElementById('m-ins-minimo').value) || 0,
  };
  if (id) body.id = +id;
  else    body.estoque_atual = parseFloat(document.getElementById('m-ins-atual').value) || 0;

  const res = await api('insumos.php', { method, body: JSON.stringify(body) });
  if (res.success) {
    toast(id ? 'Insumo atualizado!' : 'Insumo criado!');
    closeModal('modal-insumo');
    loadInsumos();
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
    return;
  }

  document.getElementById('mov-tbody').innerHTML = lista.map(m => {
    const sinal = m.tipo === 'entrada' ? '+' : (m.tipo === 'saida' ? '−' : '≡');
    const cor   = m.tipo === 'entrada' ? 'var(--green)' : (m.tipo === 'saida' ? 'var(--red)' : 'var(--blue)');
    return `<tr>
      <td style="color:var(--text2);font-size:12px">${fmtDt(m.criado_em)}</td>
      <td><strong>${esc(m.insumo_nome || '')}</strong></td>
      <td><span class="badge badge-${m.tipo}">${m.tipo}</span></td>
      <td style="font-weight:700;color:${cor}">${sinal} ${fmtQty(Math.abs(m.quantidade), m.unidade || '')}</td>
      <td style="color:var(--text3)">${m.custo_unitario > 0 ? fmt(m.custo_unitario) : '—'}</td>
      <td style="color:var(--text2);font-size:12px">${esc(m.motivo || '—')}</td>
      <td style="color:var(--text3);font-size:12px">${esc(m.usuario_nome || '—')}</td>
    </tr>`;
  }).join('');

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

  const prodNome = document.getElementById('ficha-produto-sel').options[document.getElementById('ficha-produto-sel').selectedIndex].text;
  document.getElementById('ficha-prod-nome').textContent = 'Ficha: ' + prodNome;

  if (!fichaAtualData.length) {
    document.getElementById('ficha-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="5">Sem ficha técnica cadastrada para este produto.</td></tr>';
    return;
  }

  document.getElementById('ficha-tbody').innerHTML = fichaAtualData.map(f => `<tr>
    <td><strong>${esc(f.insumo_nome)}</strong></td>
    <td style="color:var(--text2)">${esc(f.unidade)}</td>
    <td style="font-weight:700">${parseFloat(f.quantidade).toFixed(4)} ${esc(f.unidade)}</td>
    <td style="color:${parseFloat(f.estoque_atual) <= 0 ? 'var(--red)' : 'var(--text)'}">
      ${fmtQty(f.estoque_atual, f.unidade)}
    </td>
    <td style="color:var(--text3)">${fmt(f.custo_medio)}</td>
  </tr>`).join('');
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
// RELATÓRIO DE CONSUMO DE INSUMOS
// ═══════════════════════════════════════════════════════════════════════
let _relInited = false;

function initRelatorio() {
  if (_relInited) return;
  _relInited = true;
  // Defaults: primeiro dia do mês até hoje
  const hoje = new Date().toISOString().slice(0, 10);
  const mesIni = hoje.slice(0, 8) + '01';
  document.getElementById('rel-ini').value = mesIni;
  document.getElementById('rel-fim').value = hoje;
}

async function gerarRelatorio() {
  const ini = document.getElementById('rel-ini').value;
  const fim = document.getElementById('rel-fim').value;
  if (!ini || !fim) { toast('Selecione o período', 'err'); return; }

  const res = await api('estoque.php?action=relatorio&data_ini=' + ini + '&data_fim=' + fim);
  if (!res.success) { toast(res.error || 'Erro ao gerar relatório', 'err'); return; }

  const { insumos, total_custo_saidas, periodo } = res.data;

  // Mostrar KPIs
  const totalInsumos   = insumos.length;
  const totalSaidas    = insumos.reduce((s, i) => s + i.total_saida, 0);
  const totalEntradas  = insumos.reduce((s, i) => s + i.total_entrada, 0);
  document.getElementById('rel-kpis').style.display = '';
  document.getElementById('rel-kpis').innerHTML = [
    { label: 'Insumos no período', value: totalInsumos,                   color: 'var(--blue)',  sub: 'com movimentação' },
    { label: 'Total de Saídas',    value: totalSaidas.toLocaleString('pt-BR', {maximumFractionDigits:3}),  color: 'var(--red)',   sub: 'unidades consumidas' },
    { label: 'Total de Entradas',  value: totalEntradas.toLocaleString('pt-BR', {maximumFractionDigits:3}), color: 'var(--green)', sub: 'unidades recebidas' },
    { label: 'Custo Total Saídas', value: 'R$ ' + parseFloat(total_custo_saidas).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}),
      color: 'var(--gold)', sub: 'qtd × custo médio' },
  ].map(k =>
    `<div class="kpi-card" style="--c:${k.color}">
      <div class="kpi-label">${k.label}</div>
      <div class="kpi-value" style="color:${k.color};font-size:${typeof k.value === 'string' && k.value.length > 8 ? '18px' : '28px'}">${k.value}</div>
      <div class="kpi-sub">${k.sub}</div>
    </div>`
  ).join('');

  // Período label
  const fmtDate = d => new Date(d + 'T12:00').toLocaleDateString('pt-BR');
  document.getElementById('rel-periodo').textContent =
    fmtDate(periodo.ini) + ' — ' + fmtDate(periodo.fim);

  // Tabela
  document.getElementById('rel-card').style.display = '';
  if (!insumos.length) {
    document.getElementById('rel-tbody').innerHTML =
      '<tr class="empty-row"><td colspan="7">Nenhuma movimentação no período selecionado.</td></tr>';
    document.getElementById('rel-summary').innerHTML = '';
    return;
  }

  document.getElementById('rel-tbody').innerHTML = insumos.map(i => {
    const corSaida    = i.total_saida > 0   ? 'color:var(--red)'   : 'color:var(--text3)';
    const corEntrada  = i.total_entrada > 0  ? 'color:var(--green)' : 'color:var(--text3)';
    const corSaldo    = i.estoque_atual <= 0  ? 'color:var(--red);font-weight:700' : 'color:var(--text)';
    return `<tr>
      <td><strong>${esc(i.nome)}</strong></td>
      <td style="color:var(--text2)">${esc(i.unidade)}</td>
      <td style="${corSaida};font-weight:${i.total_saida > 0 ? '700' : '400'}">
        ${i.total_saida > 0 ? '−' : ''} ${parseFloat(i.total_saida).toLocaleString('pt-BR', {maximumFractionDigits:4})} ${esc(i.unidade)}
      </td>
      <td style="${corEntrada}">
        ${i.total_entrada > 0 ? '+' : ''} ${parseFloat(i.total_entrada).toLocaleString('pt-BR', {maximumFractionDigits:4})} ${esc(i.unidade)}
      </td>
      <td style="${corSaldo}">
        ${parseFloat(i.estoque_atual).toLocaleString('pt-BR', {maximumFractionDigits:4})} ${esc(i.unidade)}
      </td>
      <td style="color:var(--text3)">R$ ${parseFloat(i.custo_medio).toLocaleString('pt-BR', {minimumFractionDigits:4, maximumFractionDigits:4})}</td>
      <td style="color:var(--acc-l);font-weight:600">R$ ${parseFloat(i.custo_total_saida).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
    </tr>`;
  }).join('');

  // Rodapé
  document.getElementById('rel-summary').innerHTML =
    `<span style="color:var(--text2)">Total de saídas em custo:</span>
     <strong style="color:var(--acc);font-size:16px">
       R$ ${parseFloat(total_custo_saidas).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})}
     </strong>`;
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

<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = $_SESSION['admin_nome'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'operador';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<title>Caixa — Turno</title>
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
.main{max-width:960px;margin:0 auto;padding:28px 24px 60px}

/* CARD */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.card-head h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);display:flex;align-items:center;gap:7px}
.card-body{padding:24px}

/* KPI STRIP */
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:700px){.kpi-strip{grid-template-columns:repeat(2,1fr)}}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 20px;position:relative;overflow:hidden}
.kpi::before{content:'';position:absolute;top:-30px;right:-30px;width:80px;height:80px;border-radius:50%;background:var(--c,var(--acc));opacity:.06;pointer-events:none}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text3);margin-bottom:6px}
.kpi-value{font-size:24px;font-weight:900;color:var(--c,var(--acc));line-height:1;font-variant-numeric:tabular-nums}
.kpi-sub{font-size:12px;color:var(--text4);margin-top:4px}

/* FORM */
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border2);border-radius:10px;padding:12px 14px;color:var(--text);font-size:14px;font-family:inherit;outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--acc)}
.form-control::placeholder{color:var(--text4)}
textarea.form-control{resize:vertical;min-height:80px}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;border-radius:10px;border:none;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s;text-decoration:none}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-green{background:var(--green);color:#000}
.btn-green:hover:not(:disabled){background:#16a34a}
.btn-red{background:var(--red);color:#fff}
.btn-red:hover:not(:disabled){background:#b91c1c}
.btn-orange{background:var(--acc);color:#fff}
.btn-orange:hover:not(:disabled){background:var(--acc-l)}
.btn-ghost{background:transparent;color:var(--text2);border:1px solid var(--border2)}
.btn-ghost:hover{background:var(--card2);color:var(--text)}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-full{width:100%;justify-content:center}

/* SECTION LABEL */
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);margin-bottom:12px;margin-top:4px}

/* TURNO HEADER */
.turno-header{display:flex;align-items:center;gap:16px;padding:20px 24px;background:var(--card);border:1px solid var(--border);border-radius:16px;margin-bottom:20px}
.turno-avatar{width:48px;height:48px;border-radius:12px;background:color-mix(in srgb,var(--green) 14%,transparent);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.turno-info{flex:1}
.turno-nome{font-size:16px;font-weight:800}
.turno-meta{font-size:12px;color:var(--text3);margin-top:3px}
.turno-status{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:5px 12px;background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.25);border-radius:999px}
.turno-elapsed{font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--text2);margin-left:auto}

/* ALERT */
.alert{padding:14px 18px;border-radius:12px;font-size:13px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start}
.alert-err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--red)}
.alert-ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);color:var(--green)}
.alert-info{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);color:var(--blue)}

/* MODAL */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.hidden{display:none}
.modal{background:var(--card);border:1px solid var(--border2);border-radius:18px;padding:28px;width:100%;max-width:440px}
.modal h2{font-size:18px;font-weight:800;margin-bottom:20px}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px}

/* RESUMO FECHAMENTO */
.resumo{background:var(--card2);border-radius:12px;padding:18px 20px;margin-bottom:18px}
.resumo-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:13px}
.resumo-row+.resumo-row{border-top:1px solid var(--border)}
.resumo-row .lbl{color:var(--text2)}
.resumo-row .val{font-weight:700;font-variant-numeric:tabular-nums}
.resumo-total{padding-top:12px;margin-top:6px;border-top:2px solid var(--border2) !important}
.resumo-total .lbl{font-size:14px;font-weight:700;color:var(--text)}
.resumo-total .val{font-size:18px}
.val-pos{color:var(--green)}
.val-neg{color:var(--red)}

/* HISTORY TABLE */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{text-align:left;padding:9px 14px;color:var(--text3);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.tbl th.r,.tbl td.r{text-align:right}
.tbl td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.badge-green{background:rgba(34,197,94,.12);color:var(--green)}
.badge-gray{background:rgba(107,114,128,.12);color:var(--text3)}

/* FOOTER */
.footer{text-align:center;font-size:11px;color:var(--text4);margin-top:32px;padding-top:18px;border-top:1px solid var(--border)}

/* SPINNER */
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a href="../">← Admin</a>
  <span style="color:var(--border2)">|</span>
  <span class="topbar-title">Caixa</span>
  <span class="topbar-badge">Turno</span>
  <div class="topbar-right">
    <span><span class="pulse"></span></span>
    <span class="clock" id="clk"></span>
  </div>
</header>

<!-- MODAL SANGRIA -->
<div class="modal-backdrop hidden" id="modalSangria">
  <div class="modal">
    <h2>Registrar Sangria</h2>
    <div id="sangria-alert"></div>
    <div class="form-group">
      <label class="form-label">Valor (R$)</label>
      <input type="number" id="sangria-valor" class="form-control" min="0.01" step="0.01" placeholder="0,00">
    </div>
    <div class="form-group">
      <label class="form-label">Motivo</label>
      <input type="text" id="sangria-motivo" class="form-control" maxlength="200" placeholder="Troco, pagamento fornecedor...">
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="fecharModalSangria()">Cancelar</button>
      <button class="btn btn-orange btn-sm" id="btn-confirmar-sangria" onclick="confirmarSangria()">Registrar sangria</button>
    </div>
  </div>
</div>

<!-- MODAL RESUMO FECHAMENTO -->
<div class="modal-backdrop hidden" id="modalResumo">
  <div class="modal" style="max-width:480px">
    <h2>Resumo do Fechamento</h2>
    <div id="resumo-content"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('modalResumo').classList.add('hidden')">Fechar</button>
    </div>
  </div>
</div>

<main class="main">

  <!-- Área dinâmica principal -->
  <div id="app">
    <div style="text-align:center;padding:60px 0;color:var(--text3)">
      <span class="spinner" style="width:24px;height:24px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto"></span>
      Carregando...
    </div>
  </div>

  <!-- Histórico de turnos -->
  <div class="section-label" style="margin-top:32px">Histórico de turnos</div>
  <div class="card">
    <div class="card-head">
      <h3>Últimos turnos</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="date" id="hist-ini" class="form-control" style="width:140px;padding:6px 10px;font-size:12px">
        <input type="date" id="hist-fim" class="form-control" style="width:140px;padding:6px 10px;font-size:12px">
        <button class="btn btn-ghost btn-sm" onclick="carregarHistorico()">Filtrar</button>
      </div>
    </div>
    <div id="historico-body">
      <div style="text-align:center;padding:32px;color:var(--text3);font-size:13px">Carregando histórico...</div>
    </div>
  </div>

  <div class="footer">
    Controle de Turnos · Café Comunhão
    &nbsp;·&nbsp;<a href="../" style="color:var(--text4);text-decoration:none">← Admin</a>
  </div>

</main>

<script>
// ── Config ────────────────────────────────────────────────────────────────
const API    = '../api/turno.php';
const CSRF   = document.querySelector('meta[name="csrf-token"]').content;
let   turnoAtual = null;
let   elapsedInterval = null;
let   kpiInterval = null;

// ── Utilitários ───────────────────────────────────────────────────────────
function brl(v) {
  return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:2,maximumFractionDigits:2});
}

function fmtDatetime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
}

function fmtDuracao(abertura, fechamento) {
  const ini = new Date(abertura);
  const fim = fechamento ? new Date(fechamento) : new Date();
  const diff = Math.floor((fim - ini) / 1000);
  const h = Math.floor(diff / 3600);
  const m = Math.floor((diff % 3600) / 60);
  return `${h}h ${String(m).padStart(2,'0')}min`;
}

function html(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function apiGet(params) {
  const qs = new URLSearchParams(params).toString();
  return fetch(API + '?' + qs).then(r => r.json());
}

function apiPost(data) {
  return fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(data)
  }).then(r => r.json());
}

function showAlert(containerId, msg, type='err') {
  const el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = `<div class="alert alert-${type}">${html(msg)}</div>`;
}

function clearAlert(containerId) {
  const el = document.getElementById(containerId);
  if (el) el.innerHTML = '';
}

// ── Clock ─────────────────────────────────────────────────────────────────
const clk = document.getElementById('clk');
function tick() { clk.textContent = new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
tick(); setInterval(tick, 1000);

// ── Inicialização ─────────────────────────────────────────────────────────
async function init() {
  const res = await apiGet({ action: 'atual' });
  turnoAtual = res.turno;
  renderApp();
  carregarHistorico();
}

// ── Render principal ──────────────────────────────────────────────────────
function renderApp() {
  clearIntervals();
  if (!turnoAtual) {
    renderAbertura();
  } else {
    renderTurnoAtivo();
  }
}

function clearIntervals() {
  if (elapsedInterval) { clearInterval(elapsedInterval); elapsedInterval = null; }
  if (kpiInterval)     { clearInterval(kpiInterval);     kpiInterval = null; }
}

// ── Tela de abertura ──────────────────────────────────────────────────────
function renderAbertura() {
  document.getElementById('app').innerHTML = `
    <div class="section-label">Novo turno</div>
    <div style="max-width:520px;margin:0 auto">
      <div class="card">
        <div class="card-head">
          <h3>💰 Abrir Caixa</h3>
        </div>
        <div class="card-body">
          <div id="abertura-alert"></div>
          <div class="form-group">
            <label class="form-label">Fundo de caixa (dinheiro em gaveta) — R$</label>
            <input type="number" id="valor-abertura" class="form-control" min="0" step="0.01"
                   placeholder="0,00" autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Observações (opcional)</label>
            <textarea id="obs-abertura" class="form-control" placeholder="Observações sobre a abertura..."></textarea>
          </div>
          <button class="btn btn-green btn-full" id="btn-abrir" onclick="abrirCaixa()">
            Abrir caixa agora →
          </button>
        </div>
      </div>
    </div>
  `;
}

async function abrirCaixa() {
  const btn   = document.getElementById('btn-abrir');
  const valor = parseFloat(document.getElementById('valor-abertura').value) || 0;
  const obs   = document.getElementById('obs-abertura').value.trim();

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Abrindo...';

  const res = await apiPost({ action: 'abrir', valor_abertura: valor, obs });
  if (res.success) {
    turnoAtual = res.turno;
    renderApp();
    carregarHistorico();
  } else {
    showAlert('abertura-alert', res.error || 'Erro ao abrir caixa.');
    btn.disabled = false;
    btn.innerHTML = 'Abrir caixa agora →';
  }
}

// ── Turno ativo ───────────────────────────────────────────────────────────
function renderTurnoAtivo() {
  const t = turnoAtual;
  document.getElementById('app').innerHTML = `
    <div class="turno-header">
      <div class="turno-avatar">👤</div>
      <div class="turno-info">
        <div class="turno-nome">${html(t.admin_nome || 'Operador')}</div>
        <div class="turno-meta">Aberto em ${fmtDatetime(t.abertura_em)} · Fundo ${brl(t.valor_abertura)}</div>
      </div>
      <span class="turno-status"><span class="pulse"></span> Turno aberto</span>
      <div class="turno-elapsed" id="elapsed-timer">${fmtDuracao(t.abertura_em, null)}</div>
    </div>

    <!-- KPIs em tempo real -->
    <div class="section-label">Tempo real</div>
    <div class="kpi-strip" id="kpi-strip">
      ${renderKPIs(t)}
    </div>

    <!-- Sangria -->
    <div style="display:flex;gap:10px;margin-bottom:20px">
      <button class="btn btn-orange" onclick="abrirModalSangria()">Registrar Sangria</button>
    </div>

    <!-- Fechamento -->
    <div class="section-label">Fechar turno</div>
    <div class="card">
      <div class="card-head"><h3>🔒 Fechamento de caixa</h3></div>
      <div class="card-body">
        <div id="fechar-alert"></div>
        <div class="form-group">
          <label class="form-label">Dinheiro contado na gaveta — R$</label>
          <input type="number" id="valor-fechamento" class="form-control" min="0" step="0.01" placeholder="0,00">
        </div>
        <div class="form-group">
          <label class="form-label">Observações (opcional)</label>
          <textarea id="obs-fechamento" class="form-control" placeholder="Observações sobre o fechamento..."></textarea>
        </div>
        <button class="btn btn-red btn-full" id="btn-fechar" onclick="fecharCaixa()">
          Fechar caixa
        </button>
      </div>
    </div>
  `;

  // Timer decorrido — atualiza a cada minuto
  elapsedInterval = setInterval(() => {
    const el = document.getElementById('elapsed-timer');
    if (el && turnoAtual) el.textContent = fmtDuracao(turnoAtual.abertura_em, null);
  }, 60000);

  // KPIs — atualiza a cada 30s
  kpiInterval = setInterval(atualizarKPIs, 30000);
}

function renderKPIs(t) {
  const fat    = parseFloat(t.faturamento_no_turno || 0);
  const din    = parseFloat(t.faturamento_dinheiro || 0);
  const qtd    = parseInt(t.qtd_pedidos || 0);
  const sang   = parseFloat(t.total_sangrias || 0);
  return `
    <div class="kpi" style="--c:var(--green)">
      <div class="kpi-label">Faturamento</div>
      <div class="kpi-value" id="kpi-fat">${brl(fat)}</div>
      <div class="kpi-sub">todos os métodos</div>
    </div>
    <div class="kpi" style="--c:var(--gold)">
      <div class="kpi-label">Dinheiro</div>
      <div class="kpi-value" id="kpi-din">${brl(din)}</div>
      <div class="kpi-sub">em espécie</div>
    </div>
    <div class="kpi" style="--c:var(--blue)">
      <div class="kpi-label">Pedidos</div>
      <div class="kpi-value" id="kpi-qtd">${qtd}</div>
      <div class="kpi-sub">no turno</div>
    </div>
    <div class="kpi" style="--c:var(--red)">
      <div class="kpi-label">Sangrias</div>
      <div class="kpi-value" id="kpi-sang">${brl(sang)}</div>
      <div class="kpi-sub">retiradas</div>
    </div>
  `;
}

async function atualizarKPIs() {
  if (!turnoAtual) return;
  const res = await apiGet({ action: 'atual' });
  if (!res.success || !res.turno) return;
  turnoAtual = res.turno;

  const t = res.turno;
  const elFat  = document.getElementById('kpi-fat');
  const elDin  = document.getElementById('kpi-din');
  const elQtd  = document.getElementById('kpi-qtd');
  const elSang = document.getElementById('kpi-sang');

  if (elFat)  elFat.textContent  = brl(t.faturamento_no_turno || 0);
  if (elDin)  elDin.textContent  = brl(t.faturamento_dinheiro || 0);
  if (elQtd)  elQtd.textContent  = parseInt(t.qtd_pedidos || 0);
  if (elSang) elSang.textContent = brl(t.total_sangrias || 0);
}

// ── Sangria ───────────────────────────────────────────────────────────────
function abrirModalSangria() {
  document.getElementById('sangria-valor').value  = '';
  document.getElementById('sangria-motivo').value = '';
  clearAlert('sangria-alert');
  document.getElementById('modalSangria').classList.remove('hidden');
  document.getElementById('sangria-valor').focus();
}

function fecharModalSangria() {
  document.getElementById('modalSangria').classList.add('hidden');
}

async function confirmarSangria() {
  const btn   = document.getElementById('btn-confirmar-sangria');
  const valor = parseFloat(document.getElementById('sangria-valor').value) || 0;
  const motivo = document.getElementById('sangria-motivo').value.trim();

  if (valor <= 0) { showAlert('sangria-alert', 'Informe um valor maior que zero.'); return; }
  if (!turnoAtual) return;

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Registrando...';

  const res = await apiPost({ action: 'sangria', turno_id: turnoAtual.id, valor, motivo });
  btn.disabled = false;
  btn.textContent = 'Registrar sangria';

  if (res.success) {
    fecharModalSangria();
    await atualizarKPIs();
  } else {
    showAlert('sangria-alert', res.error || 'Erro ao registrar sangria.');
  }
}

// ── Fechar turno ──────────────────────────────────────────────────────────
async function fecharCaixa() {
  const btn  = document.getElementById('btn-fechar');
  const val  = parseFloat(document.getElementById('valor-fechamento').value);
  const obs  = document.getElementById('obs-fechamento').value.trim();

  if (isNaN(val) || val < 0) {
    showAlert('fechar-alert', 'Informe o valor contado na gaveta.'); return;
  }
  if (!turnoAtual) return;

  if (!confirm('Confirmar fechamento do caixa?')) return;

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Fechando...';

  const res = await apiPost({
    action: 'fechar',
    turno_id: turnoAtual.id,
    valor_fechamento: val,
    obs
  });

  btn.disabled = false;
  btn.textContent = 'Fechar caixa';

  if (res.success) {
    mostrarResumoFechamento(res);
    turnoAtual = null;
    clearIntervals();
  } else {
    showAlert('fechar-alert', res.error || 'Erro ao fechar caixa.');
  }
}

function mostrarResumoFechamento(res) {
  const t        = res.turno;
  const fatDin   = parseFloat(res.faturamento_dinheiro || 0);
  const esperado = parseFloat(res.esperado || 0);
  const dif      = parseFloat(res.diferenca || 0);
  const contado  = parseFloat(t.valor_fechamento || 0);
  const abertura = parseFloat(t.valor_abertura || 0);
  const sangrias = parseFloat(t.total_sangrias || 0);
  const difCls   = dif >= 0 ? 'val-pos' : 'val-neg';
  const difTxt   = dif >= 0 ? 'Sobra' : 'Falta';
  const difIcon  = dif >= 0 ? '✅' : '⚠️';

  document.getElementById('resumo-content').innerHTML = `
    <div style="text-align:center;margin-bottom:20px;padding:14px;background:color-mix(in srgb,${dif>=0?'var(--green)':'var(--red)'} 10%,transparent);border-radius:12px">
      <div style="font-size:28px;margin-bottom:4px">${difIcon}</div>
      <div style="font-size:24px;font-weight:900;class:${difCls}" class="${difCls}">${brl(Math.abs(dif))}</div>
      <div style="font-size:13px;color:var(--text2);margin-top:4px">${difTxt} no caixa</div>
    </div>
    <div class="resumo">
      <div class="resumo-row"><span class="lbl">Fundo de abertura</span><span class="val">${brl(abertura)}</span></div>
      <div class="resumo-row"><span class="lbl">Vendas em dinheiro</span><span class="val">${brl(fatDin)}</span></div>
      <div class="resumo-row"><span class="lbl">Sangrias</span><span class="val val-neg">− ${brl(sangrias)}</span></div>
      <div class="resumo-row resumo-total"><span class="lbl">Esperado na gaveta</span><span class="val">${brl(esperado)}</span></div>
      <div class="resumo-row resumo-total"><span class="lbl">Contado na gaveta</span><span class="val">${brl(contado)}</span></div>
      <div class="resumo-row resumo-total"><span class="lbl">Diferença</span><span class="val ${difCls}">${dif>=0?'+':''}${brl(dif)}</span></div>
    </div>
    <div style="font-size:12px;color:var(--text3);text-align:center">
      Turno encerrado em ${fmtDatetime(t.fechamento_em)}
    </div>
  `;

  document.getElementById('modalResumo').classList.remove('hidden');

  // Recarrega a tela de abertura e histórico
  setTimeout(() => {
    renderAbertura();
    carregarHistorico();
  }, 300);
}

// ── Histórico ─────────────────────────────────────────────────────────────
async function carregarHistorico() {
  const ini = document.getElementById('hist-ini').value;
  const fim = document.getElementById('hist-fim').value;
  const params = { action: 'historico' };
  if (ini) params.data_ini = ini;
  if (fim) params.data_fim = fim;

  const res = await apiGet(params);
  const container = document.getElementById('historico-body');

  if (!res.success || !res.turnos || res.turnos.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text3);font-size:13px">Nenhum turno encontrado</div>';
    return;
  }

  const rows = res.turnos.slice(0, 10).map(t => {
    const dif      = t.diferenca_caixa !== null ? parseFloat(t.diferenca_caixa) : null;
    const difTxt   = dif !== null
      ? `<span style="font-weight:700;color:${dif>=0?'var(--green)':'var(--red)'}">${dif>=0?'+':''}${brl(dif)}</span>`
      : '—';
    const duracao  = t.fechamento_em ? fmtDuracao(t.abertura_em, t.fechamento_em) : fmtDuracao(t.abertura_em, null);
    const statusBadge = t.status === 'aberto'
      ? '<span class="badge badge-green">aberto</span>'
      : '<span class="badge badge-gray">fechado</span>';

    return `<tr>
      <td style="font-weight:600">${html(t.admin_nome || '—')}</td>
      <td>${fmtDatetime(t.abertura_em)}</td>
      <td class="r" style="color:var(--text2)">${duracao}</td>
      <td class="r" style="font-weight:700;color:var(--acc-l)">${brl(t.faturamento_no_turno)}</td>
      <td class="r">${difTxt}</td>
      <td>${statusBadge}</td>
    </tr>`;
  }).join('');

  container.innerHTML = `
    <table class="tbl">
      <thead>
        <tr>
          <th>Operador</th>
          <th>Abertura</th>
          <th class="r">Duração</th>
          <th class="r">Faturamento</th>
          <th class="r">Diferença caixa</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

// ── Fechar modal com Escape ───────────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    fecharModalSangria();
    document.getElementById('modalResumo').classList.add('hidden');
  }
});

// ── Fechar modal clicando fora ────────────────────────────────────────────
document.getElementById('modalSangria').addEventListener('click', function(e) {
  if (e.target === this) fecharModalSangria();
});
document.getElementById('modalResumo').addEventListener('click', function(e) {
  if (e.target === this) this.classList.add('hidden');
});

// ── Bootstrap ─────────────────────────────────────────────────────────────
init();
</script>
</body>
</html>

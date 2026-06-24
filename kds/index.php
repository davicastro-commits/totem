<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../admin/');
    exit;
}
require_once '../config/csrf.php';
$csrfToken    = csrfToken();
$adminNomeKds = $_SESSION['admin_nome'] ?? 'Operador';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KDS — Café Comunhão</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0b10;--surface:#12141c;--card:#1a1c28;
  --border:rgba(255,255,255,0.07);
  --wait:#d97706;--wait-bg:rgba(217,119,6,0.1);--wait-border:rgba(217,119,6,0.3);
  --prep:#3b82f6;--prep-bg:rgba(59,130,246,0.1);--prep-border:rgba(59,130,246,0.3);
  --done:#22c55e;--done-bg:rgba(34,197,94,0.1);--done-border:rgba(34,197,94,0.3);
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;
  --accent:#ff5500;--red:#ef4444;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;overflow:hidden}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:56px;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.topbar-brand{font-weight:800;font-size:18px;color:var(--accent);letter-spacing:-0.5px}
.topbar-right{display:flex;align-items:center;gap:16px}
.topbar-clock{font-size:22px;font-weight:700;color:var(--text)}
.topbar-link{color:var(--text2);font-size:13px;text-decoration:none;padding:6px 12px;border:1px solid var(--border);border-radius:8px;transition:all .15s}
.topbar-link:hover{color:var(--text);border-color:var(--text3)}
.conn-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;transition:background .3s}
.conn-ok{background:var(--done);animation:pulse 2s infinite}
.conn-err{background:var(--red)}
.conn-wait{background:var(--wait);animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.kds-body{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;height:calc(100vh - 56px);background:var(--border);overflow:hidden}
.kds-col{display:flex;flex-direction:column;background:var(--bg);overflow:hidden}
.col-header{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;border-bottom:2px solid}
.col-header h2{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.col-badge{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px}
.col-wait .col-header{border-color:var(--wait);color:var(--wait)}
.col-wait .col-badge{background:var(--wait-bg);color:var(--wait)}
.col-prep .col-header{border-color:var(--prep);color:var(--prep)}
.col-prep .col-badge{background:var(--prep-bg);color:var(--prep)}
.col-done .col-header{border-color:var(--done);color:var(--done)}
.col-done .col-badge{background:var(--done-bg);color:var(--done)}
.col-body{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:10px}
.col-body::-webkit-scrollbar{width:4px}
.col-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.order-card{background:var(--card);border-radius:12px;border:1px solid var(--border);overflow:hidden;animation:slideIn .2s ease}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.col-wait .order-card{border-left:3px solid var(--wait)}
.col-prep .order-card{border-left:3px solid var(--prep)}
.col-done .order-card{border-left:3px solid var(--done)}
.card-head{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
.card-num{font-size:22px;font-weight:800;color:var(--text)}
.card-meta{display:flex;flex-direction:column;align-items:flex-end;gap:2px}
.card-time{font-size:11px;color:var(--text3)}
.card-tipo{font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px}
.tipo-local{background:rgba(59,130,246,0.15);color:var(--prep)}
.tipo-viagem{background:rgba(245,158,11,0.15);color:#f59e0b}
.card-items{padding:10px 14px}
.card-item{padding:5px 0;border-bottom:1px solid var(--border);font-size:13px}
.card-item:last-child{border-bottom:none}
.card-item-row{display:flex;align-items:center;gap:8px}
.item-qty{font-weight:700;color:var(--accent);width:24px;flex-shrink:0}
.item-name{color:var(--text);flex:1}
.item-obs{font-size:11px;color:var(--text3);font-style:italic;padding:2px 0 0 32px}
.card-footer{padding:8px 14px;display:flex;gap:8px;align-items:center;border-top:1px solid var(--border)}
.btn-advance{flex:1;padding:8px;border:none;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;transition:all .15s;letter-spacing:.5px}
.btn-advance:disabled{opacity:.5;cursor:not-allowed}
.btn-cancel{width:36px;padding:8px;border:1px solid var(--border);border-radius:8px;background:transparent;color:var(--text3);font-size:14px;cursor:pointer;transition:all .15s}
.btn-cancel:hover{border-color:var(--red);color:var(--red)}
.col-wait .btn-advance{background:var(--wait);color:#000}
.col-wait .btn-advance:hover{background:#f59e0b}
.col-prep .btn-advance{background:var(--prep);color:#fff}
.col-prep .btn-advance:hover{background:#60a5fa}
.col-done .btn-advance{background:var(--done);color:#000}
.col-done .btn-advance:hover{background:#4ade80}
.card-timer{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;flex-shrink:0}
.timer-ok{background:rgba(107,114,128,.15);color:var(--text3)}
.timer-warn{background:rgba(217,119,6,.2);color:var(--wait)}
.timer-over{background:rgba(239,68,68,.2);color:var(--red);animation:blink 1s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.prep-time{font-size:10px;color:var(--text3);margin-left:auto}
.col-empty{padding:24px;text-align:center;color:var(--text3);font-size:13px;margin:auto}
.col-empty-icon{font-size:32px;margin-bottom:8px;opacity:0.3}
.sound-btn{background:transparent;border:1px solid var(--border);color:var(--text2);border-radius:8px;padding:6px 10px;font-size:14px;cursor:pointer}
.sound-btn:hover{border-color:var(--text3);color:var(--text)}
</style>
</head>
<body>
<div style="display:flex;flex-direction:column;height:100vh">

<div class="topbar">
  <div class="topbar-brand">KDS — Café Comunhão</div>
  <div class="topbar-right">
    <span style="font-size:12px;color:var(--text3)">
      <span class="conn-dot conn-wait" id="conn-dot"></span>
      <span id="conn-label">Conectando...</span>
    </span>
    <button class="sound-btn" id="sound-btn" title="Ativar/desativar som">🔕</button>
    <div class="topbar-clock" id="clock"></div>
    <a href="../admin/" class="topbar-link">Admin</a>
    <a href="../" class="topbar-link">Totem</a>
  </div>
</div>

<div class="kds-body">
  <div class="kds-col col-wait">
    <div class="col-header">
      <h2>Aguardando</h2>
      <span class="col-badge" id="cnt-aguardando">0</span>
    </div>
    <div class="col-body" id="body-aguardando">
      <div class="col-empty"><div class="col-empty-icon">✓</div>Nenhum pedido</div>
    </div>
  </div>
  <div class="kds-col col-prep">
    <div class="col-header">
      <h2>Preparando</h2>
      <span class="col-badge" id="cnt-preparando">0</span>
    </div>
    <div class="col-body" id="body-preparando">
      <div class="col-empty"><div class="col-empty-icon">✓</div>Nenhum pedido</div>
    </div>
  </div>
  <div class="kds-col col-done">
    <div class="col-header">
      <h2>Pronto</h2>
      <span class="col-badge" id="cnt-pronto">0</span>
    </div>
    <div class="col-body" id="body-pronto">
      <div class="col-empty"><div class="col-empty-icon">✓</div>Nenhum pedido</div>
    </div>
  </div>
</div>

</div>

<script>
'use strict';
const ADMIN_API  = '../admin/api/';
const SSE_URL    = '../api/sse.php?topic=kds';
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

let soundEnabled = false;
let prevWaitIds  = new Set();
let audioCtx     = null;
let allOrders    = [];
let evtSource    = null;
let reconnectMs  = 2000;

// ── Audio ──────────────────────────────────────────────────────────────
document.getElementById('sound-btn').addEventListener('click', function() {
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  if (audioCtx.state === 'suspended') audioCtx.resume();
  soundEnabled = !soundEnabled;
  this.textContent = soundEnabled ? '🔔' : '🔕';
});

function playBeep() {
  if (!soundEnabled) return;
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    [880, 1100].forEach((freq, i) => {
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.connect(g); g.connect(audioCtx.destination);
      o.frequency.value = freq;
      const t = audioCtx.currentTime + i * 0.18;
      g.gain.setValueAtTime(0.35, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.25);
      o.start(t); o.stop(t + 0.25);
    });
  } catch(_) {}
}

// ── UI helpers ─────────────────────────────────────────────────────────
function setConnStatus(state, label) {
  const dot = document.getElementById('conn-dot');
  const lbl = document.getElementById('conn-label');
  dot.className = 'conn-dot ' + state;
  lbl.textContent = label;
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function fmtTime(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit' });
}

function elapsedMins(iso) {
  if (!iso) return 0;
  return Math.floor((Date.now() - new Date(iso)) / 60000);
}

function timerCls(mins, col) {
  if (col === 'aguardando') return mins >= 10 ? 'timer-over' : mins >= 5 ? 'timer-warn' : 'timer-ok';
  if (col === 'preparando') return mins >= 15 ? 'timer-over' : mins >= 8 ? 'timer-warn' : 'timer-ok';
  return 'timer-ok';
}

function fmtElapsed(mins) {
  if (mins < 60) return mins + 'min';
  const h = Math.floor(mins/60), m = mins%60;
  return h + 'h' + (m > 0 ? m + 'min' : '');
}

function prepTime(p) {
  if (!p.iniciado_em) return '';
  const mins = Math.floor((new Date(p.concluido_em||Date.now()) - new Date(p.iniciado_em)) / 60000);
  return 'Preparo: ' + fmtElapsed(mins);
}

// ── Render ─────────────────────────────────────────────────────────────
function renderCard(p, col) {
  const items     = Array.isArray(p.itens) ? p.itens : JSON.parse(p.itens || '[]');
  const tipoClass = p.tipo_consumo === 'local' ? 'tipo-local' : 'tipo-viagem';
  const tipoLabel = p.tipo_consumo === 'local' ? 'Aqui' : 'Viagem';
  const timeRef   = (col === 'preparando' && p.iniciado_em) ? p.iniciado_em : p.criado_em;
  const mins      = elapsedMins(timeRef);
  const advLabel  = col === 'aguardando' ? '→ Iniciar preparo'
                  : col === 'preparando' ? '✓ Marcar pronto' : '✓ Entregue';

  const itemsHtml = items.map(i =>
    '<div class="card-item">' +
      '<div class="card-item-row">' +
        '<span class="item-qty">' + (i.qtd||i.quantidade) + 'x</span>' +
        '<span class="item-name">' + esc(i.nome||i.nome_produto) + '</span>' +
      '</div>' +
      (i.obs ? '<div class="item-obs">📝 ' + esc(i.obs) + '</div>' : '') +
    '</div>'
  ).join('');

  return '<div class="order-card" data-id="' + p.id + '">' +
    '<div class="card-head">' +
      '<div class="card-num">#' + esc(p.numero_pedido) + '</div>' +
      '<div class="card-meta">' +
        '<span class="card-time">' + fmtTime(p.criado_em) + '</span>' +
        '<span class="card-tipo ' + tipoClass + '">' + tipoLabel + '</span>' +
      '</div>' +
    '</div>' +
    '<div class="card-items">' + itemsHtml + '</div>' +
    '<div class="card-footer">' +
      '<button class="btn-advance" data-id="' + p.id + '" data-col="' + col + '">' + advLabel + '</button>' +
      '<span class="card-timer ' + timerCls(mins,col) + '" data-ref="' + (timeRef||'') + '" data-col="' + col + '">' + fmtElapsed(mins) + '</span>' +
      (col === 'pronto' && p.iniciado_em ? '<span class="prep-time">' + esc(prepTime(p)) + '</span>' : '') +
      (col !== 'pronto' ? '<button class="btn-cancel" data-id="' + p.id + '" data-act="cancel" title="Cancelar">✕</button>' : '') +
    '</div>' +
  '</div>';
}

function renderOrders(orders) {
  allOrders = orders;
  const groups = { aguardando: [], preparando: [], pronto: [] };
  orders.forEach(p => {
    if (typeof p.itens === 'string') p.itens = JSON.parse(p.itens || '[]');
    if (groups[p.status] !== undefined) groups[p.status].push(p);
  });

  // Detect new 'aguardando' orders → beep
  const waitIds = new Set(groups.aguardando.map(p => p.id));
  if (prevWaitIds.size > 0 && [...waitIds].some(id => !prevWaitIds.has(id))) playBeep();
  prevWaitIds = waitIds;

  ['aguardando','preparando','pronto'].forEach(col => {
    const body = document.getElementById('body-' + col);
    const cnt  = document.getElementById('cnt-' + col);
    const list = groups[col];
    cnt.textContent = list.length;
    body.innerHTML  = list.length
      ? list.map(p => renderCard(p, col)).join('')
      : '<div class="col-empty"><div class="col-empty-icon">✓</div>Nenhum pedido</div>';
  });
}

// ── Timers live-tick (no re-fetch) ─────────────────────────────────────
setInterval(() => {
  document.querySelectorAll('.card-timer[data-ref]').forEach(el => {
    const mins = elapsedMins(el.dataset.ref);
    el.textContent = fmtElapsed(mins);
    el.className   = 'card-timer ' + timerCls(mins, el.dataset.col);
  });
}, 30000);

// ── SSE connection ─────────────────────────────────────────────────────
function connectSSE() {
  if (evtSource) { evtSource.close(); evtSource = null; }
  setConnStatus('conn-wait', 'Conectando...');

  evtSource = new EventSource(SSE_URL);

  evtSource.addEventListener('connected', () => {
    setConnStatus('conn-ok', 'Tempo real ●');
    reconnectMs = 2000; // Reset backoff on success
  });

  evtSource.addEventListener('orders', e => {
    const payload = JSON.parse(e.data);
    renderOrders(payload.data || []);
  });

  evtSource.addEventListener('error', () => {
    setConnStatus('conn-err', 'Reconectando...');
    evtSource.close();
    evtSource = null;
    // Exponential backoff: 2s, 4s, 8s, max 30s
    setTimeout(connectSSE, Math.min(reconnectMs, 30000));
    reconnectMs = Math.min(reconnectMs * 2, 30000);
  });
}

// ── Status updates ─────────────────────────────────────────────────────
document.querySelector('.kds-body').addEventListener('click', async e => {
  const adv = e.target.closest('.btn-advance');
  if (adv && !adv.disabled) {
    const id  = parseInt(adv.dataset.id);
    const col = adv.dataset.col;
    const map = { aguardando:'preparando', preparando:'pronto', pronto:'entregue' };
    if (!map[col]) return;
    adv.disabled = true;
    await updateStatus(id, map[col]);
    return;
  }

  const cancelBtn = e.target.closest('[data-act="cancel"]');
  if (cancelBtn && !cancelBtn.disabled) {
    const id     = parseInt(cancelBtn.dataset.id);
    const pedido = allOrders.find(p => p.id === id);
    if (!confirm('Cancelar pedido #' + (pedido?.numero_pedido || id) + '?')) return;
    cancelBtn.disabled = true;
    await updateStatus(id, 'cancelado');
  }
});

async function updateStatus(id, status) {
  try {
    await fetch(ADMIN_API + 'pedidos.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body:    JSON.stringify({ id, status }),
    });
    // SSE will push the updated list automatically within ~2s
    // For immediate feedback, do a one-shot fetch
    const res = await fetch(ADMIN_API + 'pedidos.php?status=ativos', {
      headers: { 'X-CSRF-Token': CSRF_TOKEN }
    }).then(r => r.json());
    if (res.success) renderOrders(res.data || []);
  } catch(_) {}
}

// ── Clock ──────────────────────────────────────────────────────────────
function tickClock() {
  document.getElementById('clock').textContent =
    new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}
tickClock();
setInterval(tickClock, 30000);

// ── Boot ───────────────────────────────────────────────────────────────
(async () => {
  // Carregar configs do sistema (som e intervalo de reconexão)
  try {
    const cfgRes = await fetch('../api/configuracoes.php').then(r => r.json()).catch(() => null);
    if (cfgRes?.success && cfgRes.data) {
      const kdsRefresh = parseInt(cfgRes.data.kds_refresh_segundos || '5');
      if (kdsRefresh > 0) reconnectMs = kdsRefresh * 1000;

      // Auto-habilitar som se configurado (kds_som != '0')
      const kdsSom = cfgRes.data.kds_som || '0';
      if (kdsSom !== '0') {
        soundEnabled = true;
        const btn = document.getElementById('sound-btn');
        if (btn) btn.textContent = '🔔';
        // Inicializar AudioContext após interação do usuário (será criado no primeiro beep)
      }
    }
  } catch(_) {}

  // Carregar pedidos iniciais via REST, depois SSE
  try {
    const res = await fetch(ADMIN_API + 'pedidos.php?status=ativos', {
      headers: { 'X-CSRF-Token': CSRF_TOKEN }
    }).then(r => r.json());
    if (res.success) renderOrders(res.data || []);
  } catch(_) {}
  connectSSE();
})();
</script>
</body>
</html>

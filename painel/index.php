<?php
/**
 * Painel de chamada de senhas.
 * Projetado para TV/monitor na área de espera.
 * Sem autenticação — exibe apenas números de pedido (sem dados sensíveis).
 */
require_once '../config/db.php';

// Load store name for branding
try {
    $db  = getDB();
    $cfg = $db->query("SELECT chave, valor FROM totem_configuracoes WHERE chave IN ('loja_nome','loja_logo_url')")
               ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $_) {
    $cfg = [];
}
$nomeLoja   = htmlspecialchars($cfg['loja_nome']    ?? 'Café Comunhão');
$logoUrl    = htmlspecialchars($cfg['loja_logo_url'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Painel de Senhas — <?= $nomeLoja ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#080a10;--surface:#0f1119;--card:#161924;
  --accent:#ff5500;--accent-dim:rgba(255,85,0,.15);
  --green:#22c55e;--green-dim:rgba(34,197,94,.12);
  --gray:#374151;--gray-dim:rgba(55,65,81,.2);
  --text:#f0f2f8;--text2:#9ca3af;--text3:#4b5563;
  --border:rgba(255,255,255,.06);
}
html,body{height:100%;overflow:hidden;background:var(--bg);color:var(--text);font-family:'Inter',sans-serif}

/* ── Layout ── */
.layout{display:grid;grid-template-rows:auto 1fr;height:100vh}
.topbar{
  padding:20px 40px;display:flex;align-items:center;justify-content:space-between;
  background:var(--surface);border-bottom:1px solid var(--border);
}
.brand{display:flex;align-items:center;gap:16px}
.brand-logo{height:48px;object-fit:contain}
.brand-name{font-size:28px;font-weight:900;color:var(--text)}
.topbar-right{display:flex;align-items:center;gap:28px}
.clock{font-size:40px;font-weight:300;color:var(--text2);letter-spacing:2px;font-variant-numeric:tabular-nums}
.conn-badge{font-size:12px;color:var(--text3);display:flex;align-items:center;gap:6px}
.conn-dot{width:8px;height:8px;border-radius:50%;background:var(--green);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

.body{display:grid;grid-template-columns:1fr 320px;height:100%}

/* ── Chamando (em destaque) ── */
.calling-area{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:40px;gap:24px;position:relative;overflow:hidden;
}
.calling-bg{
  position:absolute;inset:0;
  background:radial-gradient(ellipse at center, rgba(255,85,0,.08) 0%, transparent 70%);
  pointer-events:none;
}
.calling-label{
  font-size:18px;font-weight:600;text-transform:uppercase;letter-spacing:4px;
  color:var(--text2);
}
.calling-num{
  font-size:min(22vw,240px);font-weight:900;line-height:1;
  background:linear-gradient(135deg,#fff 30%,var(--accent));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
  animation:pop-in .4s cubic-bezier(.175,.885,.32,1.275);
  text-align:center;
}
@keyframes pop-in{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}
.calling-status{
  font-size:22px;font-weight:700;letter-spacing:2px;
  padding:10px 32px;border-radius:999px;
  background:var(--accent);color:#fff;
  animation:glow 2s ease infinite;
}
@keyframes glow{0%,100%{box-shadow:0 0 20px rgba(255,85,0,.4)}50%{box-shadow:0 0 40px rgba(255,85,0,.7)}}
.calling-empty{color:var(--text3);font-size:20px;text-align:center}
.calling-empty-icon{font-size:80px;opacity:.15;margin-bottom:16px}

/* ── Fila (lateral) ── */
.queue-panel{
  background:var(--surface);border-left:1px solid var(--border);
  display:flex;flex-direction:column;overflow:hidden;
}
.queue-head{
  padding:20px 20px 12px;border-bottom:1px solid var(--border);
  font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--text3);
}
.queue-list{flex:1;overflow-y:auto;padding:12px}
.queue-list::-webkit-scrollbar{width:3px}
.queue-list::-webkit-scrollbar-thumb{background:var(--border)}
.queue-item{
  display:flex;align-items:center;gap:16px;
  padding:14px 16px;border-radius:10px;margin-bottom:8px;
  background:var(--card);border:1px solid var(--border);
  animation:slideIn .25s ease;
  transition:opacity .5s;
}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
.queue-item.pronto{border-color:rgba(34,197,94,.3);background:var(--green-dim)}
.queue-item.entregue{opacity:.4}
.queue-num{font-size:26px;font-weight:900;font-variant-numeric:tabular-nums;min-width:60px}
.queue-item.pronto .queue-num{color:var(--green)}
.queue-item.entregue .queue-num{color:var(--gray)}
.queue-badge{font-size:10px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.5px}
.badge-pronto{background:var(--green-dim);color:var(--green)}
.badge-entregue{background:var(--gray-dim);color:var(--gray)}
.queue-tipo{font-size:11px;color:var(--text3);margin-top:2px}
.queue-empty{padding:24px;text-align:center;color:var(--text3);font-size:13px}
</style>
</head>
<body>
<div class="layout">

  <div class="topbar">
    <div class="brand">
      <?php if ($logoUrl): ?>
        <img class="brand-logo" src="<?= $logoUrl ?>" alt="Logo">
      <?php endif; ?>
      <span class="brand-name"><?= $nomeLoja ?></span>
    </div>
    <div class="topbar-right">
      <div class="conn-badge"><span class="conn-dot" id="conn-dot"></span><span id="conn-label">Conectando</span></div>
      <div class="clock" id="clock">--:--</div>
    </div>
  </div>

  <div class="body">
    <!-- Pedido em destaque -->
    <div class="calling-area">
      <div class="calling-bg"></div>
      <div id="calling-content">
        <div class="calling-empty">
          <div class="calling-empty-icon">🔔</div>
          <p>Aguardando pedidos prontos...</p>
        </div>
      </div>
    </div>

    <!-- Fila lateral -->
    <div class="queue-panel">
      <div class="queue-head">Últimos pedidos</div>
      <div class="queue-list" id="queue-list">
        <div class="queue-empty">Nenhum pedido ainda</div>
      </div>
    </div>
  </div>
</div>

<script>
'use strict';
const SSE_URL = '../api/sse.php?topic=painel';

let evtSource    = null;
let reconnectMs  = 2000;
let allOrders    = [];
let callingIdx   = 0;
let rotateTimer  = null;

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Clock ──────────────────────────────────────────────────────────────
function tickClock() {
  document.getElementById('clock').textContent =
    new Date().toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit' });
}
tickClock();
setInterval(tickClock, 10000);

// ── Render ─────────────────────────────────────────────────────────────
function renderPainel(orders) {
  allOrders = orders;

  const prontos   = orders.filter(o => o.status === 'pronto');
  const entregues = orders.filter(o => o.status === 'entregue');
  const all       = [...prontos, ...entregues];

  // ── Calling (destaque) ─────────────────────────────────────────────
  const callingEl = document.getElementById('calling-content');

  if (prontos.length === 0) {
    clearInterval(rotateTimer);
    callingEl.innerHTML =
      '<div class="calling-empty">' +
        '<div class="calling-empty-icon">🔔</div>' +
        '<p>Aguardando pedidos prontos...</p>' +
      '</div>';
  } else {
    // Show one at a time, rotate every 4s if many
    callingIdx = callingIdx % prontos.length;
    renderCalling(prontos[callingIdx]);

    clearInterval(rotateTimer);
    if (prontos.length > 1) {
      rotateTimer = setInterval(() => {
        callingIdx = (callingIdx + 1) % prontos.length;
        renderCalling(prontos[callingIdx]);
      }, 4000);
    }
  }

  // ── Queue (sidebar) ────────────────────────────────────────────────
  const listEl = document.getElementById('queue-list');
  if (!all.length) {
    listEl.innerHTML = '<div class="queue-empty">Nenhum pedido ainda</div>';
    return;
  }

  listEl.innerHTML = all.map(o => {
    const isPronto = o.status === 'pronto';
    const tipoLbl  = o.tipo_consumo === 'local' ? '🍽️ Comer aqui' : '🛍️ Para viagem';
    return '<div class="queue-item ' + o.status + '">' +
      '<div>' +
        '<div class="queue-num">#' + esc(o.numero_pedido) + '</div>' +
        '<div class="queue-tipo">' + tipoLbl + '</div>' +
      '</div>' +
      '<span class="queue-badge ' + (isPronto ? 'badge-pronto' : 'badge-entregue') + '">' +
        (isPronto ? 'PRONTO' : 'Entregue') +
      '</span>' +
    '</div>';
  }).join('');
}

function renderCalling(order) {
  const callingEl = document.getElementById('calling-content');
  const tipoLbl   = order.tipo_consumo === 'local' ? '🍽️ Comer aqui' : '🛍️ Para viagem';
  callingEl.innerHTML =
    '<div style="display:flex;flex-direction:column;align-items:center;gap:16px">' +
      '<p class="calling-label">Pedido pronto!</p>' +
      '<div class="calling-num">#' + esc(order.numero_pedido) + '</div>' +
      '<div class="calling-status">RETIRE SEU PEDIDO</div>' +
      '<p style="font-size:16px;color:var(--text2)">' + tipoLbl + '</p>' +
    '</div>';
}

// ── SSE ────────────────────────────────────────────────────────────────
function connectSSE() {
  if (evtSource) { evtSource.close(); evtSource = null; }

  const dot   = document.getElementById('conn-dot');
  const label = document.getElementById('conn-label');
  dot.style.background = '#f59e0b';
  label.textContent = 'Conectando...';

  evtSource = new EventSource(SSE_URL);

  evtSource.addEventListener('connected', () => {
    dot.style.background = '#22c55e';
    label.textContent = 'Ao vivo';
    reconnectMs = 2000;
  });

  evtSource.addEventListener('painel', e => {
    const { data } = JSON.parse(e.data);
    renderPainel(data || []);
  });

  evtSource.onerror = () => {
    dot.style.background = '#ef4444';
    label.textContent = 'Reconectando...';
    evtSource.close();
    evtSource = null;
    setTimeout(connectSSE, Math.min(reconnectMs, 30000));
    reconnectMs = Math.min(reconnectMs * 2, 30000);
  };
}

connectSSE();
</script>
</body>
</html>

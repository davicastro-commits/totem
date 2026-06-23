<?php
declare(strict_types=1);
/**
 * Tela do Entregador — móbile-first, sem autenticação admin.
 * Mostra todas as entregas ativas e permite avançar o status.
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Entregas — Café Comunhão</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;--card2:#22253a;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;--acc-gl:rgba(255,85,0,.12);
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;--purple:#8b5cf6;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;--text4:#4b5563;
}
html,body{min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── TOPBAR ─────────────────────────────────────────────────────────── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:54px;background:var(--surf);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-logo{font-size:16px;font-weight:900;color:var(--acc)}
.topbar-sub{font-size:12px;color:var(--text3)}
.topbar-right{display:flex;align-items:center;gap:8px}
.pulse-dot{display:inline-block;width:7px;height:7px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
#countdown{font-size:11px;color:var(--text3)}

/* ── MAIN ────────────────────────────────────────────────────────────── */
.main{padding:16px;max-width:640px;margin:0 auto}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text3);margin:20px 0 10px}
.section-title:first-child{margin-top:0}

/* ── CARDS DE ENTREGA ────────────────────────────────────────────────── */
.delivery-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;margin-bottom:14px;position:relative;overflow:hidden}
.delivery-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--status-color,var(--text3));border-radius:4px 0 0 4px}
.delivery-card.status-recebido{--status-color:var(--text3)}
.delivery-card.status-preparo{--status-color:var(--blue)}
.delivery-card.status-saiu{--status-color:var(--gold)}
.delivery-card.status-entregue{--status-color:var(--green);opacity:.6}

.dc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.dc-num{font-size:18px;font-weight:900;color:var(--acc)}
.dc-total{font-size:16px;font-weight:700;color:var(--green)}

.dc-badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.3px}
.badge-recebido{background:rgba(107,114,128,.2);color:var(--text3)}
.badge-preparo{background:rgba(59,130,246,.15);color:var(--blue)}
.badge-saiu{background:rgba(245,158,11,.15);color:var(--gold)}
.badge-entregue{background:rgba(34,197,94,.15);color:var(--green)}
.badge-cancelado{background:rgba(239,68,68,.15);color:var(--red)}

.dc-address{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;background:var(--card2);border-radius:10px;margin-bottom:12px}
.dc-address-icon{font-size:16px;flex-shrink:0;margin-top:1px}
.dc-address-text{flex:1;font-size:13px;line-height:1.5;color:var(--text2)}
.dc-address-text strong{color:var(--text);display:block;margin-bottom:2px}

.dc-meta{display:flex;gap:12px;font-size:12px;color:var(--text3);margin-bottom:14px;flex-wrap:wrap}
.dc-meta-item{display:flex;align-items:center;gap:4px}

.dc-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 16px;border-radius:10px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:none;flex:1;min-height:44px}
.btn:active{transform:scale(.97)}
.btn-saiu{background:var(--gold);color:#000}
.btn-saiu:hover{filter:brightness(1.1)}
.btn-entregue{background:var(--green);color:#000}
.btn-entregue:hover{filter:brightness(1.1)}
.btn-secondary{background:var(--card2);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none}

/* ── EMPTY ───────────────────────────────────────────────────────────── */
.empty{text-align:center;padding:60px 20px;color:var(--text3)}
.empty-icon{font-size:48px;margin-bottom:16px}
.empty h3{font-size:18px;font-weight:700;color:var(--text2);margin-bottom:8px}
.empty p{font-size:14px;line-height:1.6}

/* ── LOADING ─────────────────────────────────────────────────────────── */
.spinner{display:inline-block;width:32px;height:32px;border:3px solid var(--border2);border-top-color:var(--acc);border-radius:50%;animation:spin 0.7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading{text-align:center;padding:60px 20px}

/* ── TOAST ───────────────────────────────────────────────────────────── */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);padding:12px 24px;background:var(--card2);border:1px solid var(--border2);border-radius:12px;font-size:14px;font-weight:600;z-index:9999;opacity:0;transition:all .25s;pointer-events:none;white-space:nowrap;max-width:90vw}
#toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}

/* ── HEADER INFO ─────────────────────────────────────────────────────── */
.info-bar{background:var(--acc-gl);border:1px solid rgba(255,85,0,.2);border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:var(--acc-l);display:flex;align-items:center;gap:8px}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <div>
    <div class="topbar-logo">Café Comunhão</div>
    <div class="topbar-sub">Painel do Entregador</div>
  </div>
  <div class="topbar-right">
    <span class="pulse-dot"></span>
    <span id="countdown">Atualizando...</span>
  </div>
</header>

<!-- MAIN -->
<main class="main">
  <div id="app">
    <div class="loading"><div class="spinner"></div></div>
  </div>
</main>

<div id="toast"></div>

<script>
'use strict';

const API_BASE = '../api/delivery.php';
let refreshTimer = null;
let countdownVal = 30;
let countdownInterval = null;

// ── Toast ─────────────────────────────────────────────────────────────
function toast(msg, type = 'ok') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.className = '', 3000);
}

// ── Helpers ───────────────────────────────────────────────────────────
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmt = v => 'R$ ' + parseFloat(v || 0).toFixed(2).replace('.', ',');
const fmtDt = iso => {
  try {
    return new Date(iso).toLocaleString('pt-BR', {
      day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'
    });
  } catch { return iso || ''; }
};

const STATUS_LABEL = {
  recebido: 'Recebido',
  preparo:  'Em Preparo',
  saiu:     'Saiu p/ Entrega',
  entregue: 'Entregue',
  cancelado:'Cancelado',
};

// ── Countdown ─────────────────────────────────────────────────────────
function startCountdown() {
  clearInterval(countdownInterval);
  countdownVal = 30;
  updateCountdownDisplay();
  countdownInterval = setInterval(() => {
    countdownVal--;
    updateCountdownDisplay();
    if (countdownVal <= 0) {
      clearInterval(countdownInterval);
      loadEntregas();
    }
  }, 1000);
}

function updateCountdownDisplay() {
  const el = document.getElementById('countdown');
  if (el) el.textContent = 'Atualiza em ' + countdownVal + 's';
}

// ── Carregar entregas ativas ──────────────────────────────────────────
async function loadEntregas() {
  try {
    const res = await fetch(API_BASE + '?action=ativas');
    const data = await res.json();

    if (!data.success) {
      document.getElementById('app').innerHTML =
        '<div class="empty"><div class="empty-icon">⚠️</div><h3>Erro ao carregar</h3><p>' + esc(data.error || 'Tente novamente.') + '</p></div>';
      startCountdown();
      return;
    }

    renderEntregas(data.data || []);
    startCountdown();
  } catch (e) {
    document.getElementById('app').innerHTML =
      '<div class="empty"><div class="empty-icon">📡</div><h3>Sem conexão</h3><p>Verifique a rede e aguarde a atualização automática.</p></div>';
    startCountdown();
  }
}

// ── Renderizar cards ──────────────────────────────────────────────────
function renderEntregas(lista) {
  if (!lista.length) {
    document.getElementById('app').innerHTML =
      '<div class="empty">' +
        '<div class="empty-icon">🛵</div>' +
        '<h3>Nenhuma entrega ativa</h3>' +
        '<p>Quando houver pedidos para entregar,<br>eles aparecerão aqui automaticamente.</p>' +
      '</div>';
    return;
  }

  // Agrupar por status
  const grupos = {
    saiu:     lista.filter(e => e.status === 'saiu'),
    preparo:  lista.filter(e => e.status === 'preparo'),
    recebido: lista.filter(e => e.status === 'recebido'),
  };

  let html = '';

  // Saindo primeiro (mais urgente)
  if (grupos.saiu.length) {
    html += '<div class="section-title">🛵 Em rota (' + grupos.saiu.length + ')</div>';
    grupos.saiu.forEach(e => { html += cardHtml(e); });
  }

  if (grupos.preparo.length) {
    html += '<div class="section-title">🍳 Em preparo (' + grupos.preparo.length + ')</div>';
    grupos.preparo.forEach(e => { html += cardHtml(e); });
  }

  if (grupos.recebido.length) {
    html += '<div class="section-title">📥 Aguardando (' + grupos.recebido.length + ')</div>';
    grupos.recebido.forEach(e => { html += cardHtml(e); });
  }

  document.getElementById('app').innerHTML = html;
}

function cardHtml(e) {
  const statusLabel = STATUS_LABEL[e.status] || e.status;
  const addr = [e.logradouro, e.end_numero, e.bairro].filter(Boolean).join(', ');

  // Botões conforme status
  let btns = '';
  if (e.status === 'recebido' || e.status === 'preparo') {
    btns += `<button class="btn btn-saiu" onclick="atualizarStatus(${e.id}, 'saiu', this)">🛵 Saiu para entrega</button>`;
  }
  if (e.status === 'saiu' || e.status === 'preparo' || e.status === 'recebido') {
    btns += `<button class="btn btn-entregue" onclick="atualizarStatus(${e.id}, 'entregue', this)">✓ Marcar como entregue</button>`;
  }

  return `
    <div class="delivery-card status-${esc(e.status)}" id="card-${e.id}">
      <div class="dc-header">
        <div class="dc-num">#${esc(e.numero_pedido)}</div>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="dc-total">${fmt(e.total)}</span>
          <span class="dc-badge badge-${esc(e.status)}">${esc(statusLabel)}</span>
        </div>
      </div>

      ${addr ? `
      <div class="dc-address">
        <div class="dc-address-icon">📍</div>
        <div class="dc-address-text">
          <strong>${esc(addr)}</strong>
          ${e.bairro ? '<span>' + esc(e.bairro) + '</span>' : ''}
        </div>
      </div>` : ''}

      <div class="dc-meta">
        <div class="dc-meta-item">🕐 ${esc(fmtDt(e.criado_em))}</div>
        ${e.taxa_entrega > 0 ? `<div class="dc-meta-item">🚚 Taxa: ${fmt(e.taxa_entrega)}</div>` : ''}
        ${e.previsao_min > 0 ? `<div class="dc-meta-item">⏱ Prazo: ${esc(e.previsao_min)}min</div>` : ''}
        ${e.entregador_nome ? `<div class="dc-meta-item">👤 ${esc(e.entregador_nome)}</div>` : ''}
      </div>

      ${btns ? `<div class="dc-actions">${btns}</div>` : ''}
    </div>`;
}

// ── Atualizar status ──────────────────────────────────────────────────
async function atualizarStatus(entregaId, status, btn) {
  btn.disabled = true;
  const original = btn.textContent;
  btn.textContent = 'Aguarde...';

  try {
    const res = await fetch(API_BASE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'atualizar_status_entregador', entrega_id: entregaId, status }),
    });
    const data = await res.json();

    if (data.success) {
      const label = STATUS_LABEL[status] || status;
      toast('Status atualizado: ' + label, 'ok');
      // Recarregar imediatamente
      clearInterval(countdownInterval);
      await loadEntregas();
    } else {
      toast(data.error || 'Erro ao atualizar', 'err');
      btn.disabled = false;
      btn.textContent = original;
    }
  } catch {
    toast('Erro de conexão', 'err');
    btn.disabled = false;
    btn.textContent = original;
  }
}

// ── Init ──────────────────────────────────────────────────────────────
loadEntregas();
</script>
</body>
</html>

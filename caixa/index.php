<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../admin/');
    exit;
}
require_once '../config/csrf.php';
$csrfToken  = csrfToken();
$admin_nome = $_SESSION['admin_nome'] ?? 'Operador';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caixa — Café Comunhão</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f1017;--surface:#16181f;--card:#1e2029;--card2:#252831;
  --border:rgba(255,255,255,0.07);--border2:rgba(255,255,255,0.12);
  --accent:#ff5500;--accent-l:#ff7733;--accent-gl:rgba(255,85,0,0.15);
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;overflow:hidden}
.pdv{display:grid;grid-template-columns:1fr 380px;height:100vh;gap:1px;background:var(--border)}
.topbar{background:var(--surface);padding:0 16px;height:52px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);grid-column:1/-1}
.topbar-brand{font-weight:800;color:var(--accent);font-size:16px}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:13px;color:var(--text2)}
.topbar-right a{color:var(--text2);text-decoration:none;border:1px solid var(--border);padding:5px 10px;border-radius:7px}
.topbar-right a:hover{color:var(--text)}
.panel-left{display:flex;flex-direction:column;background:var(--bg);overflow:hidden}
.panel-left-head{padding:12px 16px;border-bottom:1px solid var(--border);flex-shrink:0}
.search-box{display:flex;align-items:center;background:var(--card);border:1px solid var(--border2);border-radius:10px;padding:0 12px;gap:8px;height:40px}
.search-box input{background:transparent;border:none;outline:none;color:var(--text);font-size:14px;flex:1;font-family:inherit}
.search-box input::placeholder{color:var(--text3)}
.cats-bar{display:flex;gap:6px;overflow-x:auto;padding:10px 16px;flex-shrink:0;border-bottom:1px solid var(--border)}
.cats-bar::-webkit-scrollbar{height:0}
.cat-pill{padding:6px 14px;border-radius:999px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap;transition:all .15s;font-family:inherit}
.cat-pill.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.cat-pill:hover:not(.active){border-color:var(--text3);color:var(--text)}
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;padding:14px;overflow-y:auto;flex:1}
.products-grid::-webkit-scrollbar{width:4px}
.products-grid::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
.prod-card{background:var(--card);border:1px solid var(--border);border-radius:12px;cursor:pointer;transition:all .15s;overflow:hidden;display:flex;flex-direction:column}
.prod-card:hover{border-color:var(--accent);transform:translateY(-1px);box-shadow:0 4px 16px rgba(255,85,0,0.15)}
.prod-card:active{transform:none}
.prod-card.added{animation:flash .25s ease}
@keyframes flash{0%,100%{border-color:var(--border)}50%{border-color:var(--accent);background:var(--accent-gl)}}
.prod-thumb{height:80px;display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0}
.prod-body{padding:10px;flex:1;display:flex;flex-direction:column;gap:4px}
.prod-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.3;flex:1}
.prod-price{font-size:15px;font-weight:700;color:var(--accent)}
.prod-estoque{font-size:10px;color:var(--red);font-weight:600}
.panel-right{display:flex;flex-direction:column;background:var(--surface)}
.cart-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.cart-head h2{font-size:15px;font-weight:700}
.btn-clear{background:transparent;border:1px solid var(--border);color:var(--text3);border-radius:7px;padding:5px 10px;font-size:12px;cursor:pointer;font-family:inherit}
.btn-clear:hover{border-color:var(--red);color:var(--red)}
.cart-list{flex:1;overflow-y:auto;padding:8px}
.cart-list::-webkit-scrollbar{width:3px}
.cart-list::-webkit-scrollbar-thumb{background:var(--border2)}
.cart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:10px;color:var(--text3)}
.cart-empty-icon{font-size:40px;opacity:.2}
.cart-item{padding:6px 8px;border-radius:8px;transition:background .12s}
.cart-item:hover{background:var(--card)}
.cart-item-row{display:flex;align-items:center;gap:10px}
.cart-item-name{flex:1;font-size:13px;font-weight:500}
.cart-item-price{font-size:12px;color:var(--text3)}
.cart-obs{width:100%;margin-top:4px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;padding:5px 8px;color:var(--text2);font-size:11px;font-family:inherit;outline:none;resize:none}
.cart-obs:focus{border-color:var(--accent)}
.cart-obs::placeholder{color:var(--text3)}
.qty-row{display:flex;align-items:center;gap:6px}
.qty-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--border2);background:transparent;color:var(--text);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .12s}
.qty-btn:hover{border-color:var(--accent);color:var(--accent)}
.qty-val{font-size:14px;font-weight:700;min-width:20px;text-align:center}
.item-sub{font-size:13px;font-weight:600;color:var(--text2);min-width:60px;text-align:right}
.cart-summary{padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0}
.sum-row{display:flex;justify-content:space-between;font-size:13px;color:var(--text2);margin-bottom:6px}
.sum-total{font-size:20px;font-weight:800;color:var(--text);margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
.tipo-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}
.tipo-btn{padding:8px;border-radius:8px;border:1px solid var(--border2);background:var(--card);color:var(--text2);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;text-align:center}
.tipo-btn.active{border-color:var(--accent);color:var(--accent);background:var(--accent-gl)}
.payment-row{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px}
.pay-btn{padding:8px 4px;border-radius:8px;border:1px solid var(--border2);background:var(--card);color:var(--text2);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;text-align:center;display:flex;flex-direction:column;align-items:center;gap:3px}
.pay-btn .pay-icon{font-size:18px}
.pay-btn.active{border-color:var(--accent);color:var(--accent);background:var(--accent-gl)}
.pay-btn:hover:not(.active){border-color:var(--text3);color:var(--text)}
.cpf-row{display:flex;gap:6px;margin-bottom:10px;align-items:center}
.cpf-row input{flex:1;background:var(--card);border:1px solid var(--border2);border-radius:8px;color:var(--text);font-size:14px;font-family:inherit;padding:7px 10px;outline:none}
.cpf-row input:focus{border-color:var(--accent)}
.cpf-row label{font-size:11px;color:var(--text2);white-space:nowrap}
.troco-box{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:10px;display:none}
.troco-box.visible{display:block}
.troco-label{font-size:11px;color:var(--text2);margin-bottom:4px}
.troco-input{display:flex;align-items:center;gap:8px}
.troco-input input{background:transparent;border:1px solid var(--border2);border-radius:7px;color:var(--text);font-size:15px;font-weight:700;font-family:inherit;padding:6px 10px;width:100%;outline:none}
.troco-input input:focus{border-color:var(--accent)}
.troco-result{margin-top:8px;font-size:13px;display:flex;justify-content:space-between;align-items:center}
.troco-val{font-weight:700;color:var(--green)}
.btn-confirm{width:100%;padding:14px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s}
.btn-confirm:hover{background:var(--accent-l);transform:translateY(-1px)}
.btn-confirm:disabled{opacity:.4;pointer-events:none}
/* SUCCESS MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;z-index:100;opacity:0;pointer-events:none;transition:opacity .15s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:16px;padding:28px;width:380px;text-align:center}
.modal h2{font-size:20px;font-weight:800;margin-bottom:8px}
.modal p{color:var(--text2);margin-bottom:20px;font-size:14px}
.modal-num{font-size:64px;font-weight:800;color:var(--accent);margin-bottom:8px}
.modal-total{font-size:18px;font-weight:600;margin-bottom:20px}
.modal-troco{font-size:15px;color:var(--green);margin-bottom:20px;font-weight:600}
.modal-btns{display:flex;gap:10px}
.modal-btns button{flex:1;padding:12px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;transition:all .15s}
.btn-print-modal{background:var(--card);color:var(--text);border:1px solid var(--border2)}
.btn-print-modal:hover{border-color:var(--text3)}
.btn-novo{background:var(--accent);color:#fff;border:none}
.btn-novo:hover{background:var(--accent-l)}
/* NOTIFICAÇÃO DE PEDIDO PRONTO */
.notify-bar{position:fixed;top:0;left:0;right:0;z-index:200;display:flex;flex-direction:column;gap:8px;padding:8px 16px;pointer-events:none}
.notify-item{background:var(--green);color:#000;border-radius:12px;padding:12px 20px;font-weight:700;font-size:15px;display:flex;align-items:center;gap:12px;box-shadow:0 4px 24px rgba(0,0,0,.5);animation:slideDown .3s ease;pointer-events:all;cursor:pointer}
.notify-item span{flex:1}
.notify-item .notify-close{opacity:.6;font-size:18px;line-height:1}
@keyframes slideDown{from{transform:translateY(-60px);opacity:0}to{transform:translateY(0);opacity:1}}
/* PRINT */
#receipt-container{display:none}
@media print{
  body *{visibility:hidden}
  #receipt-container,#receipt-container *{visibility:visible}
  #receipt-container{display:block;position:fixed;inset:0;padding:10mm 8mm}
  .receipt{font-family:'Courier New',monospace;font-size:11px;line-height:1.6;max-width:80mm;margin:0 auto}
  .receipt h2,.receipt strong{font-weight:bold}
  .receipt hr{border:none;border-top:1px dashed #000;margin:6px 0}
  .receipt-header{text-align:center;margin-bottom:8px}
  .receipt table{width:100%;border-collapse:collapse;font-size:10px}
  .receipt table th{border-bottom:1px solid #000;padding:2px 0}
  .receipt table td{padding:2px 0;vertical-align:top}
  .receipt table td:last-child{text-align:right}
  .receipt-totals{margin-top:6px}
  .receipt-total-row{display:flex;justify-content:space-between}
  .receipt-total-row.total{font-weight:bold;font-size:12px}
  .receipt-footer{text-align:center;margin-top:8px;font-size:10px}
}
/* AGUARDANDO PAGAMENTO */
.awp-bar{background:rgba(245,158,11,.08);border-bottom:2px solid rgba(245,158,11,.3);padding:8px 16px;display:none}
.awp-bar.has-items{display:block}
.awp-bar-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#f59e0b;margin-bottom:8px}
.awp-list{display:flex;flex-wrap:wrap;gap:8px}
.awp-item{background:var(--card);border:1px solid rgba(245,158,11,.4);border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:12px;min-width:220px}
.awp-num{font-size:18px;font-weight:900;color:#f59e0b}
.awp-info{flex:1;font-size:12px;line-height:1.6}
.awp-pgto{font-weight:700;color:var(--text)}
.awp-total{color:var(--text2)}
.awp-since{color:var(--text3);font-size:11px}
.btn-awp-confirm{background:#f59e0b;color:#000;border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap}
.btn-awp-confirm:hover{background:#fbbf24}
</style>
</head>
<body>

<div class="notify-bar" id="notify-bar"></div>
<div class="awp-bar" id="awp-bar">
  <div class="awp-bar-title">⏳ Aguardando confirmação de pagamento</div>
  <div class="awp-list" id="awp-list"></div>
</div>

<div class="pdv" style="display:grid;grid-template-rows:52px 1fr;grid-template-columns:1fr 380px">

  <div class="topbar" style="grid-column:1/-1">
    <div class="topbar-brand" id="brand-nome">Caixa — Café Comunhão</div>
    <div class="topbar-right">
      <span>Operador: <strong><?= htmlspecialchars($admin_nome) ?></strong></span>
      <span id="topbar-clock"></span>
      <a href="../kds/">KDS</a>
      <a href="../admin/">Admin</a>
      <a href="../">Totem</a>
    </div>
  </div>

  <div class="panel-left">
    <div class="panel-left-head">
      <div class="search-box">
        <span>🔍</span>
        <input type="text" id="search" placeholder="Buscar produto..." autocomplete="off">
      </div>
    </div>
    <div class="cats-bar" id="cats-bar"></div>
    <div class="products-grid" id="products-grid"></div>
  </div>

  <div class="panel-right">
    <div class="cart-head">
      <h2>Pedido</h2>
      <button class="btn-clear" id="btn-clear">Limpar tudo</button>
    </div>

    <div class="cart-list" id="cart-list">
      <div class="cart-empty">
        <div class="cart-empty-icon">🛒</div>
        <span>Nenhum item adicionado</span>
      </div>
    </div>

    <div class="cart-summary">
      <div class="sum-row"><span>Itens</span><span id="sum-count">0</span></div>
      <div class="sum-total"><span>Total</span><span id="sum-total">R$ 0,00</span></div>

      <div class="tipo-row">
        <button class="tipo-btn active" data-tipo="local">🍽️ Comer aqui</button>
        <button class="tipo-btn" data-tipo="viagem">🛍️ Para viagem</button>
      </div>

      <div class="cpf-row">
        <label>CPF:</label>
        <input type="text" id="cpf-input" placeholder="Opcional" maxlength="14">
      </div>

      <div class="payment-row">
        <button class="pay-btn" data-pay="pix"><span class="pay-icon">📱</span>PIX</button>
        <button class="pay-btn" data-pay="credito"><span class="pay-icon">💳</span>Crédito</button>
        <button class="pay-btn" data-pay="debito"><span class="pay-icon">💳</span>Débito</button>
        <button class="pay-btn" data-pay="dinheiro"><span class="pay-icon">💵</span>Dinheiro</button>
      </div>

      <div class="troco-box" id="troco-box">
        <div class="troco-label">Valor recebido:</div>
        <div class="troco-input">
          <input type="number" id="troco-input" placeholder="0,00" step="0.01" min="0">
        </div>
        <div class="troco-result" id="troco-result" style="display:none">
          <span style="color:var(--text2)">Troco:</span>
          <span class="troco-val" id="troco-val"></span>
        </div>
      </div>

      <button class="btn-confirm" id="btn-confirm" disabled>Confirmar pedido</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal">
  <div class="modal">
    <h2>Pedido confirmado!</h2>
    <p>Número do pedido</p>
    <div class="modal-num" id="modal-num">#000</div>
    <div class="modal-total" id="modal-total"></div>
    <div class="modal-troco" id="modal-troco" style="display:none"></div>
    <div class="modal-btns">
      <button class="btn-print-modal" id="btn-print">🖨️ Imprimir</button>
      <button class="btn-novo" id="btn-novo">Novo pedido</button>
    </div>
  </div>
</div>

<div id="receipt-container"></div>

<script>
'use strict';
const BASE       = '../api/';
const ADMIN_BASE = '../admin/api/';
const CSRF       = <?= json_encode($csrfToken) ?>;

let cfg = { loja_nome: 'Café Comunhão', loja_cnpj: '', loja_endereco: '' };

let state = {
  categorias: [], produtos: {}, allProdutos: [],
  catId: null, cart: [], tipo: 'local',
  pagamento: null, cpf: '', lastPedido: null, searchTerm: '',
};

// Pedidos prontos já notificados (evitar repetição)
let notifiedProntos = new Set();

const fmt = v => 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
function cartCount(){ return state.cart.reduce((n,i)=>n+i.quantidade,0); }
function cartTotal(){ return state.cart.reduce((n,i)=>n+i.preco*i.quantidade,0); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function loadConfig() {
  try {
    const r = await fetch(BASE + 'configuracoes.php').then(r => r.json());
    if (r.success && r.data) {
      cfg = { ...cfg, ...r.data };
      const brand = document.getElementById('brand-nome');
      if (brand) brand.textContent = 'Caixa — ' + (cfg.loja_nome || 'Café Comunhão');
    }
  } catch(_) {}
}

// ── NOTIFICAÇÕES VIA SSE ─────────────────────────────────────────────
let sseSource    = null;
let sseReconnect = 3000;

function connectSSE() {
  if (sseSource) { sseSource.close(); sseSource = null; }

  sseSource = new EventSource('../api/sse.php?topic=caixa');

  sseSource.addEventListener('connected', () => {
    sseReconnect = 3000;
  });

  sseSource.addEventListener('ready', e => {
    const { data } = JSON.parse(e.data);
    const current = new Set((data || []).map(p => p.id));

    // Notify new 'pronto' orders
    (data || []).forEach(p => {
      if (!notifiedProntos.has(p.id)) {
        notifiedProntos.add(p.id);
        showProntoNotify(p);
      }
    });

    // Clean IDs no longer 'pronto'
    notifiedProntos.forEach(id => { if (!current.has(id)) notifiedProntos.delete(id); });
  });

  sseSource.onerror = () => {
    sseSource.close();
    sseSource = null;
    setTimeout(connectSSE, Math.min(sseReconnect, 30000));
    sseReconnect = Math.min(sseReconnect * 2, 30000);
  };
}

function showProntoNotify(pedido) {
  const bar  = document.getElementById('notify-bar');
  const item = document.createElement('div');
  item.className = 'notify-item';
  item.innerHTML = '<span>✅ Pedido <strong>#' + esc(pedido.numero_pedido) + '</strong> está PRONTO para entrega!</span>' +
                   '<span class="notify-close" title="Fechar">×</span>';

  // Auto-remover em 8s
  const timer = setTimeout(() => item.remove(), 8000);
  item.querySelector('.notify-close').addEventListener('click', () => { clearTimeout(timer); item.remove(); });
  item.addEventListener('click', e => { if (!e.target.classList.contains('notify-close')) { clearTimeout(timer); item.remove(); } });

  bar.appendChild(item);
  playReadySound();
}

let audioCtx = null;
function playReadySound() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    [523, 659, 784].forEach((freq, i) => {
      const o = audioCtx.createOscillator();
      const g = audioCtx.createGain();
      o.connect(g); g.connect(audioCtx.destination);
      o.frequency.value = freq;
      o.type = 'sine';
      const t = audioCtx.currentTime + i * 0.15;
      g.gain.setValueAtTime(0.3, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.3);
      o.start(t); o.stop(t + 0.3);
    });
  } catch(_) {}
}

// ── CART ─────────────────────────────────────────────────────────────
function addToCart(prod) {
  const ex = state.cart.find(i => i.id === prod.id);
  if (ex) ex.quantidade++;
  else state.cart.push({...prod, quantidade:1, obs:''});
  renderCart();
  renderSummary();
}

function changeQty(id, delta) {
  const it = state.cart.find(i => i.id === id);
  if (!it) return;
  it.quantidade += delta;
  if (it.quantidade <= 0) state.cart = state.cart.filter(i => i.id !== id);
  renderCart();
  renderSummary();
}

function renderCart() {
  const el = document.getElementById('cart-list');
  if (!state.cart.length) {
    el.innerHTML = '<div class="cart-empty"><div class="cart-empty-icon">🛒</div><span>Nenhum item adicionado</span></div>';
    return;
  }
  el.innerHTML = state.cart.map((i, idx) =>
    '<div class="cart-item">' +
      '<div class="cart-item-row">' +
        '<div style="flex:1">' +
          '<div class="cart-item-name">' + esc(i.nome) + '</div>' +
          '<div class="cart-item-price">' + fmt(i.preco) + ' un.</div>' +
        '</div>' +
        '<div class="qty-row">' +
          '<button class="qty-btn" data-id="' + i.id + '" data-d="-1">−</button>' +
          '<span class="qty-val">' + i.quantidade + '</span>' +
          '<button class="qty-btn" data-id="' + i.id + '" data-d="1">+</button>' +
        '</div>' +
        '<div class="item-sub">' + fmt(i.preco * i.quantidade) + '</div>' +
      '</div>' +
      '<input type="text" class="cart-obs" placeholder="📝 Observação (ex: sem açúcar)" maxlength="80" ' +
        'value="' + esc(i.obs || '') + '" data-obs-idx="' + idx + '">' +
    '</div>'
  ).join('');

  // Bind obs inputs
  el.querySelectorAll('.cart-obs').forEach(inp => {
    inp.addEventListener('input', e => {
      const idx = parseInt(e.target.dataset.obsIdx);
      if (state.cart[idx]) state.cart[idx].obs = e.target.value;
    });
  });
}

function renderSummary() {
  document.getElementById('sum-count').textContent  = cartCount();
  document.getElementById('sum-total').textContent  = fmt(cartTotal());
  document.getElementById('btn-confirm').disabled   = !state.cart.length || !state.pagamento;
  calcTroco();
}

function calcTroco() {
  const box = document.getElementById('troco-box');
  const rec = document.getElementById('troco-result');
  const val = document.getElementById('troco-val');
  box.classList.toggle('visible', state.pagamento === 'dinheiro');
  if (state.pagamento === 'dinheiro') {
    const recebido = parseFloat(document.getElementById('troco-input').value) || 0;
    const troco = recebido - cartTotal();
    if (recebido > 0) {
      rec.style.display = 'flex';
      val.textContent = fmt(Math.max(0, troco));
      val.style.color = troco >= 0 ? 'var(--green)' : 'var(--red)';
    } else { rec.style.display = 'none'; }
  }
}

// ── PRODUTOS ──────────────────────────────────────────────────────────
async function loadCategorias() {
  const res = await fetch(BASE + 'categorias.php').then(r => r.json());
  if (!res.success) return;
  state.categorias = res.data;
  const bar = document.getElementById('cats-bar');
  bar.innerHTML = res.data.map(c =>
    '<button class="cat-pill' + (c.id === state.catId ? ' active' : '') + '" data-id="' + c.id + '">' +
    c.icone + ' ' + esc(c.nome) + '</button>'
  ).join('');
  if (!state.catId && res.data.length) {
    state.catId = res.data[0].id;
    document.querySelector('.cat-pill')?.classList.add('active');
    loadProdutos(state.catId);
  }
}

async function loadAllProdutos() {
  const res = await fetch(ADMIN_BASE + 'produtos.php', {
    headers: { 'X-CSRF-Token': CSRF }
  }).then(r => r.json());
  if (res.success) state.allProdutos = res.data.filter(p => p.disponivel);
}

async function loadProdutos(catId) {
  if (!state.produtos[catId]) {
    const res = await fetch(BASE + 'produtos.php?categoria_id=' + catId).then(r => r.json());
    if (res.success) state.produtos[catId] = res.data;
  }
  renderProdutos();
}

function renderProdutos() {
  const grid = document.getElementById('products-grid');
  const term = state.searchTerm.toLowerCase().trim();
  const COLORS = ['#C94B32','#1E6FA8','#1E8C45','#7B3BA8','#B5620A','#2A7A7A'];
  const catIcon = cid => { const c = state.categorias.find(x => x.id == cid); return c ? c.icone : '☕'; };

  const list = term
    ? state.allProdutos.filter(p => p.nome.toLowerCase().includes(term))
    : (state.produtos[state.catId] || []);

  grid.innerHTML = list.map(p => {
    const semEstoque = p.controlar_estoque && parseInt(p.estoque_qtd) <= 0;
    const poucas     = p.controlar_estoque && parseInt(p.estoque_qtd) > 0 && parseInt(p.estoque_qtd) <= 5;
    return '<div class="prod-card' + (semEstoque ? ' disabled' : '') + '" ' +
      (semEstoque ? '' : 'data-id="' + p.id + '" data-nome="' + esc(p.nome) + '" data-preco="' + p.preco + '"') + ' ' +
      'style="' + (semEstoque ? 'opacity:.4;pointer-events:none' : '') + '">' +
      '<div class="prod-thumb" style="background:' + COLORS[parseInt(p.id)%COLORS.length] + '">' + catIcon(p.categoria_id) + '</div>' +
      '<div class="prod-body">' +
        '<div class="prod-name">' + esc(p.nome) + '</div>' +
        '<div class="prod-price">' + fmt(p.preco) + '</div>' +
        (semEstoque ? '<div class="prod-estoque">Esgotado</div>' : '') +
        (poucas    ? '<div class="prod-estoque">Últimas ' + p.estoque_qtd + '</div>' : '') +
      '</div>' +
    '</div>';
  }).join('');
}

// ── ORDER ─────────────────────────────────────────────────────────────
async function confirmOrder() {
  const btn = document.getElementById('btn-confirm');
  btn.disabled = true;
  btn.textContent = 'Processando...';

  const cpfRaw   = document.getElementById('cpf-input').value.replace(/\D/g,'');
  const recebido = state.pagamento === 'dinheiro'
    ? parseFloat(document.getElementById('troco-input').value) || 0 : null;

  try {
    const res = await fetch(BASE + 'pedido.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tipo_consumo:    state.tipo,
        cpf:             cpfRaw || null,
        forma_pagamento: state.pagamento,
        origem:          'caixa',
        itens:           state.cart.map(i => ({ id: i.id, quantidade: i.quantidade, obs: i.obs || '' })),
      }),
    }).then(r => r.json());

    if (res.success) {
      state.lastPedido = res.pedido;
      buildReceipt(res.pedido, recebido);
      showModal(res.pedido, recebido);
    } else {
      alert('Erro: ' + (res.error || 'Falha ao processar'));
      btn.disabled = false;
      btn.textContent = 'Confirmar pedido';
    }
  } catch(e) {
    alert('Erro de conexão: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'Confirmar pedido';
  }
}

function showModal(p, recebido) {
  document.getElementById('modal-num').textContent   = '#' + p.numero;
  document.getElementById('modal-total').textContent = 'Total: ' + fmt(p.total);
  const trocoEl = document.getElementById('modal-troco');
  if (recebido && state.pagamento === 'dinheiro') {
    trocoEl.textContent = 'Troco: ' + fmt(Math.max(0, recebido - parseFloat(p.total)));
    trocoEl.style.display = 'block';
  } else { trocoEl.style.display = 'none'; }
  document.getElementById('modal').classList.add('open');
}

function buildReceipt(p, recebido) {
  const pgLbl    = { pix:'PIX', credito:'CREDITO', debito:'DEBITO', dinheiro:'DINHEIRO' }[p.forma_pagamento] || p.forma_pagamento;
  const troco    = recebido && p.forma_pagamento === 'dinheiro' ? Math.max(0, recebido - parseFloat(p.total)) : null;
  const nomeLoja = (cfg.loja_nome || 'Café Comunhão').toUpperCase();
  const cnpj     = cfg.loja_cnpj     ? 'CNPJ: ' + cfg.loja_cnpj     : '';
  const endereco = cfg.loja_endereco || '';

  document.getElementById('receipt-container').innerHTML =
    '<div class="receipt">' +
      '<div class="receipt-header">' +
        '<h2>' + esc(nomeLoja) + '</h2>' +
        (cnpj     ? '<p>' + esc(cnpj) + '</p>' : '') +
        (endereco ? '<p>' + esc(endereco) + '</p>' : '') +
      '</div>' +
      '<hr><div style="text-align:center;font-weight:bold">CUPOM NÃO FISCAL</div><hr>' +
      '<p>Pedido: <strong>#' + p.numero + '</strong></p>' +
      '<p>Data: ' + p.criado_em + '</p>' +
      '<p>Consumo: ' + (p.tipo_consumo==='local'?'COMER AQUI':'PARA VIAGEM') + '</p>' +
      '<hr>' +
      '<table><thead><tr><th style="text-align:left">Item</th><th>Qtd</th><th style="text-align:right">Valor</th></tr></thead><tbody>' +
      p.itens.map(i =>
        '<tr><td>' + esc(i.nome_produto) +
        (i.obs ? '<br><small>' + esc(i.obs) + '</small>' : '') +
        '</td><td style="text-align:center">' + i.quantidade + '</td>' +
        '<td style="text-align:right">' + fmt(i.subtotal) + '</td></tr>'
      ).join('') +
      '</tbody></table><hr>' +
      '<div class="receipt-totals"><div class="receipt-total-row total"><span>TOTAL</span><strong>' + fmt(p.total) + '</strong></div></div>' +
      '<hr>' +
      '<p>PAGAMENTO: <strong>' + pgLbl + '</strong></p>' +
      (recebido ? '<p>RECEBIDO: <strong>' + fmt(recebido) + '</strong></p>' : '') +
      (troco !== null ? '<p>TROCO: <strong>' + fmt(troco) + '</strong></p>' : '') +
      (p.cpf ? '<p>CPF: ' + p.cpf + '</p>' : '') +
      '<hr>' +
      '<div class="receipt-footer"><p>Obrigado pela preferência!</p></div>' +
    '</div>';
}

function resetOrder() {
  state.cart = []; state.pagamento = null;
  document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('cpf-input').value    = '';
  document.getElementById('troco-input').value  = '';
  document.getElementById('btn-confirm').textContent = 'Confirmar pedido';
  renderCart(); renderSummary();
  document.getElementById('modal').classList.remove('open');
}

// ── EVENTS ────────────────────────────────────────────────────────────
document.getElementById('cats-bar').addEventListener('click', e => {
  const pill = e.target.closest('.cat-pill');
  if (!pill) return;
  state.catId = parseInt(pill.dataset.id);
  state.searchTerm = '';
  document.getElementById('search').value = '';
  document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
  pill.classList.add('active');
  loadProdutos(state.catId);
});

document.getElementById('products-grid').addEventListener('click', e => {
  const card = e.target.closest('.prod-card[data-id]');
  if (!card) return;
  addToCart({ id: parseInt(card.dataset.id), nome: card.dataset.nome, preco: parseFloat(card.dataset.preco) });
  card.classList.remove('added'); void card.offsetWidth; card.classList.add('added');
});

document.getElementById('cart-list').addEventListener('click', e => {
  const btn = e.target.closest('.qty-btn');
  if (!btn) return;
  changeQty(parseInt(btn.dataset.id), parseInt(btn.dataset.d));
});

document.getElementById('btn-clear').addEventListener('click', () => {
  if (state.cart.length && confirm('Limpar todos os itens?')) {
    state.cart = []; renderCart(); renderSummary();
  }
});

document.querySelectorAll('.tipo-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    state.tipo = btn.dataset.tipo;
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  });
});

document.querySelectorAll('.pay-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    state.pagamento = btn.dataset.pay;
    document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderSummary();
  });
});

document.getElementById('troco-input').addEventListener('input', calcTroco);
document.getElementById('btn-confirm').addEventListener('click', confirmOrder);
document.getElementById('btn-print').addEventListener('click', async () => {
  const btn = document.getElementById('btn-print');
  const p   = state.lastPedido;
  if (!p) return;
  btn.disabled = true;
  btn.textContent = 'Imprimindo...';
  try {
    const res = await fetch(BASE + 'imprimir.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ pedido_id: p.id }),
    }).then(r => r.json());
    if (res.success) {
      btn.textContent = '✓ Impresso!';
      setTimeout(() => { btn.disabled = false; btn.textContent = '🖨️ Imprimir'; }, 2500);
    } else {
      // Fallback to browser print if thermal printer not configured or offline
      if (res.error && res.error.includes('não está ativa')) {
        window.print();
        btn.disabled = false; btn.textContent = '🖨️ Imprimir';
      } else {
        alert('Impressora: ' + (res.error || 'Erro'));
        btn.disabled = false; btn.textContent = '🖨️ Imprimir';
      }
    }
  } catch {
    window.print(); // network error — fallback to browser
    btn.disabled = false; btn.textContent = '🖨️ Imprimir';
  }
});
document.getElementById('btn-novo').addEventListener('click', resetOrder);

document.getElementById('search').addEventListener('input', e => {
  state.searchTerm = e.target.value;
  if (state.searchTerm) renderProdutos();
  else loadProdutos(state.catId);
});

function tickClock() {
  document.getElementById('topbar-clock').textContent =
    new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
}
tickClock();
setInterval(tickClock, 30000);

// ── INIT ──────────────────────────────────────────────────────────────
(async () => {
  await loadConfig();
  await loadCategorias();
  await loadAllProdutos();
  if (state.catId) loadProdutos(state.catId);

  // Notificações em tempo real via SSE
  connectSSE();

  // Pagamentos pendentes — polling a cada 5s
  loadAwaitingPayments();
  setInterval(loadAwaitingPayments, 5000);
})();

// ── AGUARDANDO PAGAMENTO ─────────────────────────────────────────────
async function loadAwaitingPayments() {
  try {
    const res = await fetch(ADMIN_BASE + 'pedidos.php?status=aguardando_pagamento', {
      headers: { 'X-CSRF-Token': CSRF }
    }).then(r => r.json());
    const list   = res.data || [];
    const bar    = document.getElementById('awp-bar');
    const listEl = document.getElementById('awp-list');
    if (!bar || !listEl) return;

    bar.classList.toggle('has-items', list.length > 0);
    if (!list.length) { listEl.innerHTML = ''; return; }

    const pgIcon = { pix:'📱 PIX', credito:'💳 Crédito', debito:'💳 Débito' };
    listEl.innerHTML = list.map(p => {
      const since = new Date(p.criado_em).toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit' });
      const pgLbl = pgIcon[p.forma_pagamento] || p.forma_pagamento;
      return '<div class="awp-item">' +
        '<div class="awp-num">#' + p.numero_pedido + '</div>' +
        '<div class="awp-info">' +
          '<div class="awp-pgto">' + pgLbl + '</div>' +
          '<div class="awp-total">R$ ' + parseFloat(p.total||0).toFixed(2).replace('.',',') + '</div>' +
          '<div class="awp-since">' + since + '</div>' +
        '</div>' +
        '<button class="btn-awp-confirm" onclick="confirmPayment(' + p.id + ', this)">✓ Confirmar</button>' +
      '</div>';
    }).join('');
  } catch { /* ignore */ }
}

async function confirmPayment(pedidoId, btn) {
  btn.disabled = true;
  btn.textContent = 'Confirmando...';
  try {
    const res = await fetch(ADMIN_BASE + 'pedidos.php', {
      method:  'POST',
      headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF },
      body:    JSON.stringify({ id: pedidoId, status: 'aguardando' }),
    }).then(r => r.json());
    if (res.success) {
      loadAwaitingPayments();
      // Toast visual
      const bar = document.createElement('div');
      bar.className = 'notify-item';
      bar.innerHTML = '✅ <span>Pagamento confirmado! Pedido enviado para cozinha.</span>';
      document.getElementById('notify-bar').appendChild(bar);
      setTimeout(() => bar.remove(), 4000);
    } else {
      btn.disabled = false; btn.textContent = '✓ Confirmar';
      alert(res.error || 'Erro ao confirmar');
    }
  } catch {
    btn.disabled = false; btn.textContent = '✓ Confirmar';
  }
}
</script>
</body>
</html>

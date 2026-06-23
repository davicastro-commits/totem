<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="#ff5500">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Garçom — App</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#f8f9fa;font-family:'Inter',sans-serif;min-height:100vh}
.header{background:#ff5500;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.header h1{font-size:18px;font-weight:800}
.header .btn-refresh{background:rgba(255,255,255,.2);border:none;color:#fff;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px}
.content{padding:16px;max-width:600px;margin:0 auto}
.section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin:16px 0 10px}
.mesas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:10px}
.mesa-card{background:#fff;border-radius:14px;padding:14px 8px;text-align:center;cursor:pointer;transition:transform .1s,box-shadow .1s;box-shadow:0 2px 8px rgba(0,0,0,.08);border:2px solid transparent}
.mesa-card:active{transform:scale(.97)}
.mesa-card.livre{border-color:#22c55e}
.mesa-card.ocupada{border-color:#f97316;background:#fff7ed}
.mesa-card.reservada{border-color:#3b82f6;background:#eff6ff}
.mesa-card.bloqueada{border-color:#9ca3af;opacity:.5}
.mesa-num{font-size:22px;font-weight:900;color:#111}
.mesa-label{font-size:10px;color:#6b7280;margin-top:2px;text-transform:uppercase;letter-spacing:.5px}
.mesa-status-dot{width:8px;height:8px;border-radius:50%;margin:4px auto 0}
.livre .mesa-status-dot{background:#22c55e}
.ocupada .mesa-status-dot{background:#f97316}
.reservada .mesa-status-dot{background:#3b82f6}

/* Bottom Sheet Modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:none;align-items:flex-end}
.overlay.open{display:flex}
.sheet{background:#fff;border-radius:20px 20px 0 0;width:100%;max-height:90vh;overflow-y:auto;padding:0 0 32px}
.sheet-handle{width:40px;height:4px;background:#e5e7eb;border-radius:99px;margin:12px auto 0}
.sheet-header{padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center}
.sheet-title{font-size:17px;font-weight:800}
.btn-close{background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;padding:4px}
.sheet-body{padding:16px 20px}
.status-badge{display:inline-block;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700;text-transform:uppercase}
.badge-livre{background:#dcfce7;color:#166534}
.badge-ocupada{background:#ffedd5;color:#9a3412}
.badge-reservada{background:#dbeafe;color:#1e40af}

/* Comanda itens */
.comanda-item{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f3f4f6}
.comanda-item:last-child{border-bottom:none}
.ci-qty{background:#ff5500;color:#fff;font-weight:700;font-size:13px;min-width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center}
.ci-nome{flex:1;font-size:14px}
.ci-obs{font-size:11px;color:#9ca3af}
.ci-valor{font-size:14px;font-weight:700;color:#111}
.ci-status{font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600}
.ci-status.aguardando{background:#fef9c3;color:#854d0e}
.ci-status.preparando{background:#fed7aa;color:#9a3412}
.ci-status.pronto{background:#dcfce7;color:#166534}
.ci-status.entregue{background:#f3f4f6;color:#6b7280}

/* Totais */
.total-box{background:#f9fafb;border-radius:12px;padding:14px;margin:12px 0}
.total-row{display:flex;justify-content:space-between;font-size:14px;padding:3px 0}
.total-row.bold{font-weight:700;font-size:16px;border-top:1px solid #e5e7eb;margin-top:6px;padding-top:8px}

/* Botões */
.btn{border:none;border-radius:12px;padding:13px;font-weight:700;font-size:15px;cursor:pointer;width:100%;margin-top:8px;font-family:inherit;transition:opacity .15s}
.btn:active{opacity:.8}
.btn-primary{background:#ff5500;color:#fff}
.btn-secondary{background:#f3f4f6;color:#374151}
.btn-green{background:#22c55e;color:#fff}
.btn-danger{background:#ef4444;color:#fff}
.btn-sm{font-size:13px;padding:9px 14px;width:auto;border-radius:8px}

/* Busca produto */
.search-input{width:100%;padding:12px 14px;border:2px solid #e5e7eb;border-radius:12px;font-size:15px;font-family:inherit;outline:none}
.search-input:focus{border-color:#ff5500}
.search-results{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-top:6px;max-height:240px;overflow-y:auto;display:none}
.search-results.open{display:block}
.prod-result{padding:12px 14px;display:flex;justify-content:space-between;cursor:pointer;border-bottom:1px solid #f3f4f6}
.prod-result:last-child{border-bottom:none}
.prod-result:active{background:#f9fafb}
.prod-nome{font-size:14px;font-weight:600}
.prod-preco{font-size:14px;font-weight:700;color:#ff5500}

.qty-row{display:flex;align-items:center;gap:10px;margin:10px 0}
.qty-btn{background:#f3f4f6;border:none;font-size:20px;width:40px;height:40px;border-radius:10px;cursor:pointer;font-weight:700}
.qty-num{font-size:18px;font-weight:700;min-width:30px;text-align:center}

.empty-state{text-align:center;padding:32px;color:#9ca3af}
.empty-icon{font-size:48px;margin-bottom:8px}

.toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#111;color:#fff;padding:10px 20px;border-radius:999px;font-size:14px;font-weight:600;z-index:999;opacity:0;transition:opacity .3s;pointer-events:none}
.toast.show{opacity:1}

.loading{text-align:center;padding:32px;color:#9ca3af;font-size:14px}
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>☕ Garçom</h1>
    <div style="font-size:11px;opacity:.8" id="last-update">Carregando...</div>
  </div>
  <button class="btn-refresh" onclick="loadMesas()">↺ Atualizar</button>
</div>

<div class="content">
  <div class="section-title">Mesas</div>
  <div class="mesas-grid" id="mesas-grid">
    <div class="loading">Carregando mesas...</div>
  </div>
</div>

<!-- Modal Bottom Sheet -->
<div class="overlay" id="overlay" onclick="handleOverlayClick(event)">
  <div class="sheet" id="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <span class="sheet-title" id="sheet-title">Mesa</span>
      <button class="btn-close" onclick="closeSheet()">✕</button>
    </div>
    <div class="sheet-body" id="sheet-body"></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';
const BASE = '/totem/api/';
let mesas = [];
let currentMesa = null;
let currentComanda = null;
let selectedProduto = null;
let addQty = 1;

// ── Load mesas ──────────────────────────────────────────────────────
async function loadMesas() {
  try {
    const r = await fetch(BASE + 'mesas.php');
    const d = await r.json();
    if (!d.success) return;
    mesas = d.data;
    renderMesas();
    document.getElementById('last-update').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
  } catch(e) {
    toast('Erro ao carregar mesas');
  }
}

function renderMesas() {
  const grid = document.getElementById('mesas-grid');
  if (!mesas.length) { grid.innerHTML = '<div class="empty-state"><div class="empty-icon">🪑</div><p>Nenhuma mesa cadastrada</p></div>'; return; }
  grid.innerHTML = mesas.map(m => `
    <div class="mesa-card ${m.status}" onclick="openMesa(${m.id})">
      <div class="mesa-num">${m.numero}</div>
      <div class="mesa-label">${m.localizacao||'Salão'}</div>
      <div class="mesa-status-dot"></div>
      ${m.status==='ocupada' && m.comanda_total ? `<div style="font-size:11px;margin-top:4px;color:#9a3412;font-weight:700">R$ ${parseFloat(m.comanda_total).toFixed(2).replace('.',',')}</div>` : ''}
    </div>
  `).join('');
}

// ── Open mesa ───────────────────────────────────────────────────────
async function openMesa(mesaId) {
  currentMesa = mesas.find(m => m.id === mesaId);
  if (!currentMesa) return;

  document.getElementById('sheet-title').textContent = 'Mesa ' + currentMesa.numero;

  if (currentMesa.status === 'livre') {
    renderAbrirComanda();
  } else if (currentMesa.status === 'ocupada') {
    await loadComanda(mesaId);
  } else {
    document.getElementById('sheet-body').innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">🚫</div>
        <p>Mesa ${currentMesa.status === 'reservada' ? 'reservada' : 'bloqueada'}</p>
      </div>`;
  }
  document.getElementById('overlay').classList.add('open');
}

function renderAbrirComanda() {
  document.getElementById('sheet-body').innerHTML = `
    <div class="empty-state" style="padding:20px 0">
      <div class="empty-icon">🪑</div>
      <p style="font-size:16px;font-weight:600;color:#111;margin-bottom:4px">Mesa livre</p>
      <p style="font-size:13px">Capacidade: ${currentMesa.capacidade} pessoas</p>
    </div>
    <textarea id="obs-input" style="width:100%;padding:12px;border:2px solid #e5e7eb;border-radius:12px;font-family:inherit;font-size:14px;resize:none;height:70px;margin:8px 0" placeholder="Observação (opcional)..."></textarea>
    <button class="btn btn-primary" onclick="abrirComanda(${currentMesa.id})">Abrir Comanda</button>
  `;
}

async function abrirComanda(mesaId) {
  const obs = document.getElementById('obs-input')?.value || '';
  try {
    const r = await fetch(BASE + 'mesas.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'abrir_comanda', mesa_id:mesaId, observacao:obs})
    });
    const d = await r.json();
    if (d.success) {
      toast('Comanda aberta!');
      await loadMesas();
      await loadComanda(mesaId);
    } else { toast(d.error || 'Erro'); }
  } catch(e) { toast('Erro de rede'); }
}

async function loadComanda(mesaId) {
  try {
    const r = await fetch(BASE + `mesas.php?id=${mesaId}`);
    const d = await r.json();
    if (!d.success) { toast('Erro ao carregar comanda'); return; }
    currentComanda = d.data.comanda;
    renderComanda(d.data);
  } catch(e) { toast('Erro de rede'); }
}

function renderComanda(data) {
  const comanda = data.comanda;
  const itens   = data.itens || [];
  if (!comanda) { renderAbrirComanda(); return; }

  const itensHtml = itens.length
    ? itens.map(it => `
        <div class="comanda-item">
          <div class="ci-qty">${it.quantidade}</div>
          <div style="flex:1">
            <div class="ci-nome">${it.nome || it.produto_nome || ''}</div>
            ${it.obs ? `<div class="ci-obs">📝 ${it.obs}</div>` : ''}
          </div>
          <div style="text-align:right">
            <div class="ci-valor">R$ ${parseFloat(it.subtotal).toFixed(2).replace('.',',')}</div>
            <span class="ci-status ${it.status}">${it.status}</span>
          </div>
        </div>`).join('')
    : '<div class="empty-state" style="padding:20px 0"><div class="empty-icon">🍽️</div><p>Nenhum item ainda</p></div>';

  document.getElementById('sheet-body').innerHTML = `
    <div class="total-box">
      <div class="total-row"><span>Subtotal</span><span>R$ ${parseFloat(comanda.subtotal||0).toFixed(2).replace('.',',')}</span></div>
      <div class="total-row bold"><span>Total</span><span>R$ ${parseFloat(comanda.total||0).toFixed(2).replace('.',',')}</span></div>
    </div>

    <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin:12px 0 8px">Itens</div>
    ${itensHtml}

    <div style="margin-top:16px">
      <button class="btn btn-secondary" onclick="showAddItem(${comanda.id})">+ Adicionar Item</button>
      <button class="btn btn-green" onclick="enviarKds(${comanda.id})">📟 Enviar para Cozinha</button>
      <button class="btn btn-primary" onclick="showFecharConta(${comanda.id}, ${comanda.subtotal||0})">💳 Fechar Conta</button>
      <button class="btn btn-danger" style="margin-top:4px" onclick="confirmarCancelar(${comanda.id})">Cancelar Comanda</button>
    </div>
  `;
}

// ── Adicionar item ───────────────────────────────────────────────────
function showAddItem(comandaId) {
  selectedProduto = null;
  addQty = 1;
  document.getElementById('sheet-body').innerHTML = `
    <button onclick="loadComanda(${currentMesa.id})" style="background:none;border:none;color:#ff5500;font-weight:700;cursor:pointer;font-size:14px;margin-bottom:12px">← Voltar</button>
    <input class="search-input" id="search-prod" type="text" placeholder="Buscar produto..." oninput="searchProd(this.value)" autocomplete="off">
    <div class="search-results" id="search-results"></div>

    <div id="add-form" style="display:none;margin-top:12px">
      <div style="font-size:15px;font-weight:700" id="prod-selected-nome"></div>
      <div style="font-size:18px;font-weight:800;color:#ff5500;margin:4px 0 10px" id="prod-selected-preco"></div>
      <div class="qty-row">
        <button class="qty-btn" onclick="changeQty(-1)">−</button>
        <span class="qty-num" id="qty-display">1</span>
        <button class="qty-btn" onclick="changeQty(1)">+</button>
      </div>
      <input style="width:100%;padding:10px 12px;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;font-size:14px;margin:8px 0" placeholder="Observação (opcional)" id="add-obs" type="text" maxlength="100">
      <button class="btn btn-primary" onclick="adicionarItem(${comandaId})">Adicionar à Comanda</button>
    </div>
  `;
}

let searchTimer;
async function searchProd(q) {
  clearTimeout(searchTimer);
  if (q.length < 2) { document.getElementById('search-results').classList.remove('open'); return; }
  searchTimer = setTimeout(async () => {
    try {
      const r = await fetch(BASE + `produtos.php?q=${encodeURIComponent(q)}`);
      const d = await r.json();
      const prods = d.produtos || d.data || [];
      const res   = document.getElementById('search-results');
      if (!prods.length) { res.innerHTML='<div style="padding:12px;color:#9ca3af;text-align:center">Nenhum resultado</div>'; res.classList.add('open'); return; }
      res.innerHTML = prods.slice(0,8).map(p => `
        <div class="prod-result" onclick="selectProd(${p.id},'${p.nome.replace(/'/g,"\\'")}',${p.preco})">
          <span class="prod-nome">${p.nome}</span>
          <span class="prod-preco">R$ ${parseFloat(p.preco).toFixed(2).replace('.',',')}</span>
        </div>`).join('');
      res.classList.add('open');
    } catch(e) {}
  }, 300);
}

function selectProd(id, nome, preco) {
  selectedProduto = {id, nome, preco};
  addQty = 1;
  document.getElementById('search-results').classList.remove('open');
  document.getElementById('search-prod').value = nome;
  document.getElementById('prod-selected-nome').textContent = nome;
  document.getElementById('prod-selected-preco').textContent = 'R$ ' + parseFloat(preco).toFixed(2).replace('.',',');
  document.getElementById('qty-display').textContent = 1;
  document.getElementById('add-form').style.display = 'block';
}

function changeQty(delta) {
  addQty = Math.max(1, addQty + delta);
  document.getElementById('qty-display').textContent = addQty;
}

async function adicionarItem(comandaId) {
  if (!selectedProduto) { toast('Selecione um produto'); return; }
  const obs = document.getElementById('add-obs')?.value || '';
  try {
    const r = await fetch(BASE + 'mesas.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'adicionar_item', comanda_id:comandaId, produto_id:selectedProduto.id, quantidade:addQty, obs})
    });
    const d = await r.json();
    if (d.success) { toast('Item adicionado!'); await loadComanda(currentMesa.id); }
    else toast(d.error || 'Erro');
  } catch(e) { toast('Erro de rede'); }
}

// ── Enviar KDS ───────────────────────────────────────────────────────
async function enviarKds(comandaId) {
  try {
    const r = await fetch(BASE + 'mesas.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'enviar_kds', comanda_id:comandaId})
    });
    const d = await r.json();
    toast(d.success ? '✅ Enviado para a cozinha!' : (d.error||'Erro'));
    if (d.success) await loadComanda(currentMesa.id);
  } catch(e) { toast('Erro de rede'); }
}

// ── Fechar conta ─────────────────────────────────────────────────────
function showFecharConta(comandaId, subtotal) {
  const sub = parseFloat(subtotal);
  const taxa = sub * 0.1;
  document.getElementById('sheet-body').innerHTML = `
    <button onclick="loadComanda(${currentMesa.id})" style="background:none;border:none;color:#ff5500;font-weight:700;cursor:pointer;font-size:14px;margin-bottom:12px">← Voltar</button>
    <div class="total-box">
      <div class="total-row"><span>Subtotal</span><span>R$ ${sub.toFixed(2).replace('.',',')}</span></div>
      <div class="total-row" id="taxa-row" style="display:none"><span>Taxa de serviço (10%)</span><span>R$ ${taxa.toFixed(2).replace('.',',')}</span></div>
      <div class="total-row bold"><span>Total</span><span id="total-display">R$ ${sub.toFixed(2).replace('.',',')}</span></div>
    </div>
    <label style="display:flex;align-items:center;gap:10px;padding:12px 0;font-size:15px;cursor:pointer">
      <input type="checkbox" id="taxa-check" style="width:18px;height:18px" onchange="toggleTaxa(${sub}, ${taxa})">
      Incluir taxa de serviço (10%)
    </label>
    <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin:8px 0 10px">Forma de pagamento</div>
    <div id="pgto-opts" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      ${['pix','credito','debito','dinheiro'].map(m => `
        <button class="btn btn-secondary" id="pgto-${m}" onclick="selectPgto('${m}')" style="margin:0">${{pix:'PIX',credito:'Crédito',debito:'Débito',dinheiro:'Dinheiro'}[m]}</button>`).join('')}
    </div>
    <button class="btn btn-primary" style="margin-top:14px" onclick="fecharConta(${comandaId})">Confirmar Fechamento</button>
  `;
}

let pgtoSelecionado = null;
let aplicarTaxa = false;

function toggleTaxa(sub, taxa) {
  aplicarTaxa = document.getElementById('taxa-check').checked;
  const total = aplicarTaxa ? sub + taxa : sub;
  document.getElementById('taxa-row').style.display = aplicarTaxa ? '' : 'none';
  document.getElementById('total-display').textContent = 'R$ ' + total.toFixed(2).replace('.',',');
}

function selectPgto(m) {
  pgtoSelecionado = m;
  document.querySelectorAll('#pgto-opts button').forEach(b => b.style.background = '#f3f4f6');
  document.getElementById('pgto-' + m).style.background = '#ff5500';
  document.getElementById('pgto-' + m).style.color = '#fff';
}

async function fecharConta(comandaId) {
  if (!pgtoSelecionado) { toast('Selecione a forma de pagamento'); return; }
  try {
    const r = await fetch(BASE + 'mesas.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'fechar_conta', comanda_id:comandaId, aplicar_taxa_servico:aplicarTaxa, forma_pagamento:pgtoSelecionado})
    });
    const d = await r.json();
    if (d.success) { toast('✅ Conta fechada!'); closeSheet(); await loadMesas(); }
    else toast(d.error || 'Erro');
  } catch(e) { toast('Erro de rede'); }
}

async function confirmarCancelar(comandaId) {
  if (!confirm('Cancelar esta comanda? Os itens serão removidos.')) return;
  try {
    const r = await fetch(BASE + 'mesas.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'cancelar', comanda_id:comandaId, motivo:'Cancelado pelo garçom'})
    });
    const d = await r.json();
    if (d.success) { toast('Comanda cancelada'); closeSheet(); await loadMesas(); }
    else toast(d.error || 'Erro');
  } catch(e) { toast('Erro de rede'); }
}

// ── Helpers ──────────────────────────────────────────────────────────
function closeSheet() {
  document.getElementById('overlay').classList.remove('open');
  currentMesa = null; currentComanda = null; selectedProduto = null; pgtoSelecionado = null;
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('overlay')) closeSheet();
}

let toastTimer;
function toast(msg) {
  clearTimeout(toastTimer);
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
}

// ── Init ─────────────────────────────────────────────────────────────
loadMesas();
setInterval(loadMesas, 15000);
</script>
</body>
</html>

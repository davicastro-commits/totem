<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#ff5500">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Comanda Digital — Garçom</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;--acc:#ff5500;
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;
  --border:#2a2d3a;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;overflow-x:hidden}

/* ── Topbar ── */
.topbar{
  background:var(--surf);border-bottom:1px solid var(--border);
  padding:14px 16px;display:flex;align-items:center;gap:12px;
  position:sticky;top:0;z-index:100;
}
.topbar-back{
  background:none;border:none;color:var(--acc);font-size:22px;
  cursor:pointer;padding:4px 8px;border-radius:8px;
  min-width:44px;min-height:44px;display:flex;align-items:center;justify-content:center;
}
.topbar h1{font-size:17px;font-weight:800;flex:1}
.topbar-sub{font-size:12px;color:var(--text2)}
.btn-refresh{
  background:rgba(255,85,0,.15);border:none;color:var(--acc);
  padding:8px 14px;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;
  min-height:44px;
}

/* ── Pedidos list ── */
.content{padding:12px;max-width:640px;margin:0 auto}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;
  color:var(--text3);margin:8px 0 10px}

.pedido-card{
  background:var(--card);border-radius:14px;padding:14px 16px;
  margin-bottom:10px;cursor:pointer;border:2px solid transparent;
  transition:border-color .15s,transform .1s;
  display:flex;align-items:center;gap:12px;
}
.pedido-card:active{transform:scale(.98)}
.pedido-card.selected{border-color:var(--acc)}
.pc-num{font-size:22px;font-weight:900;color:var(--acc);min-width:52px}
.pc-info{flex:1}
.pc-title{font-size:15px;font-weight:700}
.pc-meta{font-size:12px;color:var(--text2);margin-top:2px}
.pc-total{font-size:16px;font-weight:800;text-align:right}
.pc-total small{display:block;font-size:11px;font-weight:400;color:var(--text2)}

.badge{
  display:inline-flex;align-items:center;padding:3px 9px;
  border-radius:99px;font-size:11px;font-weight:700;text-transform:uppercase;
}
.badge-aguardando{background:rgba(59,130,246,.2);color:var(--blue)}
.badge-preparando{background:rgba(245,158,11,.2);color:var(--gold)}
.badge-pronto{background:rgba(34,197,94,.2);color:var(--green)}

.empty-state{text-align:center;padding:48px 24px;color:var(--text3)}
.empty-icon{font-size:52px;margin-bottom:12px}
.empty-state p{font-size:15px}

/* ── Panel (slide-in sidebar / bottom drawer) ── */
.panel-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;
  display:none;
}
.panel-overlay.open{display:block}

.panel{
  position:fixed;bottom:0;left:0;right:0;
  background:var(--surf);border-radius:20px 20px 0 0;
  max-height:92vh;display:flex;flex-direction:column;
  z-index:201;transform:translateY(100%);
  transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.panel.open{transform:translateY(0)}

@media(min-width:600px){
  .panel{
    top:0;bottom:0;right:0;left:auto;width:420px;border-radius:0;
    max-height:100vh;transform:translateX(100%);
  }
  .panel.open{transform:translateX(0)}
}

.panel-handle{width:44px;height:4px;background:var(--border);border-radius:99px;margin:12px auto 0}
@media(min-width:600px){.panel-handle{display:none}}

.panel-header{
  padding:14px 16px 12px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;flex-shrink:0;
}
.panel-title{font-size:16px;font-weight:800;flex:1}
.panel-total{font-size:18px;font-weight:900;color:var(--acc)}
.btn-close-panel{
  background:none;border:none;color:var(--text2);font-size:20px;
  cursor:pointer;min-width:44px;min-height:44px;
  display:flex;align-items:center;justify-content:center;border-radius:10px;
}
.btn-close-panel:hover{background:var(--card)}

.panel-body{flex:1;overflow-y:auto;display:flex;flex-direction:column}

/* ── Itens atuais ── */
.itens-section{padding:12px 16px;border-bottom:1px solid var(--border)}
.itens-section-title{font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:1px;color:var(--text3);margin-bottom:8px}

.item-row{
  display:flex;align-items:center;gap:10px;padding:9px 0;
  border-bottom:1px solid var(--border);
}
.item-row:last-child{border-bottom:none}
.item-qty{
  background:var(--acc);color:#fff;font-weight:700;font-size:13px;
  min-width:28px;height:28px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.item-nome{flex:1;font-size:14px;font-weight:600}
.item-sub{font-size:14px;font-weight:700;color:var(--text)}
.btn-del{
  background:rgba(239,68,68,.15);border:none;color:var(--red);
  width:36px;height:36px;border-radius:10px;cursor:pointer;font-size:16px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  min-width:44px;min-height:44px;
}
.btn-del:active{background:rgba(239,68,68,.3)}

/* ── Categorias tabs ── */
.cats-tabs{
  display:flex;gap:8px;overflow-x:auto;
  padding:12px 16px 10px;border-bottom:1px solid var(--border);
  flex-shrink:0;scrollbar-width:none;
}
.cats-tabs::-webkit-scrollbar{display:none}
.cat-tab{
  background:var(--card);border:2px solid var(--border);
  color:var(--text2);padding:8px 14px;border-radius:10px;
  font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;
  min-height:44px;display:flex;align-items:center;gap:6px;
  transition:border-color .15s,color .15s;
}
.cat-tab.active{border-color:var(--acc);color:var(--acc)}

/* ── Produtos grid ── */
.produtos-list{padding:10px 16px 80px;flex:1}
.prod-card{
  background:var(--card);border-radius:12px;padding:12px 14px;
  margin-bottom:8px;display:flex;align-items:center;gap:12px;
  border:1px solid var(--border);
}
.prod-info{flex:1}
.prod-nome{font-size:14px;font-weight:700}
.prod-preco{font-size:16px;font-weight:900;color:var(--acc);margin-top:2px}
.btn-add-prod{
  background:var(--acc);border:none;color:#fff;
  width:44px;height:44px;border-radius:12px;
  font-size:22px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;font-weight:700;
  transition:opacity .15s;
}
.btn-add-prod:active{opacity:.7}

/* ── Qty picker modal ── */
.qty-modal{
  position:fixed;inset:0;z-index:300;
  display:none;align-items:flex-end;justify-content:center;
  background:rgba(0,0,0,.6);padding:0;
}
.qty-modal.open{display:flex}
.qty-box{
  background:var(--surf);border-radius:20px 20px 0 0;
  padding:24px 24px 32px;width:100%;max-width:420px;
}
.qty-prod-nome{font-size:17px;font-weight:800;margin-bottom:4px}
.qty-prod-preco{font-size:24px;font-weight:900;color:var(--acc);margin-bottom:20px}
.qty-controls{display:flex;align-items:center;justify-content:center;gap:20px;margin-bottom:20px}
.qty-ctrl-btn{
  background:var(--card);border:2px solid var(--border);color:var(--text);
  width:56px;height:56px;border-radius:14px;font-size:28px;font-weight:700;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
}
.qty-ctrl-btn:active{background:var(--border)}
.qty-num{font-size:36px;font-weight:900;min-width:60px;text-align:center}
.btn-confirm{
  background:var(--acc);border:none;color:#fff;
  width:100%;padding:16px;border-radius:14px;font-size:17px;font-weight:800;
  cursor:pointer;font-family:inherit;
}
.btn-confirm:active{opacity:.8}
.btn-cancel-qty{
  background:none;border:none;color:var(--text2);
  width:100%;padding:10px;font-size:14px;font-weight:600;
  cursor:pointer;font-family:inherit;margin-top:6px;
}

/* ── Toast ── */
.toast{
  position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
  background:rgba(26,28,39,.95);color:var(--text);
  padding:12px 22px;border-radius:99px;font-size:14px;font-weight:600;
  z-index:999;opacity:0;transition:opacity .25s;pointer-events:none;
  border:1px solid var(--border);white-space:nowrap;
}
.toast.show{opacity:1}
.toast.success{border-color:var(--green);color:var(--green)}
.toast.error{border-color:var(--red);color:var(--red)}

.loading{text-align:center;padding:32px;color:var(--text3);font-size:14px}
.spinner{display:inline-block;width:20px;height:20px;border:2px solid var(--border);
  border-top-color:var(--acc);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <button class="topbar-back" onclick="history.back()" title="Voltar">&#8592;</button>
  <div style="flex:1">
    <h1>Comanda Digital</h1>
    <div class="topbar-sub" id="last-update">Carregando...</div>
  </div>
  <button class="btn-refresh" onclick="loadPedidos()">&#8635; Atualizar</button>
</div>

<!-- Lista de pedidos -->
<div class="content">
  <div class="section-label">Pedidos ativos hoje</div>
  <div id="pedidos-list"><div class="loading"><span class="spinner"></span>Carregando pedidos...</div></div>
</div>

<!-- Overlay do painel -->
<div class="panel-overlay" id="panel-overlay" onclick="closePanel()"></div>

<!-- Painel lateral / bottom sheet -->
<div class="panel" id="panel">
  <div class="panel-handle"></div>
  <div class="panel-header">
    <div style="flex:1">
      <div class="panel-title" id="panel-title">Pedido</div>
      <div style="font-size:12px;color:var(--text2)" id="panel-subtitle"></div>
    </div>
    <div class="panel-total" id="panel-total">R$ 0,00</div>
    <button class="btn-close-panel" onclick="closePanel()">&#10005;</button>
  </div>
  <div class="panel-body" id="panel-body">
    <div class="loading">Carregando...</div>
  </div>
</div>

<!-- Modal de quantidade -->
<div class="qty-modal" id="qty-modal">
  <div class="qty-box">
    <div class="qty-prod-nome" id="qm-nome"></div>
    <div class="qty-prod-preco" id="qm-preco"></div>
    <div class="qty-controls">
      <button class="qty-ctrl-btn" onclick="changeQty(-1)">&#8722;</button>
      <div class="qty-num" id="qm-qty">1</div>
      <button class="qty-ctrl-btn" onclick="changeQty(1)">+</button>
    </div>
    <button class="btn-confirm" id="qm-confirm">Adicionar ao pedido</button>
    <button class="btn-cancel-qty" onclick="closeQtyModal()">Cancelar</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const API = '/totem/garcom/api/comanda.php';

let pedidos       = [];
let categorias    = [];
let currentPedido = null;
let selectedProd  = null;
let addQty        = 1;
let activeCat     = null;

// ── Load pedidos ────────────────────────────────────────────────────────────
async function loadPedidos() {
  try {
    const r = await fetch(API + '?action=pedidos_ativos');
    const d = await r.json();
    if (!d.success) { toast('Erro ao carregar pedidos', 'error'); return; }
    pedidos = d.data || [];
    renderPedidos();
    document.getElementById('last-update').textContent =
      'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    // Se painel aberto, atualiza silenciosamente
    if (currentPedido) {
      const updated = pedidos.find(p => p.id === currentPedido.id);
      if (updated) { currentPedido = updated; updatePanelHeader(); renderItensAtuais(); }
    }
  } catch (e) {
    toast('Erro de rede', 'error');
  }
}

function renderPedidos() {
  const el = document.getElementById('pedidos-list');
  if (!pedidos.length) {
    el.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">&#x2615;</div>
        <p>Nenhum pedido ativo no momento</p>
      </div>`;
    return;
  }
  el.innerHTML = pedidos.map(p => {
    const badgeClass = { aguardando:'badge-aguardando', preparando:'badge-preparando', pronto:'badge-pronto' }[p.status] || 'badge-aguardando';
    const badgeLabel = { aguardando:'Aguardando', preparando:'Preparando', pronto:'Pronto' }[p.status] || p.status;
    const mesa = p.mesa_numero ? 'Mesa ' + p.mesa_numero : p.tipo_consumo === 'viagem' ? 'Viagem' : 'Local';
    const hora = new Date(p.criado_em).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    return `
      <div class="pedido-card${currentPedido && currentPedido.id === p.id ? ' selected' : ''}" onclick="openPedido(${p.id})">
        <div class="pc-num">#${p.numero_pedido}</div>
        <div class="pc-info">
          <div class="pc-title">${mesa}</div>
          <div class="pc-meta">${p.total_itens} ${p.total_itens === 1 ? 'item' : 'itens'} &bull; ${hora}
            &nbsp;<span class="badge ${badgeClass}">${badgeLabel}</span>
          </div>
        </div>
        <div class="pc-total">
          R$ ${fmtMoney(p.total)}
        </div>
      </div>`;
  }).join('');
}

// ── Open pedido ─────────────────────────────────────────────────────────────
async function openPedido(id) {
  const p = pedidos.find(x => x.id === id);
  if (!p) return;
  currentPedido = p;

  // Load categorias se ainda não carregou
  if (!categorias.length) {
    try {
      const r = await fetch(API + '?action=produtos');
      const d = await r.json();
      if (d.success) categorias = d.data || [];
    } catch (e) { toast('Erro ao carregar produtos', 'error'); }
  }

  activeCat = categorias.length ? categorias[0].id : null;

  updatePanelHeader();
  renderPanelBody();
  openPanel();
  renderPedidos(); // destacar selecionado
}

function updatePanelHeader() {
  if (!currentPedido) return;
  const mesa = currentPedido.mesa_numero ? 'Mesa ' + currentPedido.mesa_numero : currentPedido.tipo_consumo === 'viagem' ? 'Viagem' : 'Local';
  document.getElementById('panel-title').textContent = '#' + currentPedido.numero_pedido + ' — ' + mesa;
  const badgeLabel = { aguardando:'Aguardando', preparando:'Preparando', pronto:'Pronto' }[currentPedido.status] || currentPedido.status;
  document.getElementById('panel-subtitle').textContent = badgeLabel;
  document.getElementById('panel-total').textContent = 'R$ ' + fmtMoney(currentPedido.total);
}

function renderPanelBody() {
  const body = document.getElementById('panel-body');
  body.innerHTML = '';

  // Seção itens atuais
  const itensSection = document.createElement('div');
  itensSection.className = 'itens-section';
  itensSection.id = 'itens-section';
  body.appendChild(itensSection);
  renderItensAtuais();

  // Tabs de categorias
  const tabs = document.createElement('div');
  tabs.className = 'cats-tabs';
  tabs.id = 'cats-tabs';
  body.appendChild(tabs);
  renderCatTabs();

  // Lista de produtos
  const prods = document.createElement('div');
  prods.className = 'produtos-list';
  prods.id = 'produtos-list';
  body.appendChild(prods);
  renderProdutosList();
}

function renderItensAtuais() {
  const sec = document.getElementById('itens-section');
  if (!sec || !currentPedido) return;
  const itens = currentPedido.itens || [];

  if (!itens.length) {
    sec.innerHTML = `<div class="itens-section-title">Itens do pedido</div>
      <div style="color:var(--text3);font-size:13px;padding:8px 0">Nenhum item ainda</div>`;
    return;
  }

  sec.innerHTML = `<div class="itens-section-title">Itens do pedido (${itens.length})</div>` +
    itens.map(i => `
      <div class="item-row" id="item-${i.id}">
        <div class="item-qty">${i.quantidade}</div>
        <div class="item-nome">${escHtml(i.nome_produto)}</div>
        <div class="item-sub">R$ ${fmtMoney(i.subtotal)}</div>
        <button class="btn-del" onclick="confirmarRemover(${i.id}, '${escHtml(i.nome_produto).replace(/'/g, "\\'")}')">&#128465;</button>
      </div>`).join('');
}

function renderCatTabs() {
  const tabs = document.getElementById('cats-tabs');
  if (!tabs) return;
  tabs.innerHTML = categorias.map(c => `
    <button class="cat-tab${activeCat === c.id ? ' active' : ''}" onclick="selectCat(${c.id})">
      ${c.icone || ''} ${escHtml(c.nome)}
    </button>`).join('');
}

function selectCat(id) {
  activeCat = id;
  renderCatTabs();
  renderProdutosList();
}

function renderProdutosList() {
  const el = document.getElementById('produtos-list');
  if (!el) return;
  const cat = categorias.find(c => c.id === activeCat);
  if (!cat || !cat.produtos.length) {
    el.innerHTML = '<div style="color:var(--text3);text-align:center;padding:24px;font-size:14px">Nenhum produto nesta categoria</div>';
    return;
  }
  el.innerHTML = cat.produtos.map(p => `
    <div class="prod-card">
      <div class="prod-info">
        <div class="prod-nome">${escHtml(p.nome)}</div>
        <div class="prod-preco">R$ ${fmtMoney(p.preco)}</div>
      </div>
      <button class="btn-add-prod" onclick="openQtyModal(${p.id}, '${escHtml(p.nome).replace(/'/g, "\\'")}', ${p.preco})">+</button>
    </div>`).join('');
}

// ── Panel open/close ────────────────────────────────────────────────────────
function openPanel() {
  document.getElementById('panel-overlay').classList.add('open');
  document.getElementById('panel').classList.add('open');
}

function closePanel() {
  document.getElementById('panel-overlay').classList.remove('open');
  document.getElementById('panel').classList.remove('open');
  currentPedido = null;
  renderPedidos();
}

// ── Qty modal ───────────────────────────────────────────────────────────────
function openQtyModal(prodId, prodNome, preco) {
  selectedProd = { id: prodId, nome: prodNome, preco: preco };
  addQty = 1;
  document.getElementById('qm-nome').textContent = prodNome;
  document.getElementById('qm-preco').textContent = 'R$ ' + fmtMoney(preco);
  document.getElementById('qm-qty').textContent = 1;
  document.getElementById('qm-confirm').textContent = 'Adicionar — R$ ' + fmtMoney(preco);
  document.getElementById('qty-modal').classList.add('open');
}

function closeQtyModal() {
  document.getElementById('qty-modal').classList.remove('open');
  selectedProd = null;
}

function changeQty(delta) {
  addQty = Math.max(1, addQty + delta);
  document.getElementById('qm-qty').textContent = addQty;
  if (selectedProd) {
    const total = selectedProd.preco * addQty;
    document.getElementById('qm-confirm').textContent = 'Adicionar — R$ ' + fmtMoney(total);
  }
}

document.getElementById('qm-confirm').addEventListener('click', adicionarItem);

// ── API calls ───────────────────────────────────────────────────────────────
async function adicionarItem() {
  if (!selectedProd || !currentPedido) return;
  const btn = document.getElementById('qm-confirm');
  btn.disabled = true;
  btn.textContent = 'Adicionando...';

  try {
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'adicionar_item',
        pedido_id: currentPedido.id,
        produto_id: selectedProd.id,
        quantidade: addQty,
      }),
    });
    const d = await r.json();
    closeQtyModal();
    if (d.success) {
      // Atualiza pedido local
      const pd = d.data;
      const idx = pedidos.findIndex(p => p.id === currentPedido.id);
      if (idx !== -1) {
        pedidos[idx].total      = pd.pedido.total;
        pedidos[idx].itens      = pd.itens;
        pedidos[idx].total_itens = pd.itens.length;
        currentPedido           = pedidos[idx];
      }
      updatePanelHeader();
      renderItensAtuais();
      renderPedidos();
      toast('Item adicionado!', 'success');
    } else {
      toast(d.error || 'Erro ao adicionar', 'error');
    }
  } catch (e) {
    closeQtyModal();
    toast('Erro de rede', 'error');
  }
}

async function confirmarRemover(itemId, itemNome) {
  if (!confirm('Remover "' + itemNome + '" do pedido?')) return;
  await removerItem(itemId);
}

async function removerItem(itemId) {
  if (!currentPedido) return;
  try {
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'remover_item',
        item_id: itemId,
        pedido_id: currentPedido.id,
      }),
    });
    const d = await r.json();
    if (d.success) {
      const pd  = d.data;
      const idx = pedidos.findIndex(p => p.id === currentPedido.id);
      if (idx !== -1) {
        pedidos[idx].total       = pd.pedido.total;
        pedidos[idx].itens       = pd.itens;
        pedidos[idx].total_itens = pd.itens.length;
        currentPedido            = pedidos[idx];
      }
      updatePanelHeader();
      renderItensAtuais();
      renderPedidos();
      toast('Item removido', 'success');
    } else {
      toast(d.error || 'Erro ao remover', 'error');
    }
  } catch (e) {
    toast('Erro de rede', 'error');
  }
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function fmtMoney(v) {
  return parseFloat(v).toFixed(2).replace('.', ',');
}

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

let toastTimer;
function toast(msg, type = '') {
  clearTimeout(toastTimer);
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast show' + (type ? ' ' + type : '');
  toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

// ── Init ─────────────────────────────────────────────────────────────────────
loadPedidos();
setInterval(loadPedidos, 15000);
</script>
</body>
</html>

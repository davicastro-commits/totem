<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once '../../config/db.php';
require_once '../../config/csrf.php';
$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<title>Delivery — Admin</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#0f1117;color:#e2e8f0;font-family:'Inter',system-ui,sans-serif;min-height:100vh}
a{color:#ff5500;text-decoration:none}
.topbar{background:#161924;border-bottom:1px solid #1e2535;padding:14px 24px;display:flex;align-items:center;gap:16px}
.topbar-back{font-size:13px;color:#94a3b8}
.topbar h1{font-size:18px;font-weight:700;color:#fff}
.content{padding:24px;max-width:1200px;margin:0 auto}
.tabs{display:flex;gap:4px;margin-bottom:24px;background:#161924;border-radius:12px;padding:4px}
.tab{padding:10px 20px;border:none;background:transparent;color:#94a3b8;cursor:pointer;border-radius:8px;font-weight:600;font-size:14px;font-family:inherit;transition:all .15s}
.tab.active{background:#ff5500;color:#fff}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* Cards de entrega */
.pipeline{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.status-col{}
.status-col-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin-bottom:10px;padding:8px 12px;background:#161924;border-radius:8px}
.entrega-card{background:#161924;border-radius:12px;padding:16px;margin-bottom:12px;border:1px solid #1e2535}
.ec-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.ec-numero{font-size:18px;font-weight:800;color:#fff}
.ec-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase}
.badge-recebido{background:#1e3a5f;color:#60a5fa}
.badge-preparo{background:#3d1f00;color:#fb923c}
.badge-saiu{background:#1c3a2e;color:#4ade80}
.badge-entregue{background:#1a1a2e;color:#a78bfa}
.badge-cancelado{background:#3d1f1f;color:#f87171}
.ec-endereco{font-size:13px;color:#94a3b8;margin-bottom:8px}
.ec-info{font-size:12px;color:#6b7280;margin-bottom:10px}
.ec-bar{height:4px;background:#1e2535;border-radius:4px;margin-bottom:12px;overflow:hidden}
.ec-bar-fill{height:100%;background:#ff5500;border-radius:4px;transition:width .4s}
.btn{border:none;border-radius:8px;padding:8px 14px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-primary{background:#ff5500;color:#fff}
.btn-secondary{background:#1e2535;color:#e2e8f0}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-group{display:flex;gap:6px;flex-wrap:wrap}

/* Tabela bairros */
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;border-bottom:1px solid #1e2535}
td{padding:10px 12px;font-size:14px;border-bottom:1px solid #161924}
tr:hover td{background:#161924}
.badge-ativo{color:#4ade80;font-weight:700;font-size:12px}
.badge-inativo{color:#f87171;font-weight:700;font-size:12px}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#161924;border-radius:16px;padding:24px;min-width:360px;max-width:520px;width:90%;max-height:90vh;overflow-y:auto}
.modal h2{font-size:16px;font-weight:700;margin-bottom:16px}
.field{margin-bottom:14px}
.field label{display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.field input,.field select,.field textarea{width:100%;padding:10px 12px;background:#0f1117;border:1px solid #1e2535;border-radius:8px;color:#e2e8f0;font-size:14px;font-family:inherit}
.field input:focus,.field select:focus{outline:none;border-color:#ff5500}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}

/* Histórico */
.hist-row{display:grid;grid-template-columns:80px 1fr auto auto;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid #1e2535;font-size:14px}
.hist-row:last-child{border:none}
.hist-num{font-weight:700;color:#ff5500}
.hist-end{font-size:12px;color:#94a3b8}

.empty{text-align:center;padding:48px;color:#4b5563}
.empty-icon{font-size:48px;margin-bottom:8px}
.loading{text-align:center;padding:32px;color:#6b7280}

/* Alert banner */
.alert{background:#3d1f00;border:1px solid #7c3a00;border-radius:8px;padding:10px 14px;font-size:13px;color:#fb923c;margin-bottom:16px}
</style>
</head>
<body>

<div class="topbar">
  <a class="topbar-back" href="../">← Painel Admin</a>
  <h1>🛵 Gestão de Delivery</h1>
</div>

<div class="content">
  <div class="tabs">
    <button class="tab active" onclick="switchTab('ativas')">Entregas Ativas</button>
    <button class="tab" onclick="switchTab('bairros')">Bairros e Taxas</button>
    <button class="tab" onclick="switchTab('historico')">Histórico</button>
  </div>

  <!-- Entregas Ativas -->
  <div class="tab-panel active" id="tab-ativas">
    <div id="pipeline" class="loading">Carregando...</div>
  </div>

  <!-- Bairros -->
  <div class="tab-panel" id="tab-bairros">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
      <button class="btn btn-primary" onclick="showModalBairro()">+ Novo Bairro</button>
    </div>
    <div style="background:#161924;border-radius:12px;overflow:hidden">
      <table>
        <thead><tr>
          <th>Bairro</th><th>Cidade</th><th>Taxa</th><th>Prazo</th><th>Status</th><th></th>
        </tr></thead>
        <tbody id="bairros-tbody"><tr><td colspan="6" class="loading">Carregando...</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Histórico -->
  <div class="tab-panel" id="tab-historico">
    <div style="display:flex;gap:10px;margin-bottom:16px">
      <input id="hist-data" type="date" class="field input" style="background:#161924;border:1px solid #1e2535;border-radius:8px;color:#e2e8f0;padding:8px 12px;font-size:14px" value="<?= date('Y-m-d') ?>">
      <select id="hist-status" style="background:#161924;border:1px solid #1e2535;border-radius:8px;color:#e2e8f0;padding:8px 12px;font-size:14px">
        <option value="">Todos os status</option>
        <option value="recebido">Recebido</option><option value="preparo">Em preparo</option>
        <option value="saiu">Saiu para entrega</option><option value="entregue">Entregue</option><option value="cancelado">Cancelado</option>
      </select>
      <button class="btn btn-secondary" onclick="loadHistorico()">Filtrar</button>
    </div>
    <div id="historico-list" class="loading">Carregando...</div>
  </div>
</div>

<!-- Modal avançar status -->
<div class="modal-overlay" id="modal-status">
  <div class="modal">
    <h2>Avançar Entrega</h2>
    <input type="hidden" id="ms-entrega-id">
    <div class="field">
      <label>Novo Status</label>
      <select id="ms-status">
        <option value="preparo">Em Preparo</option>
        <option value="saiu">Saiu para Entrega</option>
        <option value="entregue">Entregue</option>
        <option value="cancelado">Cancelado</option>
      </select>
    </div>
    <div class="field">
      <label>Entregador (nome)</label>
      <input type="text" id="ms-entregador" placeholder="Nome do entregador">
    </div>
    <div class="field">
      <label>Telefone do Entregador</label>
      <input type="text" id="ms-telefone" placeholder="(61) 9xxxx-xxxx">
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modal-status')">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarStatus()">Salvar</button>
    </div>
  </div>
</div>

<!-- Modal bairro -->
<div class="modal-overlay" id="modal-bairro">
  <div class="modal">
    <h2 id="modal-bairro-title">Novo Bairro</h2>
    <input type="hidden" id="mb-id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="field" style="grid-column:1/-1">
        <label>Bairro</label><input type="text" id="mb-bairro" placeholder="Nome do bairro">
      </div>
      <div class="field">
        <label>Cidade</label><input type="text" id="mb-cidade" value="Brasília">
      </div>
      <div class="field">
        <label>UF</label><input type="text" id="mb-uf" value="DF" maxlength="2">
      </div>
      <div class="field">
        <label>Taxa (R$)</label><input type="number" id="mb-taxa" step="0.50" min="0" placeholder="0,00">
      </div>
      <div class="field">
        <label>Prazo (min)</label><input type="number" id="mb-prazo" min="10" value="45">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modal-bairro')">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarBairro()">Salvar</button>
    </div>
  </div>
</div>

<script>
'use strict';
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API  = '/totem/api/delivery.php';

function switchTab(name) {
  document.querySelectorAll('.tab').forEach((t,i) => {
    const names = ['ativas','bairros','historico'];
    t.classList.toggle('active', names[i] === name);
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  if (name === 'ativas') loadAtivas();
  if (name === 'bairros') loadBairros();
  if (name === 'historico') loadHistorico();
}

const STEPS = ['recebido','preparo','saiu','entregue'];
const STEP_LABELS = {recebido:'Recebido',preparo:'Em Preparo',saiu:'Saiu',entregue:'Entregue',cancelado:'Cancelado'};
const STEP_PCT = {recebido:10, preparo:40, saiu:75, entregue:100, cancelado:0};

function badgeClass(s) {
  return 'ec-badge badge-'+s;
}

async function loadAtivas() {
  document.getElementById('pipeline').innerHTML = '<div class="loading">Carregando...</div>';
  try {
    const r = await fetch(API + '?action=ativas');
    const d = await r.json();
    const entregas = d.data || [];
    if (!entregas.length) {
      document.getElementById('pipeline').innerHTML = '<div class="empty"><div class="empty-icon">🛵</div><p>Nenhuma entrega ativa</p></div>';
      return;
    }
    const byStatus = {recebido:[],preparo:[],saiu:[]};
    entregas.forEach(e => { if (byStatus[e.status]) byStatus[e.status].push(e); });

    const cols = Object.entries(byStatus).map(([st, list]) => `
      <div class="status-col">
        <div class="status-col-title">${STEP_LABELS[st]} (${list.length})</div>
        ${list.map(renderCard).join('') || '<div style="color:#4b5563;font-size:13px;padding:12px">Nenhuma</div>'}
      </div>`).join('');

    document.getElementById('pipeline').innerHTML = `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">${cols}</div>`;
  } catch(e) {
    document.getElementById('pipeline').innerHTML = '<div class="alert">Erro ao carregar entregas</div>';
  }
}

function renderCard(e) {
  const pct = STEP_PCT[e.status] || 0;
  const ende = e.logradouro ? `${e.logradouro}, ${e.numero} — ${e.bairro}` : (e.bairro || 'Endereço não informado');
  return `
  <div class="entrega-card">
    <div class="ec-header">
      <span class="ec-numero">Pedido #${e.pedido_numero || e.pedido_id}</span>
      <span class="${badgeClass(e.status)}">${STEP_LABELS[e.status]}</span>
    </div>
    <div class="ec-endereco">📍 ${ende}</div>
    ${e.entregador_nome ? `<div class="ec-info">🏍️ ${e.entregador_nome} ${e.entregador_telefone ? '· '+e.entregador_telefone : ''}</div>` : ''}
    <div class="ec-info">⏱️ Previsão: ${e.previsao_min || 45} min · R$ ${parseFloat(e.taxa_entrega||0).toFixed(2).replace('.',',')}</div>
    <div class="ec-bar"><div class="ec-bar-fill" style="width:${pct}%"></div></div>
    <div class="btn-group">
      <button class="btn btn-primary btn-sm" onclick="showModalStatus(${e.id}, '${e.status}', '${e.entregador_nome||''}', '${e.entregador_telefone||''}')">Avançar</button>
    </div>
  </div>`;
}

function showModalStatus(id, currentStatus, entregador, telefone) {
  document.getElementById('ms-entrega-id').value = id;
  const nextIdx = Math.min(STEPS.indexOf(currentStatus) + 1, STEPS.length - 1);
  document.getElementById('ms-status').value = STEPS[nextIdx];
  document.getElementById('ms-entregador').value = entregador;
  document.getElementById('ms-telefone').value = telefone;
  document.getElementById('modal-status').classList.add('open');
}

async function salvarStatus() {
  const id     = document.getElementById('ms-entrega-id').value;
  const status = document.getElementById('ms-status').value;
  const nome   = document.getElementById('ms-entregador').value;
  const tel    = document.getElementById('ms-telefone').value;
  try {
    const r = await fetch(API, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({action:'atualizar_status', entrega_id:id, status, entregador_nome:nome, entregador_telefone:tel})
    });
    const d = await r.json();
    if (d.success) { closeModal('modal-status'); loadAtivas(); }
    else alert(d.error || 'Erro');
  } catch(e) { alert('Erro de rede'); }
}

async function loadBairros() {
  try {
    const r = await fetch(API + '?action=bairros');
    const d = await r.json();
    const bairros = d.data || [];
    document.getElementById('bairros-tbody').innerHTML = bairros.map(b => `
      <tr>
        <td><strong>${b.bairro}</strong></td>
        <td>${b.cidade}</td>
        <td>R$ ${parseFloat(b.taxa).toFixed(2).replace('.',',')}</td>
        <td>${b.prazo_min} min</td>
        <td><span class="${b.ativo ? 'badge-ativo' : 'badge-inativo'}">${b.ativo ? 'Ativo' : 'Inativo'}</span></td>
        <td><button class="btn btn-secondary btn-sm" onclick="editBairro(${JSON.stringify(b).replace(/"/g,'&quot;')})">Editar</button></td>
      </tr>`).join('') || '<tr><td colspan="6" class="empty">Nenhum bairro cadastrado</td></tr>';
  } catch(e) { document.getElementById('bairros-tbody').innerHTML = '<tr><td colspan="6">Erro</td></tr>'; }
}

function showModalBairro() {
  document.getElementById('mb-id').value = '';
  document.getElementById('mb-bairro').value = '';
  document.getElementById('mb-cidade').value = 'Brasília';
  document.getElementById('mb-uf').value = 'DF';
  document.getElementById('mb-taxa').value = '';
  document.getElementById('mb-prazo').value = '45';
  document.getElementById('modal-bairro-title').textContent = 'Novo Bairro';
  document.getElementById('modal-bairro').classList.add('open');
}

function editBairro(b) {
  document.getElementById('mb-id').value = b.id;
  document.getElementById('mb-bairro').value = b.bairro;
  document.getElementById('mb-cidade').value = b.cidade;
  document.getElementById('mb-uf').value = b.uf;
  document.getElementById('mb-taxa').value = b.taxa;
  document.getElementById('mb-prazo').value = b.prazo_min;
  document.getElementById('modal-bairro-title').textContent = 'Editar Bairro';
  document.getElementById('modal-bairro').classList.add('open');
}

async function salvarBairro() {
  const id     = document.getElementById('mb-id').value;
  const bairro = document.getElementById('mb-bairro').value.trim();
  if (!bairro) { alert('Informe o nome do bairro'); return; }
  const data = {
    action: id ? 'editar_bairro' : 'criar_bairro',
    id: id || undefined,
    bairro,
    cidade: document.getElementById('mb-cidade').value.trim() || 'Brasília',
    uf: (document.getElementById('mb-uf').value.trim() || 'DF').toUpperCase().slice(0,2),
    taxa: parseFloat(document.getElementById('mb-taxa').value) || 0,
    prazo_min: parseInt(document.getElementById('mb-prazo').value) || 45,
  };
  try {
    const r = await fetch(API, {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(data)
    });
    const d = await r.json();
    if (d.success) { closeModal('modal-bairro'); loadBairros(); }
    else alert(d.error || 'Erro');
  } catch(e) { alert('Erro de rede'); }
}

async function loadHistorico() {
  document.getElementById('historico-list').innerHTML = '<div class="loading">Carregando...</div>';
  const data   = document.getElementById('hist-data').value;
  const status = document.getElementById('hist-status').value;
  try {
    const r = await fetch(API + `?action=historico&data=${data}&status=${status}`);
    const d = await r.json();
    const list = d.data || [];
    if (!list.length) {
      document.getElementById('historico-list').innerHTML = '<div class="empty"><div class="empty-icon">📦</div><p>Nenhuma entrega no período</p></div>';
      return;
    }
    document.getElementById('historico-list').innerHTML = `
      <div style="background:#161924;border-radius:12px;overflow:hidden">
        <table><thead><tr><th>Pedido</th><th>Endereço</th><th>Entregador</th><th>Status</th><th>Taxa</th><th>Horário</th></tr></thead>
        <tbody>${list.map(e => `
          <tr>
            <td><strong>#${e.pedido_numero||e.pedido_id}</strong></td>
            <td style="font-size:12px">${e.logradouro||''} ${e.numero||''} ${e.bairro ? '— '+e.bairro : ''}</td>
            <td>${e.entregador_nome||'—'}</td>
            <td><span class="ec-badge badge-${e.status}" style="font-size:10px">${STEP_LABELS[e.status]}</span></td>
            <td>R$ ${parseFloat(e.taxa_entrega||0).toFixed(2).replace('.',',')}</td>
            <td style="font-size:12px">${new Date(e.criado_em).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}</td>
          </tr>`).join('')}
        </tbody></table>
      </div>`;
  } catch(e) { document.getElementById('historico-list').innerHTML = '<div class="alert">Erro ao carregar histórico</div>'; }
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if(e.target===m) m.classList.remove('open'); }));

loadAtivas();
setInterval(loadAtivas, 20000);
</script>
</body>
</html>

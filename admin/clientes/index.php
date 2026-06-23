<?php
// ── Auth ─────────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }

require_once '../../config/db.php';
require_once '../../config/csrf.php';

$adminNome = $_SESSION['admin_nome'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'operador';
$isAdmin   = $adminRole === 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>CRM — Café Comunhão</title>
<?php csrfMeta(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
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
html,body{height:100%;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

/* ── TOPBAR ──────────────────────────────────────────────────────── */
.topbar{display:flex;align-items:center;gap:16px;padding:0 24px;height:54px;background:var(--surf);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-back{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px;font-weight:600;padding:6px 12px;border-radius:8px;transition:all .15s}
.topbar-back:hover{background:var(--card);color:var(--text)}
.topbar-title{font-size:16px;font-weight:800}
.topbar-spacer{flex:1}
.topbar-user{font-size:13px;color:var(--text2)}

/* ── CONTENT ─────────────────────────────────────────────────────── */
.content{max-width:1400px;margin:0 auto;padding:24px}

/* ── TABS ────────────────────────────────────────────────────────── */
.tabs{display:flex;gap:4px;margin-bottom:24px;background:var(--surf);border:1px solid var(--border);border-radius:12px;padding:5px}
.tab-btn{flex:1;padding:9px 16px;border:none;background:transparent;color:var(--text2);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;border-radius:9px;transition:all .15s}
.tab-btn.active{background:var(--acc);color:#fff}
.tab-btn:not(.active):hover{background:var(--card);color:var(--text)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── COMPONENTS ──────────────────────────────────────────────────── */
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.toolbar-search{display:flex;align-items:center;background:var(--card);border:1px solid var(--border2);border-radius:9px;padding:0 12px;gap:8px;height:38px;flex:1;min-width:180px}
.toolbar-search input{background:transparent;border:none;outline:none;color:var(--text);font-size:13px;font-family:inherit;width:100%}
.toolbar-search input::placeholder{color:var(--text3)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;border:none;height:38px}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{background:var(--acc-l)}
.btn-secondary{background:var(--card);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text);border-color:var(--text3)}
.btn-sm{padding:5px 12px;font-size:12px;height:30px}
.data-table-wrap{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.data-table-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.data-table-head h3{font-size:14px;font-weight:700}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{text-align:left;padding:10px 14px;color:var(--text2);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.data-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.3px}
.badge-ativo{background:rgba(34,197,94,.15);color:var(--green)}
.badge-inativo{background:rgba(107,114,128,.15);color:var(--text3)}
.badge-percentual{background:rgba(139,92,246,.15);color:var(--purple)}
.badge-fixo{background:rgba(255,85,0,.15);color:var(--acc)}
.badge-frete_gratis{background:rgba(59,130,246,.15);color:var(--blue)}
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;border-top:1px solid var(--border);justify-content:center}
.page-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:inherit;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.page-btn:hover{background:var(--card2);color:var(--text)}
.page-btn.active{background:var(--acc);color:#fff;border-color:var(--acc)}
/* Modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(4px)}
.overlay.open{display:flex}
.modal{background:var(--surf);border:1px solid var(--border2);border-radius:18px;padding:32px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;display:flex;flex-direction:column;gap:20px;box-shadow:0 32px 120px rgba(0,0,0,.8)}
.modal h3{font-size:18px;font-weight:800}
.field{display:flex;flex-direction:column;gap:7px}
.field label{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.field input,.field select,.field textarea{padding:11px 14px;background:var(--card);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--acc)}
.form-row{display:flex;gap:12px}
.form-row .field{flex:1}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:4px}
.detail-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.detail-row:last-child{border-bottom:none}
/* Toast */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;background:var(--card2);border:1px solid var(--border2);border-radius:10px;font-size:13px;font-weight:500;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}
/* Section card */
.section-card{background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:16px;overflow:hidden}
.section-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.section-head h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text2)}
.section-body{padding:16px 18px}
/* KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.kpi-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;inset:0;opacity:.04;background:var(--c,var(--acc))}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:10px}
.kpi-value{font-size:28px;font-weight:900;color:var(--c,var(--acc))}
.kpi-sub{font-size:12px;color:var(--text3);margin-top:4px}
</style>
</head>
<body>

<!-- ── TOPBAR ──────────────────────────────────────────────────────── -->
<div class="topbar">
  <a href="../" class="topbar-back">&#8592; Painel Admin</a>
  <span style="color:var(--border2)">|</span>
  <span class="topbar-title">CRM &amp; Fidelidade</span>
  <div class="topbar-spacer"></div>
  <span class="topbar-user"><?= htmlspecialchars($adminNome) ?> &mdash; <span style="color:var(--text3)"><?= htmlspecialchars($adminRole) ?></span></span>
</div>

<!-- ── CONTENT ─────────────────────────────────────────────────────── -->
<div class="content">

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('clientes')">&#128101; Clientes</button>
    <button class="tab-btn" onclick="switchTab('cupons')">&#127991; Cupons</button>
    <button class="tab-btn" onclick="switchTab('pontos')">&#11088; Programa de Pontos</button>
  </div>

  <!-- ─── TAB CLIENTES ─────────────────────────────────────────────── -->
  <div class="tab-panel active" id="tab-clientes">
    <!-- KPIs -->
    <div class="kpi-grid" id="crm-kpis"></div>

    <div class="toolbar">
      <div class="toolbar-search">
        <span>&#128269;</span>
        <input type="text" id="cli-busca" placeholder="Buscar por nome ou CPF...">
      </div>
    </div>

    <div class="data-table-wrap">
      <div class="data-table-head">
        <h3 id="cli-count">Clientes</h3>
      </div>
      <table class="data-table">
        <thead><tr>
          <th>Nome</th>
          <th>CPF</th>
          <th>Telefone</th>
          <th>Total gasto</th>
          <th>Pedidos</th>
          <th>Pontos</th>
          <th>Cadastro</th>
          <th></th>
        </tr></thead>
        <tbody id="cli-tbody">
          <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text3)">Carregando...</td></tr>
        </tbody>
      </table>
      <div class="pagination" id="cli-pagination"></div>
    </div>
  </div>

  <!-- ─── TAB CUPONS ───────────────────────────────────────────────── -->
  <div class="tab-panel" id="tab-cupons">
    <div class="toolbar">
      <button class="btn btn-primary" onclick="openCupomModal()">+ Novo cupom</button>
    </div>
    <div class="data-table-wrap">
      <div class="data-table-head"><h3 id="cup-count">Cupons</h3></div>
      <table class="data-table">
        <thead><tr>
          <th>Código</th>
          <th>Tipo</th>
          <th>Valor</th>
          <th>Mínimo</th>
          <th>Usos</th>
          <th>Validade</th>
          <th>Cliente</th>
          <th>Status</th>
          <th></th>
        </tr></thead>
        <tbody id="cup-tbody">
          <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text3)">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── TAB PONTOS CONFIG ────────────────────────────────────────── -->
  <div class="tab-panel" id="tab-pontos">
    <div class="section-card" style="max-width:560px">
      <div class="section-head"><h3>Regras do Programa de Pontos</h3></div>
      <div class="section-body" style="display:flex;flex-direction:column;gap:18px">
        <div class="form-row">
          <div class="field">
            <label>Pontos por R$ 1,00 gasto</label>
            <input type="number" id="cfg-pts-por-real" step="0.1" min="0.1" placeholder="1.0">
            <small style="color:var(--text3);font-size:11px">Ex: 1.0 = 1 ponto a cada R$ 1 gasto</small>
          </div>
          <div class="field">
            <label>R$ de desconto por ponto</label>
            <input type="number" id="cfg-real-por-ponto" step="0.01" min="0.001" placeholder="0.05">
            <small style="color:var(--text3);font-size:11px">Ex: 0.05 = R$ 0,05 por ponto resgatado</small>
          </div>
        </div>
        <div class="field" style="max-width:280px">
          <label>Validade dos pontos (dias)</label>
          <input type="number" id="cfg-validade-dias" min="1" placeholder="365">
          <small style="color:var(--text3);font-size:11px">Pontos expiram após este período</small>
        </div>
        <div style="background:var(--card2);border-radius:10px;padding:14px;font-size:13px;color:var(--text2);line-height:1.6" id="pts-preview"></div>
        <div>
          <button class="btn btn-primary" id="btn-salvar-pontos">Salvar configuração</button>
          <span id="pts-status" style="margin-left:12px;font-size:13px;color:var(--green);display:none">Salvo!</span>
        </div>
      </div>
    </div>
  </div>

</div><!-- /content -->

<!-- ── MODAL DETALHE CLIENTE ──────────────────────────────────────── -->
<div class="overlay" id="modal-cliente">
  <div class="modal" style="width:580px">
    <h3 id="mc-title">Cliente</h3>
    <div id="mc-body"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModal('modal-cliente')">Fechar</button>
    </div>
  </div>
</div>

<!-- ── MODAL CRIAR CUPOM ──────────────────────────────────────────── -->
<div class="overlay" id="modal-cupom">
  <div class="modal">
    <h3>Novo cupom</h3>
    <form id="form-cupom" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-row">
        <div class="field" style="flex:2">
          <label>Código *</label>
          <input type="text" id="fc-codigo" required placeholder="EX: PROMO10" style="text-transform:uppercase">
        </div>
        <div class="field">
          <label>Tipo *</label>
          <select id="fc-tipo" required>
            <option value="">Selecione</option>
            <option value="percentual">Percentual (%)</option>
            <option value="fixo">Fixo (R$)</option>
            <option value="frete_gratis">Frete grátis</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Valor *</label>
          <input type="number" id="fc-valor" step="0.01" min="0.01" required placeholder="0,00">
          <small id="fc-valor-hint" style="color:var(--text3);font-size:11px">Para percentual: use 10 para 10%</small>
        </div>
        <div class="field">
          <label>Pedido mínimo (R$)</label>
          <input type="number" id="fc-valor-min" step="0.01" min="0" placeholder="0,00" value="0">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Limite de usos</label>
          <input type="number" id="fc-uso-max" min="1" placeholder="1">
          <small style="color:var(--text3);font-size:11px">Deixe vazio para ilimitado</small>
        </div>
        <div class="field">
          <label>Validade</label>
          <input type="date" id="fc-validade">
        </div>
      </div>
      <div class="field">
        <label>CPF do cliente (cupom nominal, deixe vazio para público)</label>
        <input type="text" id="fc-cliente-cpf" placeholder="000.000.000-00">
        <small id="fc-cliente-hint" style="color:var(--text3);font-size:11px"></small>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-cupom')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Criar cupom</button>
      </div>
    </form>
  </div>
</div>

<div id="toast"></div>

<script>
'use strict';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Helpers ──────────────────────────────────────────────────────────
const fmt    = v => 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
const fmtDt  = iso => { try { return new Date(iso).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}); } catch{return iso;} };
const fmtDate= iso => { try { if(!iso) return '—'; return new Date(iso+'T12:00').toLocaleDateString('pt-BR'); } catch{return iso;} };
const esc    = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtCpf = cpf => String(cpf||'').replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
const maskCpf = cpf => fmtCpf(cpf).replace(/\d(?=\d{2}[.\-])/g, '*'); // exibe mascarado

function toast(msg, type='ok') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = 'show ' + type;
  clearTimeout(el._t); el._t = setTimeout(() => el.className = '', 3200);
}

async function apiAdmin(path, opts={}) {
  const headers = {'Content-Type':'application/json','X-CSRF-Token':CSRF,...(opts.headers||{})};
  const res = await fetch('../../api/' + path, {...opts, headers});
  if (res.status === 401) { alert('Sessão expirada'); window.location.reload(); return {}; }
  return res.json();
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(o =>
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); }));

function renderPagination(containerId, page, pages, cb) {
  const el = document.getElementById(containerId);
  if (!el || pages <= 1) { if(el) el.innerHTML=''; return; }
  let html = '';
  if (page > 1) html += '<button class="page-btn" onclick="' + cb + '(' + (page-1) + ')">&#8249;</button>';
  const start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
  for (let i = start; i <= end; i++)
    html += '<button class="page-btn' + (i===page?' active':'') + '" onclick="' + cb + '(' + i + ')">' + i + '</button>';
  if (page < pages) html += '<button class="page-btn" onclick="' + cb + '(' + (page+1) + ')">&#8250;</button>';
  el.innerHTML = html;
}

// ── Tab switching ────────────────────────────────────────────────────
let activeTab = 'clientes';
function switchTab(tab) {
  activeTab = tab;
  document.querySelectorAll('.tab-btn').forEach((b,i) => {
    const tabs = ['clientes','cupons','pontos'];
    b.classList.toggle('active', tabs[i] === tab);
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  if (tab === 'clientes') loadClientes(1);
  if (tab === 'cupons') loadCupons();
  if (tab === 'pontos') loadPontosConfig();
}

// ─────────────────────────────────────────────────────────────────────
// ── CLIENTES ──────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
let cliPage = 1, cliPages = 1;
const PER_PAGE = 20;

async function loadClientes(page) {
  if (page) cliPage = page;
  const busca = document.getElementById('cli-busca').value.trim();

  // Buscar todos e filtrar/paginar no cliente (tabela pequena)
  // Para escala maior, mover filtragem para API dedicada
  const res = await apiAdmin('clientes.php?id=__list__&page=' + cliPage + '&busca=' + encodeURIComponent(busca));

  // A API pública retorna cliente por id; precisamos chamar endpoint de listagem
  // Como a API pública não tem listagem admin, vamos usar fetch direto ao endpoint admin
  await loadClientesAdmin(page);
}

async function loadClientesAdmin(page) {
  if (page) cliPage = page;
  const busca = document.getElementById('cli-busca').value.trim();

  // Chamamos via PHP inline para listagem (rota especial ou via admin api)
  const params = new URLSearchParams({ page: cliPage, per_page: PER_PAGE });
  if (busca) params.set('busca', busca);

  const res = await fetch('api.php?' + params, {
    headers: { 'X-CSRF-Token': CSRF }
  });
  const data = await res.json();
  if (!data.success) { toast(data.error || 'Erro ao carregar clientes', 'err'); return; }

  const clientes = data.data || [];
  const total    = data.total || 0;
  cliPages       = Math.ceil(total / PER_PAGE) || 1;

  document.getElementById('cli-count').textContent = total + ' cliente(s)';

  // KPIs
  document.getElementById('crm-kpis').innerHTML = [
    { label: 'Total clientes',  value: total,                              color: 'var(--blue)',   sub: 'Cadastrados' },
    { label: 'Total faturado',  value: fmt(data.total_gasto || 0),        color: 'var(--green)',  sub: 'Via clientes' },
    { label: 'Pontos em saldo', value: (data.total_pontos || 0) + ' pts', color: 'var(--gold)',   sub: 'Em circulação' },
    { label: 'Cupons ativos',   value: data.cupons_ativos || 0,           color: 'var(--purple)', sub: 'Disponíveis' },
  ].map(k =>
    '<div class="kpi-card" style="--c:' + k.color + '">' +
      '<div class="kpi-label">' + k.label + '</div>' +
      '<div class="kpi-value">' + esc(String(k.value)) + '</div>' +
      '<div class="kpi-sub">' + k.sub + '</div>' +
    '</div>'
  ).join('');

  document.getElementById('cli-tbody').innerHTML = clientes.length
    ? clientes.map(c =>
        '<tr>' +
          '<td><strong>' + esc(c.nome) + '</strong></td>' +
          '<td style="font-family:monospace;color:var(--text2)">' + esc(fmtCpf(c.cpf)) + '</td>' +
          '<td>' + esc(c.telefone || '—') + '</td>' +
          '<td style="font-weight:700;color:var(--acc-l)">' + fmt(c.total_gasto) + '</td>' +
          '<td style="color:var(--text2)">' + c.total_pedidos + '</td>' +
          '<td><span style="color:var(--gold);font-weight:700">' + c.pontos_saldo + ' pts</span></td>' +
          '<td style="color:var(--text3);font-size:12px">' + fmtDate(c.criado_em) + '</td>' +
          '<td><button class="btn btn-secondary btn-sm" onclick="openClienteDetalhe(' + c.id + ')">Ver detalhes</button></td>' +
        '</tr>'
      ).join('')
    : '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text3)">Nenhum cliente encontrado</td></tr>';

  renderPagination('cli-pagination', cliPage, cliPages, 'loadClientesAdmin');
}

document.getElementById('cli-busca').addEventListener('input', debounce(() => loadClientesAdmin(1), 400));

async function openClienteDetalhe(id) {
  const res = await fetch('../../api/clientes.php?id=' + id);
  const data = await res.json();
  if (!data.success) { toast('Erro ao carregar cliente', 'err'); return; }

  const c = data.cliente;
  const h = data.historico || [];

  document.getElementById('mc-title').textContent = c.nome;
  document.getElementById('mc-body').innerHTML =
    '<div class="detail-row"><span>CPF</span><span style="font-family:monospace">' + esc(c.cpf_formatado) + '</span></div>' +
    '<div class="detail-row"><span>Pontos</span><span style="color:var(--gold);font-weight:700">' + c.pontos_saldo + ' pts</span></div>' +
    '<div class="detail-row"><span>Desconto disponível</span><span style="color:var(--green);font-weight:700">' + fmt(c.desconto_disponivel) + '</span></div>' +
    '<div class="detail-row"><span>Total gasto</span><span style="font-weight:700">' + fmt(c.total_gasto) + '</span></div>' +
    '<div class="detail-row"><span>Total pedidos</span><span>' + c.total_pedidos + '</span></div>' +
    '<div style="margin-top:18px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:10px">Últimos movimentos de pontos</div>' +
    (h.length
      ? '<table class="data-table" style="font-size:12px"><thead><tr><th>Tipo</th><th>Pontos</th><th>Descrição</th><th>Data</th></tr></thead><tbody>' +
          h.map(r =>
            '<tr>' +
              '<td><span class="badge badge-' + (r.tipo === 'ganho' ? 'ativo' : 'inativo') + '">' + esc(r.tipo) + '</span></td>' +
              '<td style="font-weight:700;color:' + (r.pontos > 0 ? 'var(--green)' : 'var(--red)') + '">' + (r.pontos > 0 ? '+' : '') + r.pontos + '</td>' +
              '<td style="color:var(--text2)">' + esc(r.descricao || '—') + '</td>' +
              '<td style="color:var(--text3)">' + fmtDt(r.criado_em) + '</td>' +
            '</tr>'
          ).join('') +
        '</tbody></table>'
      : '<div style="color:var(--text3);font-size:13px;padding:10px 0">Nenhum movimento registrado</div>');

  openModal('modal-cliente');
}

// ─────────────────────────────────────────────────────────────────────
// ── CUPONS ────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
async function loadCupons() {
  const res = await apiAdmin('cupons.php');
  if (!res.success) { toast(res.error || 'Erro', 'err'); return; }

  const cupons = res.data || [];
  document.getElementById('cup-count').textContent = cupons.length + ' cupom(ns)';

  const TIPO_LABEL = { percentual: 'Percentual', fixo: 'Fixo', frete_gratis: 'Frete grátis' };

  document.getElementById('cup-tbody').innerHTML = cupons.length
    ? cupons.map(c =>
        '<tr>' +
          '<td><strong style="font-family:monospace;font-size:14px">' + esc(c.codigo) + '</strong></td>' +
          '<td><span class="badge badge-' + c.tipo + '">' + esc(TIPO_LABEL[c.tipo] || c.tipo) + '</span></td>' +
          '<td style="font-weight:700">' + (c.tipo === 'percentual' ? c.valor + '%' : fmt(c.valor)) + '</td>' +
          '<td>' + (parseFloat(c.valor_minimo) > 0 ? fmt(c.valor_minimo) : '—') + '</td>' +
          '<td>' + c.usos_atuais + ' / ' + (c.uso_maximo ?? '∞') + '</td>' +
          '<td style="color:var(--text2)">' + fmtDate(c.validade) + '</td>' +
          '<td style="color:var(--text3);font-size:12px">' + esc(c.cliente_nome || 'Público') + '</td>' +
          '<td><span class="badge badge-' + (c.ativo ? 'ativo' : 'inativo') + '">' + (c.ativo ? 'Ativo' : 'Inativo') + '</span></td>' +
          '<td><button class="btn btn-sm btn-secondary" onclick="toggleCupom(' + c.id + ',' + !c.ativo + ')">' + (c.ativo ? 'Desativar' : 'Ativar') + '</button></td>' +
        '</tr>'
      ).join('')
    : '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text3)">Nenhum cupom cadastrado</td></tr>';
}

async function toggleCupom(id, ativo) {
  const res = await fetch('../../api/cupons.php', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ id, ativo }),
  });
  const data = await res.json();
  if (data.success) { toast(ativo ? 'Cupom ativado' : 'Cupom desativado'); loadCupons(); }
  else toast(data.error || 'Erro', 'err');
}

function openCupomModal() {
  document.getElementById('form-cupom').reset();
  document.getElementById('fc-cliente-hint').textContent = '';
  openModal('modal-cupom');
}

document.getElementById('fc-tipo').addEventListener('change', function() {
  const h = document.getElementById('fc-valor-hint');
  if (this.value === 'percentual') h.textContent = 'Para percentual: use 10 para 10%';
  else if (this.value === 'fixo')  h.textContent = 'Valor fixo em R$ de desconto';
  else h.textContent = '';
});

document.getElementById('fc-cliente-cpf').addEventListener('blur', async function() {
  const cpf = this.value.replace(/\D/g,'');
  const hint = document.getElementById('fc-cliente-hint');
  if (!cpf) { hint.textContent = ''; this.dataset.clienteId = ''; return; }
  if (cpf.length !== 11) { hint.textContent = 'CPF inválido'; hint.style.color = 'var(--red)'; return; }
  const res = await fetch('../../api/clientes.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'identificar', cpf }),
  });
  const data = await res.json();
  if (data.success && data.cliente) {
    hint.textContent = 'Cliente: ' + data.cliente.nome;
    hint.style.color = 'var(--green)';
    this.dataset.clienteId = data.cliente.id;
  } else {
    hint.textContent = 'Cliente não encontrado';
    hint.style.color = 'var(--red)';
    this.dataset.clienteId = '';
  }
});

document.getElementById('form-cupom').addEventListener('submit', async function(e) {
  e.preventDefault();
  const clienteId = document.getElementById('fc-cliente-cpf').dataset.clienteId || '';
  const payload = {
    action:       'criar',
    codigo:       document.getElementById('fc-codigo').value.toUpperCase().trim(),
    tipo:         document.getElementById('fc-tipo').value,
    valor:        parseFloat(document.getElementById('fc-valor').value),
    valor_minimo: parseFloat(document.getElementById('fc-valor-min').value) || 0,
    uso_maximo:   document.getElementById('fc-uso-max').value || null,
    validade:     document.getElementById('fc-validade').value || null,
    cliente_id:   clienteId ? parseInt(clienteId) : null,
  };

  const res = await fetch('../../api/cupons.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (data.success) {
    toast('Cupom criado!');
    closeModal('modal-cupom');
    loadCupons();
  } else toast(data.error || 'Erro ao criar cupom', 'err');
});

// ─────────────────────────────────────────────────────────────────────
// ── PONTOS CONFIG ─────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
async function loadPontosConfig() {
  const res = await fetch('api.php?action=pontos_config', { headers: { 'X-CSRF-Token': CSRF } });
  const data = await res.json();
  if (!data.success) return;
  const cfg = data.config;
  document.getElementById('cfg-pts-por-real').value    = cfg.pontos_por_real;
  document.getElementById('cfg-real-por-ponto').value  = cfg.real_por_ponto;
  document.getElementById('cfg-validade-dias').value   = cfg.validade_dias;
  updatePreview();
}

function updatePreview() {
  const ppr = parseFloat(document.getElementById('cfg-pts-por-real').value) || 1;
  const rpp = parseFloat(document.getElementById('cfg-real-por-ponto').value) || 0.05;
  const val = parseFloat(document.getElementById('cfg-validade-dias').value) || 365;
  const ex  = 100 * ppr;
  const des = (ex * rpp).toFixed(2).replace('.',',');
  document.getElementById('pts-preview').innerHTML =
    'Exemplo: em um pedido de <strong>R$ 100,00</strong>, o cliente ganha <strong>' + ex.toFixed(0) + ' pontos</strong>. ' +
    'Ao resgatar todos, obtém <strong>R$ ' + des + '</strong> de desconto. ' +
    'Os pontos expiram após <strong>' + val + ' dias</strong>.';
}

['cfg-pts-por-real','cfg-real-por-ponto','cfg-validade-dias'].forEach(id =>
  document.getElementById(id).addEventListener('input', updatePreview));

document.getElementById('btn-salvar-pontos').addEventListener('click', async () => {
  const payload = {
    action:          'salvar_pontos_config',
    pontos_por_real: parseFloat(document.getElementById('cfg-pts-por-real').value),
    real_por_ponto:  parseFloat(document.getElementById('cfg-real-por-ponto').value),
    validade_dias:   parseInt(document.getElementById('cfg-validade-dias').value),
  };
  const res = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (data.success) {
    const st = document.getElementById('pts-status');
    st.style.display = 'inline';
    setTimeout(() => st.style.display = 'none', 3000);
  } else toast(data.error || 'Erro ao salvar', 'err');
});

// ── debounce ─────────────────────────────────────────────────────────
function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

// ── Inicialização ─────────────────────────────────────────────────────
loadClientesAdmin(1);
</script>
</body>
</html>

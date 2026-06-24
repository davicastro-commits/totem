<?php
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = $_SESSION['admin_nome'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'operador';
$mesPadrao = date('Y-m');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<title>DRE — Café Comunhão</title>
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

/* LAYOUT */
.main{max-width:1200px;margin:0 auto;padding:28px 24px 60px}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);margin-bottom:12px;margin-top:4px}

/* CARD */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:24px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border)}
.card-head h3{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);display:flex;align-items:center;gap:8px}
.card-body{padding:20px}

/* KPI GRID */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.kpi-grid{grid-template-columns:1fr}}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px 22px;position:relative;overflow:hidden;transition:border-color .15s}
.kpi:hover{border-color:var(--border2)}
.kpi::before{content:'';position:absolute;top:-35px;right:-35px;width:90px;height:90px;border-radius:50%;background:var(--c,var(--acc));opacity:.06;pointer-events:none}
.kpi-icon{font-size:18px;width:36px;height:36px;border-radius:10px;background:color-mix(in srgb,var(--c,var(--acc)) 12%,transparent);display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text3);margin-bottom:4px}
.kpi-value{font-size:24px;font-weight:900;color:var(--c,var(--acc));line-height:1.1;font-variant-numeric:tabular-nums}
.kpi-sub{font-size:12px;color:var(--text4);margin-top:4px}

/* MES SELETOR */
.mes-bar{display:flex;align-items:center;gap:12px;margin-bottom:28px;flex-wrap:wrap}
.mes-bar label{font-size:13px;font-weight:600;color:var(--text2)}
input[type=month]{background:var(--card);border:1px solid var(--border2);color:var(--text);border-radius:10px;padding:7px 12px;font-size:13px;font-family:inherit;cursor:pointer;outline:none}
input[type=month]:focus{border-color:var(--acc)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
.btn-acc{background:var(--acc);color:#fff}
.btn-acc:hover{background:var(--acc-l)}
.btn-ghost{background:var(--card2);color:var(--text2);border:1px solid var(--border2)}
.btn-ghost:hover{color:var(--text);border-color:var(--border)}
.btn-danger{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:rgba(239,68,68,.2)}
.btn-sm{padding:5px 11px;font-size:12px;border-radius:8px}

/* DRE TABLE */
.dre-table{width:100%;border-collapse:collapse;font-size:14px}
.dre-table td{padding:11px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.dre-table tr:last-child td{border-bottom:none}
.dre-table .dre-signal{font-size:11px;font-weight:700;width:28px;color:var(--text3)}
.dre-table .dre-label{color:var(--text2)}
.dre-table .dre-sub{padding-left:44px;color:var(--text3);font-size:13px}
.dre-table .dre-value{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
.dre-table .dre-pct{text-align:right;font-size:12px;color:var(--text3);width:80px}
.dre-table .row-total{background:var(--card2)}
.dre-table .row-total td{border-top:1px solid var(--border2)}
.dre-table .row-final{background:rgba(34,197,94,.05)}
.dre-table .row-final td{border-top:2px solid rgba(34,197,94,.2)}
.dre-table .row-loss{background:rgba(239,68,68,.05)}
.dre-table .row-loss td{border-top:2px solid rgba(239,68,68,.2)}
.dre-pos{color:var(--green)}
.dre-neg{color:var(--red)}
.dre-acc{color:var(--acc-l)}

/* FORM LANCAMENTO */
.form-grid{display:grid;grid-template-columns:140px 1fr 2fr 130px auto auto;gap:10px;align-items:end}
@media(max-width:900px){.form-grid{grid-template-columns:1fr 1fr;row-gap:10px}}
@media(max-width:500px){.form-grid{grid-template-columns:1fr}}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text3)}
.form-control{background:var(--card2);border:1px solid var(--border2);color:var(--text);border-radius:10px;padding:9px 12px;font-size:13px;font-family:inherit;outline:none;width:100%}
.form-control:focus{border-color:var(--acc)}
.form-control::placeholder{color:var(--text4)}
select.form-control option{background:var(--card);color:var(--text)}
.toggle-wrap{display:flex;align-items:center;gap:8px;padding-bottom:2px}
.toggle{position:relative;width:36px;height:20px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:var(--card2);border:1px solid var(--border2);border-radius:20px;cursor:pointer;transition:.2s}
.toggle-slider::before{content:'';position:absolute;left:2px;top:2px;width:14px;height:14px;background:var(--text3);border-radius:50%;transition:.2s}
.toggle input:checked+.toggle-slider{background:var(--acc);border-color:var(--acc)}
.toggle input:checked+.toggle-slider::before{transform:translateX(16px);background:#fff}
.toggle-label{font-size:12px;color:var(--text3)}

/* TABELA DESPESAS */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{text-align:left;padding:9px 14px;color:var(--text3);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.tbl th.r,.tbl td.r{text-align:right}
.tbl td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;text-transform:capitalize}
.badge-aluguel   {background:rgba(139,92,246,.15);color:#a78bfa}
.badge-fornecedor{background:rgba(59,130,246,.15);color:#60a5fa}
.badge-energia   {background:rgba(245,158,11,.15);color:var(--gold)}
.badge-folha     {background:rgba(34,197,94,.15);color:var(--green)}
.badge-marketing {background:rgba(255,85,0,.12);color:var(--acc-l)}
.badge-outros    {background:rgba(107,114,128,.12);color:var(--text3)}

/* META BAR */
.meta-wrap{display:flex;flex-direction:column;gap:8px}
.meta-track{height:10px;background:var(--card2);border-radius:5px;overflow:hidden}
.meta-fill{height:100%;background:var(--green);border-radius:5px;transition:width .6s}
.meta-fill.warn{background:var(--gold)}
.meta-fill.danger{background:var(--red)}
.meta-row{display:flex;align-items:center;gap:12px}
.meta-input-wrap{display:flex;align-items:center;gap:8px}
input[type=number]{background:var(--card2);border:1px solid var(--border2);color:var(--text);border-radius:10px;padding:7px 12px;font-size:13px;font-family:inherit;outline:none;width:140px}
input[type=number]:focus{border-color:var(--acc)}

/* LOADING / EMPTY */
.loading{display:flex;align-items:center;gap:10px;padding:40px;justify-content:center;color:var(--text3);font-size:14px}
.spin{width:20px;height:20px;border:2px solid var(--border2);border-top-color:var(--acc);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{padding:40px;text-align:center;color:var(--text3);font-size:14px}
.toast{position:fixed;bottom:24px;right:24px;z-index:999;display:flex;flex-direction:column;gap:8px}
.toast-item{padding:12px 18px;border-radius:12px;font-size:13px;font-weight:600;animation:slideUp .25s ease;max-width:320px}
.toast-ok{background:#1a2e1a;border:1px solid rgba(34,197,94,.3);color:var(--green)}
.toast-err{background:#2a1a1a;border:1px solid rgba(239,68,68,.3);color:var(--red)}
@keyframes slideUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>

<div class="topbar">
  <a href="../">← Admin</a>
  <span class="topbar-title">DRE</span>
  <span class="topbar-badge">Financeiro</span>
  <div class="topbar-right">
    <span><?= htmlspecialchars($adminNome) ?></span>
  </div>
</div>

<div class="main">

  <!-- SELETOR DE MÊS + META -->
  <div class="mes-bar">
    <label for="selMes">Mês:</label>
    <input type="month" id="selMes" value="<?= htmlspecialchars($mesPadrao) ?>">
    <button class="btn btn-ghost" onclick="carregarDRE()">Atualizar</button>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
      <span style="font-size:13px;color:var(--text3)">Meta:</span>
      <input type="number" id="inpMeta" placeholder="0,00" min="0" step="0.01" style="width:140px">
      <button class="btn btn-ghost btn-sm" onclick="salvarMeta()">Salvar meta</button>
    </div>
  </div>

  <!-- BARRA DE META -->
  <div id="metaBarWrap" style="display:none;margin-bottom:24px" class="card">
    <div class="card-head">
      <h3>🎯 Meta do mês</h3>
      <span id="metaPct" style="font-size:13px;font-weight:700"></span>
    </div>
    <div class="card-body">
      <div class="meta-wrap">
        <div class="meta-track"><div id="metaFill" class="meta-fill" style="width:0%"></div></div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text3)">
          <span id="metaFatLabel"></span>
          <span id="metaGoalLabel"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid" id="kpiGrid">
    <div class="loading"><div class="spin"></div> Carregando…</div>
  </div>

  <!-- DRE TABLE -->
  <div class="card" id="cardDRE">
    <div class="card-head">
      <h3>📊 Demonstrativo de Resultado</h3>
      <span id="dreNumPed" style="font-size:12px;color:var(--text3)"></span>
    </div>
    <div class="card-body" style="padding:0">
      <div id="dreBody"><div class="loading"><div class="spin"></div> Calculando DRE…</div></div>
    </div>
  </div>

  <!-- LANÇAR DESPESA -->
  <div class="card">
    <div class="card-head">
      <h3>➕ Lançar Despesa</h3>
    </div>
    <div class="card-body">
      <form id="formDespesa" onsubmit="salvarDespesa(event)">
        <div class="form-grid">
          <div class="form-group">
            <label>Data</label>
            <input type="date" class="form-control" id="inpData" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Categoria</label>
            <select class="form-control" id="inpCategoria" required>
              <option value="">Selecione…</option>
              <option value="aluguel">Aluguel</option>
              <option value="fornecedor">Fornecedor</option>
              <option value="energia">Energia</option>
              <option value="folha">Folha</option>
              <option value="marketing">Marketing</option>
              <option value="outros">Outros</option>
            </select>
          </div>
          <div class="form-group">
            <label>Descrição</label>
            <input type="text" class="form-control" id="inpDescricao" placeholder="Ex: Aluguel do espaço…" required maxlength="200">
          </div>
          <div class="form-group">
            <label>Valor (R$)</label>
            <input type="number" class="form-control" id="inpValor" placeholder="0,00" min="0.01" step="0.01" required>
          </div>
          <div class="form-group">
            <label>Recorrente</label>
            <div class="toggle-wrap">
              <label class="toggle">
                <input type="checkbox" id="inpRecorrente">
                <span class="toggle-slider"></span>
              </label>
              <span class="toggle-label" id="recLabel">Não</span>
            </div>
          </div>
          <div class="form-group">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-acc">Lançar</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- LISTA DESPESAS DO MÊS -->
  <div class="card">
    <div class="card-head">
      <h3>📋 Despesas do mês</h3>
      <span id="totalDespLabel" style="font-size:13px;font-weight:700;color:var(--red)"></span>
    </div>
    <div style="overflow-x:auto">
      <div id="listaBody"><div class="loading"><div class="spin"></div></div></div>
    </div>
  </div>

</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function fmt(v){ return 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function pct(v){ return v !== null && v !== undefined ? Number(v).toFixed(1) + '%' : '—'; }
function mesAtual(){ return document.getElementById('selMes').value; }

function toast(msg, tipo='ok'){
  const t = document.getElementById('toast');
  const el = document.createElement('div');
  el.className = 'toast-item toast-' + tipo;
  el.textContent = msg;
  t.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ── Carregar DRE + Meta ────────────────────────────────────────────────────
async function carregarDRE(){
  const mes = mesAtual();
  if (!mes) return;

  // Atualiza data do formulário de despesa para o mês selecionado
  const d = document.getElementById('inpData');
  if (d) d.value = mes + '-01';

  document.getElementById('kpiGrid').innerHTML = '<div class="loading"><div class="spin"></div> Carregando…</div>';
  document.getElementById('dreBody').innerHTML = '<div class="loading"><div class="spin"></div> Calculando…</div>';
  document.getElementById('listaBody').innerHTML = '<div class="loading"><div class="spin"></div></div>';

  const [dreRes, listRes, metaRes] = await Promise.all([
    fetch(`../api/despesas.php?action=dre&mes=${mes}`).then(r=>r.json()).catch(()=>null),
    fetch(`../api/despesas.php?action=listar&data_ini=${mes}-01&data_fim=${mes}-31`).then(r=>r.json()).catch(()=>null),
    fetch(`../api/metas.php?mes=${mes}`).then(r=>r.json()).catch(()=>null),
  ]);

  if (dreRes?.success) renderDRE(dreRes);
  else { document.getElementById('kpiGrid').innerHTML='<div class="empty">Erro ao carregar DRE.</div>'; }

  if (listRes?.success) renderLista(listRes);

  if (metaRes?.success) renderMeta(metaRes);
}

// ── Renderizar KPIs + DRE ─────────────────────────────────────────────────
function renderDRE(d){
  const ll = d.lucro_liquido;
  const corLL = ll >= 0 ? 'var(--green)' : 'var(--red)';

  // KPIs
  document.getElementById('kpiGrid').innerHTML = `
    <div class="kpi" style="--c:var(--acc)">
      <div class="kpi-icon">💰</div>
      <div class="kpi-label">Faturamento Bruto</div>
      <div class="kpi-value">${fmt(d.faturamento)}</div>
      <div class="kpi-sub">${d.num_pedidos} pedidos no mês</div>
    </div>
    <div class="kpi" style="--c:var(--gold)">
      <div class="kpi-icon">🏭</div>
      <div class="kpi-label">Custo de Insumos</div>
      <div class="kpi-value">${fmt(d.custo_insumos)}</div>
      <div class="kpi-sub">Margem bruta: ${pct(d.margem_bruta_pct)}</div>
    </div>
    <div class="kpi" style="--c:var(--red)">
      <div class="kpi-icon">📑</div>
      <div class="kpi-label">Despesas Oper.</div>
      <div class="kpi-value">${fmt(d.despesas_total)}</div>
      <div class="kpi-sub">${Object.keys(d.despesas_map).length} categorias</div>
    </div>
    <div class="kpi" style="--c:${corLL}">
      <div class="kpi-icon">${ll >= 0 ? '📈' : '📉'}</div>
      <div class="kpi-label">Lucro Estimado</div>
      <div class="kpi-value" style="color:${corLL}">${fmt(ll)}</div>
      <div class="kpi-sub">Margem líquida: ${pct(d.margem_liq_pct)}</div>
    </div>
  `;

  document.getElementById('dreNumPed').textContent = `${d.num_pedidos} pedidos — ${d.mes}`;

  const cats = ['aluguel','fornecedor','energia','folha','marketing','outros'];
  const labMap = {aluguel:'Aluguel',fornecedor:'Fornecedores',energia:'Energia',folha:'Folha',marketing:'Marketing',outros:'Outros'};

  let despRows = '';
  cats.forEach(cat => {
    const item = d.despesas_map[cat];
    if (item) {
      despRows += `
        <tr class="dre-sub">
          <td class="dre-signal"></td>
          <td class="dre-label dre-sub">— ${labMap[cat]}</td>
          <td class="dre-value dre-neg">${fmt(item.total)}</td>
          <td class="dre-pct">${d.faturamento > 0 ? pct((item.total/d.faturamento)*100) : '—'}</td>
        </tr>`;
    }
  });

  const rowFinal = ll >= 0 ? 'row-final' : 'row-loss';
  const corFinal = ll >= 0 ? 'dre-pos' : 'dre-neg';

  document.getElementById('dreBody').innerHTML = `
    <table class="dre-table">
      <tr>
        <td class="dre-signal" style="color:var(--green);font-size:13px">(+)</td>
        <td class="dre-label" style="font-weight:600;color:var(--text)">Faturamento bruto</td>
        <td class="dre-value dre-acc">${fmt(d.faturamento)}</td>
        <td class="dre-pct"></td>
      </tr>
      <tr>
        <td class="dre-signal" style="color:var(--red)">(−)</td>
        <td class="dre-label">Custo de insumos</td>
        <td class="dre-value dre-neg">${fmt(d.custo_insumos)}</td>
        <td class="dre-pct" style="color:var(--text3)">${d.faturamento > 0 ? pct((d.custo_insumos/d.faturamento)*100) : '—'}</td>
      </tr>
      <tr class="row-total">
        <td class="dre-signal" style="color:var(--acc)">(=)</td>
        <td class="dre-label" style="font-weight:700;color:var(--text)">Lucro bruto</td>
        <td class="dre-value" style="color:${d.lucro_bruto>=0?'var(--green)':'var(--red)'}">${fmt(d.lucro_bruto)}</td>
        <td class="dre-pct" style="color:var(--text2)">${pct(d.margem_bruta_pct)}</td>
      </tr>
      <tr>
        <td class="dre-signal" style="color:var(--red)">(−)</td>
        <td class="dre-label" style="font-weight:600;color:var(--text)">Despesas operacionais</td>
        <td class="dre-value dre-neg">${fmt(d.despesas_total)}</td>
        <td class="dre-pct"></td>
      </tr>
      ${despRows}
      <tr class="${rowFinal}">
        <td class="dre-signal" style="color:${ll>=0?'var(--green)':'var(--red)'}">( = )</td>
        <td class="dre-label" style="font-weight:800;font-size:15px;color:var(--text)">LUCRO LÍQUIDO ESTIMADO</td>
        <td class="dre-value ${corFinal}" style="font-size:16px">${fmt(ll)}</td>
        <td class="dre-pct" style="color:${ll>=0?'var(--green)':'var(--red)'};font-weight:700">${pct(d.margem_liq_pct)}</td>
      </tr>
    </table>`;
}

// ── Renderizar Meta ───────────────────────────────────────────────────────
function renderMeta(m){
  if (m.meta_faturamento) {
    document.getElementById('inpMeta').value = Number(m.meta_faturamento).toFixed(2);
    const wrap = document.getElementById('metaBarWrap');
    wrap.style.display = '';
    const pctVal = Math.min(m.percentual_atingido ?? 0, 100);
    const fill = document.getElementById('metaFill');
    fill.style.width = pctVal + '%';
    fill.className = 'meta-fill' + (pctVal < 50 ? ' danger' : pctVal < 80 ? ' warn' : '');
    document.getElementById('metaPct').textContent = (m.percentual_atingido ?? 0).toFixed(1) + '% atingido';
    document.getElementById('metaPct').style.color = pctVal >= 80 ? 'var(--green)' : pctVal >= 50 ? 'var(--gold)' : 'var(--red)';
    document.getElementById('metaFatLabel').textContent = 'Atual: ' + fmt(m.faturamento_atual);
    document.getElementById('metaGoalLabel').textContent = 'Meta: ' + fmt(m.meta_faturamento);
  } else {
    document.getElementById('metaBarWrap').style.display = 'none';
  }
}

// ── Renderizar lista de despesas ──────────────────────────────────────────
function renderLista(data){
  document.getElementById('totalDespLabel').textContent = data.total_geral > 0 ? '− ' + fmt(data.total_geral) : '';
  if (!data.despesas.length) {
    document.getElementById('listaBody').innerHTML = '<div class="empty">Nenhuma despesa lançada neste mês.</div>';
    return;
  }
  let html = `<table class="tbl">
    <thead><tr>
      <th>Data</th><th>Categoria</th><th>Descrição</th>
      <th class="r">Valor</th><th>Rec.</th><th class="r"></th>
    </tr></thead><tbody>`;
  data.despesas.forEach(d => {
    const dataFmt = new Date(d.data + 'T12:00:00').toLocaleDateString('pt-BR');
    html += `<tr>
      <td style="color:var(--text3);white-space:nowrap">${dataFmt}</td>
      <td><span class="badge badge-${d.categoria}">${d.categoria}</span></td>
      <td>${escHtml(d.descricao)}</td>
      <td class="r" style="font-weight:700;color:var(--acc-l);font-variant-numeric:tabular-nums">${fmt(d.valor)}</td>
      <td style="color:var(--text3)">${d.recorrente === 't' || d.recorrente === true ? '🔄' : '—'}</td>
      <td class="r"><button class="btn btn-danger btn-sm" onclick="excluir(${d.id})">Excluir</button></td>
    </tr>`;
  });
  html += '</tbody></table>';
  document.getElementById('listaBody').innerHTML = html;
}

function escHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// ── Salvar despesa ─────────────────────────────────────────────────────────
async function salvarDespesa(e){
  e.preventDefault();
  const payload = {
    action: 'salvar',
    data:        document.getElementById('inpData').value,
    categoria:   document.getElementById('inpCategoria').value,
    descricao:   document.getElementById('inpDescricao').value,
    valor:       parseFloat(document.getElementById('inpValor').value),
    recorrente:  document.getElementById('inpRecorrente').checked,
  };
  try {
    const r = await fetch('../api/despesas.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success){ toast('Despesa lançada!'); document.getElementById('formDespesa').reset(); document.getElementById('recLabel').textContent='Não'; carregarDRE(); }
    else toast(d.error || 'Erro ao salvar.', 'err');
  } catch { toast('Falha na requisição.', 'err'); }
}

// ── Excluir despesa ───────────────────────────────────────────────────────
async function excluir(id){
  if (!confirm('Excluir esta despesa?')) return;
  try {
    const r = await fetch(`../api/despesas.php?id=${id}`, {
      method:'DELETE',
      headers:{'X-CSRF-Token':CSRF},
    });
    const d = await r.json();
    if (d.success){ toast('Despesa excluída.'); carregarDRE(); }
    else toast(d.error || 'Erro ao excluir.', 'err');
  } catch { toast('Falha na requisição.', 'err'); }
}

// ── Salvar meta ───────────────────────────────────────────────────────────
async function salvarMeta(){
  const mes  = mesAtual();
  const meta = parseFloat(document.getElementById('inpMeta').value);
  if (!mes || !(meta > 0)){ toast('Informe um valor de meta válido.', 'err'); return; }
  try {
    const r = await fetch('../api/metas.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({mes, meta_faturamento: meta}),
    });
    const d = await r.json();
    if (d.success){ toast('Meta salva!'); carregarDRE(); }
    else toast(d.error || 'Erro ao salvar meta.', 'err');
  } catch { toast('Falha na requisição.', 'err'); }
}

// ── Toggle label recorrente ───────────────────────────────────────────────
document.getElementById('inpRecorrente').addEventListener('change', function(){
  document.getElementById('recLabel').textContent = this.checked ? 'Sim' : 'Não';
});

document.getElementById('selMes').addEventListener('change', carregarDRE);

// Inicializar
carregarDRE();
</script>
</body>
</html>

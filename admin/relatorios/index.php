<?php
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once '../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = $_SESSION['admin_nome'] ?? '';
$adminRole = $_SESSION['admin_role'] ?? 'operador';
$hoje      = date('Y-m-d');
$ini30     = date('Y-m-d', strtotime('-30 days'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<title>Relatórios — Café Comunhão</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;--card2:#22253a;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;--purple:#8b5cf6;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;
}
html,body{min-height:100vh;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* TOPBAR */
.topbar{display:flex;align-items:center;gap:14px;padding:0 24px;height:54px;background:var(--surf);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar a{color:var(--text2);font-size:13px;font-weight:600;text-decoration:none;padding:5px 10px;border-radius:7px;transition:all .15s}
.topbar a:hover{background:var(--card);color:var(--text)}
.topbar-title{font-size:16px;font-weight:800}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.topbar-user{font-size:13px;color:var(--text3)}

/* CONTENT */
.content{max-width:1200px;margin:0 auto;padding:24px}

/* TABS */
.tabs{display:flex;gap:4px;background:var(--surf);border:1px solid var(--border);border-radius:12px;padding:5px;margin-bottom:28px;width:fit-content}
.tab-btn{padding:9px 20px;border-radius:8px;border:none;background:transparent;color:var(--text2);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:6px}
.tab-btn.active{background:var(--acc);color:#fff}
.tab-btn:not(.active):hover{background:var(--card);color:var(--text)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* FILTER BAR */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.filter-bar label{font-size:12px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
.filter-bar input[type=date]{background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:8px 12px;outline:none}
.filter-bar input[type=date]:focus{border-color:var(--acc)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;height:38px}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{background:var(--acc-l)}
.btn-secondary{background:var(--card);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text);border-color:var(--text3)}
.btn-sm{padding:5px 12px;font-size:12px;height:30px}

/* KPI GRID */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;position:relative;overflow:hidden}
.kpi::before{content:'';position:absolute;inset:0;background:var(--c,var(--acc));opacity:.04}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:8px}
.kpi-value{font-size:26px;font-weight:900;color:var(--c,var(--acc));line-height:1}
.kpi-sub{font-size:12px;color:var(--text3);margin-top:5px}

/* CARD */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.card-head h3{font-size:14px;font-weight:700}
.card-head .card-actions{display:flex;gap:8px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:10px 14px;color:var(--text2);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.015)}

/* PAYMENT ROW */
.pag-label{display:flex;align-items:center;gap:8px;font-weight:600}
.pag-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.pag-bar-wrap{background:var(--card2);border-radius:4px;height:8px;flex:1;min-width:80px;overflow:hidden}
.pag-bar{height:100%;border-radius:4px;transition:width .4s}

/* BADGE */
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.3px}
.badge-ok{background:rgba(34,197,94,.15);color:var(--green)}
.badge-warn{background:rgba(245,158,11,.15);color:var(--gold)}
.badge-err{background:rgba(239,68,68,.15);color:var(--red)}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue)}

/* TEMPO CHART */
.tempo-chart{display:flex;align-items:flex-end;gap:6px;height:160px;padding:0 18px 14px;border-top:1px solid var(--border)}
.bar-group{display:flex;flex-direction:column;align-items:center;flex:1;gap:4px;min-width:0}
.bar-col{width:100%;border-radius:6px 6px 0 0;transition:height .4s;min-height:4px;position:relative}
.bar-col:hover .bar-tooltip{display:block}
.bar-tooltip{display:none;position:absolute;bottom:105%;left:50%;transform:translateX(-50%);background:var(--card2);border:1px solid var(--border2);padding:5px 9px;border-radius:7px;font-size:11px;white-space:nowrap;pointer-events:none;z-index:10}
.bar-label{font-size:10px;color:var(--text3);text-align:center;line-height:1.2;margin-top:4px}
.bar-value{font-size:11px;font-weight:700;text-align:center;color:var(--text2)}

/* ALERTA PREPARO */
.alerta-lento{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:var(--red);display:none}
.alerta-lento.show{display:block}

/* PRINT */
.print-header{display:none}
@media print {
  body{background:#fff;color:#000}
  .topbar,.tabs,.filter-bar,.no-print{display:none!important}
  .tab-panel{display:block!important}
  .print-header{display:block;text-align:center;margin-bottom:16px}
  .card,.kpi{border:1px solid #ddd;break-inside:avoid}
  .kpi-value{color:#000!important}
  table{font-size:12px}
  th,td{border-color:#ddd!important}
  .pag-bar-wrap,.pag-bar{display:none}
  .tempo-chart{display:none}
}

/* TOAST */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;background:var(--card2);border:1px solid var(--border2);border-radius:10px;font-size:13px;font-weight:500;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}

/* EMPTY */
.empty{text-align:center;padding:40px;color:var(--text3);font-size:13px}

/* TOTAL ROW */
.total-row td{font-weight:700;font-size:14px;border-top:2px solid var(--border2)!important;border-bottom:none!important}

/* STATUS COLORS */
.st-aguardando{color:var(--blue)}
.st-preparando{color:var(--gold)}
.st-pronto,.st-entregue{color:var(--green)}
.st-cancelado{color:var(--red)}
.st-aguardando_pagamento{color:var(--text3)}
</style>
</head>
<body>

<div class="topbar">
  <a href="../">← Admin</a>
  <span style="color:var(--border2)">|</span>
  <span class="topbar-title">Relatórios</span>
  <div class="topbar-right">
    <span class="topbar-user"><?= htmlspecialchars($adminNome) ?> · <?= htmlspecialchars($adminRole) ?></span>
    <button class="btn btn-secondary btn-sm no-print" onclick="window.print()">🖨️ Imprimir</button>
  </div>
</div>

<div class="content">

  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('fechamento')">🗃️ Fechamento de Caixa</button>
    <button class="tab-btn" onclick="switchTab('preparo')">⏱️ Tempo de Preparo</button>
  </div>

  <!-- ══════════════ TAB: FECHAMENTO DE CAIXA ══════════════ -->
  <div class="tab-panel active" id="tab-fechamento">

    <div class="filter-bar no-print">
      <label>Data</label>
      <input type="date" id="fc-data" value="<?= $hoje ?>">
      <button class="btn btn-primary" onclick="carregarFechamento()">Gerar fechamento</button>
      <button class="btn btn-secondary" onclick="exportarFechamentoCSV()">⬇️ CSV</button>
    </div>

    <div class="print-header" id="fc-print-header"></div>

    <!-- KPIs -->
    <div class="kpi-grid" id="fc-kpis">
      <div class="kpi"><div class="kpi-label">Selecione uma data</div><div class="kpi-value" style="color:var(--text3)">—</div></div>
    </div>

    <!-- Por forma de pagamento -->
    <div class="card">
      <div class="card-head"><h3>Por forma de pagamento</h3></div>
      <table>
        <thead><tr>
          <th>Forma</th>
          <th style="text-align:right">Pedidos</th>
          <th style="text-align:right">Total</th>
          <th style="width:200px" class="no-print">Participação</th>
        </tr></thead>
        <tbody id="fc-pag-tbody">
          <tr><td colspan="4" class="empty">Clique em "Gerar fechamento"</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Lista completa do dia -->
    <div class="card">
      <div class="card-head">
        <h3 id="fc-lista-title">Pedidos do dia</h3>
      </div>
      <table>
        <thead><tr>
          <th>#</th>
          <th>Hora</th>
          <th>Itens</th>
          <th>Pagamento</th>
          <th>Consumo</th>
          <th>CPF</th>
          <th style="text-align:right">Total</th>
          <th>Status</th>
        </tr></thead>
        <tbody id="fc-lista-tbody">
          <tr><td colspan="8" class="empty">—</td></tr>
        </tbody>
      </table>
    </div>

  </div><!-- /tab-fechamento -->

  <!-- ══════════════ TAB: TEMPO DE PREPARO ══════════════ -->
  <div class="tab-panel" id="tab-preparo">

    <div class="filter-bar no-print">
      <label>De</label>
      <input type="date" id="tp-ini" value="<?= $ini30 ?>">
      <label>Até</label>
      <input type="date" id="tp-fim" value="<?= $hoje ?>">
      <button class="btn btn-primary" onclick="carregarPreparo()">Analisar</button>
    </div>

    <!-- KPIs gerais -->
    <div class="kpi-grid" id="tp-kpis">
      <div class="kpi"><div class="kpi-label">Selecione o período</div><div class="kpi-value" style="color:var(--text3)">—</div></div>
    </div>

    <div class="alerta-lento" id="tp-alerta"></div>

    <!-- Gráfico de barras -->
    <div class="card">
      <div class="card-head"><h3>Tempo médio por faixa horária</h3></div>
      <div class="tempo-chart" id="tp-chart">
        <div class="empty" style="width:100%;align-self:center">Carregando...</div>
      </div>
      <table>
        <thead><tr>
          <th>Faixa horária</th>
          <th style="text-align:right">Pedidos analisados</th>
          <th style="text-align:right">Tempo médio</th>
          <th style="text-align:right">Mais rápido</th>
          <th style="text-align:right">Mais lento</th>
          <th>Avaliação</th>
        </tr></thead>
        <tbody id="tp-tbody">
          <tr><td colspan="6" class="empty">—</td></tr>
        </tbody>
      </table>
    </div>

    <p style="font-size:12px;color:var(--text3);margin-top:8px">
      * Considera apenas pedidos com horário de início e conclusão registrados.
      Pedidos cancelados e aguardando pagamento são excluídos.
    </p>

  </div><!-- /tab-preparo -->

</div><!-- /content -->
<div id="toast"></div>

<script>
'use strict';
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API  = '../api/relatorios.php';

const fmt    = v  => 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
const fmtMin = m  => m != null ? parseFloat(m).toFixed(1).replace('.',',') + ' min' : '—';
const esc    = s  => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function toast(msg, type='ok') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = 'show ' + type;
  clearTimeout(el._t); el._t = setTimeout(() => el.className='', 3200);
}

function switchTab(t) {
  document.querySelectorAll('.tab-btn').forEach((b,i) => {
    b.classList.toggle('active', ['fechamento','preparo'][i] === t);
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + t).classList.add('active');
}

// ══════════════════════════════════════════════════════════════
// FECHAMENTO DE CAIXA
// ══════════════════════════════════════════════════════════════
const PAG_COLORS = {pix:'#3b82f6',credito:'#8b5cf6',debito:'#f59e0b',dinheiro:'#22c55e'};
const PAG_LABELS = {pix:'PIX',credito:'Crédito',debito:'Débito',dinheiro:'Dinheiro'};
const ST_LABELS  = {
  aguardando:'Confirmado',preparando:'Preparando',pronto:'Pronto',
  entregue:'Entregue',cancelado:'Cancelado',aguardando_pagamento:'Ag. Pagamento'
};

let _fechamentoData = null;

async function carregarFechamento() {
  const data = document.getElementById('fc-data').value;
  if (!data) { toast('Selecione uma data','err'); return; }

  document.getElementById('fc-kpis').innerHTML =
    '<div class="kpi"><div class="kpi-label">Carregando...</div><div class="kpi-value" style="color:var(--text3)">⏳</div></div>';

  const res = await fetch(API + '?action=fechamento&data=' + data,
    {headers:{'X-CSRF-Token':CSRF}});
  const d = await res.json();
  if (!d.success) { toast('Erro ao carregar','err'); return; }

  _fechamentoData = d;

  const tot = d.totais;
  const dataFmt = d.data;

  // Print header
  document.getElementById('fc-print-header').innerHTML =
    `<h2 style="font-size:20px">FECHAMENTO DE CAIXA — ${dataFmt}</h2>
     <p style="margin-top:4px;color:#666">Café Comunhão</p>`;

  // KPIs
  document.getElementById('fc-kpis').innerHTML = [
    {label:'Faturamento líquido', value: fmt(tot.total),          color:'var(--green)', sub: tot.pedidos + ' pedidos'},
    {label:'Ticket médio',        value: fmt(tot.ticket_medio),   color:'var(--acc)',   sub: 'por pedido'},
    {label:'Itens vendidos',      value: d.total_itens,           color:'var(--blue)',  sub: 'unidades'},
    {label:'Local / Viagem',      value: tot.local + ' / ' + tot.viagem, color:'var(--gold)', sub: 'tipo consumo'},
    {label:'Cancelados',          value: tot.cancelados,          color:'var(--red)',
     sub: tot.cancelados > 0 ? 'Valor: ' + fmt(tot.total_cancelado) : 'nenhum'},
  ].map(k =>
    `<div class="kpi" style="--c:${k.color}">
      <div class="kpi-label">${k.label}</div>
      <div class="kpi-value">${esc(String(k.value))}</div>
      <div class="kpi-sub">${k.sub}</div>
    </div>`
  ).join('');

  // Pagamentos
  const totalLiquido = parseFloat(tot.total) || 1;
  let pagHtml = '';
  let somaCheck = 0;

  d.por_pagamento.forEach(p => {
    const pct  = Math.round((parseFloat(p.total) / totalLiquido) * 100);
    const cor  = PAG_COLORS[p.forma_pagamento] || 'var(--text2)';
    const nome = PAG_LABELS[p.forma_pagamento] || p.forma_pagamento;
    somaCheck += parseFloat(p.total);
    pagHtml += `<tr>
      <td>
        <div class="pag-label">
          <div class="pag-dot" style="background:${cor}"></div>
          <strong>${esc(nome)}</strong>
        </div>
      </td>
      <td style="text-align:right;color:var(--text2)">${p.pedidos}</td>
      <td style="text-align:right;font-weight:700;font-size:15px">${fmt(p.total)}</td>
      <td class="no-print">
        <div style="display:flex;align-items:center;gap:8px">
          <div class="pag-bar-wrap">
            <div class="pag-bar" style="width:${pct}%;background:${cor}"></div>
          </div>
          <span style="font-size:12px;color:var(--text3);min-width:32px">${pct}%</span>
        </div>
      </td>
    </tr>`;
  });

  pagHtml += `<tr class="total-row">
    <td>TOTAL</td>
    <td style="text-align:right">${tot.pedidos}</td>
    <td style="text-align:right">${fmt(tot.total)}</td>
    <td class="no-print"></td>
  </tr>`;

  if (parseFloat(tot.total_cancelado) > 0) {
    pagHtml += `<tr>
      <td style="color:var(--red);font-size:12px">Cancelados (excluídos acima)</td>
      <td style="text-align:right;color:var(--red)">${tot.cancelados}</td>
      <td style="text-align:right;color:var(--red)">(${fmt(tot.total_cancelado)})</td>
      <td class="no-print"></td>
    </tr>`;
  }
  document.getElementById('fc-pag-tbody').innerHTML = pagHtml || '<tr><td colspan="4" class="empty">Nenhum pedido</td></tr>';

  // Lista
  document.getElementById('fc-lista-title').textContent = 'Pedidos do dia — ' + dataFmt + ' (' + d.pedidos.length + ')';
  document.getElementById('fc-lista-tbody').innerHTML = d.pedidos.length
    ? d.pedidos.map(p => {
        const hora = new Date(p.criado_em).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
        const stCls = 'st-' + p.status;
        const isCan = p.status === 'cancelado';
        return `<tr style="${isCan ? 'opacity:.45' : ''}">
          <td style="font-family:monospace;font-weight:700">#${esc(p.numero_pedido)}</td>
          <td style="color:var(--text2)">${hora}</td>
          <td style="font-size:12px;color:var(--text3);max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.itens||'—')}</td>
          <td>${esc(PAG_LABELS[p.forma_pagamento]||p.forma_pagamento)}</td>
          <td style="color:var(--text3)">${p.tipo_consumo==='local'?'🍽️ Local':'🛍️ Viagem'}</td>
          <td style="font-family:monospace;font-size:12px;color:var(--text3)">${p.cpf ? p.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4') : '—'}</td>
          <td style="text-align:right;font-weight:700;${isCan?'text-decoration:line-through':''}">${fmt(p.total)}</td>
          <td><span class="${stCls}" style="font-size:12px;font-weight:600">${esc(ST_LABELS[p.status]||p.status)}</span></td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="8" class="empty">Nenhum pedido nesta data</td></tr>';
}

function exportarFechamentoCSV() {
  const data = document.getElementById('fc-data').value;
  if (!data) { toast('Gere o fechamento primeiro','err'); return; }
  if (!_fechamentoData) { toast('Clique em "Gerar fechamento" primeiro','err'); return; }

  const rows = [['#','Hora','Itens','Pagamento','Consumo','CPF','Total','Status']];
  _fechamentoData.pedidos.forEach(p => {
    const hora = new Date(p.criado_em).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
    rows.push([
      p.numero_pedido, hora, p.itens||'',
      PAG_LABELS[p.forma_pagamento]||p.forma_pagamento,
      p.tipo_consumo,
      p.cpf ? p.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4') : '',
      parseFloat(p.total).toFixed(2).replace('.',','),
      ST_LABELS[p.status]||p.status,
    ]);
  });

  const csv = '﻿' + rows.map(r => r.map(c => '"' + String(c||'').replace(/"/g,'""') + '"').join(';')).join('\n');
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'fechamento_' + data + '.csv';
  a.click();
}

// ══════════════════════════════════════════════════════════════
// TEMPO DE PREPARO
// ══════════════════════════════════════════════════════════════
async function carregarPreparo() {
  const ini = document.getElementById('tp-ini').value;
  const fim = document.getElementById('tp-fim').value;
  if (!ini || !fim) { toast('Selecione o período','err'); return; }
  if (ini > fim) { toast('Data inicial maior que final','err'); return; }

  document.getElementById('tp-kpis').innerHTML =
    '<div class="kpi"><div class="kpi-label">Carregando...</div><div class="kpi-value" style="color:var(--text3)">⏳</div></div>';
  document.getElementById('tp-chart').innerHTML = '<div class="empty" style="width:100%;align-self:center">Carregando...</div>';

  const res = await fetch(API + '?action=preparo&data_ini=' + ini + '&data_fim=' + fim,
    {headers:{'X-CSRF-Token':CSRF}});
  const d = await res.json();
  if (!d.success) { toast('Erro ao carregar','err'); return; }

  const g = d.geral;
  const faixas = d.faixas || [];

  // KPIs
  const cobertura = g.total_pedidos > 0
    ? Math.round((g.total_com_tempo / g.total_pedidos) * 100) + '%' : '0%';

  document.getElementById('tp-kpis').innerHTML = [
    {label:'Tempo médio geral', value: fmtMin(g.media_geral),  color:'var(--acc)',   sub: g.total_com_tempo + ' pedidos com tempo'},
    {label:'Mais rápido',       value: fmtMin(g.mais_rapido),  color:'var(--green)', sub: 'menor tempo registrado'},
    {label:'Mais lento',        value: fmtMin(g.mais_lento),   color:'var(--red)',   sub: 'maior tempo registrado'},
    {label:'Cobertura',         value: cobertura,              color:'var(--blue)',  sub: 'pedidos com tempo medido'},
  ].map(k =>
    `<div class="kpi" style="--c:${k.color}">
      <div class="kpi-label">${k.label}</div>
      <div class="kpi-value">${esc(String(k.value))}</div>
      <div class="kpi-sub">${k.sub}</div>
    </div>`
  ).join('');

  // Alerta de pico lento
  const mediaGeral = parseFloat(g.media_geral) || 0;
  const alerta = document.getElementById('tp-alerta');
  const lentas = faixas.filter(f => parseFloat(f.tempo_medio_min) > mediaGeral * 1.5);
  if (lentas.length > 0 && mediaGeral > 0) {
    const horas = lentas.map(f => padHora(+f.faixa_inicio) + '–' + padHora(+f.faixa_inicio + 2)).join(', ');
    alerta.textContent = '⚠️ Faixas com tempo acima de 50% da média geral: ' + horas + '. Considere reforçar a equipe nesses horários.';
    alerta.classList.add('show');
  } else {
    alerta.classList.remove('show');
  }

  // Gráfico
  if (!faixas.length) {
    document.getElementById('tp-chart').innerHTML = '<div class="empty" style="width:100%;align-self:center">Sem dados de preparo no período (pedidos precisam ter horário de início e conclusão registrados)</div>';
  } else {
    const maxTempo = Math.max(...faixas.map(f => parseFloat(f.tempo_medio_min)||0), 1);
    document.getElementById('tp-chart').innerHTML = faixas.map(f => {
      const h    = padHora(+f.faixa_inicio);
      const h2   = padHora(+f.faixa_inicio + 2);
      const pct  = Math.max(4, Math.round((parseFloat(f.tempo_medio_min)||0) / maxTempo * 100));
      const lento = parseFloat(f.tempo_medio_min) > mediaGeral * 1.5;
      const cor   = lento ? 'var(--red)' : (parseFloat(f.tempo_medio_min) > mediaGeral ? 'var(--gold)' : 'var(--green)');
      return `<div class="bar-group">
        <div style="font-size:11px;color:var(--text2);font-weight:700;margin-bottom:4px">${fmtMin(f.tempo_medio_min)}</div>
        <div class="bar-col" style="height:${pct}%;background:${cor};position:relative">
          <div class="bar-tooltip">${h}–${h2}<br>${f.pedidos} pedidos<br>Médio: ${fmtMin(f.tempo_medio_min)}<br>Min: ${fmtMin(f.tempo_min)} / Max: ${fmtMin(f.tempo_max)}</div>
        </div>
        <div class="bar-label">${h}<br>${h2}</div>
      </div>`;
    }).join('');
  }

  // Tabela detalhada
  document.getElementById('tp-tbody').innerHTML = faixas.length
    ? faixas.map(f => {
        const h   = padHora(+f.faixa_inicio) + 'h – ' + padHora(+f.faixa_inicio + 2) + 'h';
        const med = parseFloat(f.tempo_medio_min) || 0;
        const lento = med > mediaGeral * 1.5;
        const ok    = med <= mediaGeral * 1.1;
        const cls   = lento ? 'badge-err' : (ok ? 'badge-ok' : 'badge-warn');
        const lbl   = lento ? 'Lento 🔴' : (ok ? 'Normal ✅' : 'Atenção ⚠️');
        return `<tr>
          <td style="font-weight:700">${h}</td>
          <td style="text-align:right;color:var(--text2)">${f.pedidos}</td>
          <td style="text-align:right;font-weight:700;color:${lento ? 'var(--red)' : ok ? 'var(--green)' : 'var(--gold)'}">${fmtMin(f.tempo_medio_min)}</td>
          <td style="text-align:right;color:var(--green)">${fmtMin(f.tempo_min)}</td>
          <td style="text-align:right;color:var(--red)">${fmtMin(f.tempo_max)}</td>
          <td><span class="badge ${cls}">${lbl}</span></td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="6" class="empty">Sem dados de preparo no período selecionado</td></tr>';
}

function padHora(h) { return String(h % 24).padStart(2,'0') + 'h'; }

// Init
carregarFechamento();
</script>
</body>
</html>

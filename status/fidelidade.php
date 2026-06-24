<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#ff5500">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Meus Pontos — Café Comunhão</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;
  --acc:#ff5500;--gold:#f59e0b;--green:#22c55e;--red:#ef4444;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;
  --border:#2a2d3a;
}

html,body{
  height:100%;min-height:100vh;
  background:radial-gradient(ellipse 140% 80% at 50% -10%, rgba(255,85,0,.18) 0%, var(--bg) 60%);
  color:var(--text);font-family:'Inter',sans-serif;
  overflow:hidden;
}

/* ── Layout full screen ── */
.screen{
  position:fixed;inset:0;display:flex;flex-direction:column;
  align-items:center;justify-content:center;padding:24px;
  transition:opacity .35s ease, transform .35s ease;
}
.screen.hidden{opacity:0;pointer-events:none;transform:scale(.97)}

/* ── Logo & header ── */
.logo-area{text-align:center;margin-bottom:36px}
.logo-icon{font-size:52px;line-height:1;margin-bottom:8px}
.logo-name{font-size:26px;font-weight:900;color:var(--text);letter-spacing:-.5px}
.logo-sub{font-size:14px;color:var(--text2);margin-top:4px}

/* ── CPF card ── */
.cpf-card{
  background:var(--card);border-radius:24px;padding:28px 24px;
  width:100%;max-width:440px;border:1px solid var(--border);
}
.cpf-label{font-size:15px;font-weight:700;color:var(--text2);margin-bottom:12px;text-align:center}
.cpf-display{
  font-size:36px;font-weight:900;letter-spacing:4px;
  text-align:center;color:var(--text);min-height:52px;
  background:var(--surf);border-radius:14px;padding:12px 16px;
  margin-bottom:20px;border:2px solid var(--border);
  transition:border-color .2s;
}
.cpf-display.active{border-color:var(--acc)}
.cpf-display.error{border-color:var(--red)}
.cpf-placeholder{color:var(--text3)}

/* ── Teclado numérico ── */
.numpad{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.numpad-btn{
  background:var(--surf);border:1px solid var(--border);color:var(--text);
  border-radius:16px;padding:0;height:72px;
  font-size:26px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:background .1s,border-color .1s;
  -webkit-tap-highlight-color:transparent;
  user-select:none;
}
.numpad-btn:active,.numpad-btn.pressed{background:var(--border)}
.numpad-btn.zero{grid-column:2/3}
.numpad-btn.del{font-size:22px;color:var(--text2)}
.numpad-btn.del:active{background:rgba(239,68,68,.15);border-color:var(--red)}

/* ── Botão confirmar ── */
.btn-ver{
  width:100%;background:var(--acc);border:none;color:#fff;
  padding:18px;border-radius:16px;font-size:18px;font-weight:800;
  cursor:pointer;font-family:inherit;letter-spacing:.3px;
  transition:opacity .15s,transform .1s;
  -webkit-tap-highlight-color:transparent;
}
.btn-ver:disabled{opacity:.4;cursor:not-allowed}
.btn-ver:active:not(:disabled){opacity:.8;transform:scale(.98)}

/* ── Resultado ── */
.result-card{
  background:var(--card);border-radius:24px;padding:32px 24px;
  width:100%;max-width:440px;border:1px solid var(--border);
  text-align:center;
}
.res-greeting{font-size:22px;font-weight:800;margin-bottom:24px;line-height:1.3}
.res-points-box{
  background:linear-gradient(135deg, rgba(255,85,0,.2), rgba(245,158,11,.2));
  border:1px solid rgba(245,158,11,.3);
  border-radius:18px;padding:20px;margin-bottom:20px;
}
.res-points-label{font-size:13px;font-weight:700;text-transform:uppercase;
  letter-spacing:1px;color:var(--gold);margin-bottom:6px}
.res-points-num{font-size:56px;font-weight:900;color:var(--gold);line-height:1}
.res-points-unit{font-size:16px;font-weight:600;color:var(--text2);margin-top:4px}

.res-stats{display:flex;gap:12px;margin-bottom:24px}
.res-stat{
  flex:1;background:var(--surf);border-radius:14px;padding:14px 10px;
  border:1px solid var(--border);
}
.res-stat-val{font-size:18px;font-weight:800;color:var(--text)}
.res-stat-label{font-size:11px;color:var(--text2);margin-top:3px}

/* Barra de progresso */
.progress-section{margin-bottom:20px}
.progress-label{
  display:flex;justify-content:space-between;
  font-size:12px;color:var(--text2);margin-bottom:6px;
}
.progress-bar{
  height:10px;background:var(--border);border-radius:99px;overflow:hidden;
}
.progress-fill{
  height:100%;background:linear-gradient(90deg, var(--acc), var(--gold));
  border-radius:99px;transition:width .8s cubic-bezier(.4,0,.2,1);
}
.progress-tip{font-size:12px;color:var(--text3);margin-top:6px;text-align:center}

.btn-voltar-res{
  width:100%;background:none;border:1px solid var(--border);color:var(--text2);
  padding:14px;border-radius:14px;font-size:15px;font-weight:700;
  cursor:pointer;font-family:inherit;margin-top:4px;
  transition:background .15s;
}
.btn-voltar-res:active{background:var(--border)}

/* ── Não encontrado ── */
.not-found-card{
  background:var(--card);border-radius:24px;padding:32px 24px;
  width:100%;max-width:440px;border:1px solid var(--border);text-align:center;
}
.nf-icon{font-size:56px;margin-bottom:12px}
.nf-title{font-size:20px;font-weight:800;margin-bottom:10px}
.nf-msg{font-size:15px;color:var(--text2);line-height:1.5;margin-bottom:24px}

/* ── Back button ── */
.btn-back{
  position:fixed;top:20px;left:20px;z-index:50;
  background:rgba(19,21,30,.85);border:1px solid var(--border);
  color:var(--text2);padding:10px 16px;border-radius:12px;
  font-size:14px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;gap:6px;
  -webkit-tap-highlight-color:transparent;
  min-height:44px;
}
.btn-back:active{background:var(--card)}

/* ── Timer indicator ── */
.auto-reset-bar{
  position:fixed;bottom:0;left:0;right:0;height:3px;
  background:rgba(255,85,0,.2);
}
.auto-reset-fill{
  height:100%;background:var(--acc);
  transition:width linear;
}

/* ── Loading overlay ── */
.loading-overlay{
  position:fixed;inset:0;background:rgba(13,15,23,.7);
  z-index:300;display:none;align-items:center;justify-content:center;
  flex-direction:column;gap:16px;
}
.loading-overlay.show{display:flex}
.spinner{
  width:48px;height:48px;border:3px solid var(--border);
  border-top-color:var(--acc);border-radius:50%;
  animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Error msg ── */
.error-msg{
  font-size:13px;color:var(--red);text-align:center;
  margin-top:8px;min-height:18px;
}
</style>
</head>
<body>

<button class="btn-back" onclick="goTotem()">&#8592; Voltar</button>

<!-- ── Tela de busca ── -->
<div class="screen" id="screen-busca">
  <div class="logo-area">
    <div class="logo-icon">&#x2615;</div>
    <div class="logo-name">Café Comunhão</div>
    <div class="logo-sub">Programa de Fidelidade</div>
  </div>

  <div class="cpf-card">
    <div class="cpf-label">Informe seu CPF para ver seus pontos</div>
    <div class="cpf-display" id="cpf-display">
      <span class="cpf-placeholder">___.___.___-__</span>
    </div>
    <div class="numpad" id="numpad"></div>
    <button class="btn-ver" id="btn-ver" onclick="buscarPontos()" disabled>Ver meus pontos &#8594;</button>
    <div class="error-msg" id="error-msg"></div>
  </div>
</div>

<!-- ── Tela de resultado ── -->
<div class="screen hidden" id="screen-resultado">
  <div class="result-card" id="result-card">
    <!-- preenchido dinamicamente -->
  </div>
</div>

<!-- ── Tela não encontrado ── -->
<div class="screen hidden" id="screen-nao-encontrado">
  <div class="not-found-card">
    <div class="nf-icon">&#128533;</div>
    <div class="nf-title">CPF não cadastrado</div>
    <div class="nf-msg">
      Peça ao atendente para criar seu cadastro e comece a acumular pontos a cada compra!
    </div>
    <button class="btn-voltar-res" onclick="resetar()">&#8592; Tentar novamente</button>
  </div>
</div>

<!-- Barra de auto-reset -->
<div class="auto-reset-bar"><div class="auto-reset-fill" id="reset-bar" style="width:0%"></div></div>

<!-- Loading overlay -->
<div class="loading-overlay" id="loading-overlay">
  <div class="spinner"></div>
  <div style="color:var(--text2);font-size:14px;font-weight:600">Buscando...</div>
</div>

<script>
'use strict';

const API = '/totem/api/fidelidade_consulta.php';
const RESET_TIMEOUT_MS = 10000; // 10s

let cpfDigits    = '';
let resetTimer   = null;
let resetBarAnim = null;

// ── Numpad ──────────────────────────────────────────────────────────────────
(function buildNumpad() {
  const pad  = document.getElementById('numpad');
  const keys = ['1','2','3','4','5','6','7','8','9','','0','DEL'];
  keys.forEach(k => {
    const btn = document.createElement('button');
    btn.className = 'numpad-btn' + (k === '0' ? ' zero' : '') + (k === 'DEL' ? ' del' : '') + (!k ? ' hidden' : '');
    btn.style.visibility = k === '' ? 'hidden' : '';
    btn.innerHTML = k === 'DEL' ? '&#9003;' : k;
    if (k !== '') {
      btn.addEventListener('click', () => {
        btn.classList.add('pressed');
        setTimeout(() => btn.classList.remove('pressed'), 120);
        k === 'DEL' ? delDigit() : addDigit(k);
      });
    }
    pad.appendChild(btn);
  });
})();

function addDigit(d) {
  if (cpfDigits.length >= 11) return;
  cpfDigits += d;
  updateDisplay();
  scheduleReset();
}

function delDigit() {
  cpfDigits = cpfDigits.slice(0, -1);
  updateDisplay();
  clearError();
  if (cpfDigits.length < 11) scheduleReset();
}

function updateDisplay() {
  const el  = document.getElementById('cpf-display');
  const btn = document.getElementById('btn-ver');
  clearError();

  if (!cpfDigits.length) {
    el.innerHTML = '<span class="cpf-placeholder">___.___.___-__</span>';
    el.classList.remove('active', 'error');
    btn.disabled = true;
    return;
  }

  // Formata progressivamente: 000.000.000-00
  let d = cpfDigits;
  let f = '';
  if (d.length <= 3)        f = d;
  else if (d.length <= 6)   f = d.slice(0,3) + '.' + d.slice(3);
  else if (d.length <= 9)   f = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6);
  else                       f = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6,9) + '-' + d.slice(9);

  el.textContent = f;
  el.classList.add('active');
  el.classList.remove('error');
  btn.disabled = cpfDigits.length !== 11;
}

function clearError() {
  const e = document.getElementById('error-msg');
  e.textContent = '';
  document.getElementById('cpf-display').classList.remove('error');
}

function showError(msg) {
  const e   = document.getElementById('error-msg');
  const disp = document.getElementById('cpf-display');
  e.textContent = msg;
  disp.classList.add('error');
  disp.classList.remove('active');
}

// ── Busca pontos ────────────────────────────────────────────────────────────
async function buscarPontos() {
  if (cpfDigits.length !== 11) return;
  stopResetTimer();
  showLoading(true);
  clearError();

  try {
    const r = await fetch(API, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ cpf: cpfDigits }),
    });

    if (r.status === 429) {
      showLoading(false);
      showError('Muitas tentativas. Aguarde um momento.');
      return;
    }

    const d = await r.json();
    showLoading(false);

    if (!d.success) {
      showError(d.error || 'Erro ao consultar. Tente novamente.');
      return;
    }

    if (d.encontrado) {
      renderResultado(d);
      showScreen('screen-resultado');
    } else {
      showScreen('screen-nao-encontrado');
    }

    startResetTimer();

  } catch (e) {
    showLoading(false);
    showError('Erro de conexão. Tente novamente.');
  }
}

function renderResultado(d) {
  const card = document.getElementById('result-card');

  // Nível e progresso (a cada 100 pontos = 1 recompensa)
  const pontos     = d.pontos_atual || 0;
  const meta       = 100; // meta padrão — cada 100 pontos
  const progresso  = Math.min(100, Math.round((pontos % meta) / meta * 100));
  const faltam     = meta - (pontos % meta);
  const valorFalta = d.real_por_ponto ? (faltam * d.real_por_ponto).toFixed(2).replace('.', ',') : null;

  card.innerHTML = `
    <div class="res-greeting">Olá, ${escHtml(d.nome.split(' ')[0])}! &#128075;</div>

    <div class="res-points-box">
      <div class="res-points-label">&#11088; Seus pontos</div>
      <div class="res-points-num">${pontos.toLocaleString('pt-BR')}</div>
      <div class="res-points-unit">pontos acumulados</div>
    </div>

    <div class="res-stats">
      <div class="res-stat">
        <div class="res-stat-val">R$ ${parseFloat(d.total_gasto).toFixed(2).replace('.',',')}</div>
        <div class="res-stat-label">Total gasto</div>
      </div>
      <div class="res-stat">
        <div class="res-stat-val">${d.total_pedidos}</div>
        <div class="res-stat-label">${d.total_pedidos === 1 ? 'pedido' : 'pedidos'}</div>
      </div>
    </div>

    <div class="progress-section">
      <div class="progress-label">
        <span>Progresso para próxima recompensa</span>
        <span>${pontos % meta}/${meta} pts</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" id="progress-fill" style="width:0%"></div>
      </div>
      ${faltam < meta
        ? `<div class="progress-tip">Faltam ${faltam} pontos${valorFalta ? ' (aprox. R$ ' + valorFalta + ')' : ''} para sua próxima recompensa</div>`
        : `<div class="progress-tip">Continue comprando para acumular mais pontos!</div>`
      }
    </div>

    <button class="btn-voltar-res" onclick="resetar()">&#8592; Consultar outro CPF</button>
  `;

  // Anima barra de progresso
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      const fill = document.getElementById('progress-fill');
      if (fill) fill.style.width = progresso + '%';
    });
  });
}

// ── Telas ────────────────────────────────────────────────────────────────────
function showScreen(id) {
  ['screen-busca','screen-resultado','screen-nao-encontrado'].forEach(s => {
    const el = document.getElementById(s);
    if (s === id) el.classList.remove('hidden');
    else          el.classList.add('hidden');
  });
}

// ── Auto-reset ───────────────────────────────────────────────────────────────
function startResetTimer() {
  stopResetTimer();
  const bar = document.getElementById('reset-bar');
  bar.style.transition = 'none';
  bar.style.width = '100%';

  // Força reflow
  bar.getBoundingClientRect();

  bar.style.transition = `width ${RESET_TIMEOUT_MS}ms linear`;
  bar.style.width = '0%';

  resetTimer = setTimeout(() => {
    resetar();
  }, RESET_TIMEOUT_MS);
}

function stopResetTimer() {
  if (resetTimer) { clearTimeout(resetTimer); resetTimer = null; }
  const bar = document.getElementById('reset-bar');
  bar.style.transition = 'none';
  bar.style.width = '0%';
}

// ── Reset ────────────────────────────────────────────────────────────────────
function resetar() {
  stopResetTimer();
  cpfDigits = '';
  updateDisplay();
  clearError();
  showScreen('screen-busca');
}

function scheduleReset() {
  // Reseta inatividade (parcial) apenas na tela de busca
  if (!document.getElementById('screen-busca').classList.contains('hidden')) {
    stopResetTimer();
    // Reseta para tela inicial após 30s sem digitar nada
    resetTimer = setTimeout(resetar, 30000);
  }
}

// ── Voltar ao totem ───────────────────────────────────────────────────────────
function goTotem() {
  window.location.href = '/totem/';
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showLoading(v) {
  document.getElementById('loading-overlay').classList.toggle('show', v);
}

// Inicializa
updateDisplay();
</script>
</body>
</html>

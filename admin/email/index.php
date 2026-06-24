<?php
/**
 * admin/email/index.php — Configuração de e-mail e relatório semanal automático.
 * Dark theme idêntico ao restante do painel.
 */
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once __DIR__ . '/../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = htmlspecialchars($_SESSION['admin_nome'] ?? '');
$adminRole = htmlspecialchars($_SESSION['admin_role'] ?? 'operador');

// Monta a URL de prévia (precisa do token vindo da API)
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$previewBase = $scheme . '://' . $host . '/totem/scripts/relatorio_semanal.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<?php csrfMeta(); ?>
<title>E-mail & Relatório Semanal — Café Comunhão</title>
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

/* LAYOUT */
.content{max-width:960px;margin:0 auto;padding:28px 24px}
.page-header{margin-bottom:28px}
.page-header h1{font-size:22px;font-weight:800;display:flex;align-items:center;gap:10px}
.page-header p{color:var(--text3);font-size:13px;margin-top:5px}

/* CARDS */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:15px 20px;border-bottom:1px solid var(--border)}
.card-head h2{font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}
.card-body{padding:22px 20px}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}
.form-full{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:5px}
.field label{font-size:12px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
.field input,.field select{background:var(--card2);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:9px 12px;outline:none;transition:border-color .15s;width:100%}
.field input:focus,.field select:focus{border-color:var(--acc)}
.field input[disabled]{opacity:.5;cursor:not-allowed}
.field-hint{font-size:11px;color:var(--text3);margin-top:2px}

/* TOGGLE */
.toggle-row{display:flex;align-items:center;gap:12px;margin:4px 0}
.toggle{position:relative;display:inline-flex;width:44px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-slider{position:absolute;inset:0;background:var(--card2);border:1px solid var(--border2);border-radius:12px;cursor:pointer;transition:.2s}
.toggle-slider::before{content:'';position:absolute;left:3px;top:3px;width:16px;height:16px;background:var(--text3);border-radius:50%;transition:.2s}
.toggle input:checked + .toggle-slider{background:var(--acc);border-color:var(--acc)}
.toggle input:checked + .toggle-slider::before{transform:translateX(20px);background:#fff}
.toggle-label{font-size:13px;font-weight:600;color:var(--text2)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;height:38px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover:not(:disabled){background:var(--acc-l)}
.btn-secondary{background:var(--card2);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover:not(:disabled){color:var(--text);border-color:var(--text3)}
.btn-green{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.35);color:var(--green)}
.btn-green:hover:not(:disabled){background:rgba(34,197,94,.25)}
.btn-blue{background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.35);color:var(--blue)}
.btn-blue:hover:not(:disabled){background:rgba(59,130,246,.25)}
.btn-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--border)}

/* TABLE */
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:9px 14px;color:var(--text3);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle;color:var(--text)}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.015)}

/* BADGES */
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase}
.badge-ok{background:rgba(34,197,94,.15);color:var(--green)}
.badge-err{background:rgba(239,68,68,.15);color:var(--red)}

/* PREVIEW IFRAME */
.preview-wrap{border:1px solid var(--border2);border-radius:12px;overflow:hidden;background:#fff;margin-top:14px}
.preview-wrap iframe{width:100%;height:580px;border:none;display:block}

/* TOAST */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;background:var(--card2);border:1px solid var(--border2);border-radius:10px;font-size:13px;font-weight:500;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none;max-width:380px}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}
#toast.info{border-color:rgba(59,130,246,.4);color:var(--blue)}

/* SPINNER */
.spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:currentColor;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px}
@keyframes spin{to{transform:rotate(360deg)}}

/* EMPTY */
.empty{text-align:center;padding:36px;color:var(--text3);font-size:13px}

@media(max-width:620px){
  .form-grid{grid-template-columns:1fr}
  .form-full{grid-column:1}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="../">← Admin</a>
  <span style="color:var(--border2)">|</span>
  <span class="topbar-title">✉️ E-mail & Relatório</span>
  <div class="topbar-right">
    <span class="topbar-user"><?= $adminNome ?> · <?= $adminRole ?></span>
  </div>
</div>

<div class="content">

  <div class="page-header">
    <h1>✉️ E-mail & Relatório Semanal</h1>
    <p>Configure o SMTP e o envio automático do relatório semanal da cafeteria.</p>
  </div>

  <!-- ═══════════════════════════════════════════════════════
       SEÇÃO 1: CONFIGURAÇÃO SMTP
  ════════════════════════════════════════════════════════ -->
  <div class="card">
    <div class="card-head">
      <h2>⚙️ Configuração SMTP</h2>
      <div class="toggle-row">
        <label class="toggle">
          <input type="checkbox" id="toggle-ativo" onchange="toggleAtivo(this)">
          <span class="toggle-slider"></span>
        </label>
        <span class="toggle-label" id="toggle-label">Desativado</span>
      </div>
    </div>
    <div class="card-body">
      <form id="form-smtp" onsubmit="salvarConfig(event)">

        <div class="form-grid">

          <div class="field">
            <label>Servidor SMTP (Host)</label>
            <input type="text" id="smtp-host" placeholder="smtp.gmail.com" autocomplete="off">
            <span class="field-hint">Ex: smtp.gmail.com, smtp.office365.com</span>
          </div>

          <div class="field">
            <label>Porta</label>
            <select id="smtp-port">
              <option value="587">587 — STARTTLS (recomendado)</option>
              <option value="465">465 — SSL/TLS</option>
              <option value="25">25 — Sem TLS</option>
            </select>
          </div>

          <div class="field">
            <label>Usuário / Login</label>
            <input type="text" id="smtp-user" placeholder="seu@email.com" autocomplete="off">
          </div>

          <div class="field">
            <label>Senha</label>
            <input type="password" id="smtp-pass" placeholder="Deixe em branco para manter a atual" autocomplete="new-password">
            <span class="field-hint">Deixe em branco para não alterar a senha salva.</span>
          </div>

          <div class="field">
            <label>E-mail Remetente (De:)</label>
            <input type="email" id="smtp-from" placeholder="noreply@cafecomunhao.com">
          </div>

          <div class="field">
            <label>Nome do Remetente</label>
            <input type="text" id="from-nome" placeholder="Café Comunhão">
          </div>

          <div class="field form-full" style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px">
            <label>E-mail de Destino do Relatório</label>
            <input type="email" id="destino" placeholder="gerente@cafecomunhao.com">
            <span class="field-hint">Para onde o relatório semanal será enviado.</span>
          </div>

          <div class="field">
            <label>Dia de Envio</label>
            <select id="dia-semana">
              <option value="1">Segunda-feira</option>
              <option value="2">Terça-feira</option>
              <option value="3">Quarta-feira</option>
              <option value="4">Quinta-feira</option>
              <option value="5">Sexta-feira</option>
              <option value="6">Sábado</option>
              <option value="7">Domingo</option>
            </select>
          </div>

          <div class="field">
            <label>Hora de Envio</label>
            <select id="hora-envio">
              <?php for ($h = 0; $h <= 23; $h++): ?>
              <option value="<?= str_pad((string)$h, 2, '0', STR_PAD_LEFT) ?>"><?= str_pad((string)$h, 2, '0', STR_PAD_LEFT) ?>:00</option>
              <?php endfor; ?>
            </select>
          </div>

        </div><!-- .form-grid -->

        <div class="btn-row">
          <button type="submit" class="btn btn-primary" id="btn-salvar">
            💾 Salvar Configurações
          </button>
          <button type="button" class="btn btn-secondary" id="btn-testar" onclick="testarEmail()">
            📤 Enviar Teste Agora
          </button>
        </div>

      </form>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════
       SEÇÃO 2: PRÉVIA E ENVIO MANUAL
  ════════════════════════════════════════════════════════ -->
  <div class="card">
    <div class="card-head">
      <h2>📊 Prévia do Relatório</h2>
    </div>
    <div class="card-body">
      <p style="color:var(--text3);font-size:13px;margin-bottom:16px">
        Visualize o e-mail gerado com os dados reais da semana anterior, ou dispare o envio manualmente.
      </p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-blue" id="btn-preview" onclick="verPrevia()">
          👁️ Ver Prévia do Relatório
        </button>
        <button class="btn btn-green" id="btn-enviar-agora" onclick="enviarAgora()">
          🚀 Enviar Relatório Agora
        </button>
      </div>
      <div id="preview-container" style="display:none">
        <div class="preview-wrap">
          <iframe id="preview-frame" title="Prévia do relatório semanal"></iframe>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════
       SEÇÃO 3: LOG DE ENVIOS
  ════════════════════════════════════════════════════════ -->
  <div class="card">
    <div class="card-head">
      <h2>📋 Log de Envios</h2>
      <button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:12px" onclick="carregarConfig()">↺ Atualizar</button>
    </div>
    <div id="log-container">
      <div class="empty">Carregando…</div>
    </div>
  </div>

</div><!-- .content -->

<div id="toast"></div>

<script>
// ── Helpers ──────────────────────────────────────────────────────────────────
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function toast(msg, tipo = 'ok', dur = 4000) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + tipo;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.className = '', dur);
}

async function api(action, body = null) {
  const opts = {
    method: body ? 'POST' : 'GET',
    headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/json' },
  };
  if (body) opts.body = JSON.stringify(body);
  const url = body
    ? '../api/email.php'
    : `../api/email.php?action=${action}`;
  const r = await fetch(url, opts);
  return r.json();
}

function setBtnLoading(id, loading, txt) {
  const btn = document.getElementById(id);
  if (!btn) return;
  btn.disabled = loading;
  btn.innerHTML = loading ? `<span class="spin"></span>${txt}` : btn.dataset.orig ?? txt;
}

// ── Carrega configurações ─────────────────────────────────────────────────────
async function carregarConfig() {
  try {
    const res = await api('config');
    if (!res.success) { toast('Erro ao carregar: ' + res.error, 'err'); return; }
    const d = res.data;

    document.getElementById('smtp-host').value  = d.smtp_host  ?? '';
    document.getElementById('smtp-port').value  = d.smtp_port  ?? '587';
    document.getElementById('smtp-user').value  = d.smtp_user  ?? '';
    document.getElementById('smtp-from').value  = d.smtp_from  ?? '';
    document.getElementById('from-nome').value  = d.from_nome  ?? 'Café Comunhão';
    document.getElementById('destino').value    = d.destino    ?? '';
    document.getElementById('dia-semana').value = d.dia_semana ?? '1';
    document.getElementById('hora-envio').value = d.hora       ?? '08';

    const ck = document.getElementById('toggle-ativo');
    ck.checked = d.ativo === true;
    document.getElementById('toggle-label').textContent = d.ativo ? 'Ativado' : 'Desativado';

    renderLog(res.logs ?? []);
  } catch(e) {
    toast('Erro de rede: ' + e.message, 'err');
  }
}

function toggleAtivo(el) {
  document.getElementById('toggle-label').textContent = el.checked ? 'Ativado' : 'Desativado';
}

// ── Salvar configurações ──────────────────────────────────────────────────────
async function salvarConfig(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-salvar');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>Salvando…';

  const payload = {
    action:      'salvar_config',
    smtp_host:   document.getElementById('smtp-host').value.trim(),
    smtp_port:   document.getElementById('smtp-port').value,
    smtp_user:   document.getElementById('smtp-user').value.trim(),
    smtp_pass:   document.getElementById('smtp-pass').value,
    smtp_from:   document.getElementById('smtp-from').value.trim(),
    from_nome:   document.getElementById('from-nome').value.trim(),
    destino:     document.getElementById('destino').value.trim(),
    ativo:       document.getElementById('toggle-ativo').checked,
    dia_semana:  document.getElementById('dia-semana').value,
    hora:        document.getElementById('hora-envio').value,
  };

  try {
    const res = await api(null, payload);
    toast(res.success ? '✅ ' + res.message : '❌ ' + res.error, res.success ? 'ok' : 'err');
    if (res.success) {
      document.getElementById('smtp-pass').value = ''; // limpa campo senha
    }
  } catch(ex) {
    toast('Erro de rede: ' + ex.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Testar e-mail ─────────────────────────────────────────────────────────────
async function testarEmail() {
  const btn = document.getElementById('btn-testar');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>Enviando teste…';

  try {
    const res = await api(null, { action: 'testar' });
    toast(res.success ? '✅ ' + res.message : '❌ ' + (res.error ?? 'Falha no envio.'), res.success ? 'ok' : 'err');
  } catch(ex) {
    toast('Erro de rede: ' + ex.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Ver prévia ────────────────────────────────────────────────────────────────
async function verPrevia() {
  // Busca o token salvo nas configs (a API não expõe a string, só se existe)
  // Solicita via endpoint dedicado que retorne a URL de prévia
  const btn = document.getElementById('btn-preview');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>Carregando…';

  try {
    // Solicita que a API gere/retorne a URL de prévia
    const res = await api(null, { action: 'preview_url' });

    let previewUrl;
    if (res.success && res.url) {
      previewUrl = res.url;
    } else {
      // Fallback: tenta com token informado manualmente
      const tk = prompt('Digite o token secreto do relatório (configurado no painel):');
      if (!tk) { btn.disabled = false; btn.innerHTML = orig; return; }
      previewUrl = `<?= htmlspecialchars($previewBase, ENT_QUOTES) ?>?token=${encodeURIComponent(tk)}&preview=1`;
    }

    const container = document.getElementById('preview-container');
    const frame = document.getElementById('preview-frame');
    frame.src = previewUrl;
    container.style.display = 'block';
    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch(ex) {
    toast('Erro: ' + ex.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Enviar agora ──────────────────────────────────────────────────────────────
async function enviarAgora() {
  if (!confirm('Deseja enviar o relatório semanal agora?\nO e-mail será enviado imediatamente para o destinatário configurado.')) return;

  const btn = document.getElementById('btn-enviar-agora');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>Enviando…';

  try {
    const res = await api(null, { action: 'enviar_agora' });
    toast(res.success ? '✅ ' + (res.message ?? 'Relatório enviado!') : '❌ ' + (res.error ?? 'Falha.'), res.success ? 'ok' : 'err', 6000);
    if (res.success) setTimeout(carregarConfig, 1500); // recarrega log
  } catch(ex) {
    toast('Erro de rede: ' + ex.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Renderiza tabela de log ────────────────────────────────────────────────────
function renderLog(logs) {
  const el = document.getElementById('log-container');
  if (!logs || logs.length === 0) {
    el.innerHTML = '<div class="empty">Nenhum envio registrado ainda.</div>';
    return;
  }

  const rows = logs.map(l => {
    const dt = l.enviado_em ? new Date(l.enviado_em).toLocaleString('pt-BR') : '—';
    const periodo = (l.periodo_ini && l.periodo_fim)
      ? formatDate(l.periodo_ini) + ' – ' + formatDate(l.periodo_fim)
      : '—';
    const badge = l.status === 'enviado'
      ? '<span class="badge badge-ok">✓ Enviado</span>'
      : '<span class="badge badge-err">✗ Erro</span>';
    const erro = l.mensagem ? `<br><span style="color:var(--red);font-size:11px">${escHtml(l.mensagem)}</span>` : '';
    return `<tr>
      <td>${dt}</td>
      <td>${escHtml(l.destinatario)}</td>
      <td>${periodo}</td>
      <td>${badge}${erro}</td>
    </tr>`;
  }).join('');

  el.innerHTML = `<table>
    <thead>
      <tr>
        <th>Data/Hora</th>
        <th>Destinatário</th>
        <th>Período</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>`;
}

function formatDate(d) {
  if (!d) return '—';
  const [y, m, day] = d.split('-');
  return `${day}/${m}/${y}`;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Inicializa ────────────────────────────────────────────────────────────────
carregarConfig();
</script>
</body>
</html>

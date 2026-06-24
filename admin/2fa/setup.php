<?php
// Página de configuração 2FA do admin logado
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../../config/session_guard.php';
require_once '../../config/csrf.php';
require_once '../../config/db.php';
require_once '../../config/totp.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT nome, email, totp_secret, totp_ativo FROM totem_admin WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$adm  = $stmt->fetch();
$ativo = (bool)($adm['totp_ativo'] ?? false);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Configurar 2FA — Café Comunhão</title>
<?php csrfMeta(); ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;
}
html,body{min-height:100%;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  background:radial-gradient(ellipse at 20% 40%,rgba(255,85,0,.07) 0%,transparent 60%)}
.box{width:480px;background:var(--surf);border:1px solid var(--border2);border-radius:20px;
  padding:40px;display:flex;flex-direction:column;gap:24px;box-shadow:0 24px 80px rgba(0,0,0,.6)}
.logo{text-align:center}
.logo h1{font-size:22px;font-weight:900;color:var(--acc)}
.logo p{color:var(--text2);font-size:13px;margin-top:4px}
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
  border-radius:999px;font-size:13px;font-weight:700}
.status-on{background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.2)}
.status-off{background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.field{display:flex;flex-direction:column;gap:7px}
.field label{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.field input{padding:12px 14px;background:var(--card);border:1px solid var(--border2);
  border-radius:10px;color:var(--text);font-family:inherit;font-size:14px;outline:none;transition:border-color .15s}
.field input:focus{border-color:var(--acc)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 20px;
  border-radius:10px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;border:none}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{background:var(--acc-l);transform:translateY(-1px)}
.btn-secondary{background:var(--card);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text);border-color:var(--text3)}
.btn-danger{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:var(--red);color:#fff}
.msg{padding:11px 14px;border-radius:10px;font-size:13px}
.msg-ok{background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.25)}
.msg-err{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.25)}
.qr-wrap{display:flex;flex-direction:column;align-items:center;gap:12px;padding:16px;
  background:var(--card);border:1px solid var(--border);border-radius:14px}
.qr-wrap img{border-radius:8px;background:#fff;padding:4px}
.secret-mono{font-family:monospace;font-size:14px;font-weight:700;letter-spacing:2px;
  background:var(--card);border:1px solid var(--border2);padding:8px 14px;border-radius:8px;
  color:var(--gold);text-align:center}
.divider{height:1px;background:var(--border);margin:4px 0}
.back{display:inline-flex;align-items:center;gap:6px;color:var(--text3);
  text-decoration:none;font-size:13px;transition:color .15s}
.back:hover{color:var(--text2)}
#section-gerar,#section-ativar,#section-desativar{display:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="box">

    <div class="logo">
      <h1>Café Comunhão</h1>
      <p>Autenticação em dois fatores (2FA)</p>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:600;color:var(--text2)">Status atual:</div>
      <span class="status-badge <?= $ativo ? 'status-on' : 'status-off' ?>" id="status-badge">
        <?= $ativo ? '🔒 Ativo' : '🔓 Inativo' ?>
      </span>
    </div>

    <div id="msg-area" style="display:none"></div>

    <?php if (!$ativo): ?>
    <!-- ── Ativar 2FA ───────────────────────────────────────────────── -->
    <div>
      <p style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:16px">
        Com o 2FA ativado, você precisará digitar um código do
        <strong>Google Authenticator</strong> (ou app compatível) a cada login.
      </p>
      <button class="btn btn-primary" id="btn-gerar" onclick="gerarSecret()" style="width:100%">
        🔒 Configurar 2FA agora
      </button>
    </div>

    <!-- step 2: exibe QR + campo para confirmar -->
    <div id="section-gerar" style="display:none">
      <div class="qr-wrap" id="qr-wrap"></div>
      <div class="field" style="margin-top:16px">
        <label>Código do app (6 dígitos)</label>
        <input type="text" id="code-ativar" maxlength="6" placeholder="000000"
               inputmode="numeric" autocomplete="one-time-code">
      </div>
      <button class="btn btn-primary" onclick="ativar2FA()" style="width:100%;margin-top:12px">
        Confirmar e ativar →
      </button>
    </div>

    <?php else: ?>
    <!-- ── Desativar 2FA ────────────────────────────────────────────── -->
    <div>
      <p style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:16px">
        Para desativar, confirme com um código atual do seu aplicativo autenticador.
      </p>
      <button class="btn btn-danger" onclick="document.getElementById('section-desativar').style.display='flex'" style="width:100%">
        Desativar 2FA
      </button>
    </div>

    <div id="section-desativar" style="display:none;flex-direction:column;gap:12px">
      <div class="field">
        <label>Código do app (6 dígitos)</label>
        <input type="text" id="code-desativar" maxlength="6" placeholder="000000"
               inputmode="numeric" autocomplete="one-time-code">
      </div>
      <button class="btn btn-danger" onclick="desativar2FA()" style="width:100%">
        Confirmar desativação
      </button>
    </div>
    <?php endif; ?>

    <div class="divider"></div>
    <a href="../index.php" class="back">← Voltar ao painel</a>

  </div>
</div>

<script>
'use strict';
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API  = '../api/2fa.php';

function showMsg(text, type='ok') {
  const el = document.getElementById('msg-area');
  el.style.display = 'block';
  el.className = 'msg ' + (type === 'ok' ? 'msg-ok' : 'msg-err');
  el.textContent = text;
}

async function gerarSecret() {
  document.getElementById('btn-gerar').disabled = true;
  document.getElementById('btn-gerar').textContent = '⏳ Gerando...';

  const res = await fetch(API, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token': CSRF},
    body: JSON.stringify({action: 'gerar'}),
  });
  const d = await res.json();

  document.getElementById('btn-gerar').style.display = 'none';

  if (!d.success) { showMsg(d.error || 'Erro ao gerar secret.', 'err'); return; }

  const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(d.uri);
  document.getElementById('qr-wrap').innerHTML =
    '<p style="font-size:12px;color:var(--text2);font-weight:600">Escaneie com seu app autenticador:</p>' +
    '<img src="' + qrUrl + '" width="200" height="200" alt="QR Code 2FA">' +
    '<p style="font-size:12px;color:var(--text2)">Ou insira o código manualmente:</p>' +
    '<div class="secret-mono">' + d.secret + '</div>';

  document.getElementById('section-gerar').style.display = 'block';
}

async function ativar2FA() {
  const code = document.getElementById('code-ativar').value.trim();
  if (!/^\d{6}$/.test(code)) { showMsg('Digite um código de 6 dígitos.', 'err'); return; }

  const res = await fetch(API, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token': CSRF},
    body: JSON.stringify({action: 'ativar', code}),
  });
  const d = await res.json();

  if (d.success) {
    showMsg(d.message || '2FA ativado!', 'ok');
    document.getElementById('status-badge').className = 'status-badge status-on';
    document.getElementById('status-badge').textContent = '🔒 Ativo';
    setTimeout(() => window.location.reload(), 1500);
  } else {
    showMsg(d.error || 'Código inválido.', 'err');
  }
}

async function desativar2FA() {
  const code = document.getElementById('code-desativar').value.trim();
  if (!/^\d{6}$/.test(code)) { showMsg('Digite um código de 6 dígitos.', 'err'); return; }

  const res = await fetch(API, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token': CSRF},
    body: JSON.stringify({action: 'desativar', code}),
  });
  const d = await res.json();

  if (d.success) {
    showMsg(d.message || '2FA desativado.', 'ok');
    document.getElementById('status-badge').className = 'status-badge status-off';
    document.getElementById('status-badge').textContent = '🔓 Inativo';
    setTimeout(() => window.location.reload(), 1500);
  } else {
    showMsg(d.error || 'Código inválido.', 'err');
  }
}
</script>
</body>
</html>

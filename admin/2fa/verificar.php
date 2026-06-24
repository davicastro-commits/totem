<?php
/**
 * Verificação 2FA intermediária após login bem-sucedido.
 * $_SESSION['_2fa_pending'] deve conter o admin_id aguardando confirmação.
 */
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) session_start();

// Se não há pending, redireciona
if (empty($_SESSION['_2fa_pending'])) {
    header('Location: ../index.php');
    exit;
}

// Se já está logado normalmente, redireciona para o painel
if (!empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once '../../config/db.php';
require_once '../../config/totp.php';
require_once '../../config/csrf.php';
require_once '../../config/audit.php';

$err  = '';
$nome = $_SESSION['_2fa_nome'] ?? 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');

    $db   = getDB();
    $stmt = $db->prepare("SELECT totp_secret, totp_ativo FROM totem_admin WHERE id = ?");
    $stmt->execute([$_SESSION['_2fa_pending']]);
    $adm  = $stmt->fetch();

    if ($adm && $adm['totp_ativo'] && totpVerify($adm['totp_secret'], $code)) {
        // Autenticação completa — promove sessão
        $adminId = (int)$_SESSION['_2fa_pending'];

        // Limpa flags 2FA pendentes
        unset($_SESSION['_2fa_pending'], $_SESSION['_2fa_nome'], $_SESSION['_2fa_email'], $_SESSION['_2fa_role']);

        // Recarrega dados do admin
        $stmt2 = $db->prepare("SELECT id, nome, email, role FROM totem_admin WHERE id = ?");
        $stmt2->execute([$adminId]);
        $full  = $stmt2->fetch();

        session_regenerate_id(true);
        $_SESSION['admin_id']        = $full['id'];
        $_SESSION['admin_nome']      = $full['nome'];
        $_SESSION['admin_email']     = $full['email'];
        $_SESSION['admin_role']      = $full['role'] ?? 'operador';
        $_SESSION['_last_activity']  = time();

        auditLog($db, '2fa_verificar', 'auth', $adminId, '2FA verificado — login completo');

        header('Location: ../index.php');
        exit;
    }

    $err = 'Código inválido ou expirado. Tente novamente.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Verificação 2FA — Café Comunhão</title>
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
.box{width:400px;background:var(--surf);border:1px solid var(--border2);border-radius:20px;
  padding:44px;display:flex;flex-direction:column;gap:24px;box-shadow:0 24px 80px rgba(0,0,0,.6)}
.logo{text-align:center}
.logo h1{font-size:24px;font-weight:900;color:var(--acc)}
.logo p{color:var(--text2);font-size:13px;margin-top:6px}
.icon-2fa{text-align:center;font-size:48px;margin-bottom:4px}
.field{display:flex;flex-direction:column;gap:7px}
.field label{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.code-input{padding:16px;background:var(--card);border:1px solid var(--border2);
  border-radius:12px;color:var(--text);font-family:monospace;font-size:28px;font-weight:700;
  outline:none;transition:border-color .15s;text-align:center;letter-spacing:8px;width:100%}
.code-input:focus{border-color:var(--acc)}
.btn-submit{padding:14px;background:var(--acc);color:#fff;border:none;border-radius:10px;
  font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s;width:100%}
.btn-submit:hover{background:var(--acc-l);transform:translateY(-1px)}
.err-msg{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.25);
  padding:11px 14px;border-radius:10px;font-size:13px}
.hint{font-size:12px;color:var(--text3);text-align:center;line-height:1.5}
.back{display:inline-flex;align-items:center;justify-content:center;gap:5px;
  color:var(--text3);text-decoration:none;font-size:12px;transition:color .15s;text-align:center}
.back:hover{color:var(--text2)}
</style>
</head>
<body>
<div class="wrap">
  <div class="box">

    <div class="logo">
      <h1>Café Comunhão</h1>
      <p>Verificação em dois fatores</p>
    </div>

    <div class="icon-2fa">🔐</div>

    <p style="text-align:center;font-size:14px;color:var(--text2)">
      Olá, <strong style="color:var(--text)"><?= htmlspecialchars($nome) ?></strong>!<br>
      Abra seu aplicativo autenticador e insira o código de 6 dígitos.
    </p>

    <?php if ($err): ?>
    <div class="err-msg"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="field">
        <label>Código do autenticador</label>
        <input type="text" name="code" class="code-input" maxlength="6"
               placeholder="000000" inputmode="numeric" autofocus
               autocomplete="one-time-code" required pattern="\d{6}">
      </div>
      <button type="submit" class="btn-submit" style="margin-top:16px">Verificar →</button>
    </form>

    <p class="hint">
      O código muda a cada 30 segundos.<br>
      Certifique-se de que o horário do dispositivo está correto.
    </p>

    <a href="../index.php?logout" class="back">← Cancelar e voltar ao login</a>

  </div>
</div>
</body>
</html>

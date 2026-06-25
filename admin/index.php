<?php
// Hardening de sessão antes de session_start()
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../config/session_guard.php';

require_once '../config/db.php';
require_once '../config/audit.php';
require_once '../config/csrf.php';
require_once '../config/rate_limit.php';

// ── Logout ────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
  try {
    $db = getDB();
    auditLog($db, 'logout', 'auth', null, 'Logout');
    $db->prepare("UPDATE totem_sessoes SET ativa=FALSE, logout_em=NOW() WHERE admin_id=? AND ativa=TRUE")
      ->execute([$_SESSION['admin_id'] ?? 0]);
  } catch (Throwable) {
  }
  session_destroy();
  header('Location: index.php');
  exit;
}

// ── Login ─────────────────────────────────────────────────────────────
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
  $email = trim($_POST['email'] ?? '');
  $senha = $_POST['senha'] ?? '';
  $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

  try {
    $db = getDB();
    loginVerificarBloqueio($db, $ip);

    $stmt = $db->prepare("SELECT id, nome, email, senha, role, ativo FROM totem_admin WHERE email = ?");
    $stmt->execute([$email]);
    $adm = $stmt->fetch();

    if ($adm && $adm['ativo'] && password_verify($senha, $adm['senha'])) {
      loginLimparTentativas($db, $ip);
      session_regenerate_id(true); // Previne session fixation
      $_SESSION['_csrf'] = bin2hex(random_bytes(32)); // Novo token após login

      $db->prepare("UPDATE totem_admin SET ultimo_login=NOW() WHERE id=?")->execute([$adm['id']]);
      $db->prepare("INSERT INTO totem_sessoes (admin_id, ip, user_agent) VALUES (?,?,?)")
        ->execute([$adm['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? null]);
      auditLog($db, 'login', 'auth', $adm['id'], "Login: {$adm['email']} IP:{$ip}");

      // Se admin tem 2FA ativo, pedir código antes de entrar
      if (!empty($adm['totp_ativo'])) {
        $_SESSION['_2fa_pending']  = $adm['id'];
        $_SESSION['_2fa_nome']     = $adm['nome'];
        $_SESSION['_2fa_email']    = $adm['email'];
        $_SESSION['_2fa_role']     = $adm['role'] ?? 'operador';
        header('Location: 2fa/verificar.php');
        exit;
      }

      $_SESSION['admin_id']    = $adm['id'];
      $_SESSION['admin_nome']  = $adm['nome'];
      $_SESSION['admin_email'] = $adm['email'];
      $_SESSION['admin_role']  = $adm['role'] ?? 'operador';
      header('Location: index.php');
      exit;
    }

    loginRegistrarFalha($db, $ip, $email);
    $err = $adm && !$adm['ativo'] ? 'Conta desativada.' : 'E-mail ou senha inválidos.';
  } catch (PDOException $e) {
    $err = 'Erro de conexão com o banco.';
  }
}

$loggedIn   = !empty($_SESSION['admin_id']);
$adminNome  = $_SESSION['admin_nome']  ?? '';
$adminEmail = $_SESSION['admin_email'] ?? '';
$adminRole  = $_SESSION['admin_role']  ?? 'operador';
$isAdmin    = $adminRole === 'admin';
$showTimeout = isset($_GET['timeout']);

// Carregar permissões do usuário logado (apenas não-admins)
$myPermissoes = null; // null = admin (acesso total)
if ($loggedIn && !$isAdmin) {
  try {
    $db = getDB();
    $pStmt = $db->prepare("SELECT permissoes FROM totem_admin WHERE id = ?");
    $pStmt->execute([$_SESSION['admin_id']]);
    $pRow = $pStmt->fetch();
    $raw = $pRow['permissoes'] ?? null;
    $myPermissoes = ($raw !== null) ? (json_decode($raw, true) ?: []) : [];
  } catch (Throwable $e) {
    $myPermissoes = [];
  }
}

// Helpers PHP de verificação de permissão (usados na sidebar)
function canSee(string $key): bool
{
  global $isAdmin, $myPermissoes;
  if ($isAdmin || $myPermissoes === null) return true;
  [$g, $k] = explode('.', $key, 2);
  return !empty($myPermissoes[$g][$k]);
}
function canSeeGroup(string $g): bool
{
  global $isAdmin, $myPermissoes;
  if ($isAdmin || $myPermissoes === null) return true;
  if (empty($myPermissoes[$g])) return false;
  return (bool) array_filter($myPermissoes[$g]);
}
function canSeeAny(string ...$keys): bool
{
  foreach ($keys as $k) {
    if (canSee($k)) return true;
  }
  return false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Admin — Café Comunhão</title>
  <?php csrfMeta(); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    :root {
      --bg: #0d0f17;
      --surf: #13151e;
      --card: #1a1c27;
      --card2: #22253a;
      --border: rgba(255, 255, 255, .07);
      --border2: rgba(255, 255, 255, .12);
      --acc: #ff5500;
      --acc-l: #ff7733;
      --acc-gl: rgba(255, 85, 0, .12);
      --green: #22c55e;
      --red: #ef4444;
      --blue: #3b82f6;
      --gold: #f59e0b;
      --purple: #8b5cf6;
      --text: #f0f2f8;
      --text2: #9ca3af;
      --text3: #6b7280;
      --text4: #4b5563;
      --sidebar-w: 240px;
    }

    html,
    body {
      height: 100%;
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased
    }

    /* ── LOGIN ──────────────────────────────────────────────────────────── */
    .login-wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: radial-gradient(ellipse at 20% 40%, rgba(255, 85, 0, .08) 0%, transparent 60%)
    }

    .login-box {
      width: 400px;
      background: var(--surf);
      border: 1px solid var(--border2);
      border-radius: 20px;
      padding: 44px;
      display: flex;
      flex-direction: column;
      gap: 28px;
      box-shadow: 0 24px 80px rgba(0, 0, 0, .6)
    }

    .login-logo {
      text-align: center
    }

    .login-logo h1 {
      font-size: 26px;
      font-weight: 900;
      color: var(--acc)
    }

    .login-logo p {
      color: var(--text2);
      font-size: 14px;
      margin-top: 6px
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 7px
    }

    .field label {
      font-size: 12px;
      font-weight: 700;
      color: var(--text2);
      text-transform: uppercase;
      letter-spacing: .5px
    }

    .field input,
    .field select,
    .field textarea {
      padding: 13px 16px;
      background: var(--card);
      border: 1px solid var(--border2);
      border-radius: 10px;
      color: var(--text);
      font-family: inherit;
      font-size: 14px;
      outline: none;
      transition: border-color .15s
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus {
      border-color: var(--acc)
    }

    .field textarea {
      resize: vertical;
      min-height: 70px
    }

    .field select {
      cursor: pointer
    }

    .field input[type=checkbox] {
      width: 18px;
      height: 18px;
      cursor: pointer
    }

    .err-msg {
      background: rgba(239, 68, 68, .1);
      color: var(--red);
      border: 1px solid rgba(239, 68, 68, .25);
      padding: 11px 14px;
      border-radius: 10px;
      font-size: 13px
    }

    .btn-login {
      padding: 14px;
      background: var(--acc);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-family: inherit;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: all .15s
    }

    .btn-login:hover {
      background: var(--acc-l);
      transform: translateY(-1px)
    }

    /* ── LAYOUT ─────────────────────────────────────────────────────────── */
    .layout {
      display: grid;
      grid-template-columns: var(--sidebar-w) 1fr;
      height: 100vh;
      overflow: hidden
    }

    /* ── SIDEBAR ────────────────────────────────────────────────────────── */
    .sidebar {
      background: var(--surf);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      overflow: hidden
    }

    .sb-brand {
      padding: 20px 18px 16px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0
    }

    .sb-brand h2 {
      font-size: 15px;
      font-weight: 900;
      color: var(--acc)
    }

    .sb-brand p {
      font-size: 11px;
      color: var(--text3);
      margin-top: 2px
    }

    .sb-nav {
      flex: 1;
      overflow-y: auto;
      padding: 10px 10px
    }

    .sb-nav::-webkit-scrollbar {
      width: 0
    }

    .sb-section {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: var(--text4);
      padding: 16px 10px 5px;
      display: flex;
      align-items: center;
      gap: 8px
    }

    .sb-section::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border)
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      transition: all .15s;
      font-size: 13px;
      font-weight: 500;
      color: var(--text2);
      border: none;
      background: transparent;
      width: 100%;
      font-family: inherit;
      text-align: left;
      text-decoration: none;
      line-height: 1.2
    }

    .nav-item:hover {
      background: var(--card);
      color: var(--text)
    }

    .nav-item.active {
      background: var(--acc-gl);
      color: var(--acc);
      font-weight: 600
    }

    .nav-icon {
      width: 16px;
      height: 16px;
      flex-shrink: 0;
      opacity: .6;
      transition: opacity .15s
    }

    .nav-item:hover .nav-icon,
    .nav-item.active .nav-icon {
      opacity: 1
    }

    .nav-badge {
      margin-left: auto;
      background: var(--red);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 999px;
      min-width: 18px;
      text-align: center
    }

    .nav-ext {
      margin-left: auto;
      opacity: .2;
      flex-shrink: 0
    }

    .sb-links {
      display: none
    }

    .sb-user {
      padding: 12px;
      border-top: 1px solid var(--border);
      flex-shrink: 0
    }

    .sb-user-box {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px;
      background: var(--card);
      border-radius: 10px
    }

    .sb-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--acc-gl);
      border: 2px solid var(--acc);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      color: var(--acc);
      flex-shrink: 0
    }

    .sb-user-info {
      flex: 1;
      min-width: 0
    }

    .sb-user-name {
      font-size: 13px;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .sb-user-role {
      font-size: 11px;
      color: var(--text3)
    }

    .sb-logout {
      color: var(--text3);
      text-decoration: none;
      font-size: 12px;
      padding: 4px
    }

    .sb-logout:hover {
      color: var(--red)
    }

    /* ── MAIN ────────────────────────────────────────────────────────────── */
    .main {
      display: flex;
      flex-direction: column;
      overflow: hidden
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      height: 54px;
      background: var(--surf);
      border-bottom: 1px solid var(--border);
      flex-shrink: 0
    }

    .topbar-title {
      font-size: 16px;
      font-weight: 700
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 12px
    }

    .topbar-clock {
      font-size: 14px;
      font-weight: 600;
      color: var(--text2)
    }

    .pulse-dot {
      display: inline-block;
      width: 7px;
      height: 7px;
      background: var(--green);
      border-radius: 50%;
      animation: pulse 2s infinite;
      margin-right: 4px
    }

    @keyframes pulse {

      0%,
      100% {
        opacity: 1
      }

      50% {
        opacity: .3
      }
    }

    .content {
      flex: 1;
      overflow: hidden
    }

    .panel {
      display: none;
      height: 100%;
      overflow-y: auto;
      padding: 24px
    }

    .panel.active {
      display: block
    }

    /* ── COMPONENTS ──────────────────────────────────────────────────────── */
    /* Cards */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: 14px;
      margin-bottom: 22px
    }

    .kpi-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
      position: relative;
      overflow: hidden
    }

    .kpi-card::before {
      content: '';
      position: absolute;
      inset: 0;
      opacity: .04;
      background: var(--c, var(--acc))
    }

    .kpi-label {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--text3);
      margin-bottom: 10px
    }

    .kpi-value {
      font-size: 28px;
      font-weight: 900;
      color: var(--c, var(--acc))
    }

    .kpi-sub {
      font-size: 12px;
      color: var(--text3);
      margin-top: 4px
    }

    /* Tables */
    .data-table-wrap {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      overflow: hidden
    }

    .data-table-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      border-bottom: 1px solid var(--border)
    }

    .data-table-head h3 {
      font-size: 14px;
      font-weight: 700
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px
    }

    .data-table th {
      text-align: left;
      padding: 10px 14px;
      color: var(--text2);
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      border-bottom: 1px solid var(--border)
    }

    .data-table td {
      padding: 11px 14px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle
    }

    .data-table tr:last-child td {
      border-bottom: none
    }

    .data-table tr:hover td {
      background: rgba(255, 255, 255, .015)
    }

    .data-table .price {
      font-weight: 700;
      color: var(--acc-l)
    }

    /* Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 999px;
      text-transform: uppercase;
      letter-spacing: .3px
    }

    .badge-aguardando {
      background: rgba(245, 158, 11, .15);
      color: var(--gold)
    }

    .badge-preparando {
      background: rgba(59, 130, 246, .15);
      color: var(--blue)
    }

    .badge-pronto {
      background: rgba(34, 197, 94, .15);
      color: var(--green)
    }

    .badge-entregue {
      background: rgba(107, 114, 128, .15);
      color: var(--text3)
    }

    .badge-cancelado {
      background: rgba(239, 68, 68, .15);
      color: var(--red)
    }

    .badge-totem {
      background: rgba(139, 92, 246, .15);
      color: var(--purple)
    }

    .badge-caixa {
      background: rgba(245, 158, 11, .15);
      color: var(--gold)
    }

    .badge-admin {
      background: rgba(255, 85, 0, .15);
      color: var(--acc)
    }

    .badge-operador {
      background: rgba(59, 130, 246, .15);
      color: var(--blue)
    }

    .badge-cozinha {
      background: rgba(34, 197, 94, .15);
      color: var(--green)
    }

    /* Toolbar */
    .toolbar {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 16px;
      flex-wrap: wrap
    }

    .toolbar-search {
      display: flex;
      align-items: center;
      background: var(--card);
      border: 1px solid var(--border2);
      border-radius: 9px;
      padding: 0 12px;
      gap: 8px;
      height: 38px;
      flex: 1;
      min-width: 180px
    }

    .toolbar-search input {
      background: transparent;
      border: none;
      outline: none;
      color: var(--text);
      font-size: 13px;
      font-family: inherit;
      width: 100%
    }

    .toolbar-search input::placeholder {
      color: var(--text3)
    }

    .toolbar select,
    .toolbar input[type=date] {
      background: var(--card);
      border: 1px solid var(--border2);
      border-radius: 9px;
      color: var(--text);
      font-family: inherit;
      font-size: 13px;
      padding: 8px 12px;
      outline: none;
      height: 38px;
      cursor: pointer
    }

    .toolbar select:focus,
    .toolbar input[type=date]:focus {
      border-color: var(--acc)
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 9px;
      font-family: inherit;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .15s;
      border: none;
      height: 38px
    }

    .btn-primary {
      background: var(--acc);
      color: #fff
    }

    .btn-primary:hover {
      background: var(--acc-l);
      transform: translateY(-1px)
    }

    .btn-secondary {
      background: var(--card);
      border: 1px solid var(--border2);
      color: var(--text2)
    }

    .btn-secondary:hover {
      color: var(--text);
      border-color: var(--text3)
    }

    .btn-danger {
      background: rgba(239, 68, 68, .1);
      color: var(--red);
      border: 1px solid rgba(239, 68, 68, .2)
    }

    .btn-danger:hover {
      background: var(--red);
      color: #fff
    }

    .btn-sm {
      padding: 5px 12px;
      font-size: 12px;
      height: 30px
    }

    .btn-icon {
      width: 32px;
      height: 32px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px
    }

    /* Toggle */
    .toggle-sw {
      position: relative;
      width: 40px;
      height: 22px;
      cursor: pointer;
      flex-shrink: 0
    }

    .toggle-sw input {
      display: none
    }

    .toggle-track {
      position: absolute;
      inset: 0;
      background: var(--card2);
      border-radius: 999px;
      transition: background .2s
    }

    .toggle-track::after {
      content: '';
      position: absolute;
      width: 16px;
      height: 16px;
      background: #fff;
      border-radius: 50%;
      top: 3px;
      left: 3px;
      transition: transform .2s;
      box-shadow: 0 1px 4px rgba(0, 0, 0, .4)
    }

    input:checked+.toggle-track {
      background: var(--green)
    }

    input:checked+.toggle-track::after {
      transform: translateX(18px)
    }

    /* Grid 2col */
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 16px
    }

    .grid-3 {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 16px;
      margin-bottom: 16px
    }

    /* Section card */
    .section-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      margin-bottom: 16px;
      overflow: hidden
    }

    .section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      border-bottom: 1px solid var(--border)
    }

    .section-head h3 {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--text2)
    }

    .section-body {
      padding: 16px 18px
    }

    /* Chart */
    .chart-wrap {
      position: relative;
      height: 180px
    }

    /* Pagination */
    .pagination {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 14px 18px;
      border-top: 1px solid var(--border);
      justify-content: center
    }

    .page-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1px solid var(--border2);
      background: transparent;
      color: var(--text2);
      font-family: inherit;
      font-size: 13px;
      cursor: pointer;
      transition: all .15s;
      display: flex;
      align-items: center;
      justify-content: center
    }

    .page-btn:hover {
      background: var(--card2);
      color: var(--text)
    }

    .page-btn.active {
      background: var(--acc);
      color: #fff;
      border-color: var(--acc)
    }

    /* Modal */
    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .7);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      backdrop-filter: blur(4px)
    }

    .overlay.open {
      display: flex
    }

    .modal {
      background: var(--surf);
      border: 1px solid var(--border2);
      border-radius: 18px;
      padding: 32px;
      width: 500px;
      max-width: 95vw;
      max-height: 90vh;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 20px;
      box-shadow: 0 32px 120px rgba(0, 0, 0, .8)
    }

    .modal h3 {
      font-size: 18px;
      font-weight: 800
    }

    .form-row {
      display: flex;
      gap: 12px
    }

    .form-row .field {
      flex: 1
    }

    .form-check {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer
    }

    .form-check label {
      font-size: 14px;
      cursor: pointer
    }

    .modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      padding-top: 4px
    }

    /* Order detail modal */
    .detail-items table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px
    }

    .detail-items th {
      text-align: left;
      padding: 7px 10px;
      border-bottom: 1px solid var(--border);
      font-size: 11px;
      color: var(--text2);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px
    }

    .detail-items td {
      padding: 8px 10px;
      border-bottom: 1px solid var(--border)
    }

    .detail-items tr:last-child td {
      border-bottom: none
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid var(--border);
      font-size: 13px
    }

    .detail-row:last-child {
      border-bottom: none
    }

    /* Toast */
    #toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      padding: 12px 20px;
      background: var(--card2);
      border: 1px solid var(--border2);
      border-radius: 10px;
      font-size: 13px;
      font-weight: 500;
      z-index: 9999;
      opacity: 0;
      transform: translateY(10px);
      transition: all .25s;
      pointer-events: none
    }

    #toast.show {
      opacity: 1;
      transform: translateY(0)
    }

    #toast.ok {
      border-color: rgba(34, 197, 94, .4);
      color: var(--green)
    }

    #toast.err {
      border-color: rgba(239, 68, 68, .4);
      color: var(--red)
    }

    /* Bar chart CSS */
    .bar-row {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
      font-size: 12px
    }

    .bar-label {
      width: 90px;
      color: var(--text2);
      text-align: right;
      flex-shrink: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .bar-track {
      flex: 1;
      height: 8px;
      background: var(--card2);
      border-radius: 4px;
      overflow: hidden
    }

    .bar-fill {
      height: 100%;
      border-radius: 4px;
      transition: width .4s ease
    }

    .bar-val {
      width: 70px;
      color: var(--text);
      font-weight: 600
    }

    /* Insight strip */
    .insight-strip {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 16px
    }

    .insight-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
      display: flex;
      flex-direction: column;
      gap: 5px;
      position: relative;
      overflow: hidden
    }

    .insight-card::before {
      content: '';
      position: absolute;
      top: -28px;
      right: -28px;
      width: 70px;
      height: 70px;
      border-radius: 50%;
      background: var(--ic, var(--acc));
      opacity: .06;
      pointer-events: none
    }

    .insight-lbl {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--text3)
    }

    .insight-val {
      font-size: 24px;
      font-weight: 900;
      line-height: 1.1;
      font-variant-numeric: tabular-nums
    }

    .insight-sub {
      font-size: 12px;
      color: var(--text4)
    }

    .insight-ibar {
      height: 5px;
      background: var(--card2);
      border-radius: 3px;
      overflow: hidden;
      margin-top: 4px
    }

    .insight-ibar-fill {
      height: 100%;
      border-radius: 3px
    }

    .idelta {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: 11px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
      margin-top: 5px
    }

    .idelta-up {
      background: rgba(34, 197, 94, .12);
      color: var(--green)
    }

    .idelta-dn {
      background: rgba(239, 68, 68, .12);
      color: var(--red)
    }

    /* Heatmap */
    .hm-wrap {
      overflow-x: auto;
      padding: 12px 16px
    }

    .hm-table {
      border-collapse: collapse;
      font-size: 11px;
      width: 100%
    }

    .hm-table th {
      padding: 2px 4px;
      color: var(--text3);
      font-weight: 600;
      text-align: center;
      white-space: nowrap
    }

    .hm-table td {
      padding: 2px
    }

    .hm-cell {
      width: 100%;
      height: 24px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
      color: transparent;
      cursor: default;
      min-width: 24px
    }

    .hm-cell:hover {
      color: #fff !important
    }

    .hm-day {
      color: var(--text2);
      font-weight: 600;
      white-space: nowrap;
      padding-right: 8px !important;
      text-align: right
    }

    .hm-legend {
      display: flex;
      align-items: center;
      gap: 5px;
      margin-top: 8px;
      font-size: 11px;
      color: var(--text3)
    }

    .hm-swatch {
      width: 18px;
      height: 10px;
      border-radius: 2px
    }

    /* Previsao estoque */
    .days-badge {
      display: inline-flex;
      align-items: center;
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 999px
    }

    .days-ok {
      background: rgba(34, 197, 94, .12);
      color: var(--green)
    }

    .days-warn {
      background: rgba(245, 158, 11, .12);
      color: var(--gold)
    }

    .days-crit {
      background: rgba(239, 68, 68, .12);
      color: var(--red)
    }

    /* Status flow */
    .status-flow {
      display: flex;
      gap: 4px;
      margin-top: 12px
    }

    .flow-step {
      flex: 1;
      padding: 8px 6px;
      text-align: center;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 700;
      border: 2px solid transparent;
      cursor: pointer;
      transition: all .15s
    }

    .flow-step.done {
      opacity: .4
    }

    .flow-step.current {
      border-color: currentColor
    }

    /* Audit log */
    .audit-row {
      display: flex;
      gap: 12px;
      padding: 10px 14px;
      border-bottom: 1px solid var(--border);
      font-size: 12px;
      align-items: flex-start
    }

    .audit-row:last-child {
      border-bottom: none
    }

    .audit-time {
      color: var(--text3);
      white-space: nowrap;
      flex-shrink: 0;
      width: 120px
    }

    .audit-user {
      color: var(--text2);
      white-space: nowrap;
      flex-shrink: 0;
      width: 120px;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .audit-acao {
      flex: 1
    }

    .audit-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 10px;
      font-weight: 700;
      margin-right: 6px;
      text-transform: uppercase
    }

    /* Relatórios — Fases 2 e 3 */
    .rel-cfg-input {
      background: var(--card2);
      border: 1px solid var(--border2, #2a2d3e);
      border-radius: 7px;
      color: var(--text);
      font-family: inherit;
      font-size: 12px;
      padding: 6px 10px;
      outline: none;
      width: 140px
    }

    .rel-cfg-input:focus {
      border-color: var(--acc)
    }

    .rel-meta-bar-wrap {
      height: 10px;
      background: var(--card2);
      border-radius: 5px;
      overflow: hidden;
      margin: 6px 0
    }

    .rel-meta-bar-fill {
      height: 100%;
      border-radius: 5px;
      transition: width .6s ease
    }

    .rel-meta-label {
      font-size: 12px;
      color: var(--text2);
      display: flex;
      justify-content: space-between;
      align-items: center
    }

    .rel-meta-proj {
      font-size: 11px;
      color: var(--text3);
      margin-top: 2px
    }

    .rel-cross-card {
      background: var(--card2);
      border-radius: 10px;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 4px
    }

    .rel-cross-tag {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      font-weight: 600;
      background: rgba(255, 255, 255, .06);
      padding: 3px 8px;
      border-radius: 5px
    }

    .boston-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px
    }

    .boston-quad {
      border-radius: 12px;
      padding: 14px;
      border: 1px solid
    }

    .boston-quad.estrela {
      background: rgba(251, 191, 36, .06);
      border-color: rgba(251, 191, 36, .2)
    }

    .boston-quad.vaca {
      background: rgba(34, 197, 94, .06);
      border-color: rgba(34, 197, 94, .2)
    }

    .boston-quad.interrogacao {
      background: rgba(59, 130, 246, .06);
      border-color: rgba(59, 130, 246, .2)
    }

    .boston-quad.abacaxi {
      background: rgba(107, 114, 128, .06);
      border-color: rgba(107, 114, 128, .18)
    }

    .boston-quad-title {
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 8px
    }

    .boston-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 5px;
      background: rgba(255, 255, 255, .06);
      margin: 2px
    }

    .boston-tip {
      font-size: 10px;
      color: var(--text3);
      margin-top: 6px;
      font-style: italic
    }

    .rel-whatif-slider {
      display: flex;
      flex-direction: column;
      gap: 8px;
      background: var(--card2);
      border-radius: 12px;
      padding: 14px
    }

    .rel-whatif-lbl {
      font-size: 12px;
      font-weight: 600;
      color: var(--text2)
    }

    .rel-whatif-result {
      font-size: 20px;
      font-weight: 900;
      margin-top: 4px
    }

    .rel-whatif-range {
      font-size: 11px;
      color: var(--text3)
    }

    input[type=range].rel-slider {
      width: 100%;
      accent-color: var(--acc);
      cursor: pointer
    }

    .rel-custo-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px
    }

    .rel-custo-table th {
      text-align: left;
      padding: 8px 12px;
      color: var(--text3);
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      border-bottom: 1px solid var(--border)
    }

    .rel-custo-table td {
      padding: 8px 12px;
      border-bottom: 1px solid var(--border)
    }

    .rel-custo-table tr:last-child td {
      border-bottom: none
    }

    .rel-custo-rec {
      background: rgba(34, 197, 94, .07);
      border: 1px solid rgba(34, 197, 94, .2);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 12px;
      color: #86efac;
      margin-top: 8px
    }

    /* ── Turno · Waterfall · Clientes · Recordes ─────────────────────────── */
    .rel-turno-card {
      background: var(--card2);
      border-radius: 10px;
      padding: 14px
    }

    .rel-turno-icon {
      font-size: 22px;
      margin-bottom: 4px
    }

    .rel-turno-lbl {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .4px;
      color: var(--text3)
    }

    .rel-turno-sub {
      font-size: 9px;
      color: var(--text3)
    }

    .rel-turno-fat {
      font-size: 17px;
      font-weight: 900;
      margin: 5px 0
    }

    .rel-turno-info {
      font-size: 10px;
      color: var(--text3)
    }

    .rel-turno-bar {
      height: 4px;
      background: var(--card);
      border-radius: 2px;
      margin-top: 8px;
      overflow: hidden
    }

    .rel-turno-bar-fill {
      height: 100%;
      border-radius: 2px
    }

    .wf-row {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 8px 0
    }

    .wf-row+.wf-row {
      border-top: 1px solid var(--border)
    }

    .wf-label {
      width: 170px;
      font-size: 12px;
      font-weight: 600;
      flex-shrink: 0
    }

    .wf-bar-wrap {
      flex: 1;
      height: 8px;
      background: var(--card2);
      border-radius: 4px;
      overflow: hidden
    }

    .wf-bar-fill {
      height: 100%;
      border-radius: 4px;
      transition: width .5s ease
    }

    .wf-val {
      width: 130px;
      text-align: right;
      font-size: 13px;
      font-weight: 700;
      flex-shrink: 0
    }

    .wf-total .wf-label {
      color: var(--green);
      font-size: 13px;
      font-weight: 800
    }

    .wf-total .wf-val {
      font-size: 15px
    }

    .rel-cli-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 16px;
      border-bottom: 1px solid var(--border)
    }

    .rel-cli-row:last-child {
      border-bottom: none
    }

    .rel-cli-medal {
      font-size: 16px;
      flex-shrink: 0
    }

    .rel-cli-nome {
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .rel-cli-sub {
      font-size: 10px;
      color: var(--text3)
    }

    .rel-cli-val {
      font-size: 12px;
      font-weight: 700;
      color: var(--green);
      text-align: right;
      flex-shrink: 0
    }

    .rel-cli-pts {
      font-size: 10px;
      color: var(--text3);
      text-align: right
    }

    .rel-rec-card {
      display: flex;
      align-items: center;
      gap: 10px;
      background: var(--card2);
      border-radius: 10px;
      padding: 12px
    }

    .rel-rec-icon {
      font-size: 26px;
      flex-shrink: 0
    }

    .rel-rec-lbl {
      font-size: 10px;
      color: var(--text3);
      font-weight: 700;
      text-transform: uppercase
    }

    .rel-rec-val {
      font-size: 18px;
      font-weight: 900;
      color: var(--gold)
    }

    .rel-rec-date {
      font-size: 11px;
      color: var(--text3)
    }

    .btn-sm {
      font-size: 11px;
      padding: 5px 10px
    }

    /* ══ PAINEL ESTRATÉGIAS — Design v2 ══════════════════════════════════ */
    /* Header */
    .est-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 12px
    }

    .est-title {
      font-size: 22px;
      font-weight: 900;
      color: var(--text);
      letter-spacing: -.3px
    }

    .est-subtitle {
      font-size: 13px;
      color: var(--text3);
      margin-top: 3px
    }

    .est-section-label {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--text3);
      margin: 24px 0 12px;
      display: flex;
      align-items: center;
      gap: 8px
    }

    .est-section-label::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border)
    }

    /* KPI Row */
    .est-kpi-row {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      gap: 12px;
      margin-bottom: 4px
    }

    .est-kpi-skeleton {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      height: 120px;
      animation: skeleton-pulse 1.5s ease-in-out infinite
    }

    @keyframes skeleton-pulse {

      0%,
      100% {
        opacity: .6
      }

      50% {
        opacity: 1
      }
    }

    .est-kpi-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      position: relative;
      overflow: hidden;
      transition: transform .2s, border-color .2s
    }

    .est-kpi-card:hover {
      transform: translateY(-2px);
      border-color: var(--border2)
    }

    .est-kpi-card::before {
      content: '';
      position: absolute;
      top: -30px;
      right: -30px;
      width: 90px;
      height: 90px;
      border-radius: 50%;
      background: var(--ek, var(--acc));
      opacity: .06;
      pointer-events: none
    }

    .est-kpi-card::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: var(--ek, var(--acc));
      opacity: .4
    }

    .est-kpi-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px
    }

    .est-kpi-emoji {
      font-size: 18px
    }

    .est-kpi-chip {
      font-size: 10px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
      background: var(--card2)
    }

    .est-kpi-chip.up {
      background: rgba(34, 197, 94, .12);
      color: #4ade80
    }

    .est-kpi-chip.dn {
      background: rgba(239, 68, 68, .12);
      color: #f87171
    }

    .est-kpi-lbl {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: var(--text3)
    }

    .est-kpi-val {
      font-size: 28px;
      font-weight: 900;
      color: var(--ek, var(--acc));
      line-height: 1;
      margin: 5px 0 4px
    }

    .est-kpi-sub {
      font-size: 11px;
      color: var(--text3)
    }

    /* Strategy 2x2 grid */
    .est-strat-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 4px
    }

    @media(max-width:900px) {
      .est-strat-grid {
        grid-template-columns: 1fr
      }
    }

    .est-strat-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 0;
      overflow: hidden;
      position: relative;
      transition: border-color .2s
    }

    .est-strat-card:hover {
      border-color: var(--est-accent, var(--acc))
    }

    .est-strat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: var(--est-accent, var(--acc));
      opacity: .6
    }

    .est-strat-card-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 16px
    }

    .est-strat-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      flex-shrink: 0
    }

    .est-strat-title {
      font-size: 14px;
      font-weight: 800;
      line-height: 1.2
    }

    .est-strat-sub {
      font-size: 11px;
      color: var(--text3);
      margin-top: 2px
    }

    .est-strat-badge-count {
      margin-left: auto;
      font-size: 14px;
      font-weight: 900;
      padding: 4px 10px;
      border-radius: 8px;
      background: rgba(255, 255, 255, .06);
      color: var(--text2);
      flex-shrink: 0
    }

    .est-strat-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 0;
      margin-bottom: 16px
    }

    .est-strat-cta {
      display: inline-flex;
      align-items: center;
      font-size: 12px;
      font-weight: 600;
      color: var(--est-accent, var(--acc));
      text-decoration: none;
      background: transparent;
      border: none;
      cursor: pointer;
      padding: 0;
      opacity: .8;
      transition: opacity .15s;
      margin-top: auto
    }

    .est-strat-cta:hover {
      opacity: 1
    }

    /* Combo rows */
    .est-combo-row {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 9px 0;
      border-bottom: 1px solid rgba(255, 255, 255, .05)
    }

    .est-combo-row:last-child {
      border-bottom: none
    }

    .est-combo-chip {
      font-size: 11px;
      font-weight: 600;
      background: rgba(255, 255, 255, .06);
      padding: 3px 8px;
      border-radius: 5px;
      color: var(--text2);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 110px;
      flex-shrink: 0
    }

    .est-combo-plus {
      font-size: 11px;
      color: var(--text3);
      flex-shrink: 0
    }

    .est-combo-gain {
      margin-left: auto;
      font-size: 11px;
      font-weight: 800;
      color: #4ade80;
      flex-shrink: 0;
      white-space: nowrap
    }

    .est-combo-pct {
      font-size: 10px;
      color: var(--text3);
      flex-shrink: 0
    }

    /* Horário bar chart */
    .est-hora-chart {
      display: flex;
      align-items: flex-end;
      gap: 4px;
      height: 72px;
      padding-bottom: 4px
    }

    .est-hora-bar-wrap {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
      flex: 1
    }

    .est-hora-bar {
      border-radius: 3px 3px 0 0;
      transition: height .4s ease;
      min-height: 3px;
      width: 100%
    }

    .est-hora-lbl {
      font-size: 8px;
      color: var(--text3);
      white-space: nowrap
    }

    /* Fidelização */
    .est-fidel-body {
      display: flex;
      align-items: center;
      gap: 16px
    }

    .est-fidel-ring {
      flex-shrink: 0
    }

    .est-fidel-ring svg {
      display: block
    }

    .est-fidel-stats {
      display: flex;
      flex-direction: column;
      gap: 8px;
      flex: 1
    }

    .est-fidel-stat {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12px
    }

    .est-fidel-stat-lbl {
      color: var(--text3)
    }

    .est-fidel-stat-val {
      font-weight: 700;
      color: var(--text)
    }

    /* Produtos parados */
    .est-prod-row {
      padding: 8px 0;
      border-bottom: 1px solid rgba(255, 255, 255, .05)
    }

    .est-prod-row:last-child {
      border-bottom: none
    }

    .est-prod-name {
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 5px;
      color: var(--text2)
    }

    .est-prod-bar-wrap {
      height: 5px;
      background: rgba(255, 255, 255, .06);
      border-radius: 3px;
      overflow: hidden
    }

    .est-prod-bar-fill {
      height: 100%;
      border-radius: 3px;
      background: linear-gradient(90deg, #ef4444, #f59e0b)
    }

    /* Insights */
    .est-insights-wrap {
      display: flex;
      flex-direction: column;
      gap: 0;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      overflow: hidden;
      margin-bottom: 24px
    }

    .est-insights-loading {
      padding: 24px;
      color: var(--text3);
      font-size: 13px;
      text-align: center
    }

    .est-insight-item {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      transition: background .15s;
      position: relative;
      overflow: hidden
    }

    .est-insight-item:last-child {
      border-bottom: none
    }

    .est-insight-item:hover {
      background: rgba(255, 255, 255, .02)
    }

    .est-insight-item::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 3px;
      background: var(--ins-col, var(--text3))
    }

    .est-insight-left {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
      padding-top: 2px
    }

    .est-insight-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--ins-col)
    }

    .est-insight-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 3px;
      line-height: 1.3
    }

    .est-insight-text {
      font-size: 12px;
      color: var(--text3);
      line-height: 1.6
    }

    /* Badges compartilhados */
    .est-badge {
      display: inline-flex;
      align-items: center;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 9px;
      border-radius: 999px;
      flex-shrink: 0
    }

    .est-bg-green {
      background: rgba(34, 197, 94, .12);
      color: #4ade80
    }

    .est-bg-red {
      background: rgba(239, 68, 68, .12);
      color: #f87171
    }

    .est-bg-gold {
      background: rgba(245, 158, 11, .12);
      color: #fbbf24
    }

    .est-bg-blue {
      background: rgba(59, 130, 246, .12);
      color: #60a5fa
    }

    .est-bg-gray {
      background: rgba(107, 114, 128, .12);
      color: #9ca3af
    }

    /* Action buttons row */
    .est-actions-row {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: 10px
    }

    .est-action-big {
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px 16px;
      cursor: pointer;
      text-decoration: none;
      transition: all .2s
    }

    .est-action-big:hover {
      background: var(--card2);
      border-color: var(--border2);
      transform: translateY(-1px)
    }

    .est-action-big-icon {
      font-size: 24px;
      flex-shrink: 0
    }

    .est-action-big-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--text)
    }

    .est-action-big-sub {
      font-size: 10px;
      color: var(--text3);
      margin-top: 2px
    }

    /* ── Config panels ───────────────────────────────────────────────────────── */
    .cfg-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
      gap: 12px
    }

    .cfg-header-left {
      display: flex;
      align-items: center;
      gap: 14px
    }

    .cfg-header-icon {
      width: 46px;
      height: 46px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      flex-shrink: 0
    }

    .cfg-title {
      font-size: 20px;
      font-weight: 800
    }

    .cfg-sub {
      font-size: 13px;
      color: var(--text3);
      margin-top: 2px
    }

    .cfg-section {
      margin-bottom: 20px
    }

    .cfg-section-title {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: var(--text3);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px
    }

    .cfg-section-title::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border)
    }

    .cfg-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-bottom: 12px
    }

    .cfg-card-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--text2);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 2px
    }

    .cfg-toggle-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 11px 0;
      border-bottom: 1px solid var(--border)
    }

    .cfg-toggle-row:last-child {
      border-bottom: none;
      padding-bottom: 0
    }

    .cfg-toggle-row:first-child {
      padding-top: 0
    }

    .cfg-toggle-lbl {
      font-size: 14px;
      font-weight: 600;
      color: var(--text)
    }

    .cfg-toggle-sub {
      font-size: 12px;
      color: var(--text3);
      margin-top: 2px
    }

    .cfg-save-bar {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid var(--border)
    }

    .cfg-horario-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 10px
    }

    .cfg-horario-card {
      background: var(--card2);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 8px
    }

    .cfg-horario-day {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .4px;
      color: var(--text2);
      display: flex;
      align-items: center;
      justify-content: space-between
    }

    .cfg-tip {
      background: rgba(59, 130, 246, .08);
      border: 1px solid rgba(59, 130, 246, .15);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 12px;
      color: var(--blue);
      display: flex;
      align-items: flex-start;
      gap: 8px
    }

    /* ── Estoque sub-menu ─────────────────────────────────────────────────────── */
    .nav-group-btn {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      transition: all .15s;
      font-size: 13px;
      font-weight: 500;
      color: var(--text2);
      border: none;
      background: transparent;
      width: 100%;
      font-family: inherit;
      text-align: left
    }

    .nav-group-btn:hover {
      background: var(--card);
      color: var(--text)
    }

    .nav-group-btn.active {
      background: var(--acc-gl);
      color: var(--acc);
      font-weight: 600
    }

    .nav-group-btn .nav-icon {
      width: 16px;
      height: 16px;
      flex-shrink: 0;
      opacity: .6
    }

    .nav-group-btn:hover .nav-icon,
    .nav-group-btn.active .nav-icon {
      opacity: 1
    }

    .nav-group-arrow {
      margin-left: auto;
      transition: transform .2s;
      opacity: .4;
      font-size: 10px
    }

    .nav-group-btn.open .nav-group-arrow {
      transform: rotate(90deg);
      opacity: .8
    }

    .nav-submenu {
      overflow: hidden;
      max-height: 0;
      transition: max-height .25s ease
    }

    .nav-submenu.open {
      max-height: 320px
    }

    .nav-sub {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 6px 10px 6px 30px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 500;
      color: var(--text3);
      border: none;
      background: transparent;
      width: 100%;
      font-family: inherit;
      text-align: left;
      transition: all .15s
    }

    .nav-sub:hover {
      background: var(--card);
      color: var(--text)
    }

    .nav-sub.active {
      color: var(--acc);
      font-weight: 600;
      background: var(--acc-gl)
    }

    /* Iframe estoque */
    #panel-estoque {
      padding: 0 !important
    }

    #estoque-frame {
      width: 100%;
      height: 100%;
      border: none;
      display: block
    }

    /* ── Permissões — layout acordeão ─────────────────────────────────────── */
    .perm-group {
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 8px;
      background: var(--card)
    }

    .perm-group-hdr {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 14px 16px;
      cursor: pointer;
      user-select: none;
      transition: background .15s;
      background: var(--card)
    }

    .perm-group-hdr:hover {
      background: var(--card2)
    }

    .perm-group-ico {
      font-size: 17px;
      width: 26px;
      text-align: center;
      flex-shrink: 0
    }

    .perm-group-name {
      flex: 1;
      font-size: 13.5px;
      font-weight: 600;
      color: var(--text);
      letter-spacing: .1px
    }

    .perm-grp-count {
      font-size: 11px;
      font-weight: 600;
      padding: 3px 10px;
      border-radius: 20px;
      background: var(--card2);
      color: var(--text3);
      transition: .2s;
      white-space: nowrap
    }

    .perm-grp-count.has-perm {
      background: rgba(34, 197, 94, .14);
      color: var(--green)
    }

    .perm-acc-chevron {
      width: 18px;
      height: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text3);
      flex-shrink: 0;
      transition: transform .22s ease
    }

    .perm-acc-chevron svg {
      width: 14px;
      height: 14px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-linejoin: round
    }

    .perm-group.open > .perm-group-hdr .perm-acc-chevron {
      transform: rotate(180deg)
    }

    .perm-acc-body {
      display: none;
      flex-direction: column;
      border-top: 1px solid var(--border)
    }

    .perm-group.open > .perm-acc-body {
      display: flex
    }

    .perm-grp-note {
      font-size: 11px;
      color: var(--text3);
      padding: 8px 16px;
      background: var(--card2);
      border-bottom: 1px solid var(--border);
      border-left: 3px solid var(--ps-c, var(--acc))
    }

    /* Item (página) como linha */
    .perm-page-card {
      display: flex;
      flex-direction: column;
      border-bottom: 1px solid var(--border)
    }

    .perm-page-card:last-child {
      border-bottom: none
    }

    .perm-page-hdr {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 13px 16px;
      cursor: default;
      user-select: none;
      transition: background .12s;
      min-height: 50px
    }

    .perm-page-hdr:hover {
      background: var(--card2)
    }

    .perm-page-ico {
      font-size: 14px;
      width: 22px;
      text-align: center;
      flex-shrink: 0
    }

    .perm-page-label {
      flex: 1;
      font-size: 13px;
      font-weight: 500;
      color: var(--text3);
      line-height: 1.3
    }

    .perm-page-card.on .perm-page-label {
      color: var(--ps-c, var(--acc));
      font-weight: 600
    }

    /* Sub-ações */
    .perm-page-actions {
      display: none;
      flex-direction: column;
      background: var(--card2)
    }

    .perm-page-card.on .perm-page-actions {
      display: flex
    }

    .perm-action-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 9px 16px 9px 52px;
      transition: background .1s;
      gap: 8px;
      border-top: 1px solid var(--border)
    }

    .perm-action-row:hover {
      background: var(--card)
    }

    .perm-action-row.disabled {
      opacity: .3;
      pointer-events: none
    }

    .perm-action-label {
      font-size: 12px;
      color: var(--text2);
      flex: 1
    }

    /* Badge de papel */
    .perm-count-badge {
      font-size: 11px;
      font-weight: 600;
      padding: 3px 10px;
      border-radius: 20px;
      background: var(--card2);
      color: var(--text3);
      transition: .2s;
      white-space: nowrap
    }

    .perm-count-badge.has-perm {
      background: rgba(34, 197, 94, .14);
      color: var(--green)
    }

    .perm-role-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600
    }

    .perm-role-badge.admin {
      background: rgba(255, 85, 0, .12);
      color: var(--acc)
    }

    .perm-role-badge.operador {
      background: rgba(59, 130, 246, .12);
      color: var(--blue)
    }

    .perm-role-badge.cozinha {
      background: rgba(34, 197, 94, .12);
      color: var(--green)
    }

    /* ═══════════════════════════════════════════════════════════════════
       FISCAL — Saúde Fiscal + Radar Fiscal
       ═══════════════════════════════════════════════════════════════════ */

    /* ── Layout base dos painéis fiscais ── */
    .fiscal-panel { display: flex; flex-direction: column; gap: 16px; }

    /* ── Banner de status ── */
    .sf-status-banner {
      display: flex; align-items: center; gap: 16px;
      padding: 16px 20px; border-radius: 12px;
      background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.25);
    }
    .sf-status-banner.danger  { background:rgba(239,68,68,.08);  border-color:rgba(239,68,68,.25); }
    .sf-status-banner.warning { background:rgba(245,158,11,.08); border-color:rgba(245,158,11,.25); }
    .sf-status-ico {
      width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; background: rgba(34,197,94,.15); color: var(--green);
    }
    .sf-status-banner.danger  .sf-status-ico { background:rgba(239,68,68,.15);  color:var(--red); }
    .sf-status-banner.warning .sf-status-ico { background:rgba(245,158,11,.15); color:var(--gold); }
    .sf-status-title {
      font-size: 13px; font-weight: 800; text-transform: uppercase;
      letter-spacing: .8px; color: var(--green); line-height: 1;
    }
    .sf-status-banner.danger  .sf-status-title { color: var(--red); }
    .sf-status-banner.warning .sf-status-title { color: var(--gold); }
    .sf-status-sub { font-size: 12px; color: var(--text2); margin-top: 4px; }
    .sf-live-badge {
      margin-left: auto; display: flex; align-items: center; gap: 6px;
      font-size: 11px; color: var(--text3); white-space: nowrap;
    }

    /* ── KPI grid ── */
    .sf-kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
    .sf-kpi {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 12px; padding: 16px 18px;
    }
    .sf-kpi-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text3); margin-bottom: 8px; }
    .sf-kpi-value { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1; }
    .sf-kpi-value.green  { color: var(--green); }
    .sf-kpi-value.orange { color: var(--acc); }
    .sf-kpi-sub { font-size: 11px; color: var(--text3); margin-top: 5px; }

    /* ── Grid principal 2 colunas ── */
    .sf-main-grid { display: grid; grid-template-columns: 1fr 340px; gap: 16px; }

    /* ── Card genérico ── */
    .sf-card {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 12px; overflow: hidden;
    }
    .sf-card-hdr {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 16px; border-bottom: 1px solid var(--border);
      font-size: 13px; font-weight: 700; color: var(--text);
    }
    .sf-card-hdr-badge { font-size: 11px; color: var(--text3); font-weight: 400; }
    .sf-card-body { padding: 0; }

    /* ── Emissão ao vivo ── */
    .sf-emissao-row {
      display: grid; grid-template-columns: 28px 90px 1fr 120px;
      align-items: center; gap: 8px;
      padding: 10px 16px; border-bottom: 1px solid var(--border);
      font-size: 12px; transition: background .1s;
    }
    .sf-emissao-row:last-child { border-bottom: none; }
    .sf-emissao-row:hover { background: var(--card2); }
    .sf-emissao-ico { font-size: 15px; text-align: center; }
    .sf-emissao-num { font-weight: 700; color: var(--text); font-size: 13px; }
    .sf-emissao-val { color: var(--text2); }
    .sf-status-tag {
      text-align: right; font-weight: 600; font-size: 11px;
    }
    .sf-status-tag.autorizada     { color: var(--green); }
    .sf-status-tag.transmitindo   { color: var(--gold); }
    .sf-status-tag.contingencia   { color: var(--purple); }
    .sf-status-tag.cancelada      { color: var(--red); }

    /* ── Coluna direita ── */
    .sf-right-col { display: flex; flex-direction: column; gap: 12px; }
    .sf-contingencia-count { font-size: 42px; font-weight: 900; color: var(--green); line-height: 1; }
    .sf-contingencia-label { font-size: 12px; color: var(--text3); margin-top: 2px; }
    .sf-contingencia-ok    { font-size: 11px; color: var(--text3); margin-top: 8px; }

    /* ── Rejeições ── */
    .sf-rejeicao-row {
      padding: 10px 16px; border-bottom: 1px solid var(--border);
      font-size: 12px; color: var(--text2);
    }
    .sf-rejeicao-row:last-child { border-bottom: none; }
    .sf-rejeicao-empty { padding: 16px; font-size: 12px; color: var(--text3); text-align: center; }

    /* ── Monitor de prazos ── */
    .sf-prazo-row {
      display: flex; align-items: flex-start; justify-content: space-between;
      padding: 10px 16px; border-bottom: 1px solid var(--border); gap: 8px;
    }
    .sf-prazo-row:last-child { border-bottom: none; }
    .sf-prazo-nome  { font-size: 12px; font-weight: 600; color: var(--text); }
    .sf-prazo-sub   { font-size: 11px; color: var(--text3); margin-top: 2px; }
    .sf-prazo-badge { font-size: 11px; font-weight: 700; white-space: nowrap; margin-top: 2px; }
    .sf-prazo-badge.ok      { color: var(--green); }
    .sf-prazo-badge.alert   { color: var(--gold); }
    .sf-prazo-badge.danger  { color: var(--red); }

    /* ═══ RADAR FISCAL ═══ */
    .rf-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 16px;
    }
    .rf-brand { font-size: 13px; color: var(--text3); }
    .rf-brand strong { color: var(--acc); font-weight: 800; }
    .rf-version { font-size: 11px; color: var(--text3); text-align: right; }

    .rf-grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; margin-top: 16px; }

    /* Agente de conformidade */
    .rf-agente-row {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 8px 0; font-size: 12px; color: var(--text2);
      border-bottom: 1px solid var(--border);
    }
    .rf-agente-row:last-child { border-bottom: none; }
    .rf-agente-ico { color: var(--green); font-weight: 700; flex-shrink: 0; margin-top: 1px; }
    .rf-highlight { color: #60a5fa; font-weight: 600; }

    /* Fontes monitoradas */
    .rf-fonte-row {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 10px 16px; border-bottom: 1px solid var(--border);
    }
    .rf-fonte-row:last-child { border-bottom: none; }
    .rf-fonte-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--green); flex-shrink: 0; margin-top: 4px; }
    .rf-fonte-nome { font-size: 12px; font-weight: 600; color: var(--text); }
    .rf-fonte-sub  { font-size: 11px; color: var(--text3); margin-top: 2px; }

    /* Atualizações aplicadas */
    .rf-update-row {
      display: flex; gap: 10px;
      padding: 10px 16px; border-bottom: 1px solid var(--border);
      font-size: 11px;
    }
    .rf-update-row:last-child { border-bottom: none; }
    .rf-update-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 3px; }
    .rf-update-when { color: var(--text3); white-space: nowrap; }
    .rf-update-tipo { font-weight: 600; color: var(--text2); margin-bottom: 1px; }
    .rf-update-desc { color: var(--text2); }

    /* Card de atualização destacado */
    .rf-update-card {
      background: var(--card); border: 1px solid var(--border);
      border-left: 3px solid var(--blue); border-radius: 12px;
      padding: 20px; margin-top: 16px;
    }
    .rf-update-tag {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 10px; font-weight: 700; text-transform: uppercase;
      padding: 3px 10px; border-radius: 6px;
      background: rgba(59,130,246,.12); color: var(--blue);
      margin-bottom: 10px; letter-spacing: .5px;
    }
    .rf-update-title { font-size: 18px; font-weight: 800; color: var(--acc); margin-bottom: 8px; line-height: 1.3; }
    .rf-update-body  { font-size: 12px; color: var(--text2); line-height: 1.6; margin-bottom: 12px; }
    .rf-update-meta  { display: grid; grid-template-columns: 90px 1fr; gap: 6px 12px; font-size: 12px; margin-bottom: 12px; }
    .rf-meta-label   { color: var(--text3); font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: .4px; }
    .rf-meta-val     { color: var(--text2); }
    .rf-meta-val a   { color: var(--blue); text-decoration: none; }
    .rf-diff {
      background: var(--bg); border-radius: 8px; padding: 12px 16px;
      font-family: monospace; font-size: 12px;
    }
    .rf-diff-minus { color: var(--red);   margin-bottom: 4px; }
    .rf-diff-plus  { color: var(--green); }
    .rf-diff-note  { font-size: 11px; color: var(--text3); margin-top: 12px; font-family: inherit; }

    /* Spinning icon para "transmitindo" */
    @keyframes sf-spin { to { transform: rotate(360deg); } }
    .sf-spin { display: inline-block; animation: sf-spin .8s linear infinite; }
  </style>
</head>

<body>

  <?php if (!$loggedIn): ?>
    <!-- ═══════════════════════════════ LOGIN ═══════════════════════════════ -->
    <div class="login-wrap">
      <div class="login-box">
        <div class="login-logo">
          <h1>Café Comunhão</h1>
          <p>Painel Administrativo</p>
        </div>
        <?php if ($err): ?>
          <div class="err-msg"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <?php if ($showTimeout): ?>
          <div class="err-msg">⏱ Sessão expirada por inatividade. Faça login novamente.</div>
        <?php endif; ?>
        <form method="POST" style="display:flex;flex-direction:column;gap:16px">
          <input type="hidden" name="_action" value="login">
          <div class="field">
            <label>E-mail</label>
            <input type="email" name="email" placeholder="admin@totem.com" required
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Senha</label>
            <input type="password" name="senha" placeholder="••••••••" required>
          </div>
          <button type="submit" class="btn-login">Entrar →</button>
        </form>
      </div>
    </div>

  <?php else: ?>
    <!-- ═══════════════════════════════ DASHBOARD ═══════════════════════════ -->
    <div class="layout">

      <!-- SIDEBAR -->
      <?php
      function _ic(string $p): string
      {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-icon">' . $p . '</svg>';
      }
      $_E = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="nav-ext"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
      ?>
      <aside class="sidebar">
        <div class="sb-brand">
          <h2>Café Comunhão</h2>
          <p>Sistema de Gestão</p>
        </div>

        <nav class="sb-nav">

          <?php if (canSeeAny('painel.dashboard', 'painel.pedidos')): ?>
            <div class="sb-section">Painel</div>
            <?php if (canSee('painel.dashboard')): ?><button class="nav-item active" data-tab="dashboard"><?= _ic('<rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/>') ?><span>Dashboard</span></button><?php endif; ?>
            <?php if (canSee('painel.pedidos')): ?><button class="nav-item" data-tab="pedidos"><?= _ic('<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><path d="M9 14l2 2 4-4"/>') ?><span>Pedidos</span><span class="nav-badge" id="badge-ativos" style="display:none">0</span></button><?php endif; ?>
          <?php endif; ?>

          <?php if (canSeeAny('painel.produtos', 'painel.categorias')): ?>
            <div class="sb-section">Cardápio</div>
            <?php if (canSee('painel.produtos')): ?><button class="nav-item" data-tab="produtos"><?= _ic('<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>') ?><span>Produtos</span></button><?php endif; ?>
            <?php if (canSee('painel.categorias')): ?><button class="nav-item" data-tab="categorias"><?= _ic('<path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/>') ?><span>Categorias</span></button><?php endif; ?>
          <?php endif; ?>

          <?php if (canSeeAny('painel.relatorios', 'painel.estrategias', 'painel.dre', 'painel.cardapio')): ?>
            <div class="sb-section">Financeiro</div>
            <?php if (canSee('painel.relatorios')): ?><button class="nav-item" data-tab="relatorios"><?= _ic('<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>') ?><span>Relatórios</span></button><?php endif; ?>
            <?php if (canSee('painel.estrategias')): ?><button class="nav-item" data-tab="estrategias"><?= _ic('<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>') ?><span>Estratégias</span></button><?php endif; ?>
            <?php if (canSee('painel.dre')): ?><a href="dre/" class="nav-item" target="_blank"><?= _ic('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>') ?><span>DRE &amp; Despesas</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('painel.cardapio')): ?><a href="relatorios/cardapio.php" class="nav-item" target="_blank"><?= _ic('<path d="M21.21 15.89A10 10 0 118.11 2.79"/><path d="M22 12A10 10 0 0012 2v10z"/>') ?><span>Análise Cardápio</span><?= $_E ?></a><?php endif; ?>
          <?php endif; ?>

          <?php if (canSeeAny('op.caixa_turnos', 'op.garcom', 'op.fidelidade', 'op.mesas_admin', 'op.delivery_admin', 'op.metas', 'op.garcom_app') || canSeeGroup('estoque')): ?>
            <div class="sb-section">Operação</div>
            <?php if (canSee('op.caixa_turnos')): ?><a href="caixa/turno.php" class="nav-item" target="_blank"><?= _ic('<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 019.9-1"/>') ?><span>Caixa &amp; Turnos</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('op.garcom_app')): ?><a href="../garcom/" class="nav-item" target="_blank"><?= _ic('<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/>') ?><span>Garçom / Comanda</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('op.mesas_admin')): ?><a href="mesas/" class="nav-item" target="_blank"><?= _ic('<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/>') ?><span>Mesas</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('op.delivery_admin')): ?><a href="delivery/" class="nav-item" target="_blank"><?= _ic('<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>') ?><span>Delivery</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('op.fidelidade')): ?><a href="clientes/" class="nav-item" target="_blank"><?= _ic('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>') ?><span>Fidelidade</span><?= $_E ?></a><?php endif; ?>
            <!-- Estoque com sub-menu -->
            <?php if (canSeeGroup('estoque')): ?>
              <div>
                <button class="nav-group-btn" id="nav-estoque-btn" onclick="toggleEstoqueMenu()">
                  <?= _ic('<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>') ?>
                  <span>Estoque</span>
                  <span class="nav-group-arrow">▶</span>
                </button>
                <div class="nav-submenu" id="estoque-submenu">
                  <?php if (canSee('estoque.insumos')): ?><button class="nav-sub" data-est="insumos" onclick="estTab('insumos')">📦 Insumos</button><?php endif; ?>
                  <?php if (canSee('estoque.movimentacoes')): ?><button class="nav-sub" data-est="movimentacoes" onclick="estTab('movimentacoes')">↕ Movimentações</button><?php endif; ?>
                  <?php if (canSee('estoque.fichas')): ?><button class="nav-sub" data-est="fichas" onclick="estTab('fichas')">🍽️ Fichas Técnicas</button><?php endif; ?>
                  <?php if (canSee('estoque.relatorio')): ?><button class="nav-sub" data-est="relatorio" onclick="estTab('relatorio')">📊 Relatório</button><?php endif; ?>
                  <?php if (canSee('estoque.inteligente')): ?><button class="nav-sub" data-est="inteligente" onclick="estTab('inteligente')">🧠 Inteligente</button><?php endif; ?>
                  <?php if (canSee('estoque.lotes')): ?><button class="nav-sub" data-est="lotes" onclick="estTab('lotes')">📋 Lotes</button><?php endif; ?>
                  <?php if (canSee('estoque.compras')): ?><button class="nav-sub" data-est="compras" onclick="estTab('compras')">🛒 Compras</button><?php endif; ?>
                  <?php if (canSee('estoque.desperdicio')): ?><button class="nav-sub" data-est="desperdicio" onclick="estTab('desperdicio')">⚠️ Desperdício</button><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (canSeeAny('tela.totem', 'tela.kds', 'tela.caixa_pdv', 'tela.painel_tv', 'tela.entregador', 'tela.rastreio', 'tela.pontos', 'tela.status')): ?>
            <div class="sb-section">Telas</div>
            <?php if (canSee('tela.totem')): ?><a href="../" class="nav-item" target="_blank"><?= _ic('<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>') ?><span>Totem</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('tela.kds')): ?><a href="../kds/" class="nav-item" target="_blank"><?= _ic('<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>') ?><span>KDS Cozinha</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('tela.caixa_pdv')): ?><a href="../caixa/" class="nav-item" target="_blank"><?= _ic('<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>') ?><span>Caixa PDV</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('tela.painel_tv')): ?><a href="../painel/" class="nav-item" target="_blank"><?= _ic('<rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/>') ?><span>Painel TV</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('tela.entregador')): ?><a href="../delivery/entregador.php" class="nav-item" target="_blank"><?= _ic('<path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>') ?><span>App Entregador</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('tela.pontos')): ?><a href="../status/fidelidade.php" class="nav-item" target="_blank"><?= _ic('<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>') ?><span>Pontos no Totem</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('tela.status')): ?><a href="../status/" class="nav-item" target="_blank"><?= _ic('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>') ?><span>Status Pedido</span><?= $_E ?></a><?php endif; ?>
          <?php endif; ?>

          <!-- ── FISCAL ── -->
          <div class="sb-section">Fiscal</div>
          <button class="nav-item" data-tab="saude-fiscal">
            <?= _ic('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/><circle cx="12" cy="12" r="1"/>') ?>
            <span>Saúde Fiscal</span>
            <span id="sf-status-dot" style="width:7px;height:7px;border-radius:50%;background:var(--green);margin-left:auto;flex-shrink:0"></span>
          </button>
          <button class="nav-item" data-tab="radar-fiscal">
            <?= _ic('<path d="M1 6l4.39 4.39A7.5 7.5 0 0112 4.5a7.5 7.5 0 017 9.5"/><path d="M5 12.5A7 7 0 0012 19.5"/><circle cx="12" cy="12" r="2"/>') ?>
            <span>Radar Fiscal</span>
          </button>

          <!-- Auditoria: visível para admins E para não-admins com painel.auditoria -->
          <?php if ($isAdmin || canSee('painel.auditoria')): ?>
            <div class="sb-section">Admin</div>
            <?php if ($isAdmin): ?>
              <!-- Usuários com sub-menu (apenas admin) -->
              <div>
                <button class="nav-group-btn" id="nav-usr-btn" onclick="toggleUsrMenu()">
                  <?= _ic('<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>') ?>
                  <span>Usuários</span>
                  <span class="nav-group-arrow">▶</span>
                </button>
                <div class="nav-submenu" id="usr-submenu">
                  <button class="nav-sub" data-usr="lista" onclick="usrTab('lista')"> 👥 Lista</button>
                  <button class="nav-sub" data-usr="permissoes" onclick="usrTab('permissoes')"> 🔑 Permissões</button>
                  <button class="nav-sub" data-usr="atividade" onclick="usrTab('atividade')"> 📋 Atividade</button>
                </div>
              </div>
            <?php endif; ?>
            <button class="nav-item" data-tab="auditoria"><?= _ic('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>') ?><span>Auditoria</span></button>
            <!-- Configurações com sub-menu -->
            <div>
              <button class="nav-group-btn" id="nav-cfg-btn" onclick="toggleCfgMenu()">
                <?= _ic('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>') ?>
                <span>Configurações</span>
                <span class="nav-group-arrow">▶</span>
              </button>
              <div class="nav-submenu" id="cfg-submenu">
                <?php if (canSee('cfg.loja')): ?><button class="nav-sub" data-cfg="loja" onclick="cfgTab('loja')">🏪 Loja</button><?php endif; ?>
                <?php if (canSee('cfg.totem_kds')): ?><button class="nav-sub" data-cfg="totem" onclick="cfgTab('totem')">🖥️ Totem &amp; KDS</button><?php endif; ?>
                <?php if (canSee('cfg.pagamentos')): ?><button class="nav-sub" data-cfg="pagamentos" onclick="cfgTab('pagamentos')">💳 Pagamentos</button><?php endif; ?>
                <?php if (canSee('cfg.impressora')): ?><button class="nav-sub" data-cfg="impressora" onclick="cfgTab('impressora')">🖨️ Impressora</button><?php endif; ?>
                <?php if (canSee('cfg.fidelidade')): ?><button class="nav-sub" data-cfg="fidelidade" onclick="cfgTab('fidelidade')">⭐ Fidelidade</button><?php endif; ?>
                <?php if (canSee('cfg.integracoes')): ?><button class="nav-sub" data-cfg="integracoes" onclick="cfgTab('integracoes')">📲 Integrações</button><?php endif; ?>
                <?php if (canSee('cfg.alertas')): ?><button class="nav-sub" data-cfg="alertas" onclick="cfgTab('alertas')">🔔 Alertas</button><?php endif; ?>
                <?php if (canSee('cfg.backup')): ?><button class="nav-sub" data-cfg="backup" onclick="cfgTab('backup')">💾 Backup</button><?php endif; ?>
              </div>
            </div>
            <?php if (canSee('cfg.seguranca_2fa')): ?><a href="2fa/setup.php" class="nav-item" target="_blank"><?= _ic('<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>') ?><span>Segurança 2FA</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('cfg.email')): ?><a href="email/" class="nav-item" target="_blank"><?= _ic('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>') ?><span>E-mail Semanal</span><?= $_E ?></a><?php endif; ?>
            <?php if (canSee('cfg.backup_bd')): ?><a href="#" class="nav-item" id="btn-backup" onclick="fazBackup(event)"><?= _ic('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>') ?><span>Backup BD</span></a><?php endif; ?>
          <?php endif; ?>

        </nav>

        <div class="sb-user">
          <div class="sb-user-box">
            <div class="sb-avatar"><?= strtoupper(substr($adminNome, 0, 1)) ?></div>
            <div class="sb-user-info">
              <div class="sb-user-name"><?= htmlspecialchars($adminNome) ?></div>
              <div class="sb-user-role"><?= htmlspecialchars($adminRole) ?></div>
            </div>
            <a href="?logout" class="sb-logout" title="Sair">⏻</a>
          </div>
        </div>
      </aside>

      <!-- MAIN -->
      <main class="main">
        <div class="topbar">
          <div class="topbar-title" id="topbar-title"><?= canSee('painel.dashboard') ? 'Dashboard' : 'Bem-vindo' ?></div>
          <div class="topbar-right">
            <span><span class="pulse-dot"></span><span id="topbar-status" style="font-size:12px;color:var(--text3)">Ao vivo</span></span>
            <span class="topbar-clock" id="topbar-clock"></span>
          </div>
        </div>

        <div class="content">

          <!-- ─── DASHBOARD ──────────────────────────────────────────────── -->
          <div class="panel <?= canSee('painel.dashboard') ? 'active' : '' ?>" id="panel-dashboard">
            <div class="kpi-grid" id="dash-kpis"></div>

            <!-- Insight strip: margem, projeção, comparativo -->
            <div class="insight-strip" id="dash-insights" style="display:none"></div>

            <div class="grid-2">
              <div class="section-card">
                <div class="section-head">
                  <h3>Pedidos ativos</h3><span id="dash-ativos-time" style="font-size:11px;color:var(--text3)"></span>
                </div>
                <div class="section-body" id="dash-ativos"></div>
              </div>
              <div class="section-card">
                <div class="section-head">
                  <h3>Faturamento — últimos 7 dias</h3>
                </div>
                <div class="section-body">
                  <div class="chart-wrap"><canvas id="chart-7d"></canvas></div>
                </div>
              </div>
            </div>

            <!-- Heatmap de vendas -->
            <div class="section-card" id="dash-heatmap-card" style="display:none;margin-bottom:16px">
              <div class="section-head">
                <h3>🌡️ Mapa de calor — pedidos por hora (30 dias)</h3>
                <span style="font-size:11px;color:var(--text3)">Seg–Dom · 6h–21h</span>
              </div>
              <div class="hm-wrap" id="dash-heatmap"></div>
            </div>

            <!-- Previsão de estoque acabar -->
            <div class="section-card" id="dash-previsao-card" style="display:none;margin-bottom:16px">
              <div class="section-head">
                <h3>🔮 Previsão de estoque — dias restantes</h3>
                <a href="estoque/" style="font-size:12px;color:var(--acc);text-decoration:none;font-weight:600" target="_blank">Gerenciar →</a>
              </div>
              <div style="overflow:hidden">
                <table class="data-table" id="dash-previsao"></table>
              </div>
            </div>

            <div class="section-card">
              <div class="section-head">
                <h3>Últimos pedidos</h3><button class="btn btn-secondary btn-sm" onclick="switchTab('pedidos')">Ver todos →</button>
              </div>
              <div class="data-table-wrap" style="border:none;border-radius:0">
                <table class="data-table" id="dash-recent">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Hora</th>
                      <th>Tipo</th>
                      <th>Pagamento</th>
                      <th>Total</th>
                      <th>Status</th>
                      <th>Origem</th>
                    </tr>
                  </thead>
                  <tbody id="dash-recent-body"></tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ─── PEDIDOS ────────────────────────────────────────────────── -->
          <div class="panel" id="panel-pedidos">
            <div class="toolbar">
              <div class="toolbar-search"><span>🔍</span><input type="text" id="ped-busca" placeholder="Buscar por número ou CPF..."></div>
              <select id="ped-status">
                <option value="ativos">Ativos</option>
                <option value="aguardando">Aguardando</option>
                <option value="preparando">Preparando</option>
                <option value="pronto">Prontos</option>
                <option value="entregue">Entregues</option>
                <option value="cancelado">Cancelados</option>
                <option value="">Todos</option>
              </select>
              <input type="date" id="ped-ini" value="">
              <input type="date" id="ped-fim" value="">
              <select id="ped-origem">
                <option value="">Todas origens</option>
                <option value="totem">Totem</option>
                <option value="caixa">Caixa</option>
              </select>
              <button class="btn btn-secondary" id="ped-refresh">↻ Atualizar</button>
            </div>
            <div class="data-table-wrap">
              <div class="data-table-head">
                <h3 id="ped-count">Pedidos</h3>
                <span id="ped-auto" style="font-size:12px;color:var(--text3)"><span class="pulse-dot"></span>Auto-atualização 10s</span>
              </div>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Data/Hora</th>
                    <th>Tipo</th>
                    <th>Itens</th>
                    <th>Pagamento</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Origem</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="ped-tbody"></tbody>
              </table>
              <div class="pagination" id="ped-pagination"></div>
            </div>
          </div>

          <!-- ─── PRODUTOS ───────────────────────────────────────────────── -->
          <div class="panel" id="panel-produtos">
            <div class="toolbar">
              <div class="toolbar-search"><span>🔍</span><input type="text" id="pr-busca" placeholder="Buscar produto..."></div>
              <select id="pr-cat">
                <option value="">Todas categorias</option>
              </select>
              <?php if (canSee('acao.prod_criar')): ?><button class="btn btn-primary" id="btn-new-prod">+ Novo produto</button><?php else: ?><span id="btn-new-prod" style="display:none"></span><?php endif; ?>
            </div>
            <div class="data-table-wrap">
              <div class="data-table-head">
                <h3 id="pr-count">Produtos</h3>
                <div style="display:flex;gap:8px">
                  <?php if (canSee('acao.prod_toggle')): ?><button class="btn btn-secondary btn-sm" id="btn-bulk-on">Ativar selecionados</button>
                    <button class="btn btn-secondary btn-sm" id="btn-bulk-off">Desativar selecionados</button><?php else: ?><span id="btn-bulk-on" style="display:none"></span><span id="btn-bulk-off" style="display:none"></span><?php endif; ?>
                </div>
              </div>
              <table class="data-table">
                <thead>
                  <tr>
                    <th style="width:36px"><input type="checkbox" id="select-all" style="width:16px;height:16px;cursor:pointer"></th>
                    <th>Produto</th>
                    <th>Categoria</th>
                    <th>Preço</th>
                    <th>Disponível</th>
                    <th>Destaque</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="pr-tbody"></tbody>
              </table>
            </div>
          </div>

          <!-- ─── CATEGORIAS ─────────────────────────────────────────────── -->
          <div class="panel" id="panel-categorias">
            <div class="toolbar">
              <?php if (canSee('acao.prod_criar_cat')): ?><button class="btn btn-primary" id="btn-new-cat">+ Nova categoria</button><?php else: ?><span id="btn-new-cat" style="display:none"></span><?php endif; ?>
            </div>
            <div class="data-table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Ícone</th>
                    <th>Nome</th>
                    <th>Ordem</th>
                    <th>Produtos</th>
                    <th>Ativos</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="cat-tbody"></tbody>
              </table>
            </div>
          </div>

          <!-- ─── RELATÓRIOS ─────────────────────────────────────────────── -->
          <div class="panel" id="panel-relatorios">
            <div class="toolbar" style="margin-bottom:20px">
              <span style="font-size:13px;color:var(--text2);font-weight:600">Período:</span>
              <input type="date" id="rel-ini">
              <span style="color:var(--text3)">até</span>
              <input type="date" id="rel-fim">
              <button class="btn btn-primary" id="btn-rel-load">Gerar relatório</button>
              <button class="btn btn-secondary" id="btn-rel-csv">⬇ Exportar CSV</button>
              <button class="btn btn-secondary" id="btn-rel-pdf" onclick="window.open('relatorio_pdf.php?data_ini='+document.getElementById('rel-ini').value+'&data_fim='+document.getElementById('rel-fim').value,'_blank')">🖨️ Gerar PDF</button>
            </div>
            <div class="kpi-grid" id="rel-kpis"></div>

            <!-- ── Metas mensais ─────────────────────────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Metas do mês</h3>
                <button class="btn btn-secondary btn-sm" id="btn-cfg-metas">⚙ Configurar</button>
              </div>
              <div id="rel-metas-cfg" style="display:none;padding:14px 16px;border-bottom:1px solid var(--border);gap:10px;flex-wrap:wrap;align-items:flex-end">
                <div style="display:flex;flex-direction:column;gap:4px">
                  <label style="font-size:11px;color:var(--text3);font-weight:700;text-transform:uppercase">Meta faturamento/mês (R$)</label>
                  <input type="number" id="cfg-meta-fat" placeholder="Ex: 5000" class="rel-cfg-input">
                </div>
                <div style="display:flex;flex-direction:column;gap:4px">
                  <label style="font-size:11px;color:var(--text3);font-weight:700;text-transform:uppercase">Meta pedidos/mês</label>
                  <input type="number" id="cfg-meta-ped" placeholder="Ex: 200" class="rel-cfg-input">
                </div>
                <div style="display:flex;flex-direction:column;gap:4px">
                  <label style="font-size:11px;color:var(--text3);font-weight:700;text-transform:uppercase">Taxa crédito (%)</label>
                  <input type="number" id="cfg-taxa-cred" placeholder="2.5" step="0.1" class="rel-cfg-input" style="width:100px">
                </div>
                <div style="display:flex;flex-direction:column;gap:4px">
                  <label style="font-size:11px;color:var(--text3);font-weight:700;text-transform:uppercase">Taxa débito (%)</label>
                  <input type="number" id="cfg-taxa-deb" placeholder="1.5" step="0.1" class="rel-cfg-input" style="width:100px">
                </div>
                <button class="btn btn-primary btn-sm" id="btn-salvar-metas">Salvar</button>
              </div>
              <div id="rel-metas-body" style="padding:16px;display:flex;flex-direction:column;gap:14px">
                <div style="color:var(--text3);font-size:12px">Carregando...</div>
              </div>
            </div>

            <!-- ── Custo real por pagamento ──────────────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Custo real por forma de pagamento</h3>
              </div>
              <div class="section-body" id="rel-custos"></div>
            </div>

            <!-- ── Matriz Boston ─────────────────────────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Matriz estratégica de produtos</h3>
              </div>
              <div id="rel-boston" style="padding:16px"></div>
            </div>

            <div class="grid-2">
              <div class="section-card">
                <div class="section-head">
                  <h3>Por forma de pagamento</h3>
                </div>
                <div class="section-body" id="rel-pag"></div>
              </div>
              <div class="section-card">
                <div class="section-head">
                  <h3>Por origem</h3>
                </div>
                <div class="section-body" id="rel-origem"></div>
              </div>
            </div>
            <div class="grid-2">
              <div class="section-card">
                <div class="section-head">
                  <h3>Faturamento por dia</h3>
                </div>
                <div class="section-body">
                  <div class="chart-wrap"><canvas id="chart-rel-dias"></canvas></div>
                </div>
              </div>
              <div class="section-card">
                <div class="section-head">
                  <h3>Hora pico</h3>
                </div>
                <div class="section-body">
                  <div class="chart-wrap"><canvas id="chart-rel-hora"></canvas></div>
                </div>
              </div>
            </div>

            <!-- ── Projeção de faturamento (15 dias) ─────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Projeção de faturamento</h3>
                <span style="font-size:11px;color:var(--text3)">Linha tracejada = projeção 15 dias</span>
              </div>
              <div class="section-body">
                <div class="chart-wrap" style="height:220px"><canvas id="chart-rel-proj"></canvas></div>
              </div>
            </div>

            <!-- ── Ranking produtos + Cross-sell ──────────────────────────────── -->
            <div class="grid-2">
              <div class="section-card">
                <div class="section-head">
                  <h3>Top 15 produtos</h3>
                </div>
                <div class="section-body" id="rel-top"></div>
              </div>
              <div class="section-card">
                <div class="section-head">
                  <h3>Vendidos juntos (cross-sell)</h3>
                </div>
                <div id="rel-crosssell" style="padding:12px 16px;display:flex;flex-direction:column;gap:10px"></div>
              </div>
            </div>

            <!-- ── Simulador E se? ───────────────────────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Simulador "E se?" — impacto estimado</h3>
              </div>
              <div id="rel-whatif" style="padding:16px;display:flex;flex-direction:column;gap:20px"></div>
            </div>

            <!-- ── Análise por turno ─────────────────────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Análise por turno</h3><span style="font-size:11px;color:var(--text3)">Manhã · Almoço · Tarde · Noite</span>
              </div>
              <div id="rel-turnos" style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px"></div>
            </div>

            <!-- ── Waterfall de receita ───────────────────────────────────────── -->
            <div class="section-card" style="margin-bottom:16px">
              <div class="section-head">
                <h3>Waterfall de receita</h3><span style="font-size:11px;color:var(--text3)">Bruto → Cancelamentos → Taxas → Líquido</span>
              </div>
              <div id="rel-waterfall" style="padding:16px 20px"></div>
            </div>

            <!-- ── Top clientes + Recordes ───────────────────────────────────── -->
            <div class="grid-2">
              <div class="section-card">
                <div class="section-head">
                  <h3>Top 5 clientes do período</h3>
                </div>
                <div id="rel-top-clientes"></div>
              </div>
              <div class="section-card">
                <div class="section-head">
                  <h3>Recordes históricos</h3>
                </div>
                <div id="rel-records" style="padding:14px 16px;display:flex;flex-direction:column;gap:10px"></div>
              </div>
            </div>

            <!-- ── Lista de pedidos ──────────────────────────────────────────── -->
            <div class="section-card">
              <div class="section-head">
                <h3 id="rel-lista-title">Lista de pedidos do período</h3>
              </div>
              <div class="data-table-wrap" style="border:none;border-radius:0">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Data/Hora</th>
                      <th>Consumo</th>
                      <th>Pagamento</th>
                      <th>Itens</th>
                      <th>Total</th>
                      <th>Status</th>
                      <th>Origem</th>
                    </tr>
                  </thead>
                  <tbody id="rel-lista"></tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ─── ESTRATÉGIAS & INSIGHTS ──────────────────────────────────── -->
          <div class="panel" id="panel-estrategias">

            <!-- Header com título e atualização -->
            <div class="est-header">
              <div>
                <h2 class="est-title">Central de Estratégias</h2>
                <p class="est-subtitle">Análise automática com base nos dados de hoje</p>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <span id="est-last-update" style="font-size:11px;color:var(--text3)"></span>
                <button class="btn btn-secondary btn-sm" onclick="loadEstrategias()" id="btn-est-refresh">↺ Atualizar</button>
              </div>
            </div>

            <!-- KPIs do dia -->
            <div class="est-section-label">Resumo do dia</div>
            <div class="est-kpi-row" id="est-resumo">
              <div class="est-kpi-skeleton"></div>
              <div class="est-kpi-skeleton"></div>
              <div class="est-kpi-skeleton"></div>
              <div class="est-kpi-skeleton"></div>
            </div>

            <!-- Estratégias 2x2 -->
            <div class="est-section-label">Estratégias ativas</div>
            <div class="est-strat-grid">

              <!-- Combos inteligentes -->
              <div class="est-strat-card" style="--est-accent:#ff5500">
                <div class="est-strat-card-header">
                  <div class="est-strat-icon" style="background:rgba(255,85,0,.12);color:#ff5500">🎁</div>
                  <div>
                    <div class="est-strat-title">Combos inteligentes</div>
                    <div class="est-strat-sub">Pares mais pedidos juntos</div>
                  </div>
                  <div class="est-strat-badge-count" id="est-combos-count">—</div>
                </div>
                <div id="est-combos" class="est-strat-body"></div>
                <button class="est-strat-cta" onclick="switchTab('relatorios')">Ver análise completa →</button>
              </div>

              <!-- Horário ocioso -->
              <div class="est-strat-card" style="--est-accent:#3b82f6">
                <div class="est-strat-card-header">
                  <div class="est-strat-icon" style="background:rgba(59,130,246,.12);color:#3b82f6">📊</div>
                  <div>
                    <div class="est-strat-title">Movimento por horário</div>
                    <div class="est-strat-sub">Últimos 30 dias</div>
                  </div>
                </div>
                <div id="est-horarios" class="est-strat-body"></div>
                <button class="est-strat-cta" onclick="switchTab('relatorios')">Ver mapa de calor →</button>
              </div>

              <!-- Fidelização -->
              <div class="est-strat-card" style="--est-accent:#22c55e">
                <div class="est-strat-card-header">
                  <div class="est-strat-icon" style="background:rgba(34,197,94,.12);color:#22c55e">♻️</div>
                  <div>
                    <div class="est-strat-title">Fidelização</div>
                    <div class="est-strat-sub">Taxa de retorno de clientes</div>
                  </div>
                </div>
                <div id="est-fidelizacao" class="est-strat-body"></div>
                <a href="clientes/" target="_blank" class="est-strat-cta">Gerenciar clientes →</a>
              </div>

              <!-- Produtos parados -->
              <div class="est-strat-card" style="--est-accent:#8b5cf6">
                <div class="est-strat-card-header">
                  <div class="est-strat-icon" style="background:rgba(139,92,246,.12);color:#8b5cf6">📉</div>
                  <div>
                    <div class="est-strat-title">Produtos parados</div>
                    <div class="est-strat-sub">Sem venda nos últimos 7 dias</div>
                  </div>
                  <div class="est-strat-badge-count" id="est-parados-count" style="background:rgba(239,68,68,.12);color:#ef4444">—</div>
                </div>
                <div id="est-parados" class="est-strat-body"></div>
                <button class="est-strat-cta" onclick="switchTab('produtos')">Gerenciar produtos →</button>
              </div>

            </div>

            <!-- Insights -->
            <div class="est-section-label">Insights automáticos para hoje</div>
            <div class="est-insights-wrap" id="est-insights">
              <div class="est-insights-loading">Carregando insights...</div>
            </div>

            <!-- Ações -->
            <div class="est-actions-row">
              <button class="est-action-big" onclick="switchTab('relatorios')">
                <span class="est-action-big-icon">📈</span>
                <div>
                  <div class="est-action-big-title">Relatórios</div>
                  <div class="est-action-big-sub">Análise completa do período</div>
                </div>
              </button>
              <a href="relatorio_pdf.php?data_ini=<?= date('Y-m-01') ?>&data_fim=<?= date('Y-m-d') ?>" target="_blank" class="est-action-big">
                <span class="est-action-big-icon">🖨️</span>
                <div>
                  <div class="est-action-big-title">PDF do mês</div>
                  <div class="est-action-big-sub">Relatório executivo</div>
                </div>
              </a>
              <a href="clientes/" target="_blank" class="est-action-big">
                <span class="est-action-big-icon">⭐</span>
                <div>
                  <div class="est-action-big-title">Fidelidade</div>
                  <div class="est-action-big-sub">CRM e programa de pontos</div>
                </div>
              </a>
              <a href="estoque/" target="_blank" class="est-action-big">
                <span class="est-action-big-icon">📦</span>
                <div>
                  <div class="est-action-big-title">Estoque</div>
                  <div class="est-action-big-sub">Controle de insumos</div>
                </div>
              </a>
            </div>
          </div>

          <!-- ─── ESTOQUE (iframe embutido) ───────────────────────────────── -->
          <div class="panel" id="panel-estoque">
            <iframe id="estoque-frame" src="estoque/?embedded=1" title="Gestão de Estoque"></iframe>
          </div>

          <?php if ($isAdmin): ?>
            <!-- ─── USUÁRIOS ────────────────────────────────────────────────── -->
            <!-- ─── USUÁRIOS — Lista ──────────────────────────────────────── -->
            <div class="panel" id="panel-usr-lista">
              <div class="toolbar">
                <button class="btn btn-primary" id="btn-new-user">+ Novo usuário</button>
              </div>
              <div class="data-table-wrap">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Nome</th>
                      <th>E-mail</th>
                      <th>Papel</th>
                      <th>Status</th>
                      <th>Último login</th>
                      <th>Logins</th>
                      <th style="width:160px"></th>
                    </tr>
                  </thead>
                  <tbody id="user-tbody"></tbody>
                </table>
              </div>
            </div>

            <!-- ─── USUÁRIOS — Permissões ──────────────────────────────────── -->
            <div class="panel" id="panel-usr-permissoes" style="padding:0!important">
            <div style="display:flex;flex-direction:column;height:100%">

              <!-- Cabeçalho fixo do seletor -->
              <div style="background:var(--surf);border-bottom:1px solid var(--border);padding:16px 24px;flex-shrink:0">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                  <!-- Seletor -->
                  <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:260px">
                    <div style="width:36px;height:36px;border-radius:8px;background:rgba(139,92,246,.12);color:var(--purple);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">🔑</div>
                    <div>
                      <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;font-weight:600">Editando permissões de</div>
                      <select id="perm-user-sel" onchange="loadPermissoes(parseInt(this.value))" style="background:transparent;border:none;color:var(--text);font-size:16px;font-weight:700;font-family:inherit;outline:none;cursor:pointer;margin-top:2px;max-width:280px">
                        <option value="">— Selecione um usuário —</option>
                      </select>
                    </div>
                  </div>
                  <!-- Info do usuário -->
                  <div id="perm-user-info" style="display:flex;align-items:center;gap:10px"></div>
                  <!-- Botão salvar com nome dinâmico -->
                  <button class="btn btn-primary" id="btn-salvar-perm" onclick="salvarPermissoes()" style="display:none;gap:8px;padding:10px 20px">
                    💾 <span id="btn-salvar-perm-label">Salvar</span>
                  </button>
                </div>

                <!-- Banner aviso admin -->
                <div id="perm-admin-notice" style="display:none;margin-top:12px;background:rgba(255,85,0,.08);border:1px solid rgba(255,85,0,.2);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--acc)">
                  ⚠️ Usuários <strong>Admin</strong> têm acesso total ao sistema — as permissões abaixo não restringem admins.
                </div>

                <!-- Barra de progresso / contagem total -->
                <div id="perm-bar-wrap" style="display:none;margin-top:12px">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <span style="font-size:12px;font-weight:600;color:var(--text2)" id="perm-total-badge">0 permissões ativas</span>
                    <div style="display:flex;gap:6px">
                      <button class="btn btn-sm" style="background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.25);font-size:11px" onclick="toggleTodos(true)">✅ Marcar tudo</button>
                      <button class="btn btn-sm" style="background:rgba(239,68,68,.08);color:var(--red);border:1px solid rgba(239,68,68,.2);font-size:11px" onclick="toggleTodos(false)">✕ Desmarcar tudo</button>
                    </div>
                  </div>
                  <div style="height:4px;background:var(--card2);border-radius:4px;overflow:hidden">
                    <div id="perm-progress-bar" style="height:100%;background:var(--purple);border-radius:4px;transition:width .3s;width:0%"></div>
                  </div>
                </div>
              </div>

              <!-- Estado vazio -->
              <div id="perm-empty" style="display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:12px;color:var(--text3);padding:60px 0">
                <div style="font-size:48px;opacity:.3">🔑</div>
                <div style="font-size:15px;font-weight:600">Selecione um usuário acima</div>
                <div style="font-size:13px">Escolha quem você quer editar no seletor "Editando permissões de"</div>
              </div>

              <!-- Grid de permissões (scrollável) -->
              <div id="perm-grupos" style="display:none;overflow-y:auto;flex:1;padding:16px 24px">

                <?php
                // ── Árvore hierárquica de permissões ─────────────────────────────────
                // Estrutura: grupo → páginas → ações dependentes
                $permTree = [
                  ['key' => 'painel', 'label' => 'Painel Administrativo', 'ico' => '📊', 'c' => '#ff5500', 'bg' => 'rgba(255,85,0,.08)', 'pages' => [
                    ['k' => 'painel.dashboard',   'l' => 'Dashboard',            'i' => '📈', 'a' => []],
                    ['k' => 'painel.pedidos',     'l' => 'Pedidos',              'i' => '📋', 'a' => [
                      ['k' => 'acao.ped_ver_detalhes', 'l' => 'Ver detalhes'],
                      ['k' => 'acao.ped_cancelar',    'l' => 'Cancelar pedido'],
                      ['k' => 'acao.ped_alterar_status', 'l' => 'Alterar status'],
                      ['k' => 'acao.ped_filtrar',     'l' => 'Filtrar / buscar'],
                      ['k' => 'acao.ped_reimprimir',  'l' => 'Reimprimir'],
                    ]],
                    ['k' => 'painel.produtos',    'l' => 'Produtos',             'i' => '🛍️', 'a' => [
                      ['k' => 'acao.prod_criar',          'l' => 'Criar produto'],
                      ['k' => 'acao.prod_editar',         'l' => 'Editar produto'],
                      ['k' => 'acao.prod_excluir',        'l' => 'Excluir produto'],
                      ['k' => 'acao.prod_toggle',         'l' => 'Ativar / desativar'],
                      ['k' => 'acao.prod_upload_imagem',  'l' => 'Upload de imagem'],
                    ]],
                    ['k' => 'painel.categorias',  'l' => 'Categorias',           'i' => '🏷️', 'a' => [
                      ['k' => 'acao.prod_criar_cat',  'l' => 'Criar categoria'],
                      ['k' => 'acao.prod_editar_cat', 'l' => 'Editar categoria'],
                      ['k' => 'acao.prod_excluir_cat', 'l' => 'Excluir categoria'],
                    ]],
                    ['k' => 'painel.relatorios',  'l' => 'Relatórios',           'i' => '📊', 'a' => [
                      ['k' => 'acao.fin_exportar_pdf', 'l' => 'Exportar PDF'],
                      ['k' => 'acao.fin_enviar_email', 'l' => 'Enviar por e-mail'],
                    ]],
                    ['k' => 'painel.estrategias', 'l' => 'Estratégias',          'i' => '🎯', 'a' => []],
                    ['k' => 'painel.dre',         'l' => 'DRE &amp; Despesas',   'i' => '💵', 'a' => [
                      ['k' => 'acao.fin_lancar_despesa',  'l' => 'Lançar despesa'],
                      ['k' => 'acao.fin_excluir_despesa', 'l' => 'Excluir despesa'],
                    ]],
                    ['k' => 'painel.cardapio',    'l' => 'Análise Cardápio',     'i' => '🍽️', 'a' => []],
                    ['k' => 'painel.auditoria',   'l' => 'Log de Auditoria',     'i' => '🛡️', 'a' => [
                      ['k' => 'acao.sys_auditoria', 'l' => 'Ver log completo'],
                    ]],
                  ]],
                  ['key' => 'op', 'label' => 'Operação', 'ico' => '⚙️', 'c' => '#3b82f6', 'bg' => 'rgba(59,130,246,.08)', 'pages' => [
                    ['k' => 'op.caixa_turnos',   'l' => 'Caixa &amp; Turnos',     'i' => '🏧', 'a' => [
                      ['k' => 'acao.op_abrir_turno',         'l' => 'Abrir turno'],
                      ['k' => 'acao.op_fechar_turno',        'l' => 'Fechar turno'],
                      ['k' => 'acao.op_processar_pagamento', 'l' => 'Processar pagamento'],
                      ['k' => 'acao.op_imprimir',            'l' => 'Imprimir comprovante'],
                    ]],
                    ['k' => 'op.garcom_app',     'l' => 'App Garçom',             'i' => '👨‍🍳', 'a' => [
                      ['k' => 'acao.op_item_comanda', 'l' => 'Adicionar à comanda'],
                    ]],
                    ['k' => 'op.mesas_admin',    'l' => 'Gestão de Mesas',        'i' => '🪑', 'a' => [
                      ['k' => 'acao.op_gerenciar_mesa', 'l' => 'Abrir / fechar mesa'],
                      ['k' => 'acao.op_fechar_conta',  'l' => 'Fechar conta'],
                    ]],
                    ['k' => 'op.delivery_admin', 'l' => 'Gestão de Delivery',     'i' => '🛵', 'a' => [
                      ['k' => 'acao.op_atribuir_entregador', 'l' => 'Atribuir entregador'],
                      ['k' => 'acao.op_status_entrega',     'l' => 'Atualizar status'],
                      ['k' => 'acao.op_zonas_delivery',     'l' => 'Gerenciar zonas'],
                    ]],
                    ['k' => 'op.fidelidade',     'l' => 'Fidelidade &amp; CRM',   'i' => '⭐', 'a' => [
                      ['k' => 'acao.cli_ver',           'l' => 'Ver clientes'],
                      ['k' => 'acao.cli_exportar',      'l' => 'Exportar lista'],
                      ['k' => 'acao.cli_criar_cupom',   'l' => 'Criar / editar cupom'],
                      ['k' => 'acao.cli_aplicar_cupom', 'l' => 'Aplicar cupom'],
                      ['k' => 'acao.cli_ajustar_pontos', 'l' => 'Ajustar pontos'],
                      ['k' => 'acao.cli_config_pontos', 'l' => 'Configurar pontos'],
                    ]],
                    ['k' => 'op.metas',          'l' => 'Metas de Faturamento',   'i' => '🎯', 'a' => [
                      ['k' => 'acao.fin_definir_meta', 'l' => 'Definir meta'],
                    ]],
                    ['k' => 'op.webhooks',       'l' => 'Webhooks n8n/WPP',       'i' => '📲', 'a' => [
                      ['k' => 'acao.sys_testar_webhook', 'l' => 'Testar integração'],
                    ]],
                  ]],
                  ['key' => 'estoque', 'label' => 'Estoque', 'ico' => '📦', 'c' => '#22c55e', 'bg' => 'rgba(34,197,94,.08)', 'pages' => [
                    ['k' => 'estoque.insumos',       'l' => 'Insumos',              'i' => '🧴', 'a' => [
                      ['k' => 'acao.est_criar_insumo', 'l' => 'Criar / editar insumo'],
                    ]],
                    ['k' => 'estoque.movimentacoes', 'l' => 'Movimentações',        'i' => '↕️', 'a' => [
                      ['k' => 'acao.est_movimentar', 'l' => 'Registrar entrada/saída'],
                    ]],
                    ['k' => 'estoque.fichas',        'l' => 'Fichas Técnicas',      'i' => '🍽️', 'a' => [
                      ['k' => 'acao.est_fichas', 'l' => 'Criar / editar ficha'],
                    ]],
                    ['k' => 'estoque.relatorio',     'l' => 'Relatório Estoque',    'i' => '📊', 'a' => []],
                    ['k' => 'estoque.inteligente',   'l' => 'Estoque Inteligente',  'i' => '🧠', 'a' => [
                      ['k' => 'acao.est_sugestao_ia', 'l' => 'Sugestões de compra'],
                      ['k' => 'acao.est_recalcular', 'l' => 'Recalcular indicadores'],
                    ]],
                    ['k' => 'estoque.lotes',         'l' => 'Lotes &amp; Rastreio', 'i' => '📋', 'a' => [
                      ['k' => 'acao.est_lotes', 'l' => 'Gerenciar lotes'],
                    ]],
                    ['k' => 'estoque.compras',       'l' => 'Compras / Sugestões',  'i' => '🛒', 'a' => []],
                    ['k' => 'estoque.desperdicio',   'l' => 'Desperdício',          'i' => '⚠️', 'a' => [
                      ['k' => 'acao.est_desperdicio', 'l' => 'Registrar desperdício'],
                    ]],
                  ]],
                  ['key' => 'tela', 'label' => 'Telas &amp; Apps', 'ico' => '🖥️', 'c' => '#8b5cf6', 'bg' => 'rgba(139,92,246,.08)', 'pages' => [
                    ['k' => 'tela.totem',     'l' => 'Totem',                'i' => '🖥️', 'a' => []],
                    ['k' => 'tela.kds',       'l' => 'KDS — Cozinha',        'i' => '👨‍🍳', 'a' => []],
                    ['k' => 'tela.caixa_pdv', 'l' => 'Caixa PDV',           'i' => '💰', 'a' => []],
                    ['k' => 'tela.painel_tv', 'l' => 'Painel TV',            'i' => '📺', 'a' => []],
                    ['k' => 'tela.entregador', 'l' => 'App Entregador',       'i' => '🛵', 'a' => []],
                    ['k' => 'tela.rastreio',  'l' => 'Rastreio de Entrega',  'i' => '📍', 'a' => []],
                    ['k' => 'tela.pontos',    'l' => 'Pontos no Totem',      'i' => '⭐', 'a' => []],
                    ['k' => 'tela.status',    'l' => 'Status do Pedido',     'i' => '📱', 'a' => []],
                  ]],
                  [
                    'key' => 'cfg',
                    'label' => 'Configurações',
                    'ico' => '⚙️',
                    'c' => '#f97316',
                    'bg' => 'rgba(249,115,22,.08)',
                    'note' => 'Controlam quais abas de Configurações cada Admin pode ver. Não aparecem para Operador/Cozinha.',
                    'pages' => [
                      ['k' => 'cfg.loja',         'l' => 'Dados da Loja',   'i' => '🏪', 'a' => []],
                      ['k' => 'cfg.totem_kds',    'l' => 'Totem &amp; KDS', 'i' => '🖥️', 'a' => []],
                      ['k' => 'cfg.pagamentos',   'l' => 'Pagamentos',       'i' => '💳', 'a' => []],
                      ['k' => 'cfg.impressora',   'l' => 'Impressora',       'i' => '🖨️', 'a' => []],
                      ['k' => 'cfg.fidelidade',   'l' => 'Fidelidade',       'i' => '⭐', 'a' => []],
                      ['k' => 'cfg.integracoes',  'l' => 'Integrações',      'i' => '📲', 'a' => []],
                      ['k' => 'cfg.alertas',      'l' => 'Alertas',          'i' => '🔔', 'a' => []],
                      ['k' => 'cfg.backup',       'l' => 'Backup',           'i' => '💾', 'a' => []],
                      ['k' => 'cfg.seguranca_2fa', 'l' => 'Segurança / 2FA', 'i' => '🔐', 'a' => []],
                      ['k' => 'cfg.email',        'l' => 'E-mail SMTP',      'i' => '📧', 'a' => []],
                    ]
                  ],
                  [
                    'key' => 'sys',
                    'label' => 'Sistema &amp; Admin',
                    'ico' => '🔐',
                    'c' => '#ef4444',
                    'bg' => 'rgba(239,68,68,.08)',
                    'note' => 'Ações do sistema que não dependem de uma página específica.',
                    'pages' => [
                      ['k' => 'acao.sys_criar_usuario', 'l' => 'Criar usuário',       'i' => '👤', 'a' => []],
                      ['k' => 'acao.sys_editar_usuario', 'l' => 'Editar usuário',      'i' => '✏️', 'a' => []],
                      ['k' => 'acao.sys_permissoes',    'l' => 'Gerenciar permissões', 'i' => '🔑', 'a' => []],
                      ['k' => 'acao.sys_backup',        'l' => 'Backup do banco',     'i' => '💾', 'a' => []],
                      ['k' => 'acao.sys_2fa',           'l' => 'Configurar 2FA',      'i' => '🔒', 'a' => []],
                      ['k' => 'acao.sys_email_agora',   'l' => 'Enviar relatório agora', 'i' => '📧', 'a' => []],
                      ['k' => 'acao.sys_recalcular_kpi', 'l' => 'Recalcular KPIs',     'i' => '📊', 'a' => []],
                    ]
                  ],
                ];

                foreach ($permTree as $grp):
                  $gk  = $grp['key'];
                  $gc  = $grp['c'];
                  $gbg = $grp['bg'];
                ?>
                  <div class="perm-group" data-grp="<?= $gk ?>" style="--ps-c:<?= $gc ?>;--ps-bg:<?= $gbg ?>">
                    <div class="perm-group-hdr" onclick="togglePermAccordion(this.closest('.perm-group'))">
                      <span class="perm-group-ico"><?= $grp['ico'] ?></span>
                      <span class="perm-group-name"><?= $grp['label'] ?></span>
                      <span class="perm-grp-count">0/0</span>
                      <button class="btn btn-sm" style="margin-left:4px;background:var(--ps-bg);color:var(--ps-c);border:1px solid var(--ps-c);font-size:11px" onclick="event.stopPropagation();togglePermGroup('<?= $gk ?>',true)">Todos</button>
                      <span class="perm-acc-chevron"><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></span>
                    </div>
                    <div class="perm-acc-body">
                      <?php if (!empty($grp['note'])): ?>
                        <div class="perm-grp-note">ℹ️ <?= $grp['note'] ?></div>
                      <?php endif; ?>
                      <?php foreach ($grp['pages'] as $pg):
                        $pk = $pg['k'];
                        $pl = $pg['l'];
                        $pi = $pg['i'];
                        $pa = $pg['a'];
                        $hasActions = !empty($pa);
                      ?>
                        <div class="perm-page-card" data-page="<?= $pk ?>">
                          <div class="perm-page-hdr">
                            <span class="perm-page-ico"><?= $pi ?></span>
                            <span class="perm-page-label"><?= $pl ?></span>
                            <label class="toggle-sw" onclick="event.stopPropagation()">
                              <input type="checkbox" class="perm-page-inp" data-perm="<?= $pk ?>" onchange="onPageToggle(this)">
                              <span class="toggle-track"></span>
                            </label>
                          </div>
                          <?php if ($hasActions): ?>
                            <div class="perm-page-actions">
                              <?php foreach ($pa as $ac): ?>
                                <div class="perm-action-row disabled" data-action="<?= $ac['k'] ?>">
                                  <span class="perm-action-label"><?= $ac['l'] ?></span>
                                  <label class="toggle-sw">
                                    <input type="checkbox" class="perm-action-inp" data-perm="<?= $ac['k'] ?>" onchange="atualizarContagens()">
                                    <span class="toggle-track" style="transform:scale(.8);transform-origin:right center"></span>
                                  </label>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

              </div><!-- /perm-grupos -->

              <div id="perm-empty" class="cfg-tip" style="margin:40px auto;text-align:center;max-width:400px">
                👆 Selecione um usuário acima para configurar as permissões de acesso
              </div>
            </div><!-- /flex-wrapper -->
            </div><!-- /panel-usr-permissoes -->

            <!-- ─── USUÁRIOS — Atividade ───────────────────────────────────── -->
            <div class="panel" id="panel-usr-atividade">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(59,130,246,.12);color:var(--blue)">📋</div>
                  <div>
                    <div class="cfg-title">Atividade dos Usuários</div>
                    <div class="cfg-sub">Histórico de logins, sessões e ações recentes por usuário</div>
                  </div>
                </div>
              </div>
              <div class="cfg-section">
                <div class="cfg-section-title">Filtrar por usuário</div>
                <div class="cfg-card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                  <div class="field" style="flex:1;min-width:220px">
                    <label>Usuário</label>
                    <select id="ativ-user-sel" onchange="loadAtividade(parseInt(this.value))">
                      <option value="">Todos</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="cfg-section">
                <div class="cfg-card" id="ativ-lista" style="min-height:200px">
                  <div style="text-align:center;color:var(--text3);padding:40px">Selecione um usuário para ver sua atividade</div>
                </div>
              </div>
            </div>

            <!-- ═══ SAÚDE FISCAL ════════════════════════════════════════════ -->
            <div class="panel" id="panel-saude-fiscal">
              <div class="fiscal-panel">

                <!-- Status banner -->
                <div id="sf-banner" class="sf-status-banner">
                  <div class="sf-status-ico">✓</div>
                  <div style="flex:1">
                    <div class="sf-status-title" id="sf-banner-title">TUDO OPERANDO NORMALMENTE</div>
                    <div class="sf-status-sub" id="sf-banner-sub">Notas autorizadas em tempo real. Pré-validação ativa antes de cada envio.</div>
                  </div>
                  <div style="display:flex;align-items:center;gap:10px;margin-left:auto">
                    <span id="sf-ambiente-badge" style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:12px;background:rgba(245,158,11,.12);color:var(--gold)">HOMOLOGAÇÃO</span>
                    <div class="sf-live-badge">
                      <span class="pulse-dot"></span>
                      ao vivo · <span id="sf-clock">00:00:00</span>
                    </div>
                  </div>
                </div>

                <!-- KPIs -->
                <div class="sf-kpi-grid">
                  <div class="sf-kpi">
                    <div class="sf-kpi-label">Vendas hoje</div>
                    <div class="sf-kpi-value" id="sf-vendas">—</div>
                  </div>
                  <div class="sf-kpi">
                    <div class="sf-kpi-label">Vendas que não pararam</div>
                    <div class="sf-kpi-value orange" id="sf-sem-parar">R$ 0,00</div>
                    <div class="sf-kpi-sub">que um sistema comum teria travado</div>
                  </div>
                  <div class="sf-kpi">
                    <div class="sf-kpi-label">Taxa de autorização</div>
                    <div class="sf-kpi-value green" id="sf-taxa">—</div>
                  </div>
                  <div class="sf-kpi">
                    <div class="sf-kpi-label">Rejeições evitadas</div>
                    <div class="sf-kpi-value orange" id="sf-rejeicoes-evitadas">—</div>
                    <div class="sf-kpi-sub">pela pré-validação</div>
                  </div>
                </div>

                <!-- ── Wizard de configuração NFC-e (mostra quando não configurado) ── -->
                <div id="sf-config-block" style="background:var(--card);border:1px solid rgba(245,158,11,.3);border-left:3px solid var(--gold);border-radius:12px;padding:20px 24px">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                    <div>
                      <div style="font-size:14px;font-weight:700;color:var(--text)">⚙️ Configurar NFC-e</div>
                      <div style="font-size:12px;color:var(--text3);margin-top:2px">Preencha os dados fiscais para ativar a emissão real de notas</div>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="salvarConfigNfce()">💾 Salvar</button>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
                    <div class="field"><label>CNPJ do emitente *</label><input type="text" id="sf-cfg-cnpj" placeholder="00.000.000/0001-00" maxlength="18"></div>
                    <div class="field"><label>Inscrição Estadual</label><input type="text" id="sf-cfg-ie" placeholder="IE ou ISENTO"></div>
                    <div class="field"><label>UF (estado)</label>
                      <select id="sf-cfg-uf">
                        <option value="AC">AC</option><option value="AL">AL</option><option value="AP">AP</option>
                        <option value="AM">AM</option><option value="BA">BA</option><option value="CE">CE</option>
                        <option value="DF" selected>DF</option><option value="ES">ES</option><option value="GO">GO</option>
                        <option value="MA">MA</option><option value="MT">MT</option><option value="MS">MS</option>
                        <option value="MG">MG</option><option value="PA">PA</option><option value="PB">PB</option>
                        <option value="PR">PR</option><option value="PE">PE</option><option value="PI">PI</option>
                        <option value="RJ">RJ</option><option value="RN">RN</option><option value="RS">RS</option>
                        <option value="RO">RO</option><option value="RR">RR</option><option value="SC">SC</option>
                        <option value="SP">SP</option><option value="SE">SE</option><option value="TO">TO</option>
                      </select>
                    </div>
                    <div class="field"><label>Regime tributário</label>
                      <select id="sf-cfg-regime">
                        <option value="1">Simples Nacional</option>
                        <option value="2">Lucro Presumido</option>
                        <option value="3">Lucro Real</option>
                      </select>
                    </div>
                    <div class="field"><label>Série NFC-e</label><input type="text" id="sf-cfg-serie" placeholder="001" maxlength="3" value="001"></div>
                    <div class="field"><label>CSC (código seg. contribuinte)</label><input type="text" id="sf-cfg-csc" placeholder="CSC da SEFAZ"></div>
                    <div class="field"><label>Id do CSC</label><input type="text" id="sf-cfg-csc_id" placeholder="000001" maxlength="6"></div>
                    <div class="field"><label>Validade certificado A1</label><input type="date" id="sf-cfg-cert_validade"></div>
                    <div class="field"><label>Ambiente</label>
                      <select id="sf-cfg-ambiente">
                        <option value="homologacao">Homologação (testes)</option>
                        <option value="producao">Produção (real)</option>
                      </select>
                    </div>
                    <div class="field" style="justify-content:flex-end;flex-direction:row;align-items:center;gap:10px;padding-top:20px">
                      <label style="font-size:13px;font-weight:600">Ativo</label>
                      <label class="toggle-sw"><input type="checkbox" id="sf-cfg-ativo"><span class="toggle-track"></span></label>
                    </div>
                  </div>
                  <div style="margin-top:12px;font-size:11px;color:var(--text3)">
                    ⚠️ <strong>Atenção:</strong> o CSC é obtido no portal da SEFAZ da sua UF. Valide os parâmetros fiscais com seu contador antes de ativar em produção.
                  </div>
                </div>

                <!-- ── Barra de testes Fase 2 ── -->
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--card2);border-radius:10px;border:1px solid var(--border)">
                  <span style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Fase 2 — Testar emissão mock</span>
                  <div style="display:flex;gap:6px;margin-left:auto">
                    <button class="btn btn-sm" style="background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.3)" onclick="testarEmissao('autorizar')">✅ Autorizada</button>
                    <button class="btn btn-sm" style="background:rgba(239,68,68,.08);color:var(--red);border:1px solid rgba(239,68,68,.2)" onclick="testarEmissao('rejeitar')">✕ Rejeitada</button>
                    <button class="btn btn-sm" style="background:rgba(139,92,246,.08);color:var(--purple);border:1px solid rgba(139,92,246,.2)" onclick="testarEmissao('contingencia')">⚡ Contingência</button>
                  </div>
                </div>

                <!-- Grid principal -->
                <div class="sf-main-grid">

                  <!-- Esquerda: emissão ao vivo -->
                  <div class="sf-card">
                    <div class="sf-card-hdr">
                      Emissão ao vivo
                      <span class="sf-card-hdr-badge">NFC-e · modelo 65</span>
                    </div>
                    <div class="sf-card-body" id="sf-emissao-list">
                      <div style="text-align:center;padding:40px;color:var(--text3);font-size:13px">Carregando...</div>
                    </div>
                  </div>

                  <!-- Direita -->
                  <div class="sf-right-col">

                    <!-- Fila de contingência -->
                    <div class="sf-card">
                      <div class="sf-card-hdr">Fila de contingência</div>
                      <div class="sf-card-body" style="padding:16px" id="sf-contingencia">
                        <div class="sf-contingencia-count" id="sf-cont-count">0</div>
                        <div class="sf-contingencia-label">nota(s) aguardando</div>
                        <div class="sf-contingencia-ok" id="sf-cont-status">Vazia. Tudo transmitido e autorizado.</div>
                      </div>
                    </div>

                    <!-- Rejeições em português -->
                    <div class="sf-card">
                      <div class="sf-card-hdr">Rejeições, em português</div>
                      <div class="sf-card-body" id="sf-rejeicoes-list">
                        <div class="sf-rejeicao-empty">Nenhuma pendência. Quando a SEFAZ rejeitar algo, o erro aparece traduzido aqui.</div>
                      </div>
                    </div>

                    <!-- Monitor de prazos -->
                    <div class="sf-card">
                      <div class="sf-card-hdr">Monitor de prazos</div>
                      <div class="sf-card-body" id="sf-prazos">
                        <div class="sf-prazo-row">
                          <div>
                            <div class="sf-prazo-nome">Certificado digital A1</div>
                            <div class="sf-prazo-sub">renovação já agendada</div>
                          </div>
                          <div class="sf-prazo-badge alert">vence em 23 dias</div>
                        </div>
                        <div class="sf-prazo-row">
                          <div>
                            <div class="sf-prazo-nome">Transmissão de contingência</div>
                            <div class="sf-prazo-sub">limite legal monitorado</div>
                          </div>
                          <div class="sf-prazo-badge ok">dentro do prazo</div>
                        </div>
                        <div class="sf-prazo-row">
                          <div>
                            <div class="sf-prazo-nome">Sequência de numeração</div>
                            <div class="sf-prazo-sub">nenhum salto detectado</div>
                          </div>
                          <div class="sf-prazo-badge ok">sem falhas</div>
                        </div>
                      </div>
                    </div>

                  </div><!-- /sf-right-col -->
                </div><!-- /sf-main-grid -->
              </div><!-- /fiscal-panel -->
            </div><!-- /panel-saude-fiscal -->

            <!-- ═══ RADAR FISCAL ══════════════════════════════════════════════ -->
            <div class="panel" id="panel-radar-fiscal">
              <div class="fiscal-panel">

                <!-- Header -->
                <div class="rf-header">
                  <div>
                    <div class="rf-brand" style="font-size:11px;margin-bottom:2px">☕ Café Comunhão · Sistema de Gestão</div>
                    <div style="font-size:20px;font-weight:900;letter-spacing:1px">
                      <span style="color:var(--text)">RADAR</span>
                      <span style="color:var(--acc)"> FISCAL</span>
                    </div>
                    <div style="font-size:11px;color:var(--text3);margin-top:2px">conformidade fiscal em tempo real — dados do seu sistema</div>
                  </div>
                  <div class="rf-version" style="text-align:right">
                    <div id="rf-score" style="display:flex;flex-direction:column;align-items:flex-end">
                      <div style="font-size:28px;font-weight:900;color:var(--text3)">—%</div>
                      <div style="font-size:11px;color:var(--text3)">conformidade</div>
                    </div>
                    <div style="font-size:11px;color:var(--text3);margin-top:4px" id="rf-ultima-ver">verificando...</div>
                  </div>
                </div>

                <!-- Banner de status (dinâmico) -->
                <div id="rf-banner" class="sf-status-banner">
                  <div class="sf-status-ico">↻</div>
                  <div>
                    <div class="sf-status-title">CARREGANDO DADOS DE CONFORMIDADE...</div>
                    <div class="sf-status-sub">Verificando produtos, configurações e status da Reforma Tributária.</div>
                  </div>
                </div>

                <!-- Grid 2 colunas -->
                <div class="rf-grid">

                  <!-- Esquerda: checklist de conformidade (dinâmico) -->
                  <div class="sf-card">
                    <div class="sf-card-hdr">
                      <span><span class="pulse-dot" style="margin-right:6px"></span>Checklist de conformidade</span>
                      <span id="rf-alertas-badge" style="display:none;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px;background:rgba(239,68,68,.12);color:var(--red)"></span>
                    </div>
                    <div id="rf-checklist" style="padding:8px 16px">
                      <div style="color:var(--text3);font-size:12px;padding:20px 0;text-align:center">Carregando...</div>
                    </div>
                  </div>

                  <!-- Direita: fontes + NTs -->
                  <div style="display:flex;flex-direction:column;gap:12px">

                    <!-- Fontes monitoradas (estático) -->
                    <div class="sf-card">
                      <div class="sf-card-hdr">Fontes monitoradas</div>
                      <div class="sf-card-body">
                        <div class="rf-fonte-row"><div class="rf-fonte-dot"></div><div><div class="rf-fonte-nome">Portal da NF-e / SEFAZ</div><div class="rf-fonte-sub">Notas Técnicas e esquemas XML</div></div></div>
                        <div class="rf-fonte-row"><div class="rf-fonte-dot"></div><div><div class="rf-fonte-nome">Receita Federal</div><div class="rf-fonte-sub">Comunicados CBS / Imposto Seletivo</div></div></div>
                        <div class="rf-fonte-row"><div class="rf-fonte-dot"></div><div><div class="rf-fonte-nome">Comitê Gestor do IBS</div><div class="rf-fonte-sub">Regras e tabelas do IBS</div></div></div>
                        <div class="rf-fonte-row" style="border:none"><div class="rf-fonte-dot"></div><div><div class="rf-fonte-nome">Tabelas de códigos</div><div class="rf-fonte-sub">cClassTrib, cCredPres, CST/CSOSN</div></div></div>
                      </div>
                    </div>

                    <!-- Notas Técnicas (dinâmico) -->
                    <div class="sf-card">
                      <div class="sf-card-hdr">
                        <span>Notas Técnicas <span id="rf-nt-nova-badge" style="display:none;background:rgba(245,158,11,.15);color:var(--gold);font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;margin-left:6px"></span></span>
                        <div style="display:flex;gap:6px">
                          <button id="btn-verificar-nt" onclick="verificarNovasNTs()" style="background:var(--card2);border:1px solid var(--border);border-radius:6px;color:var(--text2);font-size:11px;padding:3px 10px;cursor:pointer">↻ Verificar</button>
                          <button onclick="abrirModalNt()" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);border-radius:6px;color:var(--blue);font-size:11px;padding:3px 10px;cursor:pointer">+ Adicionar</button>
                        </div>
                      </div>
                      <div id="rf-nt-list" class="sf-card-body">
                        <div style="color:var(--text3);font-size:12px;padding:16px;text-align:center">Carregando...</div>
                      </div>
                    </div>

                    <!-- Modal adicionar NT -->
                    <div id="modal-add-nt" class="overlay" style="display:none">
                      <div class="modal" style="width:420px">
                        <h3>Adicionar Nota Técnica</h3>
                        <div style="display:flex;flex-direction:column;gap:12px;margin-top:16px">
                          <div class="field"><label>Código *</label><input type="text" id="nt-codigo" placeholder="Ex: NT 2025.001 ou NT RTC"></div>
                          <div class="field"><label>Título</label><input type="text" id="nt-titulo" placeholder="Descrição da nota técnica"></div>
                          <div class="field"><label>Data de publicação</label><input type="date" id="nt-data"></div>
                          <div class="field"><label>Status inicial</label>
                            <select id="nt-status">
                              <option value="nova">Nova (requer análise)</option>
                              <option value="analisada">Analisada</option>
                              <option value="aplicada">Aplicada</option>
                              <option value="ignorada">Ignorada</option>
                            </select>
                          </div>
                        </div>
                        <div class="modal-actions">
                          <button class="btn btn-secondary" onclick="closeModal('modal-add-nt')">Cancelar</button>
                          <button class="btn btn-primary" onclick="salvarNt()">Salvar</button>
                        </div>
                      </div>
                    </div>

                  </div>
                </div><!-- /rf-grid -->

                <!-- Timeline da Reforma (dinâmico) -->
                <div class="sf-card" style="margin-top:0">
                  <div class="sf-card-hdr">📅 Timeline — Reforma Tributária</div>
                  <div id="rf-timeline-list" class="sf-card-body" style="padding:8px 0">
                    <div style="color:var(--text3);font-size:12px;padding:16px;text-align:center">Carregando...</div>
                  </div>
                </div>

                <!-- Alertas ativos (dinâmico) -->
                <div class="sf-card" style="margin-top:0">
                  <div class="sf-card-hdr">🔔 Alertas de conformidade</div>
                  <div id="rf-alertas-list" class="sf-card-body">
                    <div style="color:var(--text3);font-size:12px;padding:16px;text-align:center">Carregando...</div>
                  </div>
                </div>

              </div><!-- /fiscal-panel -->
            </div><!-- /panel-radar-fiscal -->

            <!-- ─── AUDITORIA ──────────────────────────────────────────────── -->
            <div class="panel" id="panel-auditoria">
              <div class="toolbar">
                <div class="toolbar-search"><span>🔍</span><input type="text" id="aud-busca" placeholder="Filtrar por descrição..."></div>
                <select id="aud-acao">
                  <option value="">Todas ações</option>
                </select>
                <input type="date" id="aud-ini">
                <input type="date" id="aud-fim">
                <button class="btn btn-secondary" id="btn-aud-load">Filtrar</button>
              </div>
              <div class="data-table-wrap">
                <div class="data-table-head">
                  <h3 id="aud-count">Log de auditoria</h3>
                </div>
                <div id="aud-list" style="min-height:200px"></div>
                <div class="pagination" id="aud-pagination"></div>
              </div>
            </div>
            <!-- ─── CONFIGURAÇÕES — 8 sub-painéis ────────────────────────── -->
            <!-- ── CFG: LOJA ──────────────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-loja">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(255,85,0,.12);color:var(--acc)">🏪</div>
                  <div>
                    <div class="cfg-title">Informações da Loja</div>
                    <div class="cfg-sub">Dados que aparecem no totem, notas e relatórios</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgLoja()">💾 Salvar</button>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Dados principais</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field" style="flex:2"><label>Nome da loja *</label><input type="text" id="cfg-nome" placeholder="Café Comunhão"></div>
                    <div class="field"><label>CNPJ</label><input type="text" id="cfg-cnpj" placeholder="00.000.000/0001-00" maxlength="18"></div>
                  </div>
                  <div class="form-row">
                    <div class="field" style="flex:2"><label>Endereço completo</label><input type="text" id="cfg-endereco" placeholder="SCS Quadra 04, Bloco A — Brasília"></div>
                    <div class="field"><label>Telefone / WhatsApp</label><input type="text" id="cfg-telefone" placeholder="(61) 98000-0000"></div>
                  </div>
                  <div class="form-row">
                    <div class="field"><label>E-mail de contato</label><input type="email" id="cfg-email" placeholder="contato@cafecomunhao.com"></div>
                    <div class="field"><label>Instagram</label><input type="text" id="cfg-instagram" placeholder="@cafecomunhao"></div>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Identidade visual</div>
                <div class="cfg-card">
                  <div class="field"><label>URL da Logo (deixe vazio para usar texto)</label><input type="text" id="cfg-logo" placeholder="https://..."></div>
                  <div class="form-row">
                    <div class="field"><label>URL base do Totem <small style="color:var(--text3)">(para QR Code nas notas)</small></label><input type="text" id="cfg-url" placeholder="http://192.168.1.237/totem"><small style="color:var(--text3);font-size:11px">Sem /status no final</small></div>
                    <div class="field"><label>Mensagem de boas-vindas no totem</label><input type="text" id="cfg-msg-boasvindas" placeholder="Bem-vindo! O que vai querer hoje?" maxlength="60"></div>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Horários de funcionamento</div>
                <div class="cfg-tip" style="margin-bottom:12px">ℹ️ Informativo — aparece no rodapé do totem e pode ser usado para alertas automáticos.</div>
                <div class="cfg-horario-grid" id="cfg-horarios-grid">
                  <?php foreach (['seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado', 'dom' => 'Domingo'] as $k => $v): ?>
                    <div class="cfg-horario-card">
                      <div class="cfg-horario-day">
                        <span><?= $v ?></span>
                        <label class="toggle-sw"><input type="checkbox" id="cfg-h-<?= $k ?>-ativo" checked><span class="toggle-track"></span></label>
                      </div>
                      <div class="form-row" style="gap:8px">
                        <div class="field"><label style="font-size:10px">Abertura</label><input type="time" id="cfg-h-<?= $k ?>-ab" value="08:00" style="background:var(--card2);border:1px solid var(--border2);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;padding:6px 10px;outline:none;width:100%"></div>
                        <div class="field"><label style="font-size:10px">Fechamento</label><input type="time" id="cfg-h-<?= $k ?>-fc" value="18:00" style="background:var(--card2);border:1px solid var(--border2);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;padding:6px 10px;outline:none;width:100%"></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div id="cfg-loja-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: TOTEM & KDS ──────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-totem">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(59,130,246,.12);color:var(--blue)">🖥️</div>
                  <div>
                    <div class="cfg-title">Totem &amp; KDS</div>
                    <div class="cfg-sub">Comportamento do autoatendimento e da cozinha</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgTotem()">💾 Salvar</button>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Totem — autoatendimento</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field"><label>Inatividade antes de voltar ao início (s)</label><input type="number" id="cfg-idle" min="30" max="600" placeholder="120"><small style="color:var(--text3);font-size:11px">Recomendado: 60-120s</small></div>
                    <div class="field"><label>Contagem regressiva na confirmação (s)</label><input type="number" id="cfg-confirm" min="10" max="120" placeholder="30"></div>
                    <div class="field"><label>Máx. itens por pedido</label><input type="number" id="cfg-max-itens" min="1" max="50" placeholder="20"></div>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Horário de funcionamento</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field">
                      <label>Aviso de fechamento (minutos antes)</label>
                      <input type="number" id="cfg-aviso-fechamento" min="0" max="60" placeholder="10" value="10">
                      <small style="color:var(--text3);font-size:11px">0 = desativado · Exibe alerta no totem X min antes de fechar</small>
                    </div>
                    <div class="field">
                      <label>Auto-recarga da página (minutos)</label>
                      <input type="number" id="cfg-autoreload" min="0" max="1440" placeholder="0" value="0">
                      <small style="color:var(--text3);font-size:11px">0 = desativado · Recarrega automaticamente a cada X min</small>
                    </div>
                  </div>
                  <div class="cfg-tip">
                    💡 A auto-recarga garante que novas configurações (horários, mensagens, produtos) sejam aplicadas sem intervenção manual no totem.
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">KDS — cozinha</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field"><label>Intervalo de atualização do KDS (s)</label><input type="number" id="cfg-kds-refresh" min="2" max="60" placeholder="5"><small style="color:var(--text3);font-size:11px">Recomendado: 5-10s</small></div>
                    <div class="field"><label>Alerta sonoro no KDS ao novo pedido</label>
                      <select id="cfg-kds-som">
                        <option value="0">Desativado</option>
                        <option value="1">Bip simples</option>
                        <option value="2">Notificação</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Taxa de serviço</div>
                <div class="cfg-card">
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Cobrar taxa de serviço</div>
                      <div class="cfg-toggle-sub">Aplica % sobre o subtotal do pedido</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-taxa-serv-ativa"><span class="toggle-track"></span></label>
                  </div>
                  <div class="field"><label>Percentual da taxa de serviço (%)</label><input type="number" id="cfg-taxa-servico" min="0" max="30" step="0.5" placeholder="10"></div>
                </div>
              </div>
              <div id="cfg-totem-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: PAGAMENTOS ──────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-pagamentos">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(34,197,94,.12);color:var(--green)">💳</div>
                  <div>
                    <div class="cfg-title">Pagamentos</div>
                    <div class="cfg-sub">Métodos aceitos, taxas de operadoras e PIX</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgPagamentos()">💾 Salvar</button>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Métodos aceitos no totem</div>
                <div class="cfg-card">
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">PIX</div>
                      <div class="cfg-toggle-sub">QR Code gerado na tela</div>
                    </div><label class="toggle-sw"><input type="checkbox" id="cfg-pag-pix" checked><span class="toggle-track"></span></label>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Cartão de Crédito</div>
                      <div class="cfg-toggle-sub">Pagamento via maquininha</div>
                    </div><label class="toggle-sw"><input type="checkbox" id="cfg-pag-credito" checked><span class="toggle-track"></span></label>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Cartão de Débito</div>
                      <div class="cfg-toggle-sub">Pagamento via maquininha</div>
                    </div><label class="toggle-sw"><input type="checkbox" id="cfg-pag-debito" checked><span class="toggle-track"></span></label>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Dinheiro</div>
                      <div class="cfg-toggle-sub">Pagamento no caixa</div>
                    </div><label class="toggle-sw"><input type="checkbox" id="cfg-pag-dinheiro" checked><span class="toggle-track"></span></label>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Taxas de operadoras (para cálculo de custo real)</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field"><label>Taxa cartão de crédito (%)</label><input type="number" id="cfg-taxa-cred" step="0.01" min="0" max="10" placeholder="2.5"></div>
                    <div class="field"><label>Taxa cartão de débito (%)</label><input type="number" id="cfg-taxa-deb" step="0.01" min="0" max="10" placeholder="1.5"></div>
                    <div class="field"><label>Taxa PIX (%)</label><input type="number" id="cfg-taxa-pix" step="0.01" min="0" max="5" placeholder="0" value="0"></div>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">PIX — QR Code automático</div>
                <div class="cfg-card">
                  <div class="field"><label>Chave PIX</label><input type="text" id="cfg-pix-chave" placeholder="CNPJ, CPF, email, telefone ou chave aleatória"></div>
                  <div class="form-row">
                    <div class="field"><label>Nome do beneficiário <small style="color:var(--text3)">(máx 25 chars)</small></label><input type="text" id="cfg-pix-benef" maxlength="25" placeholder="Cafe Comunhao"></div>
                    <div class="field"><label>Cidade <small style="color:var(--text3)">(máx 15 chars, sem acento)</small></label><input type="text" id="cfg-pix-cidade" maxlength="15" placeholder="BRASILIA"></div>
                  </div>
                </div>
              </div>
              <div id="cfg-pag-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: IMPRESSORA ──────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-impressora">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(107,114,128,.12);color:var(--text2)">🖨️</div>
                  <div>
                    <div class="cfg-title">Impressora Térmica</div>
                    <div class="cfg-sub">ESC/POS via rede TCP/IP</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgImpressora()">💾 Salvar</button>
              </div>

              <div class="cfg-card">
                <div class="cfg-toggle-row">
                  <div>
                    <div class="cfg-toggle-lbl">Impressão automática ao confirmar pedido</div>
                    <div class="cfg-toggle-sub">Imprime cupom sem precisar de ação manual</div>
                  </div>
                  <label class="toggle-sw"><input type="checkbox" id="cfg-imp-ativa"><span class="toggle-track"></span></label>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Configuração de rede</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field" style="flex:2"><label>IP da impressora na rede</label><input type="text" id="cfg-imp-ip" placeholder="192.168.1.100"></div>
                    <div class="field"><label>Porta TCP</label><input type="number" id="cfg-imp-porta" value="9100" min="1" max="65535"></div>
                  </div>
                  <div class="form-row">
                    <div class="field"><label>Largura do papel</label>
                      <select id="cfg-imp-largura">
                        <option value="42">80mm — 42 colunas</option>
                        <option value="32">58mm — 32 colunas</option>
                      </select>
                    </div>
                    <div class="field"><label>Cópias por pedido</label><input type="number" id="cfg-imp-copias" min="1" max="3" value="1" placeholder="1"></div>
                    <div class="field"><label>Imprimir na cozinha (KDS)</label>
                      <select id="cfg-imp-cozinha">
                        <option value="0">Não</option>
                        <option value="1">Sim — via KDS</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              <div class="cfg-tip">
                💡 Para testar: vá em <strong>Admin → Pedidos</strong>, abra um pedido e clique em "Reimprimir".
              </div>
              <div id="cfg-imp-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: FIDELIDADE ──────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-fidelidade">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(245,158,11,.12);color:var(--gold)">⭐</div>
                  <div>
                    <div class="cfg-title">Programa de Fidelidade</div>
                    <div class="cfg-sub">Pontos, resgate e validade</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgFidelidade()">💾 Salvar</button>
              </div>

              <div class="cfg-card">
                <div class="cfg-toggle-row">
                  <div>
                    <div class="cfg-toggle-lbl">Programa de fidelidade ativo</div>
                    <div class="cfg-toggle-sub">Clientes acumulam pontos a cada compra</div>
                  </div>
                  <label class="toggle-sw"><input type="checkbox" id="cfg-fid-ativa"><span class="toggle-track"></span></label>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Regras de pontuação</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field"><label>Pontos por R$ 1,00 gasto</label><input type="number" id="cfg-fid-pts-real" step="0.1" min="0.1" placeholder="1.0"><small style="color:var(--text3);font-size:11px">Ex: 1 = ganha 1 ponto por real</small></div>
                    <div class="field"><label>Valor de 1 ponto em R$</label><input type="number" id="cfg-fid-real-pts" step="0.01" min="0.01" placeholder="0.05"><small style="color:var(--text3);font-size:11px">Ex: 0.05 = 20 pts = R$ 1,00</small></div>
                  </div>
                  <div class="form-row">
                    <div class="field"><label>Validade dos pontos (dias)</label><input type="number" id="cfg-fid-val-dias" min="30" placeholder="365"><small style="color:var(--text3);font-size:11px">0 = sem validade</small></div>
                    <div class="field"><label>Mínimo de pontos para resgatar</label><input type="number" id="cfg-fid-min-resgate" min="1" placeholder="100"></div>
                    <div class="field"><label>Máx. desconto por resgate (%)</label><input type="number" id="cfg-fid-max-desc" min="1" max="100" placeholder="20"><small style="color:var(--text3);font-size:11px">% máximo do pedido em pontos</small></div>
                  </div>
                </div>
              </div>

              <div id="cfg-fid-preview" style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;font-size:13px;color:var(--text2)">
                💡 <strong>Simulação:</strong> Um pedido de <strong>R$ 25,00</strong> gera <span id="cfg-fid-sim-pts" style="color:var(--gold);font-weight:700">—</span> pontos, valendo <span id="cfg-fid-sim-val" style="color:var(--green);font-weight:700">—</span>.
              </div>
              <div id="cfg-fid-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: INTEGRAÇÕES ──────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-integracoes">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(139,92,246,.12);color:var(--purple)">📲</div>
                  <div>
                    <div class="cfg-title">Integrações Externas</div>
                    <div class="cfg-sub">n8n, WhatsApp, webhooks e automações</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgIntegracoes()">💾 Salvar</button>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">n8n — automação</div>
                <div class="cfg-card">
                  <div class="field">
                    <label>URL base do webhook n8n</label>
                    <input type="text" id="cfg-n8n-url" placeholder="http://192.168.1.100:5678/webhook">
                    <small style="color:var(--text3);font-size:11px">Sem barra no final</small>
                  </div>
                  <div class="field">
                    <label>Número WhatsApp para alertas</label>
                    <input type="text" id="cfg-n8n-whatsapp" placeholder="5561999999999" maxlength="20">
                    <small style="color:var(--text3);font-size:11px">DDI + DDD + número, sem espaços ou traços</small>
                  </div>
                  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <button class="btn btn-secondary" id="btn-testar-n8n">📡 Testar conexão</button>
                    <span id="n8n-status" style="font-size:13px;display:none"></span>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Webhook — eventos</div>
                <div class="cfg-card">
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Notificar novo pedido</div>
                      <div class="cfg-toggle-sub">Envia dados do pedido ao webhook</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-wh-pedido"><span class="toggle-track"></span></label>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Notificar mudança de status</div>
                      <div class="cfg-toggle-sub">Preparando, pronto, entregue</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-wh-status"><span class="toggle-track"></span></label>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Notificar alerta de estoque</div>
                      <div class="cfg-toggle-sub">Quando insumo atingir ROP</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-wh-estoque"><span class="toggle-track"></span></label>
                  </div>
                </div>
              </div>

              <div id="cfg-int-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: ALERTAS ─────────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-alertas">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(239,68,68,.1);color:var(--red)">🔔</div>
                  <div>
                    <div class="cfg-title">Alertas &amp; Notificações</div>
                    <div class="cfg-sub">Quando e como o sistema avisa você</div>
                  </div>
                </div>
                <button class="btn btn-primary" onclick="salvarCfgAlertas()">💾 Salvar</button>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Estoque</div>
                <div class="cfg-card">
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Alerta de estoque via WhatsApp</div>
                      <div class="cfg-toggle-sub">Envia mensagem quando insumo atingir ROP</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-alerta-zap-ativo"><span class="toggle-track"></span></label>
                  </div>
                  <div class="field"><label>Antecedência de alerta (dias antes de acabar)</label><input type="number" id="cfg-alerta-est-dias" min="1" max="30" placeholder="3"><small style="color:var(--text3);font-size:11px">Avisa quando restar menos que X dias de estoque</small></div>
                  <div class="field"><label>Alerta de validade de lote (dias)</label><input type="number" id="cfg-alerta-validade-dias" min="1" max="60" placeholder="7"><small style="color:var(--text3);font-size:11px">Avisa quando lote vencer em menos de X dias</small></div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Relatórios automáticos</div>
                <div class="cfg-card">
                  <div class="field"><label>E-mail para relatório diário</label><input type="email" id="cfg-alerta-email" placeholder="gestor@cafecomunhao.com"></div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Enviar resumo diário por e-mail</div>
                      <div class="cfg-toggle-sub">Envia às 23h com faturamento e pedidos do dia</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-email-diario"><span class="toggle-track"></span></label>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Enviar relatório semanal</div>
                      <div class="cfg-toggle-sub">Toda segunda-feira com resumo da semana</div>
                    </div>
                    <label class="toggle-sw"><input type="checkbox" id="cfg-email-semanal"><span class="toggle-track"></span></label>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Limites de atenção</div>
                <div class="cfg-card">
                  <div class="form-row">
                    <div class="field"><label>Alerta de pedido sem movimentação (min)</label><input type="number" id="cfg-alerta-pedido-min" min="5" max="120" placeholder="30"><small style="color:var(--text3);font-size:11px">Pedido preso em "preparando" por mais de X min</small></div>
                    <div class="field"><label>Alerta de caixa com muito troco (R$)</label><input type="number" id="cfg-alerta-caixa-max" min="50" placeholder="500"></div>
                  </div>
                </div>
              </div>
              <div id="cfg-alertas-status" style="display:none;font-size:13px;color:var(--green);margin-top:8px"></div>
            </div>

            <!-- ── CFG: BACKUP ──────────────────────────────────────────────── -->
            <div class="panel" id="panel-cfg-backup">
              <div class="cfg-header">
                <div class="cfg-header-left">
                  <div class="cfg-header-icon" style="background:rgba(34,197,94,.1);color:var(--green)">💾</div>
                  <div>
                    <div class="cfg-title">Backup &amp; Exportação</div>
                    <div class="cfg-sub">Exporte seus dados com segurança</div>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Exportar dados</div>
                <div class="cfg-card">
                  <div class="cfg-tip" style="margin-bottom:4px">🔒 Nenhuma exportação contém senhas em texto simples.</div>
                  <div class="form-row">
                    <div class="field"><label>Data inicial</label><input type="date" id="bkp-ini"></div>
                    <div class="field"><label>Data final</label><input type="date" id="bkp-fim"></div>
                  </div>
                  <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button class="btn btn-primary" id="btn-bkp-json">⬇ JSON completo</button>
                    <button class="btn btn-secondary" id="btn-bkp-csv">⬇ CSV pedidos</button>
                    <button class="btn btn-secondary" id="btn-bkp-pdf">🖨️ PDF relatório</button>
                  </div>
                  <div id="bkp-status" style="font-size:13px;color:var(--text2);display:none"></div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Manutenção do banco</div>
                <div class="cfg-card">
                  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button class="btn btn-secondary" id="btn-backup" onclick="fazBackup(event)">💾 Backup completo do BD</button>
                    <span style="font-size:12px;color:var(--text3)">Exporta dump SQL do PostgreSQL</span>
                  </div>
                </div>
              </div>

              <div class="cfg-section">
                <div class="cfg-section-title">Segurança da sessão</div>
                <div class="cfg-card">
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">2FA obrigatório para admins</div>
                      <div class="cfg-toggle-sub">Requer código de autenticação no login</div>
                    </div>
                    <a href="2fa/setup.php" target="_blank" class="btn btn-secondary btn-sm">Configurar →</a>
                  </div>
                  <div class="cfg-toggle-row">
                    <div>
                      <div class="cfg-toggle-lbl">Logs de auditoria</div>
                      <div class="cfg-toggle-sub">Registro de todas as ações administrativas</div>
                    </div>
                    <button class="btn btn-secondary btn-sm" onclick="cfgTab('auditoria_inline')">Ver logs →</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Manter também o panel-backup legado para o link do btn-backup na sidebar -->
            <div class="panel" id="panel-backup" style="display:none!important"></div>
            <!-- Manter panel-configuracoes legado para não quebrar o switchTab existente -->
            <div class="panel" id="panel-configuracoes" style="display:none!important">
              <!-- campos legados para o JS de save existente -->
              <input type="hidden" id="cfg-status">
              <input type="hidden" id="btn-salvar-cfg">
            </div>
            <div class="form-row">
              <div class="field" style="flex:2">
                <label>Nome da loja</label>
                <input type="text" id="cfg-nome" placeholder="Café Comunhão">
              </div>
              <div class="field">
                <label>CNPJ</label>
                <input type="text" id="cfg-cnpj" placeholder="00.000.000/0001-00">
              </div>
            </div>
            <div class="field">
              <label>Endereço</label>
              <input type="text" id="cfg-endereco" placeholder="Rua Exemplo, 123 — Bairro">
            </div>
            <div class="form-row">
              <div class="field">
                <label>Telefone</label>
                <input type="text" id="cfg-telefone" placeholder="(00) 00000-0000">
              </div>
              <div class="field" style="flex:2">
                <label>URL base do totem (para QR de rastreio na nota)</label>
                <input type="text" id="cfg-url" placeholder="http://192.168.1.237/totem">
                <small style="color:var(--text3);font-size:11px">Só o endereço base, sem /status. Ex: http://192.168.1.237/totem</small>
              </div>
            </div>
            <div class="field">
              <label>URL da Logo (deixe vazio para usar texto)</label>
              <input type="text" id="cfg-logo" placeholder="https://...">
            </div>
            <div class="form-row">
              <div class="field">
                <label>Inatividade do totem (segundos)</label>
                <input type="number" id="cfg-idle" min="30" max="600" placeholder="120">
              </div>
              <div class="field">
                <label>Contagem regressiva na confirmação (s)</label>
                <input type="number" id="cfg-confirm" min="10" max="120" placeholder="30">
              </div>
              <div class="field">
                <label>Refresh do KDS (segundos)</label>
                <input type="number" id="cfg-kds-refresh" min="2" max="60" placeholder="5">
              </div>
            </div>
            <!-- Impressora térmica -->
            <div style="border:1px solid var(--border);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:12px">
              <div style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">🖨️ Impressora Térmica (ESC/POS via rede)</div>
              <label class="form-check">
                <input type="checkbox" id="cfg-imp-ativa">
                <label for="cfg-imp-ativa">Habilitar impressão automática</label>
              </label>
              <div class="form-row">
                <div class="field" style="flex:2">
                  <label>IP da impressora</label>
                  <input type="text" id="cfg-imp-ip" placeholder="192.168.1.100">
                </div>
                <div class="field">
                  <label>Porta TCP</label>
                  <input type="number" id="cfg-imp-porta" value="9100" min="1" max="65535">
                </div>
                <div class="field">
                  <label>Papel</label>
                  <select id="cfg-imp-largura">
                    <option value="42">80mm (42 cols)</option>
                    <option value="32">58mm (32 cols)</option>
                  </select>
                </div>
              </div>
            </div>
            <!-- PIX -->
            <div style="border:1px solid var(--border);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:12px">
              <div style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">📱 PIX (QR Code no totem)</div>
              <div class="field">
                <label>Chave PIX</label>
                <input type="text" id="cfg-pix-chave" placeholder="CPF, CNPJ, email, telefone (+55...) ou chave aleatória">
              </div>
              <div class="form-row">
                <div class="field">
                  <label>Nome do beneficiário (máx 25 chars)</label>
                  <input type="text" id="cfg-pix-benef" maxlength="25" placeholder="Cafe Comunhao">
                </div>
                <div class="field">
                  <label>Cidade (máx 15 chars)</label>
                  <input type="text" id="cfg-pix-cidade" maxlength="15" placeholder="Sao Paulo">
                </div>
              </div>
            </div>
            <div style="display:flex;gap:12px;margin-top:8px">
              <button class="btn btn-primary" id="btn-salvar-cfg">Salvar configurações</button>
              <span id="cfg-status" style="align-self:center;font-size:13px;color:var(--green);display:none">Salvo!</span>
            </div>

            <!-- n8n / WhatsApp -->
            <div style="border:1px solid var(--border);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:14px;margin-top:4px">
              <div style="font-size:13px;font-weight:700;color:var(--text2);display:flex;align-items:center;gap:8px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.68A2 2 0 012 .93h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z" />
                </svg>
                Automações n8n / WhatsApp
              </div>
              <div class="field">
                <label>URL base do n8n webhook</label>
                <input type="text" id="cfg-n8n-url" placeholder="http://192.168.1.100:5678/webhook">
                <small style="color:var(--text3);font-size:11px">Sem barra no final. Ex: http://192.168.1.100:5678/webhook</small>
              </div>
              <div class="field">
                <label>Número WhatsApp destino (alertas)</label>
                <input type="text" id="cfg-n8n-whatsapp" placeholder="5511999999999" maxlength="20">
                <small style="color:var(--text3);font-size:11px">DDI + DDD + número, sem espaços. Ex: 5511999999999</small>
              </div>
              <div style="display:flex;gap:10px;align-items:center">
                <button class="btn btn-primary" id="btn-salvar-n8n">Salvar</button>
                <button class="btn" id="btn-testar-n8n" style="background:var(--card2);color:var(--text)">Testar conexão</button>
                <span id="n8n-status" style="font-size:13px;display:none"></span>
              </div>
            </div>

        </div>
    </div>
    </div>

    <!-- ─── BACKUP ────────────────────────────────────────────────────── -->
    <div class="panel" id="panel-backup">
      <div class="data-table-wrap">
        <div class="data-table-head">
          <h3>Backup e Exportação</h3>
        </div>
        <div style="padding:24px;display:flex;flex-direction:column;gap:16px;max-width:540px">
          <p style="color:var(--text2);font-size:14px;line-height:1.6">
            Exporte os dados do sistema em JSON. O arquivo contém pedidos, produtos, categorias e usuários.<br>
            <strong>Não contém senhas em texto simples.</strong>
          </p>
          <div class="form-row">
            <div class="field">
              <label>Data inicial</label>
              <input type="date" id="bkp-ini">
            </div>
            <div class="field">
              <label>Data final</label>
              <input type="date" id="bkp-fim">
            </div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" id="btn-bkp-json">⬇️ Exportar JSON</button>
            <button class="btn btn-secondary" id="btn-bkp-csv">⬇️ Exportar CSV (pedidos)</button>
            <button class="btn btn-secondary" id="btn-bkp-pdf">🖨️ Relatório PDF</button>
          </div>
          <div id="bkp-status" style="font-size:13px;color:var(--text2);display:none"></div>
        </div>
      </div>
    </div>

  <?php endif; ?>

  </div><!-- /content -->
  </main>
  </div><!-- /layout -->

  <!-- ─── MODALS ──────────────────────────────────────────────────────── -->

  <!-- Order Detail -->
  <div class="overlay" id="modal-pedido">
    <div class="modal" style="width:540px">
      <h3 id="modal-ped-title">Pedido</h3>
      <div id="modal-ped-body"></div>
      <div class="modal-actions" id="modal-ped-actions">
        <button class="btn btn-secondary" onclick="closeModal('modal-pedido')">Fechar</button>
      </div>
    </div>
  </div>

  <!-- Cancel Reason -->
  <div class="overlay" id="modal-cancel">
    <div class="modal" style="width:400px">
      <h3>Cancelar pedido</h3>
      <div class="field">
        <label>Motivo (opcional)</label>
        <textarea id="cancel-motivo" placeholder="Ex: Solicitação do cliente..."></textarea>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick="closeModal('modal-cancel')">Voltar</button>
        <button class="btn btn-danger" id="btn-confirm-cancel">Confirmar cancelamento</button>
      </div>
    </div>
  </div>

  <!-- Product Modal -->
  <div class="overlay" id="modal-prod">
    <div class="modal">
      <h3 id="modal-prod-title">Produto</h3>
      <form id="form-prod" style="display:flex;flex-direction:column;gap:14px">
        <input type="hidden" id="fp-id">
        <div class="form-row">
          <div class="field" style="flex:2">
            <label>Nome *</label>
            <input type="text" id="fp-nome" required placeholder="Ex: Cappuccino">
          </div>
          <div class="field">
            <label>Preço (R$) *</label>
            <input type="number" id="fp-preco" step="0.01" min="0.01" required placeholder="0,00">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Categoria *</label>
            <select id="fp-cat" required>
              <option value="">Selecione</option>
            </select>
          </div>
          <div class="field">
            <label>Ordem</label>
            <input type="number" id="fp-ordem" min="1" value="99">
          </div>
        </div>
        <div class="field">
          <label>Descrição</label>
          <textarea id="fp-desc" placeholder="Descrição breve..."></textarea>
        </div>
        <!-- Imagem do produto -->
        <div style="border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:10px">
          <div style="font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Imagem do produto</div>
          <div style="display:flex;align-items:center;gap:16px">
            <img id="fp-img-preview" src="" alt="" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:none">
            <div id="fp-img-placeholder" style="width:80px;height:80px;border-radius:8px;border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--text3);font-size:24px">📷</div>
            <div style="display:flex;flex-direction:column;gap:8px">
              <label class="btn btn-secondary" style="cursor:pointer;font-size:13px;padding:8px 16px">
                Selecionar imagem
                <input type="file" id="fp-img-file" accept="image/jpeg,image/png,image/webp" style="display:none">
              </label>
              <span id="fp-img-status" style="font-size:12px;color:var(--text3)">JPG, PNG ou WebP · máx 3 MB</span>
            </div>
          </div>
          <input type="hidden" id="fp-imagem">
        </div>
        <label class="form-check">
          <input type="checkbox" id="fp-destaque">
          <label for="fp-destaque">Marcar como destaque ⭐</label>
        </label>
        <div style="border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:10px">
          <label class="form-check">
            <input type="checkbox" id="fp-estoque-ativo" onchange="document.getElementById('fp-estoque-row').style.display=this.checked?'flex':'none'">
            <label for="fp-estoque-ativo">Controlar estoque deste produto</label>
          </label>
          <div class="form-row" id="fp-estoque-row" style="display:none">
            <div class="field">
              <label>Quantidade em estoque</label>
              <input type="number" id="fp-estoque-qtd" min="0" value="0">
            </div>
            <div class="field">
              <label>Alerta quando ≤</label>
              <input type="number" id="fp-estoque-alerta" min="0" value="5">
            </div>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-prod')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Salvar produto</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Category Modal -->
  <div class="overlay" id="modal-cat">
    <div class="modal" style="width:400px">
      <h3 id="modal-cat-title">Categoria</h3>
      <form id="form-cat" style="display:flex;flex-direction:column;gap:14px">
        <input type="hidden" id="fc-id">
        <div class="form-row">
          <div class="field" style="flex:2">
            <label>Nome *</label>
            <input type="text" id="fc-nome" required>
          </div>
          <div class="field">
            <label>Ícone</label>
            <input type="text" id="fc-icone" placeholder="☕">
          </div>
        </div>
        <div class="field">
          <label>Ordem</label>
          <input type="number" id="fc-ordem" min="1" value="99">
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-cat')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Salvar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- User Modal -->
  <div class="overlay" id="modal-user">
    <div class="modal" style="width:420px">
      <h3 id="modal-user-title">Usuário</h3>
      <form id="form-user" style="display:flex;flex-direction:column;gap:14px">
        <input type="hidden" id="fu-id">
        <div class="field"><label>Nome *</label><input type="text" id="fu-nome" required></div>
        <div class="field"><label>E-mail *</label><input type="email" id="fu-email" required></div>
        <div class="form-row">
          <div class="field">
            <label>Papel</label>
            <select id="fu-role">
              <option value="admin">Admin</option>
              <option value="operador" selected>Operador</option>
              <option value="cozinha">Cozinha</option>
            </select>
          </div>
          <div class="field" style="justify-content:flex-end;align-items:center;flex-direction:row;gap:10px;padding-top:20px">
            <label style="font-size:13px;font-weight:600">Ativo</label>
            <label class="toggle-sw"><input type="checkbox" id="fu-ativo" checked><span class="toggle-track"></span></label>
          </div>
        </div>
        <div class="field">
          <label>Senha <span id="fu-senha-hint" style="color:var(--text3);font-weight:400">(deixe vazio para não alterar)</span></label>
          <input type="password" id="fu-senha" placeholder="••••••••">
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-user')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Salvar</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast"></div>

  <script>
    'use strict';
    const BASE = window.location.pathname.replace(/\/[^/]*$/, '/') + 'api/';
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    const MY_PERM = <?= $isAdmin ? 'null' : json_encode($myPermissoes ?? []) ?>;

    // Mapa: permissão → como encontrar o elemento na sidebar
    const PERM_MAP = [
      // Painel
      {
        k: 'painel.dashboard',
        tab: 'dashboard'
      },
      {
        k: 'painel.pedidos',
        tab: 'pedidos'
      },
      {
        k: 'painel.produtos',
        tab: 'produtos'
      },
      {
        k: 'painel.categorias',
        tab: 'categorias'
      },
      {
        k: 'painel.relatorios',
        tab: 'relatorios'
      },
      {
        k: 'painel.estrategias',
        tab: 'estrategias'
      },
      {
        k: 'painel.dre',
        sel: 'a[href="dre/"]'
      },
      {
        k: 'painel.cardapio',
        sel: 'a[href="relatorios/cardapio.php"]'
      },
      {
        k: 'painel.auditoria',
        tab: 'auditoria'
      },
      // Operação
      {
        k: 'op.caixa_turnos',
        sel: 'a[href="caixa/turno.php"]'
      },
      {
        k: 'op.garcom_app',
        sel: 'a[href="../garcom/"]'
      },
      {
        k: 'op.mesas_admin',
        sel: 'a[href="mesas/"]'
      },
      {
        k: 'op.delivery_admin',
        sel: 'a[href="delivery/"]'
      },
      {
        k: 'op.fidelidade',
        sel: 'a[href="clientes/"]'
      },
      // Estoque sub-itens
      {
        k: 'estoque.insumos',
        est: 'insumos'
      },
      {
        k: 'estoque.movimentacoes',
        est: 'movimentacoes'
      },
      {
        k: 'estoque.fichas',
        est: 'fichas'
      },
      {
        k: 'estoque.relatorio',
        est: 'relatorio'
      },
      {
        k: 'estoque.inteligente',
        est: 'inteligente'
      },
      {
        k: 'estoque.lotes',
        est: 'lotes'
      },
      {
        k: 'estoque.compras',
        est: 'compras'
      },
      {
        k: 'estoque.desperdicio',
        est: 'desperdicio'
      },
      // Telas externas
      {
        k: 'tela.totem',
        sel: 'a[href="../"]'
      },
      {
        k: 'tela.kds',
        sel: 'a[href="../kds/"]'
      },
      {
        k: 'tela.caixa_pdv',
        sel: 'a[href="../caixa/"]'
      },
      {
        k: 'tela.painel_tv',
        sel: 'a[href="../painel/"]'
      },
      {
        k: 'tela.entregador',
        sel: 'a[href="../delivery/entregador.php"]'
      },
      {
        k: 'tela.pontos',
        sel: 'a[href="../status/fidelidade.php"]'
      },
      {
        k: 'tela.status',
        sel: 'a[href="../status/"]'
      },
      // Configurações sub-itens (admin)
      {
        k: 'cfg.loja',
        cfg: 'loja'
      },
      {
        k: 'cfg.totem_kds',
        cfg: 'totem'
      },
      {
        k: 'cfg.pagamentos',
        cfg: 'pagamentos'
      },
      {
        k: 'cfg.impressora',
        cfg: 'impressora'
      },
      {
        k: 'cfg.fidelidade',
        cfg: 'fidelidade'
      },
      {
        k: 'cfg.integracoes',
        cfg: 'integracoes'
      },
      {
        k: 'cfg.alertas',
        cfg: 'alertas'
      },
      {
        k: 'cfg.backup',
        cfg: 'backup'
      },
      {
        k: 'cfg.seguranca_2fa',
        sel: 'a[href="2fa/setup.php"]'
      },
      {
        k: 'cfg.email',
        sel: 'a[href="email/"]'
      },
      {
        k: 'cfg.backup_bd',
        sel: 'a#btn-backup'
      },
    ];

    function hasPerm(key) {
      if (IS_ADMIN || !MY_PERM) return true;
      if (!MY_PERM || Object.keys(MY_PERM).length === 0) return false;
      const [g, k] = key.split('.');
      return !!(MY_PERM[g] && MY_PERM[g][k]);
    }

    function aplicarPermissoes() {
      if (IS_ADMIN || !MY_PERM) return; // admins veem tudo

      PERM_MAP.forEach(rule => {
        const allowed = hasPerm(rule.k);
        let el = null;
        if (rule.tab) el = document.querySelector(`.nav-item[data-tab="${rule.tab}"]`);
        if (rule.sel) el = document.querySelector(rule.sel);
        if (rule.est) el = document.querySelector(`[data-est="${rule.est}"]`);
        if (rule.cfg) el = document.querySelector(`[data-cfg="${rule.cfg}"]`);
        if (el) el.style.display = allowed ? '' : 'none';
      });

      // Estoque: esconder o grupo inteiro se nenhum sub-item for permitido
      const anyEst = Object.values(MY_PERM.estoque || {}).some(Boolean);
      const estGrp = document.getElementById('nav-estoque-btn')?.closest('div');
      if (estGrp) estGrp.style.display = anyEst ? '' : 'none';

      // Cfg: esconder sub-itens que não tiver acesso (admin section já guarda o grupo)

      // Esconder sb-sections que fiquem sem nenhum item visível
      document.querySelectorAll('.sb-section').forEach(sec => {
        let next = sec.nextElementSibling;
        let hasVisible = false;
        while (next && !next.classList.contains('sb-section')) {
          if (next.style.display !== 'none') hasVisible = true;
          next = next.nextElementSibling;
        }
        sec.style.display = hasVisible ? '' : 'none';
      });

      // Redirecionar para primeiro painel permitido se o dashboard for negado
      if (!hasPerm('painel.dashboard')) {
        const first = PERM_MAP.find(r => r.tab && hasPerm(r.k));
        if (first) switchTab(first.tab);
      }
    }

    // ── Utils ────────────────────────────────────────────────────────────
    const fmt = v => 'R$ ' + parseFloat(v || 0).toFixed(2).replace('.', ',');
    const fmtDt = iso => {
      try {
        return new Date(iso).toLocaleString('pt-BR', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });
      } catch {
        return iso;
      }
    };
    const fmtDate = iso => {
      try {
        return new Date(iso).toLocaleDateString('pt-BR');
      } catch {
        return iso;
      }
    };
    const esc = s => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    function toast(msg, type = 'ok') {
      const el = document.getElementById('toast');
      el.textContent = msg;
      el.className = 'show ' + type;
      clearTimeout(el._t);
      el._t = setTimeout(() => el.className = '', 3200);
    }

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function api(path, opts = {}) {
      const headers = {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF_TOKEN,
        ...(opts.headers || {})
      };
      const res = await fetch(BASE + path, {
        ...opts,
        headers
      });
      if (res.status === 401) {
        window.location.reload();
        return {};
      }
      return res.json();
    }

    function openModal(id) {
      document.getElementById(id).classList.add('open');
    }

    function closeModal(id) {
      document.getElementById(id).classList.remove('open');
    }
    document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => {
      if (e.target === o) o.classList.remove('open');
    }));

    // ── Clock ────────────────────────────────────────────────────────────
    function tickClock() {
      document.getElementById('topbar-clock').textContent =
        new Date().toLocaleTimeString('pt-BR', {
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit'
        });
    }
    tickClock();
    setInterval(tickClock, 1000);

    // ── Tab switching ────────────────────────────────────────────────────
    const TITLES = {
      dashboard: 'Dashboard',
      pedidos: 'Pedidos',
      produtos: 'Produtos',
      categorias: 'Categorias',
      relatorios: 'Relatórios',
      estrategias: 'Estratégias',
      auditoria: 'Auditoria',
      'saude-fiscal': '🟢 Saúde Fiscal',
      'radar-fiscal': '📡 Radar Fiscal',
      estoque: 'Estoque',
      configuracoes: 'Configurações',
      backup: 'Backup',
      'cfg-loja': 'Configurações — 🏪 Loja',
      'cfg-totem': 'Configurações — 🖥️ Totem & KDS',
      'cfg-pagamentos': 'Configurações — 💳 Pagamentos',
      'cfg-impressora': 'Configurações — 🖨️ Impressora',
      'cfg-fidelidade': 'Configurações — ⭐ Fidelidade',
      'cfg-integracoes': 'Configurações — 📲 Integrações',
      'cfg-alertas': 'Configurações — 🔔 Alertas',
      'cfg-backup': 'Configurações — 💾 Backup',
      'usr-lista': 'Usuários — 👥 Lista',
      'usr-permissoes': 'Usuários — 🔑 Permissões',
      'usr-atividade': 'Usuários — 📋 Atividade'
    };

    let currentTab = 'dashboard';
    // Mapa tab → permissão necessária
    const TAB_PERM = {
      dashboard: 'painel.dashboard',
      pedidos: 'painel.pedidos',
      produtos: 'painel.produtos',
      categorias: 'painel.categorias',
      relatorios: 'painel.relatorios',
      estrategias: 'painel.estrategias',
      auditoria: 'painel.auditoria',
    };

    function switchTab(tab) {
      // Verificar permissão antes de navegar
      if (TAB_PERM[tab] && !hasPerm(TAB_PERM[tab])) {
        toast('Sem permissão para acessar esta área', 'err');
        return;
      }
      currentTab = tab;
      document.querySelectorAll('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
      document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + tab));
      document.getElementById('topbar-title').textContent = TITLES[tab] || tab;
      loadTab(tab);
    }

    document.querySelectorAll('.nav-item[data-tab]').forEach(btn =>
      btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

    function loadTab(tab) {
      switch (tab) {
        case 'dashboard':
          loadDashboard();
          break;
        case 'pedidos':
          loadPedidos();
          break;
        case 'produtos':
          loadProdutos();
          break;
        case 'categorias':
          loadCategorias();
          break;
        case 'relatorios':
          initRelatorios();
          break;
        case 'estrategias':
          loadEstrategias();
          break;
        case 'auditoria':
          loadAuditoria();
          break;
        case 'saude-fiscal':
          loadSaudeFiscal();
          break;
        case 'radar-fiscal':
          loadRadarFiscal();
          break;
        case 'configuracoes':
          loadConfiguracoes();
          break;
        case 'backup':
          initBackup();
          break;
      }
    }

    // ─────────────────────────────────────────────────────────────────────
    // ── DASHBOARD ─────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let chart7d = null;
    async function loadDashboard() {
      const today = new Date().toISOString().slice(0, 10);
      const [rel, ped] = await Promise.all([
        api('relatorios.php?data_ini=' + today + '&data_fim=' + today),
        api('pedidos.php?status=ativos'),
      ]);

      // KPIs
      if (rel.success) {
        const d = rel.data;
        document.getElementById('dash-kpis').innerHTML = [{
            label: 'Faturamento hoje',
            value: fmt(d.faturamento),
            color: 'var(--green)',
            sub: 'Pedidos pagos'
          },
          {
            label: 'Pedidos hoje',
            value: d.pedidos_total,
            color: 'var(--blue)',
            sub: 'Confirmados'
          },
          {
            label: 'Ticket médio',
            value: fmt(d.ticket_medio),
            color: 'var(--acc)',
            sub: 'Por pedido'
          },
          {
            label: 'Itens vendidos',
            value: d.itens_total,
            color: 'var(--gold)',
            sub: 'Unidades'
          },
          {
            label: 'Cancelados',
            value: d.cancelados,
            color: 'var(--red)',
            sub: 'No dia'
          },
        ].map(k =>
          '<div class="kpi-card" style="--c:' + k.color + '">' +
          '<div class="kpi-label">' + k.label + '</div>' +
          '<div class="kpi-value">' + k.value + '</div>' +
          '<div class="kpi-sub">' + k.sub + '</div>' +
          '</div>'
        ).join('');

        // 7-day chart — load last 7 days
        load7dChart();

        // Insights (margem, projeção, heatmap, previsão estoque)
        loadDashboardInsights();

        // Recent orders
        const rows = (d.pedidos_lista || []).slice(0, 10);
        document.getElementById('dash-recent-body').innerHTML = rows.map(p =>
          '<tr>' +
          '<td><strong>#' + esc(p.numero) + '</strong></td>' +
          '<td style="color:var(--text2)">' + fmtDt(p.criado_em) + '</td>' +
          '<td>' + (p.tipo_consumo === 'local' ? 'Aqui' : 'Viagem') + '</td>' +
          '<td>' + esc(p.forma_pagamento) + '</td>' +
          '<td class="price">' + fmt(p.total) + '</td>' +
          '<td><span class="badge badge-' + p.status + '">' + p.status + '</span></td>' +
          '<td><span class="badge badge-' + (p.origem || 'totem') + '">' + (p.origem || 'totem') + '</span></td>' +
          '</tr>'
        ).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:30px">Sem pedidos hoje</td></tr>';
      }

      // Active orders
      if (ped.success) {
        const ativos = ped.data || [];
        const badge = document.getElementById('badge-ativos');
        if (ativos.length > 0) {
          badge.textContent = ativos.length;
          badge.style.display = '';
        } else badge.style.display = 'none';

        const grps = {
          aguardando: [],
          preparando: [],
          pronto: []
        };
        ativos.forEach(p => {
          if (grps[p.status]) grps[p.status].push(p);
        });

        const STATUS_CLR = {
          aguardando: 'var(--gold)',
          preparando: 'var(--blue)',
          pronto: 'var(--green)'
        };
        document.getElementById('dash-ativos').innerHTML =
          Object.entries(grps).map(([st, list]) =>
            '<div style="margin-bottom:12px">' +
            '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:' + STATUS_CLR[st] + ';margin-bottom:6px">' +
            st + ' (' + list.length + ')</div>' +
            (list.length ? list.map(p =>
              '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 10px;background:var(--card2);border-radius:8px;margin-bottom:4px;font-size:13px">' +
              '<strong>#' + esc(p.numero_pedido) + '</strong>' +
              '<span style="color:var(--text2)">' + p.itens.map(i => i.qtd + 'x ' + i.nome).join(', ').slice(0, 40) + '</span>' +
              '<button class="btn btn-secondary btn-sm" onclick="openPedidoDetail(' + p.id + ')">Ver</button>' +
              '</div>'
            ).join('') : '<div style="color:var(--text3);font-size:12px;padding:6px">Nenhum</div>') +
            '</div>'
          ).join('');

        document.getElementById('dash-ativos-time').textContent =
          'Atualizado ' + new Date().toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
          });
      }
    }

    async function load7dChart() {
      const fim = new Date().toISOString().slice(0, 10);
      const ini = new Date(Date.now() - 6 * 864e5).toISOString().slice(0, 10);
      const res = await api('relatorios.php?data_ini=' + ini + '&data_fim=' + fim);
      if (!res.success) return;

      const dias = res.data.por_dia || [];
      const labels = dias.map(d => new Date(d.dia + 'T12:00').toLocaleDateString('pt-BR', {
        weekday: 'short',
        day: '2-digit'
      }));
      const values = dias.map(d => parseFloat(d.total));

      const ctx = document.getElementById('chart-7d');
      if (!ctx) return;
      if (chart7d) chart7d.destroy();
      chart7d = new Chart(ctx, {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: 'rgba(255,85,0,0.7)',
            borderRadius: 6,
            borderSkipped: false
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              },
              ticks: {
                color: '#6b7280',
                font: {
                  size: 11
                }
              }
            },
            y: {
              grid: {
                color: 'rgba(255,255,255,.05)'
              },
              ticks: {
                color: '#6b7280',
                font: {
                  size: 11
                },
                callback: v => 'R$' + v.toFixed(0)
              }
            }
          }
        }
      });
    }

    // ─────────────────────────────────────────────────────────────────────
    // ── DASHBOARD INSIGHTS ────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    async function loadDashboardInsights() {
      const res = await api('dashboard.php');
      if (!res || !res.success) return;

      const {
        fat_hoje,
        ped_hoje,
        fat_ontem,
        ped_ontem,
        custo_hoje,
        fat_mes,
        dias_passados,
        dias_mes,
        fat_mes_ant,
        heatmap,
        previsao,
      } = res;

      // ── Helpers ──
      const brl = v => 'R$ ' + parseFloat(v || 0).toFixed(2).replace('.', ',');

      function deltaHtml(novo, ant) {
        if (ant <= 0) return '';
        const d = Math.round(((novo - ant) / ant) * 1000) / 10;
        const cls = d >= 0 ? 'idelta-up' : 'idelta-dn';
        return '<span class="idelta ' + cls + '">' + (d >= 0 ? '↑' : '↓') + ' ' + Math.abs(d) + '% vs ontem</span>';
      }

      // ── INSIGHT STRIP ─────────────────────────────────────────────────
      const margemVal = Math.max(0, fat_hoje - custo_hoje);
      const margemPct = fat_hoje > 0 ? Math.round((margemVal / fat_hoje) * 1000) / 10 : 0;
      const diasP = Math.max(1, dias_passados);
      const projecao = (fat_mes / diasP) * dias_mes;
      const varMes = fat_mes_ant > 0 ? Math.round(((projecao - fat_mes_ant) / fat_mes_ant) * 1000) / 10 : null;
      const media = fat_mes / diasP;
      const varHoje = media > 0 ? Math.round(((fat_hoje / media) - 1) * 1000) / 10 : null;
      const progPct = dias_mes > 0 ? Math.round((diasP / dias_mes) * 100) : 0;

      const strip = document.getElementById('dash-insights');
      strip.innerHTML =
        // Margem bruta
        '<div class="insight-card" style="--ic:var(--green)">' +
        '<div class="insight-lbl">📊 Margem bruta estimada — hoje</div>' +
        '<div class="insight-val" style="color:var(--green)">' + brl(margemVal) + '</div>' +
        '<div class="insight-sub">Fat. ' + brl(fat_hoje) + ' − Custo ' + brl(custo_hoje) + '</div>' +
        '<div class="insight-ibar"><div class="insight-ibar-fill" style="width:' + Math.min(100, margemPct) + '%;background:var(--green)"></div></div>' +
        '<span style="font-size:12px;color:var(--green);font-weight:700">' + margemPct + '% de margem</span>' +
        (custo_hoje <= 0 ? '<span style="font-size:11px;color:var(--text3)">* Cadastre custo nos insumos</span>' : '') +
        '</div>' +
        // Projeção do mês
        '<div class="insight-card" style="--ic:var(--blue)">' +
        '<div class="insight-lbl">📈 Projeção — mês atual</div>' +
        '<div class="insight-val" style="color:var(--blue)">' + brl(projecao) + '</div>' +
        '<div class="insight-sub">Realizado: ' + brl(fat_mes) + ' (' + diasP + '/' + dias_mes + ' dias)</div>' +
        '<div class="insight-ibar"><div class="insight-ibar-fill" style="width:' + progPct + '%;background:var(--blue)"></div></div>' +
        (varMes !== null ? '<span class="idelta ' + (varMes >= 0 ? 'idelta-up' : 'idelta-dn') + '">' + (varMes >= 0 ? '↑' : '↓') + ' ' + Math.abs(varMes) + '% vs mês anterior</span>' : '') +
        '</div>' +
        // Hoje vs média
        '<div class="insight-card" style="--ic:var(--gold)">' +
        '<div class="insight-lbl">📅 Média diária — este mês</div>' +
        '<div class="insight-val" style="color:var(--gold)">' + brl(media) + '</div>' +
        '<div class="insight-sub">Base: ' + diasP + ' dias no mês</div>' +
        (varHoje !== null ? '<span class="idelta ' + (varHoje >= 0 ? 'idelta-up' : 'idelta-dn') + '">' + (varHoje >= 0 ? '↑' : '↓') + ' ' + Math.abs(varHoje) + '% — hoje vs média</span>' : '') +
        '<div style="font-size:12px;color:var(--text3);margin-top:6px">Fechamento estimado: <strong style="color:var(--text)">' + brl(projecao) + '</strong></div>' +
        '</div>';
      strip.style.display = 'grid';

      // ── HEATMAP ───────────────────────────────────────────────────────
      const hm = {};
      let hmMax = 1;
      (heatmap || []).forEach(r => {
        if (!hm[r.dow]) hm[r.dow] = {};
        hm[r.dow][r.hora] = parseInt(r.cnt);
        if (parseInt(r.cnt) > hmMax) hmMax = parseInt(r.cnt);
      });

      const DIAS_PT = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
      const HORAS = Array.from({
        length: 16
      }, (_, i) => i + 6); // 6–21
      const ORDEM = [1, 2, 3, 4, 5, 6, 0]; // Seg→Dom

      if (Object.keys(hm).length > 0) {
        let hmHtml = '<table class="hm-table"><thead><tr><th></th>';
        HORAS.forEach(h => {
          hmHtml += '<th>' + h + 'h</th>';
        });
        hmHtml += '</tr></thead><tbody>';
        ORDEM.forEach(dow => {
          hmHtml += '<tr><td class="hm-day">' + DIAS_PT[dow] + '</td>';
          HORAS.forEach(h => {
            const cnt = (hm[dow] || {})[h] || 0;
            const int = cnt / hmMax;
            let bg;
            if (int <= 0) bg = 'rgba(255,255,255,.04)';
            else if (int < 0.25) bg = 'rgba(59,130,246,' + (0.15 + int * 0.8).toFixed(2) + ')';
            else if (int < 0.6) bg = 'rgba(245,158,11,' + (0.3 + int * 0.7).toFixed(2) + ')';
            else bg = 'rgba(255,85,0,' + (0.5 + int * 0.5).toFixed(2) + ')';
            const title = cnt > 0 ? cnt + ' pedido' + (cnt != 1 ? 's' : '') + ' às ' + h + 'h (' + DIAS_PT[dow] + ')' : '';
            hmHtml += '<td title="' + title + '"><div class="hm-cell" style="background:' + bg + ';color:' + (cnt > 0 ? 'rgba(255,255,255,.7)' : 'transparent') + '">' +
              (cnt > 0 ? cnt : '') + '</div></td>';
          });
          hmHtml += '</tr>';
        });
        hmHtml += '</tbody></table>';
        hmHtml += '<div class="hm-legend"><span>Menos</span><div style="display:flex;gap:3px">';
        ['rgba(255,255,255,.04)', 'rgba(59,130,246,.3)', 'rgba(59,130,246,.6)', 'rgba(245,158,11,.5)', 'rgba(245,158,11,.8)', 'rgba(255,85,0,.7)', 'rgba(255,85,0,1)']
        .forEach(c => {
          hmHtml += '<div class="hm-swatch" style="background:' + c + '"></div>';
        });
        hmHtml += '</div><span>Mais</span></div>';
        document.getElementById('dash-heatmap').innerHTML = hmHtml;
        document.getElementById('dash-heatmap-card').style.display = '';
      }

      // ── PREVISÃO DE ESTOQUE ────────────────────────────────────────────
      if (previsao && previsao.length > 0) {
        const tbl = document.getElementById('dash-previsao');
        tbl.innerHTML =
          '<thead><tr>' +
          '<th>Insumo</th>' +
          '<th style="text-align:right">Estoque</th>' +
          '<th style="text-align:right">Consumo/dia</th>' +
          '<th style="text-align:right">Dias restantes</th>' +
          '<th>Status</th>' +
          '</tr></thead><tbody>' +
          previsao.map(p => {
            const dias2 = p.consumo_dia > 0 ? Math.floor(parseFloat(p.estoque_atual) / parseFloat(p.consumo_dia)) : 999;
            const cls = dias2 <= 3 ? 'days-crit' : (dias2 <= 7 ? 'days-warn' : 'days-ok');
            const ico = dias2 <= 3 ? '🔴' : (dias2 <= 7 ? '⚠️' : '✅');
            const lbl = dias2 >= 999 ? 'Estável' : (dias2 <= 3 ? 'Comprar urgente' : (dias2 <= 7 ? 'Comprar em breve' : 'OK por ' + dias2 + ' dias'));
            return '<tr>' +
              '<td style="font-weight:600">' + esc(p.nome) + '</td>' +
              '<td style="text-align:right;color:var(--text3);font-size:12px">' + parseFloat(p.estoque_atual).toFixed(2).replace('.', ',') + ' ' + esc(p.unidade) + '</td>' +
              '<td style="text-align:right;color:var(--text3);font-size:12px">' + parseFloat(p.consumo_dia).toFixed(2).replace('.', ',') + '/dia</td>' +
              '<td style="text-align:right;font-weight:700;font-size:15px">' + (dias2 >= 999 ? '—' : dias2) + '</td>' +
              '<td><span class="days-badge ' + cls + '">' + ico + ' ' + lbl + '</span></td>' +
              '</tr>';
          }).join('') + '</tbody>';
        document.getElementById('dash-previsao-card').style.display = '';
      }
    }

    // ─────────────────────────────────────────────────────────────────────
    // ── PEDIDOS ────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let pedPage = 1,
      pedTotal = 0,
      pedPages = 1,
      pedTimer = null;
    let cancelingId = null;

    function startPedTimer() {
      clearInterval(pedTimer);
      pedTimer = setInterval(loadPedidos, 10000);
    }

    async function loadPedidos(page) {
      if (page) pedPage = page;
      const busca = document.getElementById('ped-busca').value;
      const status = document.getElementById('ped-status').value;
      const ini = document.getElementById('ped-ini').value;
      const fim = document.getElementById('ped-fim').value;
      const origem = document.getElementById('ped-origem').value;

      const params = new URLSearchParams({
        status,
        page: pedPage
      });
      if (busca) params.set('busca', busca);
      if (ini) params.set('data_ini', ini);
      if (fim) params.set('data_fim', fim);
      if (origem) params.set('origem', origem);

      const res = await api('pedidos.php?' + params);
      if (!res.success) return;

      pedTotal = res.total;
      pedPages = res.pages;
      pedPage = res.page;
      document.getElementById('ped-count').textContent = pedTotal + ' pedido(s)';

      const S = {
        aguardando: 'Aguardando',
        preparando: 'Preparando',
        pronto: 'Pronto',
        entregue: 'Entregue',
        cancelado: 'Cancelado'
      };
      const N = {
        aguardando: 'preparando',
        preparando: 'pronto',
        pronto: 'entregue'
      };
      const NL = {
        aguardando: 'Iniciar',
        preparando: 'Pronto',
        pronto: 'Entregar'
      };

      document.getElementById('ped-tbody').innerHTML = res.data.map(p =>
        '<tr>' +
        '<td><strong>#' + esc(p.numero_pedido) + '</strong></td>' +
        '<td style="color:var(--text2);font-size:12px">' + fmtDt(p.criado_em) + '</td>' +
        '<td>' + (p.tipo_consumo === 'local' ? 'Aqui' : 'Viagem') + '</td>' +
        '<td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
        (p.itens || []).map(i => i.qtd + 'x ' + i.nome).join(', ') + '</td>' +
        '<td>' + esc(p.forma_pagamento) + '</td>' +
        '<td class="price">' + fmt(p.total) + '</td>' +
        '<td><span class="badge badge-' + p.status + '">' + (S[p.status] || p.status) + '</span></td>' +
        '<td><span class="badge badge-' + (p.origem || 'totem') + '">' + (p.origem || 'totem') + '</span></td>' +
        '<td style="white-space:nowrap">' +
        (hasPerm('acao.ped_alterar_status') && N[p.status] ? '<button class="btn btn-secondary btn-sm" style="margin-right:4px" onclick="advancePedido(' + p.id + ',\'' + N[p.status] + '\')">' + NL[p.status] + '</button>' : '') +
        (hasPerm('acao.ped_cancelar') && p.status !== 'cancelado' && p.status !== 'entregue' ? '<button class="btn btn-sm" style="background:var(--card);border:1px solid var(--border2);color:var(--text3);margin-right:4px" onclick="openCancel(' + p.id + ')">✕</button>' : '') +
        (hasPerm('acao.ped_ver_detalhes') ? '<button class="btn btn-secondary btn-sm" onclick="openPedidoDetail(' + p.id + ')">Detalhes</button>' : '') +
        '</td>' +
        '</tr>'
      ).join('') || '<tr><td colspan="9" style="text-align:center;color:var(--text3);padding:40px">Nenhum pedido encontrado</td></tr>';

      renderPagination('ped-pagination', pedPage, pedPages, p => loadPedidos(p));
      startPedTimer();
    }

    async function advancePedido(id, status) {
      const res = await api('pedidos.php', {
        method: 'POST',
        body: JSON.stringify({
          id,
          status
        })
      });
      if (res.success) {
        toast('Status atualizado!');
        loadPedidos();
      } else toast(res.error || 'Erro', 'err');
    }

    function openCancel(id) {
      cancelingId = id;
      document.getElementById('cancel-motivo').value = '';
      openModal('modal-cancel');
    }

    document.getElementById('btn-confirm-cancel').addEventListener('click', async () => {
      if (!cancelingId) return;
      const motivo = document.getElementById('cancel-motivo').value;
      const res = await api('pedidos.php', {
        method: 'POST',
        body: JSON.stringify({
          id: cancelingId,
          status: 'cancelado',
          motivo
        })
      });
      if (res.success) {
        toast('Pedido cancelado');
        closeModal('modal-cancel');
        loadPedidos();
        cancelingId = null;
      } else toast(res.error || 'Erro', 'err');
    });

    async function openPedidoDetail(id) {
      const res = await api('pedidos.php?id=' + id);
      if (!res.success) {
        toast('Pedido nao encontrado', 'err');
        return;
      }
      const p = res.pedido;
      const S = {
        aguardando: 'Aguardando',
        preparando: 'Preparando',
        pronto: 'Pronto',
        entregue: 'Entregue',
        cancelado: 'Cancelado'
      };

      document.getElementById('modal-ped-title').innerHTML = 'Pedido <span style="color:var(--acc)">#' + esc(p.numero_pedido) + '</span>';
      document.getElementById('modal-ped-body').innerHTML =
        '<div class="detail-row"><span>Status</span><span class="badge badge-' + p.status + '">' + (S[p.status] || p.status) + '</span></div>' +
        '<div class="detail-row"><span>Data/Hora</span><span>' + fmtDt(p.criado_em) + '</span></div>' +
        '<div class="detail-row"><span>Consumo</span><span>' + (p.tipo_consumo === 'local' ? 'Comer aqui' : 'Para viagem') + '</span></div>' +
        '<div class="detail-row"><span>Pagamento</span><span>' + esc(p.forma_pagamento) + '</span></div>' +
        '<div class="detail-row"><span>Origem</span><span><span class="badge badge-' + (p.origem || 'totem') + '">' + (p.origem || 'totem') + '</span></span></div>' +
        (p.cpf ? '<div class="detail-row"><span>CPF</span><span>' + esc(p.cpf) + '</span></div>' : '') +
        (p.operador_nome ? '<div class="detail-row"><span>Operador</span><span>' + esc(p.operador_nome) + '</span></div>' : '') +
        (p.cancelado_motivo ? '<div class="detail-row"><span>Motivo cancel.</span><span style="color:var(--red)">' + esc(p.cancelado_motivo) + '</span></div>' : '') +
        '<div class="detail-items" style="margin-top:14px"><table>' +
        '<thead><tr><th>Produto</th><th>Qtd</th><th>Unitário</th><th>Subtotal</th></tr></thead><tbody>' +
        (p.itens || []).map(i => '<tr><td>' + esc(i.nome_produto) + '</td><td>' + i.quantidade + '</td><td>' + fmt(i.preco_unitario) + '</td><td class="price">' + fmt(i.subtotal) + '</td></tr>').join('') +
        '</tbody></table></div>' +
        '<div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);padding-top:12px">' +
        '<span style="font-size:13px;color:var(--text2)">Total</span>' +
        '<strong style="font-size:20px;color:var(--acc)">' + fmt(p.total) + '</strong>' +
        '</div>';

      const N = {
        aguardando: 'preparando',
        preparando: 'pronto',
        pronto: 'entregue'
      };
      const NL = {
        aguardando: 'Iniciar preparo',
        preparando: 'Marcar pronto',
        pronto: 'Marcar entregue'
      };
      let actHtml = '<button class="btn btn-secondary" onclick="closeModal(\'modal-pedido\')">Fechar</button>';
      actHtml = '<button class="btn btn-secondary" onclick="imprimirPedido(' + p.id + ')" title="Imprimir comanda">🖨️ Imprimir</button>' + actHtml;
      if (N[p.status]) actHtml = '<button class="btn btn-primary" onclick="advancePedido(' + p.id + ',\'' + N[p.status] + '\');closeModal(\'modal-pedido\')">' + NL[p.status] + ' →</button>' + actHtml;
      if (p.status !== 'cancelado' && p.status !== 'entregue')
        actHtml = '<button class="btn btn-danger btn-sm" onclick="openCancel(' + p.id + ');closeModal(\'modal-pedido\')">Cancelar pedido</button>' + actHtml;
      document.getElementById('modal-ped-actions').innerHTML = actHtml;

      window._pedidoAtual = p;
      openModal('modal-pedido');
    }

    document.getElementById('ped-refresh').addEventListener('click', () => loadPedidos(1));
    ['ped-status', 'ped-ini', 'ped-fim', 'ped-origem'].forEach(id =>
      document.getElementById(id).addEventListener('change', () => loadPedidos(1)));
    document.getElementById('ped-busca').addEventListener('input',
      debounce(() => loadPedidos(1), 400));

    // ─────────────────────────────────────────────────────────────────────
    // ── PRODUTOS ──────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let allProdData = [],
      catData = [];
    let selectedProds = new Set();

    async function loadProdutos() {
      const catId = document.getElementById('pr-cat').value;
      const busca = document.getElementById('pr-busca').value;
      const params = new URLSearchParams();
      if (catId) params.set('categoria_id', catId);
      if (busca) params.set('busca', busca);

      const [prods, cats] = await Promise.all([
        api('produtos.php?' + params),
        api('categorias.php'),
      ]);

      if (cats.success) {
        catData = cats.data;
        const sel = document.getElementById('pr-cat');
        const current = sel.value;
        sel.innerHTML = '<option value="">Todas categorias</option>' +
          cats.data.map(c => '<option value="' + c.id + '">' + esc(c.nome) + '</option>').join('');
        sel.value = current;

        const fpCat = document.getElementById('fp-cat');
        fpCat.innerHTML = '<option value="">Selecione</option>' +
          cats.data.map(c => '<option value="' + c.id + '">' + esc(c.icone) + ' ' + esc(c.nome) + '</option>').join('');
      }

      if (!prods.success) return;
      allProdData = prods.data;
      selectedProds.clear();

      document.getElementById('pr-count').textContent = prods.data.length + ' produto(s)';
      document.getElementById('pr-tbody').innerHTML = prods.data.map(p =>
        '<tr data-id="' + p.id + '">' +
        '<td><input type="checkbox" class="prod-check" data-id="' + p.id + '" style="width:16px;height:16px;cursor:pointer"></td>' +
        '<td>' +
        '<div style="font-weight:600">' + esc(p.nome) + '</div>' +
        (p.descricao ? '<div style="font-size:11px;color:var(--text3)">' + esc(p.descricao.slice(0, 50)) + (p.descricao.length > 50 ? '…' : '') + '</div>' : '') +
        '</td>' +
        '<td><span style="font-size:12px">' + esc(p.cat_icone || '') + '</span> ' + esc(p.categoria) + '</td>' +
        '<td class="price">' + fmt(p.preco) + '</td>' +
        '<td>' + (hasPerm('acao.prod_toggle') ? '<label class="toggle-sw"><input type="checkbox" ' + (p.disponivel ? 'checked' : '') + ' onchange="toggleProd(' + p.id + ',this)"><span class="toggle-track"></span></label>' : '<span style="color:' + (p.disponivel ? 'var(--green)' : 'var(--text3)') + ';font-size:12px">' + (p.disponivel ? 'Ativo' : 'Inativo') + '</span>') + '</td>' +
        '<td>' + (p.destaque ? '<span style="color:var(--gold)">⭐</span>' : '—') + '</td>' +
        '<td>' + (hasPerm('acao.prod_editar') ? '<button class="btn btn-secondary btn-sm" onclick="openProdModal(' + p.id + ')">Editar</button>' : '') + '</td>' +
        '</tr>'
      ).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:40px">Nenhum produto</td></tr>';
    }

    async function toggleProd(id, el) {
      const res = await api('produtos.php', {
        method: 'POST',
        body: JSON.stringify({
          toggle_disponivel: true,
          id
        })
      });
      if (res.success) toast(res.disponivel ? 'Produto ativado' : 'Produto desativado');
      else {
        el.checked = !el.checked;
        toast(res.error || 'Erro', 'err');
      }
    }

    document.getElementById('select-all').addEventListener('change', function() {
      document.querySelectorAll('.prod-check').forEach(cb => {
        cb.checked = this.checked;
        if (this.checked) selectedProds.add(+cb.dataset.id);
        else selectedProds.delete(+cb.dataset.id);
      });
    });
    document.getElementById('pr-tbody').addEventListener('change', e => {
      const cb = e.target.closest('.prod-check');
      if (!cb) return;
      if (cb.checked) selectedProds.add(+cb.dataset.id);
      else selectedProds.delete(+cb.dataset.id);
    });

    async function bulkToggle(disp) {
      if (!selectedProds.size) {
        toast('Selecione ao menos um produto', 'err');
        return;
      }
      const res = await api('produtos.php', {
        method: 'POST',
        body: JSON.stringify({
          bulk_disponivel: true,
          ids: [...selectedProds],
          disponivel: disp
        })
      });
      if (res.success) {
        toast(res.affected + ' produto(s) ' + (disp ? 'ativado(s)' : 'desativado(s)'));
        loadProdutos();
      } else toast(res.error || 'Erro', 'err');
    }
    document.getElementById('btn-bulk-on').addEventListener('click', () => bulkToggle(true));
    document.getElementById('btn-bulk-off').addEventListener('click', () => bulkToggle(false));
    document.getElementById('btn-new-prod').addEventListener('click', () => openProdModal());
    document.getElementById('pr-busca').addEventListener('input', debounce(loadProdutos, 350));
    document.getElementById('pr-cat').addEventListener('change', loadProdutos);

    function openProdModal(id) {
      const data = id ? allProdData.find(p => p.id === id) : null;
      document.getElementById('modal-prod-title').textContent = data ? 'Editar produto' : 'Novo produto';
      document.getElementById('fp-id').value = data?.id || '';
      document.getElementById('fp-nome').value = data?.nome || '';
      document.getElementById('fp-preco').value = data?.preco || '';
      document.getElementById('fp-cat').value = data?.categoria_id || '';
      document.getElementById('fp-desc').value = data?.descricao || '';
      document.getElementById('fp-destaque').checked = data?.destaque || false;
      document.getElementById('fp-ordem').value = data?.ordem || 99;
      const estoqueAtivo = data?.controlar_estoque || false;
      document.getElementById('fp-estoque-ativo').checked = estoqueAtivo;
      document.getElementById('fp-estoque-qtd').value = data?.estoque_qtd ?? 0;
      document.getElementById('fp-estoque-alerta').value = data?.estoque_alerta ?? 5;
      document.getElementById('fp-estoque-row').style.display = estoqueAtivo ? 'flex' : 'none';
      // Image
      const imgUrl = data?.imagem || '';
      document.getElementById('fp-imagem').value = imgUrl;
      document.getElementById('fp-img-file').value = '';
      document.getElementById('fp-img-status').textContent = 'JPG, PNG ou WebP · máx 3 MB';
      if (imgUrl) {
        document.getElementById('fp-img-preview').src = imgUrl;
        document.getElementById('fp-img-preview').style.display = 'block';
        document.getElementById('fp-img-placeholder').style.display = 'none';
      } else {
        document.getElementById('fp-img-preview').style.display = 'none';
        document.getElementById('fp-img-placeholder').style.display = 'flex';
      }
      openModal('modal-prod');
    }

    document.getElementById('form-prod').addEventListener('submit', async e => {
      e.preventDefault();
      const body = {
        id: parseInt(document.getElementById('fp-id').value) || undefined,
        nome: document.getElementById('fp-nome').value,
        preco: parseFloat(document.getElementById('fp-preco').value),
        categoria_id: parseInt(document.getElementById('fp-cat').value),
        descricao: document.getElementById('fp-desc').value,
        destaque: document.getElementById('fp-destaque').checked,
        ordem: parseInt(document.getElementById('fp-ordem').value),
        controlar_estoque: document.getElementById('fp-estoque-ativo').checked,
        estoque_qtd: parseInt(document.getElementById('fp-estoque-qtd').value) || 0,
        estoque_alerta: parseInt(document.getElementById('fp-estoque-alerta').value) || 5,
        imagem: document.getElementById('fp-imagem').value || null,
      };
      if (!body.id) delete body.id;
      if (!body.imagem) delete body.imagem;
      const res = await api('produtos.php', {
        method: 'POST',
        body: JSON.stringify(body)
      });
      if (res.success) {
        toast(res.action === 'created' ? 'Produto criado!' : 'Produto atualizado!');
        closeModal('modal-prod');
        loadProdutos();
      } else toast(res.error || 'Erro', 'err');
    });

    // ── Product image upload ──────────────────────────────────────────────
    document.getElementById('fp-img-file').addEventListener('change', async function() {
      const file = this.files[0];
      if (!file) return;
      const statusEl = document.getElementById('fp-img-status');
      const previewEl = document.getElementById('fp-img-preview');
      const placeholderEl = document.getElementById('fp-img-placeholder');
      statusEl.textContent = 'Enviando...';
      statusEl.style.color = 'var(--text2)';
      const fd = new FormData();
      fd.append('imagem', file);
      fd.append('_csrf', CSRF_TOKEN);
      try {
        const res = await fetch('api/upload.php', {
          method: 'POST',
          body: fd
        });
        const data = await res.json();
        if (data.success) {
          document.getElementById('fp-imagem').value = data.url;
          previewEl.src = data.url;
          previewEl.style.display = 'block';
          placeholderEl.style.display = 'none';
          statusEl.textContent = 'Imagem carregada ✓';
          statusEl.style.color = 'var(--green)';
        } else {
          statusEl.textContent = data.error || 'Erro no upload';
          statusEl.style.color = 'var(--red)';
        }
      } catch {
        statusEl.textContent = 'Falha na conexão';
        statusEl.style.color = 'var(--red)';
      }
    });

    // ─────────────────────────────────────────────────────────────────────
    // ── CATEGORIAS ────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let allCatData = [];

    async function loadCategorias() {
      const res = await api('categorias.php');
      if (!res.success) return;
      allCatData = res.data;

      document.getElementById('cat-tbody').innerHTML = res.data.map(c =>
        '<tr>' +
        '<td style="font-size:22px;text-align:center">' + esc(c.icone) + '</td>' +
        '<td><strong>' + esc(c.nome) + '</strong></td>' +
        '<td>' + c.ordem + '</td>' +
        '<td>' + c.total_produtos + '</td>' +
        '<td><span style="color:var(--green);font-weight:600">' + c.produtos_ativos + '</span></td>' +
        '<td style="white-space:nowrap">' +
        (hasPerm('acao.prod_editar_cat') ? '<button class="btn btn-secondary btn-sm" style="margin-right:6px" onclick="openCatModal(' + c.id + ')">Editar</button>' : '') +
        (hasPerm('acao.prod_excluir_cat') && c.total_produtos == 0 ? '<button class="btn btn-danger btn-sm" onclick="deleteCategoria(' + c.id + ')">Excluir</button>' : '') +
        '</td>' +
        '</tr>'
      ).join('');
    }

    function openCatModal(id) {
      const data = id ? allCatData.find(c => c.id === id) : null;
      document.getElementById('modal-cat-title').textContent = data ? 'Editar categoria' : 'Nova categoria';
      document.getElementById('fc-id').value = data?.id || '';
      document.getElementById('fc-nome').value = data?.nome || '';
      document.getElementById('fc-icone').value = data?.icone || '';
      document.getElementById('fc-ordem').value = data?.ordem || 99;
      openModal('modal-cat');
    }

    document.getElementById('btn-new-cat').addEventListener('click', () => openCatModal());

    document.getElementById('form-cat').addEventListener('submit', async e => {
      e.preventDefault();
      const body = {
        id: parseInt(document.getElementById('fc-id').value) || undefined,
        nome: document.getElementById('fc-nome').value,
        icone: document.getElementById('fc-icone').value,
        ordem: parseInt(document.getElementById('fc-ordem').value),
      };
      if (!body.id) delete body.id;
      const res = await api('categorias.php', {
        method: 'POST',
        body: JSON.stringify(body)
      });
      if (res.success) {
        toast(res.action === 'created' ? 'Categoria criada!' : 'Categoria atualizada!');
        closeModal('modal-cat');
        loadCategorias();
      } else toast(res.error || 'Erro', 'err');
    });

    async function deleteCategoria(id) {
      if (!confirm('Excluir esta categoria? Essa acao nao pode ser desfeita.')) return;
      const res = await api('categorias.php', {
        method: 'DELETE',
        body: JSON.stringify({
          id
        })
      });
      if (res.success) {
        toast('Categoria excluida');
        loadCategorias();
      } else toast(res.error || 'Erro', 'err');
    }

    // ─────────────────────────────────────────────────────────────────────
    // ── RELATÓRIOS ────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let chartDias = null,
      chartHora = null,
      chartProj = null;

    function initRelatorios() {
      const today = new Date().toISOString().slice(0, 10);
      const ini = new Date(Date.now() - 29 * 864e5).toISOString().slice(0, 10);
      document.getElementById('rel-ini').value = ini;
      document.getElementById('rel-fim').value = today;

      // Config metas toggle
      document.getElementById('btn-cfg-metas')?.addEventListener('click', () => {
        const cfg = document.getElementById('rel-metas-cfg');
        if (cfg) cfg.style.display = cfg.style.display === 'flex' ? 'none' : 'flex';
      });

      // Salvar metas
      document.getElementById('btn-salvar-metas')?.addEventListener('click', async () => {
        const payload = {
          meta_fat_mes: document.getElementById('cfg-meta-fat')?.value || '0',
          meta_pedidos_mes: document.getElementById('cfg-meta-ped')?.value || '0',
          taxa_credito: document.getElementById('cfg-taxa-cred')?.value || '2.5',
          taxa_debito: document.getElementById('cfg-taxa-deb')?.value || '1.5',
        };
        const res = await api('configuracoes.php', {
          method: 'POST',
          body: JSON.stringify(payload)
        });
        if (res?.success) {
          toast('Configuracoes salvas!');
          loadRelatorios();
        } else toast('Erro ao salvar', 'err');
      });

      loadRelatorios();
    }

    async function loadRelatorios() {
      const ini = document.getElementById('rel-ini').value;
      const fim = document.getElementById('rel-fim').value;
      const res = await api('relatorios.php?action=analytics&data_ini=' + ini + '&data_fim=' + fim);
      if (!res?.success) {
        toast((res && res.error) || 'Erro ao carregar relatorio', 'err');
        return;
      }
      const d = res;

      // ── KPIs ─────────────────────────────────────────────────────────────
      const kpi = d.kpi || {};
      document.getElementById('rel-kpis').innerHTML = [{
          label: 'Faturamento',
          value: fmt(kpi.faturamento),
          color: 'var(--green)',
          sub: 'Sem cancelados'
        },
        {
          label: 'Pedidos',
          value: kpi.pedidos || 0,
          color: 'var(--blue)',
          sub: 'Confirmados'
        },
        {
          label: 'Ticket medio',
          value: fmt(kpi.ticket_medio),
          color: 'var(--acc)',
          sub: 'Por pedido'
        },
        {
          label: 'Itens vendidos',
          value: kpi.itens_total || 0,
          color: 'var(--gold)',
          sub: 'Unidades'
        },
        {
          label: 'Cancelados',
          value: kpi.cancelados || 0,
          color: 'var(--red)',
          sub: 'No periodo'
        },
      ].map(k =>
        '<div class="kpi-card" style="--c:' + k.color + '">' +
        '<div class="kpi-label">' + k.label + '</div>' +
        '<div class="kpi-value">' + k.value + '</div>' +
        '<div class="kpi-sub">' + k.sub + '</div>' +
        '</div>'
      ).join('');

      // ── Metas mensais ─────────────────────────────────────────────────────
      renderMetas(d.metas, d.taxas);

      // ── Custo por pagamento ───────────────────────────────────────────────
      renderCustosPagamento(d.custos_pagamento, d.total_custo_periodo, d.taxas);

      // ── Matriz Boston ─────────────────────────────────────────────────────
      renderBostonMatrix(d.produtos || []);

      // ── Pagamento bars ────────────────────────────────────────────────────
      const maxPag = Math.max(...(d.por_pagamento || []).map(r => parseFloat(r.total)), 1);
      const PAG_LABEL = {
        pix: 'PIX',
        credito: 'Credito',
        debito: 'Debito',
        dinheiro: 'Dinheiro'
      };
      const PAG_COLOR = {
        pix: 'var(--blue)',
        credito: 'var(--green)',
        debito: 'var(--gold)',
        dinheiro: 'var(--acc)'
      };
      document.getElementById('rel-pag').innerHTML = (d.por_pagamento || []).map(r =>
        '<div class="bar-row">' +
        '<div class="bar-label">' + (PAG_LABEL[r.forma_pagamento] || r.forma_pagamento) + '</div>' +
        '<div class="bar-track"><div class="bar-fill" style="width:' + ((parseFloat(r.total) / maxPag) * 100).toFixed(1) + '%;background:' + (PAG_COLOR[r.forma_pagamento] || 'var(--acc)') + '"></div></div>' +
        '<div class="bar-val">' + fmt(r.total) + '<span style="color:var(--text3);font-size:10px"> (' + r.qtd + ')</span></div>' +
        '</div>'
      ).join('') || '<div style="color:var(--text3);font-size:13px">Sem dados</div>';

      // ── Origem ────────────────────────────────────────────────────────────
      const maxOri = Math.max(...(d.por_origem || []).map(r => parseFloat(r.total)), 1);
      const ORI_COLOR = {
        totem: 'var(--purple)',
        caixa: 'var(--gold)',
        admin: 'var(--acc)'
      };
      document.getElementById('rel-origem').innerHTML = (d.por_origem || []).map(r =>
        '<div class="bar-row">' +
        '<div class="bar-label">' + esc(r.origem) + '</div>' +
        '<div class="bar-track"><div class="bar-fill" style="width:' + ((parseFloat(r.total) / maxOri) * 100).toFixed(1) + '%;background:' + (ORI_COLOR[r.origem] || 'var(--text3)') + '"></div></div>' +
        '<div class="bar-val">' + fmt(r.total) + '<span style="color:var(--text3);font-size:10px"> (' + r.qtd + ')</span></div>' +
        '</div>'
      ).join('') || '<div style="color:var(--text3);font-size:13px">Sem dados</div>';

      // ── Chart faturamento por dia ─────────────────────────────────────────
      const dias = d.por_dia || [];
      const ctxD = document.getElementById('chart-rel-dias');
      if (ctxD) {
        if (chartDias) chartDias.destroy();
        chartDias = new Chart(ctxD, {
          type: 'bar',
          data: {
            labels: dias.map(x => new Date(x.dia + 'T12:00').toLocaleDateString('pt-BR', {
              day: '2-digit',
              month: '2-digit'
            })),
            datasets: [{
              data: dias.map(x => parseFloat(x.total)),
              backgroundColor: 'rgba(255,85,0,0.7)',
              borderRadius: 5,
              borderSkipped: false
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: false
              }
            },
            scales: {
              x: {
                grid: {
                  display: false
                },
                ticks: {
                  color: '#6b7280',
                  font: {
                    size: 10
                  }
                }
              },
              y: {
                grid: {
                  color: 'rgba(255,255,255,.05)'
                },
                ticks: {
                  color: '#6b7280',
                  font: {
                    size: 10
                  },
                  callback: v => 'R$' + v.toFixed(0)
                }
              }
            }
          }
        });
      }

      // ── Chart hora pico ───────────────────────────────────────────────────
      const horas = new Array(24).fill(0);
      (d.hora_pico || []).forEach(h => {
        horas[parseInt(h.hora)] = parseInt(h.qtd);
      });
      const ctxH = document.getElementById('chart-rel-hora');
      if (ctxH) {
        if (chartHora) chartHora.destroy();
        chartHora = new Chart(ctxH, {
          type: 'bar',
          data: {
            labels: Array.from({
              length: 24
            }, (_, i) => i + 'h'),
            datasets: [{
              data: horas,
              backgroundColor: 'rgba(59,130,246,0.6)',
              borderRadius: 4,
              borderSkipped: false
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: false
              }
            },
            scales: {
              x: {
                grid: {
                  display: false
                },
                ticks: {
                  color: '#6b7280',
                  font: {
                    size: 9
                  }
                }
              },
              y: {
                grid: {
                  color: 'rgba(255,255,255,.05)'
                },
                ticks: {
                  color: '#6b7280',
                  font: {
                    size: 10
                  },
                  stepSize: 1
                }
              }
            }
          }
        });
      }

      // ── Projeção de faturamento ───────────────────────────────────────────
      renderProjecao(d.por_dia || [], d.hora_pico || []);

      // ── Top produtos ──────────────────────────────────────────────────────
      const prods = d.produtos || [];
      const maxTop = Math.max(...prods.map(r => parseInt(r.qtd)), 1);
      document.getElementById('rel-top').innerHTML = prods.slice(0, 15).map((r, i) =>
        '<div class="bar-row">' +
        '<div style="width:20px;text-align:center;color:var(--text3);font-size:11px;font-weight:700;flex-shrink:0">' + (i + 1) + '</div>' +
        '<div class="bar-label" style="width:200px;text-align:left">' + esc(r.nome_produto) + '</div>' +
        '<div class="bar-track"><div class="bar-fill" style="width:' + ((parseInt(r.qtd) / maxTop) * 100).toFixed(1) + '%;background:var(--acc)"></div></div>' +
        '<div class="bar-val">' + r.qtd + 'x <span style="color:var(--text3)"> ' + fmt(r.receita || r.total || 0) + '</span></div>' +
        '</div>'
      ).join('') || '<div style="color:var(--text3);font-size:13px">Sem dados</div>';

      // ── Cross-sell ────────────────────────────────────────────────────────
      const cs = d.crosssell || [];
      const crossEl = document.getElementById('rel-crosssell');
      if (crossEl) {
        crossEl.innerHTML = cs.length ?
          cs.map(c => {
            const precoCombo = Math.round((parseFloat(c.preco_combo) || 0) * 0.9);
            return '<div class="rel-cross-card">' +
              '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">' +
              '<span class="rel-cross-tag">📦 ' + esc(c.prod_a) + '</span>' +
              '<span style="color:var(--text3);font-size:11px">+</span>' +
              '<span class="rel-cross-tag">📦 ' + esc(c.prod_b) + '</span>' +
              '</div>' +
              '<div style="font-size:11px;color:var(--text2);margin-top:4px">' +
              'Pedidos juntos: <strong style="color:var(--text)">' + c.ocorrencias + 'x</strong>' +
              (precoCombo > 0 ? ' · Combo sugerido: <strong style="color:var(--green)">R$ ' + precoCombo + '</strong>' : '') +
              '</div>' +
              '</div>';
          }).join('') :
          '<div style="color:var(--text3);font-size:12px">Sem pares detectados no periodo</div>';
      }

      // ── Simulador E se? ───────────────────────────────────────────────────
      renderWhatIf(d.kpi || {}, d.produtos || [], d.crosssell || []);
      renderTurnos(d.por_turno || []);
      renderWaterfall(d.kpi || {}, d.total_custo_periodo || 0, d.kpi?.cancelados || 0);
      renderTopClientes(d.top_clientes || []);
      renderRecords(d.records || {});

      // ── Lista pedidos ─────────────────────────────────────────────────────
      const S = {
        aguardando: 'Aguardando',
        preparando: 'Preparando',
        pronto: 'Pronto',
        entregue: 'Entregue',
        cancelado: 'Cancelado'
      };
      document.getElementById('rel-lista').innerHTML = (d.pedidos_lista || []).map(p =>
        '<tr>' +
        '<td><strong>#' + esc(p.numero) + '</strong></td>' +
        '<td style="font-size:12px;color:var(--text2)">' + fmtDt(p.criado_em) + '</td>' +
        '<td>' + (p.tipo_consumo === 'local' ? 'Aqui' : 'Viagem') + '</td>' +
        '<td>' + esc(PAG_LABEL[p.forma_pagamento] || p.forma_pagamento) + '</td>' +
        '<td>' + p.total_itens + '</td>' +
        '<td class="price">' + fmt(p.total) + '</td>' +
        '<td><span class="badge badge-' + p.status + '">' + (S[p.status] || p.status) + '</span></td>' +
        '<td><span class="badge badge-' + (p.origem || 'totem') + '">' + (p.origem || 'totem') + '</span></td>' +
        '</tr>'
      ).join('') || '<tr><td colspan="8" style="text-align:center;color:var(--text3);padding:30px">Sem pedidos no periodo</td></tr>';
    }

    // ── Helpers de relatório ──────────────────────────────────────────────
    function renderMetas(metas, taxas) {
      if (!metas) return;

      const inFat = document.getElementById('cfg-meta-fat');
      const inPed = document.getElementById('cfg-meta-ped');
      const inCred = document.getElementById('cfg-taxa-cred');
      const inDeb = document.getElementById('cfg-taxa-deb');
      if (inFat && metas.fat_meta) inFat.value = metas.fat_meta;
      if (inPed && metas.ped_meta) inPed.value = metas.ped_meta;
      if (inCred && taxas?.credito) inCred.value = taxas.credito;
      if (inDeb && taxas?.debito) inDeb.value = taxas.debito;

      const body = document.getElementById('rel-metas-body');
      if (!body) return;

      const bars = [];

      if (metas.fat_meta > 0) {
        const pct = Math.min(100, Math.round((metas.fat_atual / metas.fat_meta) * 100));
        const cor = pct >= 100 ? 'var(--green)' : pct >= 70 ? 'var(--gold)' : 'var(--acc)';
        const proj = metas.fat_projecao;
        const projPct = Math.round((proj / metas.fat_meta) * 100);
        bars.push(
          '<div>' +
          '<div class="rel-meta-label">' +
          '<span style="font-weight:700">💰 Faturamento do mes</span>' +
          '<span style="color:' + cor + ';font-weight:800">' + pct + '% — ' + fmt(metas.fat_atual) + '</span>' +
          '</div>' +
          '<div class="rel-meta-bar-wrap"><div class="rel-meta-bar-fill" style="width:' + pct + '%;background:' + cor + '"></div></div>' +
          '<div class="rel-meta-proj">' +
          'Meta: ' + fmt(metas.fat_meta) + ' &nbsp;·&nbsp; ' +
          'Projecao: <strong style="color:' + (projPct >= 100 ? 'var(--green)' : projPct >= 80 ? 'var(--gold)' : 'var(--red)') + '">' + fmt(proj) + '</strong>' +
          ' (' + projPct + '% da meta) &nbsp;·&nbsp; Dia ' + metas.dia_atual + ' de ' + metas.dias_mes +
          '</div>' +
          '</div>'
        );
      } else {
        bars.push('<div style="color:var(--text3);font-size:12px">Meta de faturamento nao configurada. Clique em ⚙ Configurar.</div>');
      }

      if (metas.ped_meta > 0) {
        const pct = Math.min(100, Math.round((metas.ped_atual / metas.ped_meta) * 100));
        const cor = pct >= 100 ? 'var(--green)' : pct >= 70 ? 'var(--gold)' : 'var(--blue)';
        bars.push(
          '<div>' +
          '<div class="rel-meta-label">' +
          '<span style="font-weight:700">📦 Pedidos do mes</span>' +
          '<span style="color:' + cor + ';font-weight:800">' + pct + '% — ' + metas.ped_atual + ' pedidos</span>' +
          '</div>' +
          '<div class="rel-meta-bar-wrap"><div class="rel-meta-bar-fill" style="width:' + pct + '%;background:' + cor + '"></div></div>' +
          '<div class="rel-meta-proj">Meta: ' + metas.ped_meta + ' &nbsp;·&nbsp; Projecao: <strong>' + metas.ped_projecao + '</strong> pedidos</div>' +
          '</div>'
        );
      }

      body.innerHTML = bars.join('') || '<div style="color:var(--text3);font-size:12px">Configure as metas clicando em ⚙ Configurar.</div>';
    }

    function renderCustosPagamento(custos, totalCusto, taxas) {
      const el = document.getElementById('rel-custos');
      if (!el || !custos?.length) {
        if (el) el.innerHTML = '<div style="color:var(--text3);font-size:12px;padding:12px">Sem dados de pagamento no periodo.</div>';
        return;
      }
      const PAG = {
        pix: 'PIX',
        credito: 'Credito',
        debito: 'Debito',
        dinheiro: 'Dinheiro'
      };
      const totalRec = custos.reduce((s, r) => s + parseFloat(r.total), 0);
      const econPix = custos.filter(r => r.forma_pagamento !== 'pix' && r.forma_pagamento !== 'dinheiro').reduce((s, r) => s + (r.custo || 0), 0);

      let html = '<table class="rel-custo-table">' +
        '<thead><tr><th>Metodo</th><th>Receita</th><th>Taxa</th><th>Custo R$</th><th>Liquido</th></tr></thead><tbody>';
      custos.forEach(r => {
        const nome = PAG[r.forma_pagamento] || r.forma_pagamento;
        const taxaStr = r.taxa > 0 ? r.taxa.toFixed(1) + '%' : '<span style="color:var(--green)">Gratis</span>';
        const custoStr = r.custo > 0 ? '<span style="color:var(--red)">-' + fmt(r.custo) + '</span>' : '<span style="color:var(--green)">R$0,00</span>';
        html += '<tr><td style="font-weight:600">' + nome + '</td><td>' + fmt(r.total) + '</td><td>' + taxaStr + '</td><td>' + custoStr + '</td><td style="font-weight:700">' + fmt(r.liquido ?? r.total) + '</td></tr>';
      });
      html += '<tr style="font-weight:700;border-top:2px solid var(--border2,#2a2d3e)"><td>TOTAL</td><td>' + fmt(totalRec) + '</td><td></td><td style="color:var(--red)">-' + fmt(totalCusto || 0) + '</td><td style="color:var(--green)">' + fmt(totalRec - (totalCusto || 0)) + '</td></tr>';
      html += '</tbody></table>';
      if (econPix > 0.01)
        html += '<div class="rel-custo-rec">💡 Se todos os pagamentos fossem em PIX, voce economizaria <strong>R$ ' + econPix.toFixed(2).replace('.', ',') + '</strong> em taxas neste periodo.</div>';
      el.innerHTML = html;
    }

    function renderBostonMatrix(produtos) {
      const el = document.getElementById('rel-boston');
      if (!el) return;
      if (!produtos?.length) {
        el.innerHTML = '<div style="color:var(--text3);font-size:12px">Sem dados de produtos</div>';
        return;
      }

      const qtds = produtos.map(p => +p.qtd).sort((a, b) => a - b);
      const recs = produtos.map(p => +p.receita).sort((a, b) => a - b);
      const medQtd = qtds[Math.floor(qtds.length / 2)];
      const medRec = recs[Math.floor(recs.length / 2)];

      const grupos = {
        estrela: [],
        vaca: [],
        interrogacao: [],
        abacaxi: []
      };
      produtos.forEach(p => {
        const q = +p.qtd > medQtd,
          r = +p.receita > medRec;
        const key = q && r ? 'estrela' : !q && r ? 'vaca' : q && !r ? 'interrogacao' : 'abacaxi';
        grupos[key].push(p);
      });

      const defs = {
        estrela: {
          icon: '⭐',
          label: 'Estrelas',
          cor: '#fbbf24',
          tip: 'Priorize no totem, destaque visual, nunca tire do cardapio.'
        },
        vaca: {
          icon: '🐄',
          label: 'Vacas Leiteiras',
          cor: '#22c55e',
          tip: 'Alta margem — expanda com combos e promocoes.'
        },
        interrogacao: {
          icon: '❓',
          label: 'Interrogacoes',
          cor: '#3b82f6',
          tip: 'Volume alto mas receita baixa — considere ajuste de preco.'
        },
        abacaxi: {
          icon: '🍌',
          label: 'Abacaxis',
          cor: '#6b7280',
          tip: 'Baixo volume e receita — remova ou transforme em combo.'
        },
      };

      const chips = grp => grp.slice(0, 6).map(p => '<span class="boston-chip">' + esc(p.nome_produto) + ' <span style="opacity:.5">' + p.qtd + 'x</span></span>').join('');
      const keys = ['estrela', 'vaca', 'interrogacao', 'abacaxi'];

      el.innerHTML = '<div class="boston-grid">' + keys.map(k => {
        const def = defs[k];
        const grp = grupos[k];
        return '<div class="boston-quad ' + k + '">' +
          '<div class="boston-quad-title" style="color:' + def.cor + '">' + def.icon + ' ' + def.label + ' (' + grp.length + ')</div>' +
          '<div>' + (chips(grp) || '<span style="color:var(--text3);font-size:11px">Nenhum produto</span>') + '</div>' +
          '<div class="boston-tip">' + def.tip + '</div>' +
          '</div>';
      }).join('') + '</div>';
    }

    function renderProjecao(porDia, horaPico) {
      const ctxP = document.getElementById('chart-rel-proj');
      if (!ctxP || !porDia?.length) return;

      // Média por dia da semana a partir dos dados do período
      const dowSum = new Array(7).fill(0),
        dowCnt = new Array(7).fill(0);
      porDia.forEach(d => {
        const dow = new Date(d.dia + 'T12:00').getDay();
        dowSum[dow] += parseFloat(d.total) || 0;
        dowCnt[dow]++;
      });
      const dowAvg = dowSum.map((s, i) => dowCnt[i] > 0 ? s / dowCnt[i] : 0);

      // Tendência linear (regressão simples)
      const vals = porDia.map(d => parseFloat(d.total) || 0);
      const n = vals.length;
      let sumX = 0,
        sumY = 0,
        sumXY = 0,
        sumX2 = 0;
      vals.forEach((v, i) => {
        sumX += i;
        sumY += v;
        sumXY += i * v;
        sumX2 += i * i;
      });
      const slope = n > 1 ? (n * sumXY - sumX * sumY) / (n * sumX2 - sumX * sumX) : 0;
      const intercept = n > 0 ? (sumY - slope * sumX) / n : 0;

      const labelsReal = porDia.map(d => new Date(d.dia + 'T12:00').toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit'
      }));

      // Projeção: próximos 15 dias
      const labelsPrj = [],
        dataPrj = [],
        lastDate = porDia.length ? new Date(porDia[porDia.length - 1].dia + 'T12:00') : new Date();
      const lastIdx = n - 1;
      for (let i = 1; i <= 15; i++) {
        const d = new Date(lastDate);
        d.setDate(d.getDate() + i);
        labelsPrj.push(d.toLocaleDateString('pt-BR', {
          day: '2-digit',
          month: '2-digit'
        }));
        const trendVal = intercept + slope * (lastIdx + i);
        const dowVal = dowAvg[d.getDay()];
        const proj = dowVal > 0 ? (trendVal * 0.4 + dowVal * 0.6) : Math.max(0, trendVal);
        dataPrj.push(Math.round(proj * 100) / 100);
      }

      const allLabels = [...labelsReal, ...labelsPrj];
      const realData = [...vals, ...new Array(15).fill(null)];
      const projData = [...new Array(n).fill(null), ...dataPrj];
      const projHigh = dataPrj.map(v => Math.round(v * 1.20 * 100) / 100);
      const projLow = dataPrj.map(v => Math.round(v * 0.80 * 100) / 100);
      const highData = [...new Array(n).fill(null), ...projHigh];
      const lowData = [...new Array(n).fill(null), ...projLow];

      if (chartProj) chartProj.destroy();
      chartProj = new Chart(ctxP, {
        data: {
          labels: allLabels,
          datasets: [{
              type: 'line',
              label: 'Faturamento real',
              data: realData,
              borderColor: '#ff5500',
              backgroundColor: 'rgba(255,85,0,.1)',
              borderWidth: 2,
              fill: true,
              tension: .3,
              pointRadius: 3,
              spanGaps: false
            },
            {
              type: 'line',
              label: 'Projecao',
              data: projData,
              borderColor: 'rgba(59,130,246,.8)',
              borderDash: [6, 4],
              borderWidth: 2,
              fill: false,
              tension: .3,
              pointRadius: 0,
              spanGaps: false
            },
            {
              type: 'line',
              label: 'Max projecao',
              data: highData,
              borderColor: 'transparent',
              backgroundColor: 'rgba(59,130,246,.08)',
              fill: '+1',
              pointRadius: 0,
              tension: .3,
              spanGaps: false
            },
            {
              type: 'line',
              label: 'Min projecao',
              data: lowData,
              borderColor: 'transparent',
              backgroundColor: 'rgba(59,130,246,.08)',
              fill: false,
              pointRadius: 0,
              tension: .3,
              spanGaps: false
            },
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              callbacks: {
                label: c => c.dataset.label + ': ' + fmt(c.raw || 0)
              }
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              },
              ticks: {
                color: '#6b7280',
                font: {
                  size: 9
                },
                maxTicksLimit: 12
              }
            },
            y: {
              grid: {
                color: 'rgba(255,255,255,.05)'
              },
              ticks: {
                color: '#6b7280',
                font: {
                  size: 10
                },
                callback: v => v ? 'R$' + parseFloat(v).toFixed(0) : ''
              }
            },
          }
        }
      });
    }

    function renderWhatIf(kpi, produtos, crosssell) {
      const el = document.getElementById('rel-whatif');
      if (!el) return;

      const ticketMedio = parseFloat(kpi.ticket_medio) || 0;
      const pedMedio = parseFloat(kpi.pedidos) || 0;
      const topProd = produtos[0];
      const cs = crosssell[0];

      el.innerHTML =
        '<div class="rel-whatif-slider">' +
        '<div class="rel-whatif-lbl">📈 E se aumentar o preco de "' + esc(topProd?.nome_produto || 'produto principal') + '" em <span id="wi-preco-val">10</span>%?</div>' +
        '<input type="range" class="rel-slider" id="wi-preco" min="1" max="50" value="10">' +
        '<div class="rel-whatif-result" id="wi-preco-result" style="color:var(--green)">+R$0,00/mes</div>' +
        '<div class="rel-whatif-range" id="wi-preco-range"></div>' +
        '</div>' +
        (cs ?
          '<div class="rel-whatif-slider">' +
          '<div class="rel-whatif-lbl">🎁 E se criar combo "' + esc(cs.prod_a) + '" + "' + esc(cs.prod_b) + '" com desconto de <span id="wi-combo-val">10</span>%?</div>' +
          '<input type="range" class="rel-slider" id="wi-combo" min="5" max="30" value="10">' +
          '<div class="rel-whatif-result" id="wi-combo-result" style="color:var(--green)">+R$0,00/mes</div>' +
          '<div class="rel-whatif-range" id="wi-combo-range"></div>' +
          '</div>' : '') +
        '<div class="rel-whatif-slider">' +
        '<div class="rel-whatif-lbl">⏰ E se abrir <span id="wi-horas-val">1</span> hora(s) a mais por dia?</div>' +
        '<input type="range" class="rel-slider" id="wi-horas" min="1" max="4" value="1">' +
        '<div class="rel-whatif-result" id="wi-horas-result" style="color:var(--blue)">+R$0,00/mes</div>' +
        '<div class="rel-whatif-range" id="wi-horas-range"></div>' +
        '</div>';

      const topQtd = parseFloat(topProd?.qtd) || 0;
      const topRec = parseFloat(topProd?.receita) || 0;

      function calcWI() {
        const deltap = parseInt(document.getElementById('wi-preco')?.value || 10);
        const deltac = parseInt(document.getElementById('wi-combo')?.value || 10);
        const deltah = parseInt(document.getElementById('wi-horas')?.value || 1);
        const periodoMult = 30;

        // Impacto preço (receita_do_produto × delta%)
        const impPreco = topRec * deltap / 100;
        const impPes = impPreco * 0.70;
        const elPrecoV = document.getElementById('wi-preco-val');
        if (elPrecoV) elPrecoV.textContent = deltap;
        const rPreco = document.getElementById('wi-preco-result');
        if (rPreco) {
          rPreco.textContent = '+' + fmt(impPreco) + '/periodo';
          rPreco.style.color = 'var(--green)';
        }
        const rPrecoR = document.getElementById('wi-preco-range');
        if (rPrecoR) rPrecoR.textContent = 'Pessimista: +' + fmt(impPes) + ' · Otimista: +' + fmt(impPreco) + ' (se volume mantiver)';

        // Combo
        if (cs) {
          const precoCombo = parseFloat(cs.preco_combo) || 0;
          const oc = parseInt(cs.ocorrencias) || 0;
          const adocao = 0.4;
          const impCombo = oc * adocao * precoCombo * (1 - deltac / 100);
          const elComboV = document.getElementById('wi-combo-val');
          if (elComboV) elComboV.textContent = deltac;
          const rCombo = document.getElementById('wi-combo-result');
          if (rCombo) {
            rCombo.textContent = '+' + fmt(impCombo) + '/periodo';
            rCombo.style.color = 'var(--green)';
          }
          const rComboR = document.getElementById('wi-combo-range');
          if (rComboR) rComboR.textContent = 'Baseado em ' + oc + ' ocorrencias no periodo · ' + Math.round(adocao * 100) + '% adocao estimada';
        }

        // Horas extras
        const pedPorHora = ticketMedio > 0 && pedMedio > 0 ? (pedMedio / 8) : 0;
        const impHoras = deltah * pedPorHora * ticketMedio * periodoMult;
        const elHorasV = document.getElementById('wi-horas-val');
        if (elHorasV) elHorasV.textContent = deltah;
        const rHoras = document.getElementById('wi-horas-result');
        if (rHoras) {
          rHoras.textContent = '+' + fmt(impHoras) + '/mes';
          rHoras.style.color = 'var(--blue)';
        }
        const rHorasR = document.getElementById('wi-horas-range');
        if (rHorasR) rHorasR.textContent = '~' + (pedPorHora * deltah * periodoMult).toFixed(0) + ' pedidos extras/mes · Ticket medio ' + fmt(ticketMedio);
      }

      calcWI();
      ['wi-preco', 'wi-combo', 'wi-horas'].forEach(id => {
        const el2 = document.getElementById(id);
        if (el2) el2.addEventListener('input', calcWI);
      });
    }

    document.getElementById('btn-rel-load').addEventListener('click', loadRelatorios);
    document.getElementById('btn-rel-csv').addEventListener('click', () => {
      const ini = document.getElementById('rel-ini').value;
      const fim = document.getElementById('rel-fim').value;
      window.location.href = BASE + 'relatorios.php?export=csv&data_ini=' + ini + '&data_fim=' + fim;
    });

    // ─────────────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    // ── ESTRATÉGIAS & INSIGHTS ───────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    async function loadEstrategias() {
      const btn = document.getElementById('btn-est-refresh');
      if (btn) {
        btn.textContent = '↺ Carregando...';
        btn.disabled = true;
      }

      const res = await api('estrategias.php');

      if (btn) {
        btn.textContent = '↺ Atualizar';
        btn.disabled = false;
      }
      if (!res.success) {
        toast(res.error || 'Erro ao carregar estratégias', 'err');
        return;
      }

      const now = new Date().toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit'
      });
      const luEl = document.getElementById('est-last-update');
      if (luEl) luEl.textContent = `Atualizado às ${now}`;

      const d = res;
      const r = d.resumo || {};

      // ── KPI Cards ──────────────────────────────────────────────────────
      const metaTk = parseFloat(r.meta_ticket || 0);
      const horaIni = r.hora_pico_ini;
      const fatDelta = r.fat_delta_pct;

      const kpis = [{
          emoji: '💰',
          lbl: 'Faturamento hoje',
          ek: 'var(--green)',
          val: fmt(r.faturamento),
          chip: fatDelta !== null ? {
            cls: fatDelta >= 0 ? 'up' : 'dn',
            txt: (fatDelta >= 0 ? '↑' : '↓') + Math.abs(fatDelta) + '% ontem'
          } : null,
          sub: fatDelta === null ? 'sem dados de ontem' : `vs R$${parseFloat(r.faturamento||0).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')} ontem`,
        },
        {
          emoji: '🎯',
          lbl: 'Ticket médio',
          ek: 'var(--acc)',
          val: fmt(r.ticket_medio),
          chip: metaTk > 0 ? {
            cls: parseFloat(r.ticket_medio || 0) >= metaTk ? 'up' : 'dn',
            txt: parseFloat(r.ticket_medio || 0) >= metaTk ? '✓ meta' : 'abaixo'
          } : null,
          sub: metaTk > 0 ? `Meta: ${fmt(metaTk)}` : 'configure uma meta',
        },
        {
          emoji: '📦',
          lbl: 'Pedidos hoje',
          ek: 'var(--blue)',
          val: r.pedidos || 0,
          chip: (r.em_aberto || 0) > 0 ? {
            cls: '',
            txt: `${r.em_aberto} abertos`
          } : null,
          sub: `${r.cancelados||0} cancelado${r.cancelados!==1?'s':''}`,
        },
        {
          emoji: '⏰',
          lbl: 'Hora de pico',
          ek: 'var(--gold)',
          val: horaIni !== null ? `${horaIni}h–${horaIni+2}h` : '—',
          chip: r.hora_pico_qtd ? {
            cls: '',
            txt: `${r.hora_pico_qtd} pedidos`
          } : null,
          sub: 'janela de maior movimento',
        },
      ];

      document.getElementById('est-resumo').innerHTML = kpis.map(k => {
        const chipHtml = k.chip ?
          `<div class="est-kpi-chip ${k.chip.cls}">${k.chip.txt}</div>` :
          `<div style="height:20px"></div>`;
        return `<div class="est-kpi-card" style="--ek:${k.ek}">
      <div class="est-kpi-header"><span class="est-kpi-emoji">${k.emoji}</span>${chipHtml}</div>
      <div class="est-kpi-lbl">${k.lbl}</div>
      <div class="est-kpi-val">${k.val}</div>
      <div class="est-kpi-sub">${k.sub}</div>
    </div>`;
      }).join('');

      // ── Combos inteligentes ──────────────────────────────────────────
      const combos = d.combos || [];
      const combEl = document.getElementById('est-combos-count');
      if (combEl) combEl.textContent = combos.length ? `${combos.length} detectados` : '—';

      document.getElementById('est-combos').innerHTML = combos.slice(0, 4).map(c => {
        const ganho = parseFloat(c.ganho) || 0;
        const pct = c.pct || 0;
        const nameA = c.prod_a.length > 16 ? c.prod_a.slice(0, 14) + '…' : c.prod_a;
        const nameB = c.prod_b.length > 16 ? c.prod_b.slice(0, 14) + '…' : c.prod_b;
        return `<div class="est-combo-row">
      <span class="est-combo-chip" title="${esc(c.prod_a)}">${esc(nameA)}</span>
      <span class="est-combo-plus">+</span>
      <span class="est-combo-chip" title="${esc(c.prod_b)}">${esc(nameB)}</span>
      <span class="est-combo-pct" style="color:var(--text3)">${pct}%</span>
      <span class="est-combo-gain">${ganho>0?'+R$ '+ganho.toFixed(2).replace('.',','):'combo'}</span>
    </div>`;
      }).join('') || `<div style="color:var(--text3);font-size:12px;padding:8px 0;text-align:center">
    Sem combos detectados<br><span style="font-size:10px">Precisa de mais pedidos com 2+ itens</span>
  </div>`;

      // ── Horário — gráfico de barras visual ───────────────────────────
      const faixas = d.faixas_horario || [];
      const maxQtd = Math.max(...faixas.map(f => f.qtd || 0), 1);
      const COR_H = {
        forte: '#22c55e',
        normal: 'rgba(255,255,255,.2)',
        fraco: '#ef4444'
      };

      document.getElementById('est-horarios').innerHTML = faixas.length ?
        `<div class="est-hora-chart">${faixas.map(f => {
        const pct = Math.max(8, Math.round((f.qtd/maxQtd)*100));
        const cor = COR_H[f.status]||'rgba(255,255,255,.15)';
        const lbl = f.label.split('–')[0];
        return `<div class="est-hora-bar-wrap" title="${f.label}: ${f.qtd} pedidos (${f.status})">
          <div class="est-hora-bar" style="height:${pct}%;background:${cor}"></div>
          <div class="est-hora-lbl">${lbl}</div>
        </div>`;
      }).join('')}</div>
      <div style="display:flex;gap:12px;margin-top:8px;font-size:10px;color:var(--text3)">
        <span style="display:flex;align-items:center;gap:4px"><span style="width:8px;height:8px;border-radius:2px;background:#22c55e;display:inline-block"></span>Forte</span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:8px;height:8px;border-radius:2px;background:rgba(255,255,255,.2);display:inline-block"></span>Normal</span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:8px;height:8px;border-radius:2px;background:#ef4444;display:inline-block"></span>Fraco</span>
      </div>` :
        '<div style="color:var(--text3);font-size:12px;padding:8px 0">Sem dados suficientes</div>';

      // ── Fidelização — anel SVG + stats ──────────────────────────────
      const fidel = d.fidelizacao || {};
      const pctRet = Math.min(100, fidel.retorno_7dias_pct || 0);
      const radius = 36,
        circ = 2 * Math.PI * radius;
      const dash = (pctRet / 100) * circ;
      const ringCol = pctRet >= 60 ? '#22c55e' : pctRet >= 30 ? '#f59e0b' : '#ef4444';

      document.getElementById('est-fidelizacao').innerHTML = `
    <div class="est-fidel-body">
      <div class="est-fidel-ring">
        <svg width="88" height="88" viewBox="0 0 88 88">
          <circle cx="44" cy="44" r="${radius}" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="8"/>
          <circle cx="44" cy="44" r="${radius}" fill="none" stroke="${ringCol}" stroke-width="8"
            stroke-dasharray="${dash.toFixed(1)} ${(circ-dash).toFixed(1)}"
            stroke-dashoffset="${(circ*0.25).toFixed(1)}" stroke-linecap="round"/>
          <text x="44" y="40" text-anchor="middle" fill="${ringCol}" font-size="14" font-weight="900" font-family="Inter,sans-serif">${pctRet}%</text>
          <text x="44" y="54" text-anchor="middle" fill="#6b7280" font-size="8" font-family="Inter,sans-serif">retorno</text>
        </svg>
      </div>
      <div class="est-fidel-stats">
        <div class="est-fidel-stat">
          <span class="est-fidel-stat-lbl">Frequentes/semana</span>
          <span class="est-fidel-stat-val" style="color:var(--green)">${fidel.frequentes_semana||0}</span>
        </div>
        <div class="est-fidel-stat">
          <span class="est-fidel-stat-lbl">Voltaram em 7 dias</span>
          <span class="est-fidel-stat-val" style="color:${ringCol}">${pctRet}%</span>
        </div>
        <div class="est-fidel-stat">
          <span class="est-fidel-stat-lbl">Programa pontos</span>
          <span class="est-badge ${fidel.programa_ativo?'est-bg-green':'est-bg-red'}">${fidel.programa_ativo?'Ativo':'Inativo'}</span>
        </div>
      </div>
    </div>`;

      // ── Produtos parados ─────────────────────────────────────────────
      const parados = d.produtos_parados || [];
      const paradEl = document.getElementById('est-parados-count');
      if (paradEl) paradEl.textContent = parados.length ? `${parados.length}` : '✓ 0';

      document.getElementById('est-parados').innerHTML = parados.length ?
        parados.slice(0, 4).map(p => {
          const qtd30 = parseInt(p.qtd_30d) || 1;
          const semPct = 100;
          return `<div class="est-prod-row">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <div class="est-prod-name">${esc(p.nome)}</div>
            <span class="est-badge est-bg-red" style="font-size:9px">7d sem venda</span>
          </div>
          <div class="est-prod-bar-wrap">
            <div class="est-prod-bar-fill" style="width:${semPct}%"></div>
          </div>
          <div style="font-size:10px;color:var(--text3);margin-top:3px">${qtd30} vendas nos últimos 30 dias</div>
        </div>`;
        }).join('') :
        `<div style="text-align:center;padding:16px 0">
        <div style="font-size:28px;margin-bottom:6px">🎉</div>
        <div style="font-size:12px;font-weight:600;color:var(--green)">Nenhum produto parado!</div>
        <div style="font-size:11px;color:var(--text3);margin-top:3px">Todos os itens venderam nos últimos 7 dias</div>
      </div>`;

      // ── Insights com ícone e cor por tipo ─────────────────────────────
      const insights = d.insights || [];
      const INS_CFG = {
        green: {
          cor: '#22c55e',
          icon: '💡',
          bg: 'rgba(34,197,94,.06)'
        },
        yellow: {
          cor: '#f59e0b',
          icon: '⚠️',
          bg: 'rgba(245,158,11,.06)'
        },
        blue: {
          cor: '#3b82f6',
          icon: '📌',
          bg: 'rgba(59,130,246,.06)'
        },
        red: {
          cor: '#ef4444',
          icon: '🔴',
          bg: 'rgba(239,68,68,.06)'
        },
      };
      document.getElementById('est-insights').innerHTML = insights.length ?
        insights.map(ins => {
          const cfg = INS_CFG[ins.cor] || INS_CFG.blue;
          return `<div class="est-insight-item" style="--ins-col:${cfg.cor}">
          <div class="est-insight-left">
            <div class="est-insight-dot" style="background:${cfg.cor}"></div>
          </div>
          <div style="flex:1">
            <div class="est-insight-title">${esc(ins.titulo)}</div>
            <div class="est-insight-text">${esc(ins.texto)}</div>
          </div>
          <div style="font-size:18px;flex-shrink:0;opacity:.7">${cfg.icon}</div>
        </div>`;
        }).join('') :
        '<div class="est-insights-loading">Nenhum insight gerado ainda</div>';
    }

    // ── RELATÓRIOS — Turno, Waterfall, Clientes, Recordes ─────────────────
    // ─────────────────────────────────────────────────────────────────────
    function renderTurnos(porTurno) {
      const el = document.getElementById('rel-turnos');
      if (!el) return;
      const DEF = {
        manha: {
          icon: '🌅',
          lbl: 'Manhã',
          sub: '06h–11h',
          cor: 'var(--gold)'
        },
        almoco: {
          icon: '☀️',
          lbl: 'Almoço',
          sub: '12h–14h',
          cor: 'var(--acc)'
        },
        tarde: {
          icon: '🌤️',
          lbl: 'Tarde',
          sub: '15h–18h',
          cor: 'var(--blue)'
        },
        noite: {
          icon: '🌙',
          lbl: 'Noite',
          sub: '19h–23h',
          cor: 'var(--purple)'
        },
      };
      if (!porTurno?.length) {
        el.innerHTML = '<div style="color:var(--text3);font-size:12px">Sem dados de turno</div>';
        return;
      }
      const maxFat = Math.max(...porTurno.map(t => parseFloat(t.faturamento) || 0), 1);
      el.innerHTML = porTurno.map(t => {
        const d = DEF[t.turno] || {
          icon: '⏰',
          lbl: t.turno,
          sub: '',
          cor: 'var(--acc)'
        };
        const pct = Math.round((parseFloat(t.faturamento) || 0) / maxFat * 100);
        return `<div class="rel-turno-card">
      <div class="rel-turno-icon">${d.icon}</div>
      <div class="rel-turno-lbl">${d.lbl}</div>
      <div class="rel-turno-sub">${d.sub}</div>
      <div class="rel-turno-fat" style="color:${d.cor}">${fmt(t.faturamento)}</div>
      <div class="rel-turno-info">${t.pedidos} pedidos · TM ${fmt(t.ticket_medio)}</div>
      <div class="rel-turno-bar"><div class="rel-turno-bar-fill" style="width:${pct}%;background:${d.cor}"></div></div>
    </div>`;
      }).join('');
    }

    function renderWaterfall(kpi, custoTotal, cancelados) {
      const el = document.getElementById('rel-waterfall');
      if (!el) return;
      const fat = parseFloat(kpi.faturamento) || 0;
      const tktM = parseFloat(kpi.ticket_medio) || 0;
      const canVal = Math.min(fat * 0.15, parseFloat(cancelados || 0) * tktM);
      const taxas = parseFloat(custoTotal) || 0;
      const liquido = fat - taxas;
      const steps = [{
          lbl: 'Receita bruta',
          val: fat,
          cor: 'var(--blue)',
          cls: ''
        },
        {
          lbl: '− Cancelamentos',
          val: -canVal,
          cor: 'var(--red)',
          cls: ''
        },
        {
          lbl: '− Taxas (máquina)',
          val: -taxas,
          cor: 'var(--gold)',
          cls: ''
        },
        {
          lbl: '= Receita líquida',
          val: liquido,
          cor: 'var(--green)',
          cls: 'wf-total'
        },
      ];
      const maxV = Math.max(...steps.map(s => Math.abs(s.val)), 1);
      el.innerHTML = steps.map(s => {
        const pct = Math.round(Math.abs(s.val) / maxV * 100);
        const disp = s.val < 0 ? `-${fmt(Math.abs(s.val))}` : fmt(s.val);
        const vCor = s.val < 0 ? 'var(--red)' : s.cor;
        return `<div class="wf-row ${s.cls}">
      <div class="wf-label" style="color:${s.cls==='wf-total'?s.cor:'var(--text2)'}">${s.lbl}</div>
      <div class="wf-bar-wrap"><div class="wf-bar-fill" style="width:${pct}%;background:${s.cor}"></div></div>
      <div class="wf-val" style="color:${vCor}">${disp}</div>
    </div>`;
      }).join('');
    }

    function renderTopClientes(clientes) {
      const el = document.getElementById('rel-top-clientes');
      if (!el) return;
      if (!clientes?.length) {
        el.innerHTML = '<div style="color:var(--text3);font-size:12px;padding:14px 16px">Nenhum cliente identificado no período.<br><span style="font-size:10px">Os pedidos precisam ser feitos com CPF.</span></div>';
        return;
      }
      const medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
      el.innerHTML = clientes.map((c, i) => {
        const cpfMask = c.cpf ? c.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4') : '—';
        const ultima = c.ultima_visita ? new Date(c.ultima_visita).toLocaleDateString('pt-BR', {
          day: '2-digit',
          month: '2-digit'
        }) : '—';
        return `<div class="rel-cli-row">
      <div class="rel-cli-medal">${medals[i]||i+1}</div>
      <div style="flex:1;min-width:0">
        <div class="rel-cli-nome">${esc(c.nome||'—')}</div>
        <div class="rel-cli-sub">${cpfMask} · última: ${ultima}</div>
      </div>
      <div>
        <div class="rel-cli-val">${fmt(c.total_gasto)}</div>
        <div class="rel-cli-pts">${c.pedidos} pedidos · ⭐ ${c.pontos_saldo||0}pts</div>
      </div>
    </div>`;
      }).join('');
    }

    function renderRecords(records) {
      const el = document.getElementById('rel-records');
      if (!el || !records) return;
      const items = [];
      if (records.dia_maior_fat?.total > 0) {
        const d = new Date(records.dia_maior_fat.dia + 'T12:00').toLocaleDateString('pt-BR', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric'
        });
        items.push({
          icon: '🏆',
          lbl: 'Recorde de faturamento',
          val: fmt(records.dia_maior_fat.total),
          date: d
        });
      }
      if (records.dia_mais_ped?.qtd > 0) {
        const d = new Date(records.dia_mais_ped.dia + 'T12:00').toLocaleDateString('pt-BR', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric'
        });
        items.push({
          icon: '📦',
          lbl: 'Recorde de pedidos',
          val: records.dia_mais_ped.qtd + ' pedidos',
          date: d
        });
      }
      el.innerHTML = items.map(r =>
        `<div class="rel-rec-card">
      <div class="rel-rec-icon">${r.icon}</div>
      <div>
        <div class="rel-rec-lbl">${r.lbl}</div>
        <div class="rel-rec-val">${r.val}</div>
        <div class="rel-rec-date">${r.date}</div>
      </div>
    </div>`
      ).join('') || '<div style="color:var(--text3);font-size:12px">Nenhum recorde registrado ainda.</div>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // ── USUÁRIOS ──────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let allUserData = [];

    async function loadUsuarios() {
      if (!IS_ADMIN) return;
      const res = await api('usuarios.php');
      if (!res.success) return;
      allUserData = res.data;

      const ROLE_LBL = {
        admin: 'Admin',
        operador: 'Operador',
        cozinha: 'Cozinha'
      };
      // Preencher selects de usuário nos painéis auxiliares
      const selPerm = document.getElementById('perm-user-sel');
      const selAtiv = document.getElementById('ativ-user-sel');
      [selPerm, selAtiv].forEach(sel => {
        const cur = sel.value;
        sel.innerHTML = (sel === selPerm ? '<option value="">— selecione —</option>' : '<option value="">Todos</option>') +
          res.data.map(u => '<option value="' + u.id + '">' + esc(u.nome) + ' (' + (ROLE_LBL[u.role] || u.role) + ')</option>').join('');
        if (cur) sel.value = cur;
      });

      document.getElementById('user-tbody').innerHTML = res.data.map(u =>
        '<tr>' +
        '<td>' +
        '<div class="sb-avatar" style="display:inline-flex;width:28px;height:28px;font-size:12px;margin-right:8px">' + esc(u.nome.charAt(0).toUpperCase()) + '</div>' +
        '<strong>' + esc(u.nome) + '</strong>' +
        '</td>' +
        '<td style="color:var(--text2)">' + esc(u.email) + '</td>' +
        '<td><span class="badge badge-' + u.role + '">' + (ROLE_LBL[u.role] || u.role) + '</span></td>' +
        '<td>' + (u.ativo ? '<span style="color:var(--green);font-weight:600">Ativo</span>' : '<span style="color:var(--red)">Inativo</span>') + '</td>' +
        '<td style="color:var(--text2);font-size:12px">' + (u.ultimo_login ? fmtDt(u.ultimo_login) : 'Nunca') + '</td>' +
        '<td>' + u.total_logins + '</td>' +
        '<td style="display:flex;gap:6px">' +
        '<button class="btn btn-secondary btn-sm" onclick="openUserModal(' + u.id + ')">✏️ Editar</button>' +
        '<button class="btn btn-sm" style="background:rgba(139,92,246,.12);color:var(--purple);border:1px solid rgba(139,92,246,.25)" onclick="abrirPermissoes(' + u.id + ')">🔑 Permissões</button>' +
        '</td>' +
        '</tr>'
      ).join('');
    }

    function openUserModal(id) {
      const data = id ? allUserData.find(u => u.id === id) : null;
      document.getElementById('modal-user-title').textContent = data ? 'Editar usuário' : 'Novo usuário';
      document.getElementById('fu-id').value = data?.id || '';
      document.getElementById('fu-nome').value = data?.nome || '';
      document.getElementById('fu-email').value = data?.email || '';
      document.getElementById('fu-role').value = data?.role || 'operador';
      document.getElementById('fu-ativo').checked = data ? data.ativo : true;
      document.getElementById('fu-senha').value = '';
      document.getElementById('fu-senha-hint').style.display = data ? '' : 'none';
      openModal('modal-user');
    }

    document.getElementById('btn-new-user').addEventListener('click', () => openUserModal());

    // ── Usuários sub-menu ─────────────────────────────────────────────────────
    function toggleUsrMenu() {
      const btn = document.getElementById('nav-usr-btn');
      const menu = document.getElementById('usr-submenu');
      const isOpen = menu.classList.contains('open');
      if (isOpen) {
        menu.classList.remove('open');
        btn.classList.remove('open', 'active');
      } else {
        menu.classList.add('open');
        btn.classList.add('open', 'active');
        usrTab('lista');
      }
    }

    function usrTab(section) {
      document.querySelectorAll('.panel.active').forEach(p => p.classList.remove('active'));
      const panel = document.getElementById('panel-usr-' + section);
      if (panel) panel.classList.add('active');
      document.querySelectorAll('#usr-submenu .nav-sub').forEach(el =>
        el.classList.toggle('active', el.dataset.usr === section)
      );
      document.getElementById('usr-submenu').classList.add('open');
      document.getElementById('nav-usr-btn').classList.add('open', 'active');
      document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
      document.getElementById('topbar-title').textContent = TITLES['usr-' + section] || 'Usuários';
      if (section === 'lista') loadUsuarios();
      if (section === 'atividade' && document.getElementById('ativ-user-sel').value) {
        loadAtividade(parseInt(document.getElementById('ativ-user-sel').value));
      }
    }

    // Abre permissões de um usuário específico vindo da lista
    function abrirPermissoes(userId) {
      usrTab('permissoes');
      const sel = document.getElementById('perm-user-sel');
      sel.value = userId;
      loadPermissoes(userId);
    }

    // Carrega e renderiza permissões do usuário selecionado
    function loadPermissoes(userId) {
      const notice = document.getElementById('perm-admin-notice');
      const grupos = document.getElementById('perm-grupos');
      const empty = document.getElementById('perm-empty');
      const info = document.getElementById('perm-user-info');
      const barWrap = document.getElementById('perm-bar-wrap');
      const btnSalv = document.getElementById('btn-salvar-perm');
      const btnLbl = document.getElementById('btn-salvar-perm-label');

      if (!userId) {
        notice.style.display = 'none';
        grupos.style.display = 'none';
        empty.style.display = '';
        barWrap.style.display = 'none';
        btnSalv.style.display = 'none';
        info.innerHTML = '';
        return;
      }

      const u = allUserData.find(x => x.id === userId);
      if (!u) return;

      const ROLE_LBL = {
        admin: 'Admin',
        operador: 'Operador',
        cozinha: 'Cozinha'
      };
      info.innerHTML =
        '<span class="perm-role-badge ' + u.role + '">' + (ROLE_LBL[u.role] || u.role) + '</span>' +
        '<span style="color:var(--text3);font-size:12px">' + esc(u.email) + '</span>' +
        (u.ativo ?
          '<span style="color:var(--green);font-size:11px;font-weight:700">● Ativo</span>' :
          '<span style="color:var(--red);font-size:11px;font-weight:700">● Inativo</span>');

      btnLbl.textContent = 'Salvar permissões de ' + esc(u.nome);
      btnSalv.style.display = '';
      empty.style.display = 'none';
      barWrap.style.display = '';

      if (u.role === 'admin') {
        notice.style.display = '';
        grupos.style.display = 'none';
        return;
      }

      notice.style.display = 'none';
      grupos.style.display = '';

      const perm = u.permissoes || {};

      // Aplicar valores nos cards hierárquicos
      document.querySelectorAll('.perm-page-card').forEach(card => {
        const pageInp = card.querySelector('.perm-page-inp');
        if (!pageInp) return;
        const [g, k] = pageInp.dataset.perm.split('.');
        const pageOn = !!(perm[g] && perm[g][k]);
        pageInp.checked = pageOn;
        card.classList.toggle('on', pageOn);
        // Sub-ações
        card.querySelectorAll('.perm-action-row').forEach(row => {
          const ai = row.querySelector('.perm-action-inp');
          const dot = ai.dataset.perm.indexOf('.');
          const ag = ai.dataset.perm.slice(0, dot);
          const ak = ai.dataset.perm.slice(dot + 1);
          ai.checked = !!(perm[ag] && perm[ag][ak]);
          row.classList.toggle('disabled', !pageOn);
        });
      });
      atualizarContagens();
    }

    // Marcar/desmarcar todos os toggles de um grupo (painel.*, op.*, etc.)
    function toggleGrupo(grupo, valor) {
      document.querySelectorAll('[data-perm^="' + grupo + '."]').forEach(inp => {
        inp.checked = valor;
        inp.closest('.perm-item').classList.toggle('perm-on', valor);
      });
    }

    // Marcar/desmarcar por prefixo de chave (acao.ped_*, acao.prod_*, etc.)
    function toggleGrupoPrefix(prefixo, valor) {
      document.querySelectorAll('[data-perm]').forEach(inp => {
        if (inp.dataset.perm.startsWith(prefixo)) {
          inp.checked = valor;
          inp.closest('.perm-item').classList.toggle('perm-on', valor);
        }
      });
    }

    // Marcar/desmarcar absolutamente tudo
    function toggleTodos(valor) {
      const nomeUsuario = document.getElementById('perm-user-sel')?.selectedOptions[0]?.text || 'este usuário';

      if (valor) {
        // Confirmação para marcar tudo (acesso total)
        permConfirm({
          icone: '⚠️',
          cor: '#f59e0b',
          titulo: 'Ei, vai com calma! 👀',
          msg: `Você vai liberar <strong style="background:#f59e0b22;color:#f59e0b;padding:1px 6px;border-radius:4px;font-weight:700">tudo</strong> para <em>${nomeUsuario}</em>.<br><br>
              Pedidos, produtos, financeiro, configurações, usuários... é como dar a <strong style="background:#f59e0b22;color:#f59e0b;padding:1px 6px;border-radius:4px;font-weight:700">chave do estabelecimento inteiro</strong> pra essa pessoa.<br><br>
              Só faça isso se você <strong style="background:#f59e0b22;color:#f59e0b;padding:1px 6px;border-radius:4px;font-weight:700">confia totalmente</strong> nela!`,
          ok: '✅ Confio, pode liberar tudo',
          cancel: 'Melhor não',
          onOk: () => {
            document.querySelectorAll('.perm-page-inp').forEach(inp => {
              inp.checked = true;
              onPageToggle(inp);
            });
            document.querySelectorAll('.perm-action-inp').forEach(inp => {
              inp.checked = true;
            });
            atualizarContagens();
          }
        });
      } else {
        // Confirmação para desmarcar tudo (remover todo acesso)
        permConfirm({
          icone: '🚫',
          cor: '#ef4444',
          titulo: 'Zerar tudo mesmo? 🤔',
          msg: `Isso vai tirar <strong style="background:#ef444422;color:#ef4444;padding:1px 6px;border-radius:4px;font-weight:700">todas as permissões</strong> de <em>${nomeUsuario}</em>.<br><br>
              Na prática: quando essa pessoa entrar no sistema, não vai conseguir ver <strong style="background:#ef444422;color:#ef4444;padding:1px 6px;border-radius:4px;font-weight:700">nada</strong>, nem um botão, nem uma página. Tela em branco total.<br><br>
              Se quiser bloquear o acesso dela completamente, essa é a opção. Mas lembre de salvar depois!`,
          ok: '🚫 Sim, zerar tudo',
          cancel: 'Deixa assim',
          onOk: () => {
            document.querySelectorAll('.perm-page-inp').forEach(inp => {
              inp.checked = false;
              onPageToggle(inp);
            });
            atualizarContagens();
          }
        });
      }
    }

    // Modal de confirmação reutilizável para permissões
    function permConfirm({
      icone,
      cor,
      titulo,
      msg,
      ok,
      cancel,
      onOk
    }) {
      // Remove modal anterior se existir
      document.getElementById('perm-confirm-overlay')?.remove();

      const overlay = document.createElement('div');
      overlay.id = 'perm-confirm-overlay';
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)';

      overlay.innerHTML = `
    <div style="background:var(--card);border:1px solid var(--border2);border-top:3px solid ${cor};border-radius:14px;max-width:480px;width:100%;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.5)">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
        <div style="width:44px;height:44px;border-radius:10px;background:${cor}20;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">${icone}</div>
        <div style="font-size:17px;font-weight:700;color:var(--text)">${titulo}</div>
      </div>
      <div style="font-size:13px;color:var(--text2);line-height:1.7;margin-bottom:24px">${msg}</div>
      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button id="perm-confirm-cancel" class="btn btn-secondary">${cancel}</button>
        <button id="perm-confirm-ok" class="btn btn-primary" style="background:${cor};border-color:${cor}">${ok}</button>
      </div>
    </div>`;

      document.body.appendChild(overlay);

      document.getElementById('perm-confirm-ok').onclick = () => {
        overlay.remove();
        onOk();
      };
      document.getElementById('perm-confirm-cancel').onclick = () => overlay.remove();
      overlay.onclick = e => {
        if (e.target === overlay) overlay.remove();
      };
    }

    // Quando a página (toggle pai) muda
    function onPageToggle(inp) {
      const card = inp.closest('.perm-page-card');
      const on = inp.checked;
      card.classList.toggle('on', on);
      card.querySelectorAll('.perm-action-row').forEach(row => {
        row.classList.toggle('disabled', !on);
        if (!on) row.querySelector('.perm-action-inp').checked = false;
      });
      atualizarContagens();
    }

    // Abrir/fechar seção de acordeão
    function togglePermAccordion(section) {
      section.classList.toggle('open');
    }

    // Marcar/desmarcar todos os itens de um grupo
    function togglePermGroup(grpKey, valor) {
      const grp = document.querySelector('.perm-group[data-grp="' + grpKey + '"]');
      if (!grp) return;
      grp.querySelectorAll('.perm-page-inp').forEach(inp => {
        inp.checked = valor;
        onPageToggle(inp);
      });
      if (valor) {
        grp.querySelectorAll('.perm-action-inp').forEach(inp => {
          inp.checked = true;
          atualizarContagens();
        });
      }
      atualizarContagens();
    }

    // Atualizar badges de contagem por grupo + barra global
    function atualizarContagens() {
      let totalOn = 0,
        totalAll = 0;

      document.querySelectorAll('.perm-group').forEach(grp => {
        const inps = grp.querySelectorAll('[data-perm]');
        const on = [...inps].filter(i => i.checked).length;
        const badge = grp.querySelector('.perm-grp-count');
        if (badge) {
          badge.textContent = on + '/' + inps.length;
          badge.classList.toggle('has-perm', on > 0);
        }
        totalOn += on;
        totalAll += inps.length;
      });

      const tot = document.getElementById('perm-total-badge');
      if (tot) {
        tot.textContent = totalOn + ' de ' + totalAll + ' permissões ativas';
        tot.style.color = totalOn > 0 ? 'var(--green)' : 'var(--text3)';
      }
      const bar = document.getElementById('perm-progress-bar');
      if (bar) bar.style.width = (totalAll > 0 ? Math.round(totalOn / totalAll * 100) : 0) + '%';
    }

    // Salvar permissões do usuário selecionado
    async function salvarPermissoes() {
      const userId = parseInt(document.getElementById('perm-user-sel').value);
      if (!userId) {
        toast('Selecione um usuário', 'err');
        return;
      }

      const perm = {};
      document.querySelectorAll('[data-perm]').forEach(inp => {
        const [grupo, chave] = inp.dataset.perm.split('.');
        if (!perm[grupo]) perm[grupo] = {};
        perm[grupo][chave] = inp.checked;
      });

      const res = await api('usuarios.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'salvar_permissoes',
          id: userId,
          permissoes: perm
        })
      });

      if (res?.success) {
        const u = allUserData.find(x => x.id === userId);
        if (u) u.permissoes = perm;
        toast('✅ Permissões de ' + (u?.nome || 'usuário') + ' salvas! O usuário verá as mudanças ao recarregar.');
      } else {
        toast(res?.error || 'Erro ao salvar', 'err');
      }
    }

    // Atividade do usuário (sessões recentes via auditoria)
    async function loadAtividade(userId) {
      const el = document.getElementById('ativ-lista');
      el.innerHTML = '<div style="text-align:center;color:var(--text3);padding:20px">Carregando...</div>';
      const params = new URLSearchParams({
        page: 1
      });
      if (userId) params.set('usuario_id', userId);
      const res = await api('audit.php?' + params);
      if (!res?.success) {
        el.innerHTML = '<div style="color:var(--red);padding:20px">Erro ao carregar</div>';
        return;
      }

      const ACAO_CLR = {
        login: '#22c55e',
        logout: '#6b7280',
        usuario_criado: '#3b82f6',
        usuario_editado: '#f59e0b',
        permissoes_editadas: '#8b5cf6',
        pedido_criado: '#3b82f6',
        pedido_cancelado: '#ef4444'
      };
      const rows = res.data || [];
      el.innerHTML = rows.length ? rows.map(r =>
        '<div class="audit-row">' +
        '<div class="audit-time">' + fmtDt(r.criado_em) + '</div>' +
        '<div class="audit-user">' + esc(r.usuario_nome || 'Sistema') + '</div>' +
        '<div class="audit-acao">' +
        '<span class="audit-badge" style="background:' + (ACAO_CLR[r.acao] || '#6b7280') + '22;color:' + (ACAO_CLR[r.acao] || '#6b7280') + '">' + esc(r.acao) + '</span>' +
        '<span style="color:var(--text2)">' + esc(r.descricao || '') + '</span>' +
        '</div>' +
        '<div style="color:var(--text3);font-size:11px;flex-shrink:0">' + esc(r.ip || '') + '</div>' +
        '</div>'
      ).join('') : '<div style="text-align:center;color:var(--text3);padding:40px">Nenhuma atividade encontrada</div>';
    }

    document.getElementById('form-user').addEventListener('submit', async e => {
      e.preventDefault();
      const body = {
        id: parseInt(document.getElementById('fu-id').value) || undefined,
        nome: document.getElementById('fu-nome').value,
        email: document.getElementById('fu-email').value,
        role: document.getElementById('fu-role').value,
        ativo: document.getElementById('fu-ativo').checked,
        senha: document.getElementById('fu-senha').value || undefined,
      };
      if (!body.id) delete body.id;
      if (!body.senha) delete body.senha;
      const res = await api('usuarios.php', {
        method: 'POST',
        body: JSON.stringify(body)
      });
      if (res.success) {
        toast(res.action === 'created' ? 'Usuario criado!' : 'Usuario atualizado!');
        closeModal('modal-user');
        loadUsuarios();
      } else toast(res.error || 'Erro', 'err');
    });

    // ─────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════
    // ── SAÚDE FISCAL ─────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════

    let _sfInterval = null;
    let _sfClock    = null;
    let _sfStart    = null;

    // ── Relógio ao vivo ───────────────────────────────────────────────────
    function _sfStartClock() {
      _sfStart = _sfStart || Date.now();
      clearInterval(_sfClock);
      _sfClock = setInterval(() => {
        const s  = Math.floor((Date.now() - _sfStart) / 1000);
        const el = document.getElementById('sf-clock');
        if (el) el.textContent =
          String(Math.floor(s/3600)).padStart(2,'0')+':'+
          String(Math.floor((s%3600)/60)).padStart(2,'0')+':'+
          String(s%60).padStart(2,'0');
      }, 1000);
    }

    // ── Mapa de ícones/labels por status NFC-e ────────────────────────────
    const NFCE_STATUS = {
      autorizada:   { ico:'<span style="color:var(--green)">✓</span>',  tag:'Autorizada',      cls:'autorizada' },
      transmitindo: { ico:'<span class="sf-spin">↻</span>',             tag:'Transmitindo...',  cls:'transmitindo' },
      pendente:     { ico:'<span style="color:var(--text3)">◌</span>',  tag:'Aguardando',      cls:'transmitindo' },
      contingencia: { ico:'<span style="color:var(--purple)">⚡</span>',tag:'Contingência',    cls:'contingencia' },
      rejeitada:    { ico:'<span style="color:var(--red)">!</span>',     tag:'Rejeitada',       cls:'cancelada' },
      cancelada:    { ico:'<span style="color:var(--red)">✕</span>',     tag:'Cancelada',       cls:'cancelada' },
    };

    // ── Sincronizar pedidos sem NFC-e ─────────────────────────────────────
    async function sfSincronizar() {
      await api('fiscal.php', { method:'POST', body: JSON.stringify({ action:'sincronizar' }) });
    }

    // ── Carregar dashboard ────────────────────────────────────────────────
    async function loadSaudeFiscal() {
      _sfStartClock();

      // Sincronizar pedidos que ainda não têm NFC-e (silencioso)
      sfSincronizar().catch(() => {});

      const res = await api('fiscal.php?action=dashboard');
      if (!res?.success) {
        document.getElementById('sf-emissao-list').innerHTML =
          '<div style="text-align:center;padding:40px;color:var(--red)">Erro ao carregar dados fiscais.</div>';
        return;
      }

      // ── Banner de status ──
      const banner = document.getElementById('sf-banner');
      banner.className = 'sf-status-banner ' + (res.status_type !== 'ok' ? res.status_type : '');
      const ico = banner.querySelector('.sf-status-ico');
      if (ico) ico.textContent = res.status_type === 'ok' ? '✓' : (res.status_type === 'warning' ? '⚡' : '✕');
      document.getElementById('sf-banner-title').textContent = res.status_title;
      document.getElementById('sf-banner-sub').textContent   = res.status_sub;

      // ── KPIs ──
      document.getElementById('sf-vendas').textContent              = fmt(res.vendas_hoje);
      document.getElementById('sf-taxa').textContent                = res.taxa_autorizacao + '%';
      document.getElementById('sf-rejeicoes-evitadas').textContent  = res.autorizadas;

      // Dot sidebar
      const dot = document.getElementById('sf-status-dot');
      if (dot) dot.style.background =
        res.status_type === 'ok' ? 'var(--green)' : res.status_type === 'warning' ? 'var(--gold)' : 'var(--red)';

      // ── Badge config (ambiente) ──
      const ambiEl = document.getElementById('sf-ambiente-badge');
      if (ambiEl) {
        const isHom = res.config?.ambiente === 'homologacao';
        ambiEl.textContent   = isHom ? 'HOMOLOGAÇÃO' : 'PRODUÇÃO';
        ambiEl.style.color   = isHom ? 'var(--gold)' : 'var(--green)';
        ambiEl.style.background = isHom ? 'rgba(245,158,11,.12)' : 'rgba(34,197,94,.12)';
      }

      // ── Emissão ao vivo ──
      const emissoes = res.emissao_recente || [];
      document.getElementById('sf-emissao-list').innerHTML = emissoes.length
        ? emissoes.map(n => {
            const s = NFCE_STATUS[n.status] || NFCE_STATUS.transmitindo;
            const numStr = 'NFC-e #' + String(n.numero).padStart(4,'0') + '/' + n.serie;
            return '<div class="sf-emissao-row">'+
              '<div class="sf-emissao-ico">'+s.ico+'</div>'+
              '<div class="sf-emissao-num">'+esc(numStr)+'</div>'+
              '<div class="sf-emissao-val">'+fmt(n.total)+'</div>'+
              '<div class="sf-status-tag '+s.cls+'">'+s.tag+'</div>'+
            '</div>';
          }).join('')
        : '<div style="text-align:center;padding:40px;color:var(--text3);font-size:13px">Nenhuma nota emitida hoje ainda</div>';

      // ── Fila de contingência ──
      const cont = res.contingencia;
      document.getElementById('sf-cont-count').textContent  = cont;
      document.getElementById('sf-cont-count').style.color  = cont > 0 ? 'var(--gold)' : 'var(--green)';
      document.getElementById('sf-cont-status').textContent = cont === 0
        ? 'Vazia. Tudo transmitido e autorizado.'
        : cont + ' nota(s) aguardando transmissão.';

      // ── Rejeições em português ──
      const rejeicoes = res.rejeicoes || [];
      document.getElementById('sf-rejeicoes-list').innerHTML = rejeicoes.length
        ? rejeicoes.map(r =>
            '<div class="sf-rejeicao-row">'+
              '<div style="display:flex;justify-content:space-between;margin-bottom:3px">'+
                '<strong style="color:var(--text)">NFC-e #'+esc(String(r.numero||'').padStart(4,'0'))+'</strong>'+
                '<span style="font-size:10px;color:var(--text3)">'+esc(fmtDt(r.criado_em))+'</span>'+
              '</div>'+
              '<div style="display:flex;align-items:center;gap:6px">'+
                '<span style="background:rgba(239,68,68,.12);color:var(--red);font-size:10px;padding:2px 7px;border-radius:4px;font-weight:700">Cód. '+esc(r.codigo||'?')+'</span>'+
                '<span>'+esc(r.descricao_pt || r.descricao || 'Rejeição SEFAZ')+'</span>'+
              '</div>'+
            '</div>'
          ).join('')
        : '<div class="sf-rejeicao-empty">Nenhuma rejeição nas últimas 24h. Tudo certo! ✓</div>';

      // ── Monitor de prazos ──
      const p = res.prazos || {};
      const prazoHtml = (nome, sub, prazo) => {
        const gaps = prazo.gaps?.length
          ? '<div style="font-size:10px;color:var(--red);margin-top:3px">Saltos: '+esc(prazo.gaps.slice(0,5).join(', '))+(prazo.gaps.length>5?'…':'')+'</div>'
          : '';
        return '<div class="sf-prazo-row">'+
          '<div><div class="sf-prazo-nome">'+nome+'</div><div class="sf-prazo-sub">'+sub+'</div>'+gaps+'</div>'+
          '<div class="sf-prazo-badge '+prazo.tipo+'">'+prazo.label+'</div>'+
        '</div>';
      };
      document.getElementById('sf-prazos').innerHTML =
        prazoHtml('Certificado digital A1','renovação monitorada', p.certificado  || {label:'não configurado',tipo:'alert'}) +
        prazoHtml('Contingência SEFAZ',   'limite legal',          p.contingencia || {label:'dentro do prazo',tipo:'ok'}) +
        prazoHtml('Sequência de numeração','nenhum salto detectado',p.numeracao    || {label:'sem falhas',tipo:'ok'});

      // ── Config: mostrar/esconder wizard ──
      const cfgBlock = document.getElementById('sf-config-block');
      if (cfgBlock) cfgBlock.style.display = (!res.config?.cnpj || !res.config?.ativo) ? '' : 'none';

      // Auto-refresh a cada 15s
      clearInterval(_sfInterval);
      _sfInterval = setInterval(loadSaudeFiscal, 15000);
    }

    // ── Testar emissão NFC-e (Fase 2 mock) ───────────────────────────────
    async function testarEmissao(modo) {
      const btn = event.target;
      const orig = btn.textContent;
      btn.textContent = '⏳ Processando...';
      btn.style.pointerEvents = 'none';

      const res = await api('fiscal.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'emitir_nfce', modo })
      });

      btn.textContent = orig;
      btn.style.pointerEvents = '';

      if (!res?.success) { toast(res?.error || 'Erro na emissão', 'err'); return; }

      // Modal de resultado
      document.getElementById('nfce-modal-overlay')?.remove();

      const statusInfo = {
        autorizada:   { cor:'#22c55e', ico:'✅', titulo:'NFC-e Autorizada!', bg:'rgba(34,197,94,.08)' },
        rejeitada:    { cor:'#ef4444', ico:'✕',  titulo:'NFC-e Rejeitada',   bg:'rgba(239,68,68,.08)' },
        contingencia: { cor:'#8b5cf6', ico:'⚡', titulo:'Modo Contingência',  bg:'rgba(139,92,246,.08)' },
      }[res.status] || { cor:'var(--acc)', ico:'📋', titulo:'Resultado', bg:'' };

      const chaveFormatada = res.chave
        ? res.chave.replace(/(.{4})/g, '$1 ').trim()
        : '—';

      const overlay = document.createElement('div');
      overlay.id    = 'nfce-modal-overlay';
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)';

      overlay.innerHTML = `
        <div style="background:var(--card);border:1px solid var(--border2);border-top:3px solid ${statusInfo.cor};border-radius:14px;max-width:620px;width:100%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.6)">

          <!-- Header -->
          <div style="display:flex;align-items:center;gap:14px;padding:20px 24px;border-bottom:1px solid var(--border)">
            <div style="width:42px;height:42px;border-radius:10px;background:${statusInfo.cor}20;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">${statusInfo.ico}</div>
            <div style="flex:1">
              <div style="font-size:16px;font-weight:800;color:var(--text)">${statusInfo.titulo}</div>
              <div style="font-size:11px;color:var(--text3);margin-top:2px">Simulação Fase 2 · Pedido #${esc(res.pedido_num||'')} · NFC-e #${String(res.numero||0).padStart(4,'0')}</div>
            </div>
            <span style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:12px;background:rgba(245,158,11,.12);color:var(--gold)">MOCK</span>
            <button onclick="document.getElementById('nfce-modal-overlay').remove()" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer;padding:4px">✕</button>
          </div>

          <!-- Info -->
          <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:12px;border-bottom:1px solid var(--border)">
            <div style="background:var(--card2);border-radius:8px;padding:12px">
              <div style="font-size:10px;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Status</div>
              <div style="font-size:14px;font-weight:700;color:${statusInfo.cor}">${res.status?.toUpperCase()}</div>
            </div>
            <div style="background:var(--card2);border-radius:8px;padding:12px">
              <div style="font-size:10px;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Protocolo SEFAZ</div>
              <div style="font-size:13px;font-weight:600;color:var(--text);font-family:monospace">${res.protocolo || '—'}</div>
            </div>
            ${res.mensagem ? `<div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:12px;grid-column:1/-1">
              <div style="font-size:10px;color:var(--red);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Motivo</div>
              <div style="font-size:13px;color:var(--text2)">${esc(res.mensagem)}</div>
            </div>` : ''}
            <div style="grid-column:1/-1;background:var(--card2);border-radius:8px;padding:12px">
              <div style="font-size:10px;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Chave de Acesso (44 dígitos)</div>
              <div style="font-size:11px;font-weight:600;color:var(--text);font-family:monospace;word-break:break-all;letter-spacing:.5px">${chaveFormatada}</div>
            </div>
          </div>

          <!-- XML -->
          <div style="flex:1;overflow-y:auto;padding:16px 24px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
              <div style="font-size:12px;font-weight:700;color:var(--text)">XML NFC-e gerado</div>
              <button onclick="navigator.clipboard.writeText(document.getElementById('nfce-xml-pre').textContent);this.textContent='✓ Copiado!';setTimeout(()=>this.textContent='Copiar XML',2000)" style="background:var(--card2);border:1px solid var(--border);border-radius:6px;color:var(--text2);font-size:11px;padding:4px 10px;cursor:pointer">Copiar XML</button>
            </div>
            <pre id="nfce-xml-pre" style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;font-size:10px;font-family:monospace;overflow-x:auto;white-space:pre;color:var(--text2);max-height:280px;overflow-y:auto">${esc(res.xml||'')}</pre>
          </div>

          <!-- Footer -->
          <div style="padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
            <button class="btn btn-secondary" onclick="document.getElementById('nfce-modal-overlay').remove()">Fechar</button>
            <button class="btn btn-primary" onclick="loadSaudeFiscal();document.getElementById('nfce-modal-overlay').remove()">Atualizar dashboard</button>
          </div>
        </div>`;

      document.body.appendChild(overlay);
      overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

      // Atualizar dashboard silenciosamente
      loadSaudeFiscal();
    }

    // ── Salvar config NFC-e ───────────────────────────────────────────────
    async function salvarConfigNfce() {
      const campos = ['nfce_ativo','nfce_cnpj','nfce_ie','nfce_uf','nfce_serie',
                      'nfce_ambiente','nfce_regime','nfce_csc','nfce_csc_id','nfce_cert_validade'];
      const body = { action: 'salvar_config' };
      campos.forEach(c => {
        const el = document.getElementById('sf-cfg-' + c.replace('nfce_',''));
        if (el) body[c] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
      });
      const res = await api('fiscal.php', { method:'POST', body: JSON.stringify(body) });
      if (res?.success) { toast('✅ Configuração NFC-e salva!'); loadSaudeFiscal(); }
      else toast('Erro: '+(res?.error||'desconhecido'), 'err');
    }

    // ── Radar Fiscal — dados reais ────────────────────────────────────────
    let _rfLoaded = false;

    async function loadRadarFiscal() {
      if (_rfLoaded) return; // evitar duplo load

      const res = await api('radar.php?action=dashboard');
      if (!res?.success) {
        const msg = '<div style="padding:20px;color:var(--red);font-size:12px;text-align:center">⚠ ' + esc(res?.error || 'Erro ao carregar dados fiscais') + '</div>';
        ['rf-checklist','rf-nt-list','rf-timeline-list','rf-alertas-list'].forEach(id => {
          const el = document.getElementById(id); if (el) el.innerHTML = msg;
        });
        const bTitle = document.querySelector('#rf-banner .sf-status-title');
        if (bTitle) bTitle.textContent = 'ERRO AO CARREGAR DADOS';
        return;
      }
      _rfLoaded = true;

      const s = res.status_geral;

      // ── Banner de status ──
      const banner = document.getElementById('rf-banner');
      if (banner) {
        banner.className = 'sf-status-banner' + (s.tipo !== 'ok' ? ' ' + s.tipo : '');
        banner.querySelector('.sf-status-ico').textContent = s.tipo === 'ok' ? '✓' : (s.tipo === 'warning' ? '⚡' : '✕');
        banner.querySelector('.sf-status-title').textContent  = s.titulo;
        banner.querySelector('.sf-status-sub').textContent    = s.sub;
      }

      // ── Score ──
      const scoreEl = document.getElementById('rf-score');
      if (scoreEl) {
        const sc = res.score;
        const cor = sc >= 90 ? 'var(--green)' : sc >= 60 ? 'var(--gold)' : 'var(--red)';
        scoreEl.innerHTML = `<div style="font-size:32px;font-weight:900;color:${cor}">${sc}<span style="font-size:14px;font-weight:400;color:var(--text3)">%</span></div><div style="font-size:11px;color:var(--text3)">conformidade</div>`;
      }

      // ── Alertas rápidos ──
      const alertsBadge = document.getElementById('rf-alertas-badge');
      if (alertsBadge) {
        alertsBadge.style.display = res.total_alertas > 0 ? '' : 'none';
        alertsBadge.textContent   = res.total_alertas + ' alerta(s)';
      }

      // ── Hora da última verificação ──
      const ultVerEl = document.getElementById('rf-ultima-ver');
      if (ultVerEl && res.ultima_verificacao) {
        ultVerEl.textContent = 'última verificação: ' + fmtDt(res.ultima_verificacao);
      }

      // ── Checklist de conformidade ──
      const checkEl = document.getElementById('rf-checklist');
      if (checkEl) {
        const STATUS = {
          ok:      { ico:'✓', cor:'var(--green)' },
          warning: { ico:'⚠',  cor:'var(--gold)' },
          danger:  { ico:'✕',  cor:'var(--red)' },
          info:    { ico:'ℹ',  cor:'var(--blue)' },
        };
        const grupos = {};
        (res.conformidade || []).forEach(c => {
          if (!grupos[c.grupo]) grupos[c.grupo] = [];
          grupos[c.grupo].push(c);
        });

        checkEl.innerHTML = Object.entries(grupos).map(([grp, items]) => `
          <div style="margin-bottom:16px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:6px">${esc(grp)}</div>
            ${items.map(c => {
              const st = STATUS[c.status] || STATUS.info;
              return `<div class="rf-agente-row" style="justify-content:space-between">
                <span style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:13px">${esc(c.icone||'•')}</span>
                  <span>${esc(c.titulo)}</span>
                </span>
                <span style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                  <span style="font-size:11px;color:var(--text3);text-align:right;max-width:240px">${esc(c.descricao)}</span>
                  <span style="color:${st.cor};font-weight:700;font-size:13px">${st.ico}</span>
                </span>
              </div>`;
            }).join('')}
          </div>`
        ).join('');
      }

      // ── Timeline ──
      const tlEl = document.getElementById('rf-timeline-list');
      if (tlEl) {
        const hoje = new Date().toISOString().slice(0,10);
        tlEl.innerHTML = (res.timeline || []).map(t => {
          const isVigente = t.status_marco === 'vigente';
          const isPassed  = t.status_marco === 'passado';
          const dotCor    = isPassed ? 'var(--green)' : isVigente ? 'var(--gold)' : 'var(--border2)';
          const txtCor    = isPassed ? 'var(--text2)' : isVigente ? 'var(--text)' : 'var(--text3)';
          const regimeBadge = t.regime_afetado !== 'todos'
            ? `<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:4px;background:rgba(59,130,246,.12);color:var(--blue)">${esc(t.regime_afetado)}</span>`
            : '';
          return `<div class="rf-update-row" style="${isVigente ? 'background:rgba(245,158,11,.06);margin:0 -16px;padding:10px 16px;border-radius:0;' : ''}">
            <div class="rf-update-dot" style="background:${dotCor};${isVigente ? 'width:10px;height:10px;' : ''}"></div>
            <div>
              <div style="display:flex;gap:6px;align-items:center;margin-bottom:2px">
                <span style="font-size:11px;color:var(--text3);font-weight:600">${esc(new Date(t.data_vigencia+'T12:00:00').toLocaleDateString('pt-BR',{month:'short',year:'numeric'}))}</span>
                ${regimeBadge}
                ${isVigente ? '<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:4px;background:rgba(245,158,11,.15);color:var(--gold)">VIGENTE AGORA</span>' : ''}
                ${isPassed  ? '<span style="font-size:9px;color:var(--green);font-weight:700">✓ aplicado</span>' : ''}
              </div>
              <div style="font-size:12px;font-weight:600;color:${txtCor}">${esc(t.titulo)}</div>
              ${t.descricao ? `<div style="font-size:11px;color:var(--text3);margin-top:2px;line-height:1.4">${esc(t.descricao)}</div>` : ''}
            </div>
          </div>`;
        }).join('');
      }

      // ── Notas Técnicas ──
      const ntEl = document.getElementById('rf-nt-list');
      if (ntEl) {
        const COR_NT = {nova:'var(--gold)',analisada:'var(--blue)',aplicada:'var(--green)',ignorada:'var(--text3)'};
        const LBL_NT = {nova:'NOVA',analisada:'ANALISADA',aplicada:'APLICADA',ignorada:'IGNORADA'};
        const novas  = (res.notas_tecnicas || []).filter(n => n.status === 'nova').length;

        // Badge contador de NTs novas
        const badge = document.getElementById('rf-nt-nova-badge');
        if (badge) { badge.style.display = novas > 0 ? '' : 'none'; badge.textContent = novas + ' nova' + (novas > 1 ? 's' : ''); }

        ntEl.innerHTML = (res.notas_tecnicas || []).map(n => {
          const cor = COR_NT[n.status] || 'var(--text3)';
          const isNova = n.status === 'nova';
          const bgRow = isNova ? 'background:rgba(245,158,11,.04);' : '';
          const statusOpts = ['nova','analisada','aplicada','ignorada']
            .map(s => `<option value="${s}" ${n.status===s?'selected':''}>${LBL_NT[s]}</option>`).join('');
          return `<div class="rf-update-row" style="align-items:flex-start;${bgRow}">
            <div class="rf-update-dot" style="background:${cor};margin-top:4px"></div>
            <div style="flex:1;min-width:0">
              <div style="display:flex;gap:6px;align-items:center;margin-bottom:3px;flex-wrap:wrap">
                <span style="font-size:10px;color:var(--text3)">${n.data_publicacao ? new Date(n.data_publicacao+'T12:00:00').toLocaleDateString('pt-BR',{month:'short',year:'numeric'}) : '—'}</span>
                <select onchange="mudarStatusNt(${n.id}, this.value)" style="font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;background:${cor}22;color:${cor};border:1px solid ${cor}44;cursor:pointer;font-family:inherit">
                  ${statusOpts}
                </select>
                ${isNova ? '<span style="font-size:10px;font-weight:700;color:var(--gold);animation:pulse 1.5s infinite">⚠ Requer análise</span>' : ''}
              </div>
              <div style="font-size:12px;font-weight:700;color:${isNova ? 'var(--text)' : 'var(--text2)'}">${esc(n.codigo||'')}</div>
              <div style="font-size:11px;color:var(--text3);margin-top:1px">${esc(n.titulo||'')}</div>
            </div>
            ${isNova ? `<div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;margin-top:2px">
              <button class="btn btn-sm" style="background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.3)" onclick="mudarStatusNt(${n.id},'aplicada')">✓ Aplicada</button>
              <button class="btn btn-sm" style="background:rgba(59,130,246,.08);color:var(--blue);border:1px solid rgba(59,130,246,.2)" onclick="mudarStatusNt(${n.id},'analisada')">Analisar</button>
            </div>` : ''}
          </div>`;
        }).join('') || '<div style="padding:16px;color:var(--text3);font-size:12px;text-align:center">Nenhuma NT registrada — use "+ Adicionar" para cadastrar</div>';
      }

      // ── Alertas ──
      const alertEl = document.getElementById('rf-alertas-list');
      if (alertEl) {
        const alertas = res.alertas || [];
        alertEl.innerHTML = alertas.length
          ? alertas.map(a => {
              const cor = a.severidade === 'danger' ? 'var(--red)' : a.severidade === 'warning' ? 'var(--gold)' : 'var(--blue)';
              return `<div style="display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border)">
                <span style="color:${cor};font-size:16px;flex-shrink:0">${a.severidade==='danger'?'✕':'⚠'}</span>
                <div style="flex:1">
                  <div style="font-size:12px;font-weight:700;color:var(--text)">${esc(a.titulo)}</div>
                  <div style="font-size:11px;color:var(--text2);margin-top:2px">${esc(a.descricao)}</div>
                </div>
                <button onclick="dispensarAlerta(${a.id})" class="btn btn-sm" style="background:var(--card2);color:var(--text3);border:1px solid var(--border);flex-shrink:0">Dispensar</button>
              </div>`;
            }).join('')
          : '<div style="padding:24px;text-align:center;color:var(--green);font-size:13px">✓ Nenhum alerta ativo</div>';
      }
    }

    // ── Verificar portal por novas NTs ───────────────────────────────────
    async function verificarNovasNTs() {
      const btn = document.getElementById('btn-verificar-nt');
      btn.textContent = '⏳ Verificando...';
      btn.style.pointerEvents = 'none';

      const res = await api('radar.php', { method:'POST', body: JSON.stringify({action:'verificar_nt'}) });

      btn.textContent = '↻ Verificar';
      btn.style.pointerEvents = '';

      if (res?.success) {
        if (res.novas_registradas > 0) {
          toast('🔔 ' + res.novas_registradas + ' nova(s) NT encontrada(s): ' + res.novas.join(', '));
        } else if (res.erros?.length) {
          toast('Portal não respondeu — verifique sua conexão', 'err');
        } else {
          toast('✓ Sistema atualizado — nenhuma NT nova encontrada');
        }
        _rfLoaded = false;
        loadRadarFiscal();
      } else {
        toast('Erro ao verificar: ' + (res?.msg || 'sem resposta'), 'err');
      }
    }

    // ── Mudar status de uma NT ────────────────────────────────────────────
    async function mudarStatusNt(id, status) {
      const res = await api('radar.php', { method:'POST', body: JSON.stringify({action:'atualizar_nt', id, status}) });
      if (res?.success) {
        toast('NT marcada como: ' + status.toUpperCase());
        _rfLoaded = false;
        loadRadarFiscal();
      } else {
        toast('Erro ao atualizar NT', 'err');
      }
    }

    // ── Abrir modal para adicionar NT manualmente ─────────────────────────
    function abrirModalNt() {
      document.getElementById('nt-codigo').value = '';
      document.getElementById('nt-titulo').value = '';
      document.getElementById('nt-data').value = new Date().toISOString().slice(0,10);
      document.getElementById('nt-status').value = 'nova';
      openModal('modal-add-nt');
    }

    // ── Salvar NT adicionada manualmente ─────────────────────────────────
    async function salvarNt() {
      const codigo = document.getElementById('nt-codigo').value.trim();
      if (!codigo) { toast('Código obrigatório', 'err'); return; }
      const res = await api('radar.php', {
        method:'POST',
        body: JSON.stringify({
          action: 'add_nt',
          codigo,
          titulo: document.getElementById('nt-titulo').value.trim(),
          data_publicacao: document.getElementById('nt-data').value,
          status: document.getElementById('nt-status').value,
        })
      });
      if (res?.success) {
        toast('NT cadastrada com sucesso!');
        closeModal('modal-add-nt');
        _rfLoaded = false;
        loadRadarFiscal();
      } else {
        toast(res?.error || 'Erro ao salvar', 'err');
      }
    }

    async function dispensarAlerta(id) {
      await api('radar.php', { method:'POST', body: JSON.stringify({action:'dispensar_alerta', id}) });
      _rfLoaded = false;
      loadRadarFiscal();
    }

    // marcarNtAplicada mantida como alias para compatibilidade
    async function marcarNtAplicada(id) { await mudarStatusNt(id, 'aplicada'); }

    // ══════════════════════════════════════════════════════════════════════
    // ── AUDITORIA ────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let audPage = 1;

    async function loadAuditoria(page) {
      if (!IS_ADMIN) return;
      if (page) audPage = page;

      const busca = document.getElementById('aud-busca').value;
      const acao = document.getElementById('aud-acao').value;
      const ini = document.getElementById('aud-ini').value;
      const fim = document.getElementById('aud-fim').value;
      const params = new URLSearchParams({
        page: audPage
      });
      if (ini) params.set('data_ini', ini);
      if (fim) params.set('data_fim', fim);
      if (acao) params.set('acao', acao);

      const res = await api('audit.php?' + params);
      if (!res.success) return;

      // populate acoes filter
      const sel = document.getElementById('aud-acao');
      const cur = sel.value;
      sel.innerHTML = '<option value="">Todas ações</option>' +
        (res.acoes || []).map(a => '<option value="' + esc(a) + '">' + esc(a) + '</option>').join('');
      sel.value = cur;

      document.getElementById('aud-count').textContent = res.total + ' registros';

      const ACAO_CLR = {
        login: '#22c55e',
        logout: '#6b7280',
        pedido_criado: '#3b82f6',
        pedido_status_alterado: '#f59e0b',
        pedido_cancelado: '#ef4444',
        produto_editado: '#8b5cf6',
        produto_criado: '#22c55e',
        produto_ativado: '#22c55e',
        produto_desativado: '#ef4444',
        usuario_criado: '#3b82f6',
        usuario_editado: '#f59e0b',
        categoria_criada: '#22c55e',
        categoria_editada: '#f59e0b',
      };

      let rows = res.data || [];
      if (busca) rows = rows.filter(r => (r.descricao || '').toLowerCase().includes(busca.toLowerCase()) ||
        (r.usuario_nome || '').toLowerCase().includes(busca.toLowerCase()));

      document.getElementById('aud-list').innerHTML = rows.map(r =>
        '<div class="audit-row">' +
        '<div class="audit-time">' + fmtDt(r.criado_em) + '</div>' +
        '<div class="audit-user">' + esc(r.usuario_nome || 'Sistema') + '</div>' +
        '<div class="audit-acao">' +
        '<span class="audit-badge" style="background:' + (ACAO_CLR[r.acao] || '#6b7280') + '22;color:' + (ACAO_CLR[r.acao] || '#6b7280') + '">' + esc(r.acao) + '</span>' +
        '<span style="color:var(--text2)">' + esc(r.descricao || '') + '</span>' +
        '</div>' +
        '<div style="color:var(--text3);font-size:11px;flex-shrink:0">' + esc(r.ip || '') + '</div>' +
        '</div>'
      ).join('') || '<div style="text-align:center;color:var(--text3);padding:40px">Nenhum registro encontrado</div>';

      renderPagination('aud-pagination', audPage, res.pages, p => loadAuditoria(p));
    }

    document.getElementById('btn-aud-load').addEventListener('click', () => loadAuditoria(1));
    document.getElementById('aud-busca').addEventListener('input', debounce(() => loadAuditoria(1), 400));

    // ─────────────────────────────────────────────────────────────────────
    // ── HELPERS ────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    function renderPagination(elId, current, total, onClick) {
      const el = document.getElementById(elId);
      if (!el) return;
      if (total <= 1) {
        el.innerHTML = '';
        return;
      }
      let html = '';
      if (current > 1) html += '<button class="page-btn" onclick="(' + onClick + ')(' + (current - 1) + ')">‹</button>';
      for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
        html += '<button class="page-btn' + (i === current ? ' active' : '') + '" onclick="(' + onClick + ')(' + i + ')">' + i + '</button>';
      }
      if (current < total) html += '<button class="page-btn" onclick="(' + onClick + ')(' + (current + 1) + ')">›</button>';
      el.innerHTML = html;
    }

    function debounce(fn, ms) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
      };
    }

    // ─────────────────────────────────────────────────────────────────────
    // ── CONFIGURAÇÕES ────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    let _cfgLoaded = false;
    async function loadConfiguracoes() {
      if (_cfgLoaded) return;
      _cfgLoaded = true;
      const res = await api('configuracoes.php');
      if (!res.success) return;
      const d = res.data;

      const g = (id, k) => {
        const el = document.getElementById(id);
        if (el && d[k]) el.value = d[k].valor ?? '';
      };
      const gb = (id, k) => {
        const el = document.getElementById(id);
        if (el && d[k]) el.checked = d[k].valor === '1' || d[k].valor === 'true';
      };
      const gv = (id, k) => {
        const el = document.getElementById(id);
        if (el && d[k]) el.value = d[k].valor ?? el.value;
      };

      // Loja
      g('cfg-nome', 'loja_nome');
      g('cfg-cnpj', 'loja_cnpj');
      g('cfg-endereco', 'loja_endereco');
      g('cfg-telefone', 'loja_telefone');
      g('cfg-email', 'loja_email');
      g('cfg-instagram', 'loja_instagram');
      g('cfg-url', 'loja_url');
      g('cfg-logo', 'loja_logo_url');
      g('cfg-msg-boasvindas', 'totem_mensagem_boasvindas');
      // Horários
      ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'].forEach(dia => {
        gb('cfg-h-' + dia + '-ativo', 'horario_' + dia + '_ativo');
        g('cfg-h-' + dia + '-ab', 'horario_' + dia + '_abertura');
        g('cfg-h-' + dia + '-fc', 'horario_' + dia + '_fechamento');
      });
      // Totem
      g('cfg-idle', 'totem_idle_segundos');
      g('cfg-confirm', 'totem_confirmar_segundos');
      g('cfg-kds-refresh', 'kds_refresh_segundos');
      g('cfg-max-itens', 'totem_max_itens_pedido');
      g('cfg-aviso-fechamento', 'totem_aviso_fechamento_min');
      g('cfg-autoreload', 'totem_autoreload_minutos');
      gv('cfg-kds-som', 'kds_som');
      gv('cfg-taxa-servico', 'taxa_servico_percentual');
      gb('cfg-taxa-serv-ativa', 'taxa_servico_ativa');
      // Pagamentos
      gb('cfg-pag-pix', 'pagamento_pix_ativo');
      gb('cfg-pag-credito', 'pagamento_credito_ativo');
      gb('cfg-pag-debito', 'pagamento_debito_ativo');
      gb('cfg-pag-dinheiro', 'pagamento_dinheiro_ativo');
      g('cfg-taxa-cred', 'taxa_credito');
      g('cfg-taxa-deb', 'taxa_debito');
      g('cfg-taxa-pix', 'taxa_pix');
      g('cfg-pix-chave', 'pix_chave');
      g('cfg-pix-benef', 'pix_beneficiario');
      g('cfg-pix-cidade', 'pix_cidade');
      // Impressora
      gb('cfg-imp-ativa', 'impressora_ativa');
      g('cfg-imp-ip', 'impressora_ip');
      g('cfg-imp-porta', 'impressora_porta');
      gv('cfg-imp-largura', 'impressora_largura');
      g('cfg-imp-copias', 'impressora_copias');
      gv('cfg-imp-cozinha', 'impressora_cozinha');
      // Fidelidade
      gb('cfg-fid-ativa', 'fidelidade_ativa');
      g('cfg-fid-pts-real', 'pontos_por_real');
      g('cfg-fid-real-pts', 'real_por_ponto');
      g('cfg-fid-val-dias', 'validade_dias');
      g('cfg-fid-min-resgate', 'pontos_minimo_resgate');
      g('cfg-fid-max-desc', 'pontos_max_desc_pct');
      // Integrações
      g('cfg-n8n-url', 'n8n_webhook_base');
      g('cfg-n8n-whatsapp', 'n8n_whatsapp');
      gb('cfg-wh-pedido', 'webhook_novo_pedido');
      gb('cfg-wh-status', 'webhook_status');
      gb('cfg-wh-estoque', 'webhook_estoque');
      // Alertas
      gb('cfg-alerta-zap-ativo', 'alerta_estoque_zap');
      g('cfg-alerta-est-dias', 'alerta_estoque_dias');
      g('cfg-alerta-validade-dias', 'alerta_validade_dias');
      g('cfg-alerta-email', 'alerta_email');
      gb('cfg-email-diario', 'alerta_email_diario');
      gb('cfg-email-semanal', 'alerta_email_semanal');
      g('cfg-alerta-pedido-min', 'alerta_pedido_min');
      g('cfg-alerta-caixa-max', 'alerta_caixa_max');

      atualizarSimFidelidade();
    }

    document.getElementById('btn-salvar-cfg')?.addEventListener('click', async () => {
      const payload = {
        loja_nome: document.getElementById('cfg-nome').value.trim(),
        loja_cnpj: document.getElementById('cfg-cnpj').value.trim(),
        loja_endereco: document.getElementById('cfg-endereco').value.trim(),
        loja_telefone: document.getElementById('cfg-telefone').value.trim(),
        loja_url: document.getElementById('cfg-url')?.value.trim() || '',
        loja_logo_url: document.getElementById('cfg-logo').value.trim(),
        totem_idle_segundos: document.getElementById('cfg-idle').value,
        totem_confirmar_segundos: document.getElementById('cfg-confirm').value,
        kds_refresh_segundos: document.getElementById('cfg-kds-refresh').value,
        impressora_ativa: (document.getElementById('cfg-imp-ativa')?.checked ? 'true' : 'false'),
        impressora_ip: document.getElementById('cfg-imp-ip')?.value.trim() || '',
        impressora_porta: document.getElementById('cfg-imp-porta')?.value || '9100',
        impressora_largura: document.getElementById('cfg-imp-largura')?.value || '42',
        pix_chave: document.getElementById('cfg-pix-chave')?.value.trim() || '',
        pix_beneficiario: document.getElementById('cfg-pix-benef')?.value.trim() || '',
        pix_cidade: document.getElementById('cfg-pix-cidade')?.value.trim() || '',
      };
      const res = await api('configuracoes.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      const st = document.getElementById('cfg-status');
      if (res.success) {
        st.textContent = '✓ Salvo com sucesso!';
        st.style.color = 'var(--green)';
        st.style.display = 'inline';
        toast('Configurações salvas!');
        setTimeout(() => st.style.display = 'none', 3000);
      } else {
        st.textContent = '✗ Erro ao salvar';
        st.style.color = 'var(--red)';
        st.style.display = 'inline';
        toast('Erro: ' + (res.error || 'desconhecido'), 'err');
      }
    });

    // ─────────────────────────────────────────────────────────────────────
    // ── n8n / WHATSAPP ────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    (async () => {
      const res = await fetch('api/configuracoes.php?action=listar').then(r => r.json()).catch(() => null);
      if (!res?.data) return;
      const d = res.data;
      const u = document.getElementById('cfg-n8n-url');
      const w = document.getElementById('cfg-n8n-whatsapp');
      if (u && d['n8n_webhook_base']) u.value = d['n8n_webhook_base'].valor || '';
      if (w && d['n8n_whatsapp']) w.value = d['n8n_whatsapp'].valor || '';
    })();

    document.getElementById('btn-salvar-n8n')?.addEventListener('click', async () => {
      const st = document.getElementById('n8n-status');
      const payload = {
        n8n_webhook_base: document.getElementById('cfg-n8n-url')?.value.trim() || '',
        n8n_whatsapp: document.getElementById('cfg-n8n-whatsapp')?.value.trim() || '',
      };
      const res = await api('configuracoes.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      st.style.display = 'inline';
      if (res.success) {
        st.textContent = '✓ Salvo!';
        st.style.color = 'var(--green)';
      } else {
        st.textContent = '✗ Erro';
        st.style.color = 'var(--red)';
      }
      setTimeout(() => st.style.display = 'none', 3000);
    });

    document.getElementById('btn-testar-n8n')?.addEventListener('click', async () => {
      const btn = document.getElementById('btn-testar-n8n');
      const st = document.getElementById('n8n-status');
      btn.disabled = true;
      btn.textContent = 'Enviando…';
      st.style.display = 'none';
      try {
        const res = await fetch('api/webhook_test.php').then(r => r.json());
        st.style.display = 'inline';
        if (res.success) {
          st.textContent = '✓ ' + res.msg;
          st.style.color = 'var(--green)';
          toast('Webhook enviado! Verifique o n8n.');
        } else {
          st.textContent = '✗ ' + res.msg;
          st.style.color = 'var(--red)';
          toast(res.msg, 'err');
        }
      } catch (e) {
        st.style.display = 'inline';
        st.textContent = '✗ Erro de conexão';
        st.style.color = 'var(--red)';
      }
      btn.disabled = false;
      btn.textContent = 'Testar conexão';
      setTimeout(() => st.style.display = 'none', 5000);
    });

    // ─────────────────────────────────────────────────────────────────────
    // ── BACKUP & EXPORTAÇÃO ───────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    function initBackup() {
      const today = new Date().toISOString().slice(0, 10);
      const ini30 = new Date(Date.now() - 29 * 864e5).toISOString().slice(0, 10);
      const iniEl = document.getElementById('bkp-ini');
      const fimEl = document.getElementById('bkp-fim');
      if (iniEl && !iniEl.value) {
        iniEl.value = ini30;
        fimEl.value = today;
      }
    }

    document.getElementById('btn-bkp-csv')?.addEventListener('click', () => {
      const ini = document.getElementById('bkp-ini').value;
      const fim = document.getElementById('bkp-fim').value;
      if (!ini || !fim) {
        toast('Selecione o período', 'err');
        return;
      }
      window.open(BASE + 'relatorios.php?export=csv&data_ini=' + ini + '&data_fim=' + fim);
    });

    document.getElementById('btn-bkp-pdf')?.addEventListener('click', () => {
      const ini = document.getElementById('bkp-ini').value;
      const fim = document.getElementById('bkp-fim').value;
      if (!ini || !fim) {
        toast('Selecione o período', 'err');
        return;
      }
      window.open('relatorio_pdf.php?data_ini=' + ini + '&data_fim=' + fim);
    });

    document.getElementById('btn-bkp-json')?.addEventListener('click', async () => {
      const ini = document.getElementById('bkp-ini').value;
      const fim = document.getElementById('bkp-fim').value;
      if (!ini || !fim) {
        toast('Selecione o período', 'err');
        return;
      }
      const st = document.getElementById('bkp-status');
      st.textContent = 'Gerando backup...';
      st.style.display = 'block';

      const [pedidos, produtos, cats] = await Promise.all([
        api('pedidos.php?data_ini=' + ini + '&data_fim=' + fim + '&page=1'),
        api('produtos.php'),
        api('categorias.php'),
      ]);

      const dump = {
        gerado_em: new Date().toISOString(),
        periodo: {
          ini,
          fim
        },
        pedidos: pedidos.data || [],
        produtos: produtos.data || [],
        categorias: cats.data || [],
      };

      const blob = new Blob([JSON.stringify(dump, null, 2)], {
        type: 'application/json'
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'backup_' + ini + '_' + fim + '.json';
      a.click();
      URL.revokeObjectURL(url);
      st.textContent = '✓ Backup gerado!';
      st.style.color = 'var(--green)';
      setTimeout(() => st.style.display = 'none', 4000);
    });

    // ─────────────────────────────────────────────────────────────────────
    // ── INIT ────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    (function init() {
      const today = new Date().toISOString().slice(0, 10);
      const ini30 = new Date(Date.now() - 29 * 864e5).toISOString().slice(0, 10);
      document.getElementById('ped-ini').value = today;
      document.getElementById('ped-fim').value = today;
      if (document.getElementById('aud-ini')) document.getElementById('aud-ini').value = ini30;
      if (document.getElementById('aud-fim')) document.getElementById('aud-fim').value = today;

      loadDashboard();
      setInterval(loadDashboard, 15000);

      // Alertas de estoque baixo no painel
      (async () => {
        try {
          const res = await fetch('../../totem/api/estoque_alertas.php'.replace('../../totem', '..'), {
            headers: {
              'X-CSRF-Token': CSRF
            }
          });
          // usa api relativa
          const r = await fetch('../api/estoque_alertas.php', {
            headers: {
              'X-CSRF-Token': CSRF
            }
          });
          const d = await r.json();
          if (d.success && d.total > 0) {
            const banner = document.createElement('div');
            banner.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#7f1d1d;border:1px solid #ef4444;color:#fca5a5;padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:9998;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.5)';
            banner.textContent = '⚠️ ' + d.total + ' insumo(s) com estoque baixo — clique para ver';
            banner.onclick = () => {
              window.open('estoque/', '_blank');
              banner.remove();
            };
            document.body.appendChild(banner);
            setTimeout(() => banner.remove(), 15000);
          }
        } catch {}
      })();
    })();

    async function imprimirPedido(pedidoId) {
      try {
        const res = await fetch('../api/imprimir.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF
          },
          body: JSON.stringify({
            pedido_id: pedidoId
          }),
        });
        const d = await res.json();
        if (d.success) toast('Imprimindo...', 'ok');
        else {
          // Fallback: abre janela de impressão do browser
          const w = window.open('', '_blank', 'width=400,height=600');
          const ped = window._pedidoAtual || {};
          w.document.write(gerarHtmlCupom(ped));
          w.document.close();
          w.print();
        }
      } catch {
        toast('Impressora não configurada', 'err');
      }
    }

    function gerarHtmlCupom(p) {
      if (!p || !p.numero_pedido) return '<p>Dados não disponíveis</p>';
      const itens = (p.itens || []).map(i =>
        `<tr><td>${i.qtd}x ${i.nome}</td><td style="text-align:right">R$ ${parseFloat(i.sub||0).toFixed(2).replace('.',',')}</td></tr>`
      ).join('');
      return `<!DOCTYPE html><html><head><meta charset="UTF-8">
  <style>body{font-family:monospace;font-size:13px;width:300px;margin:0 auto}
  h2{text-align:center;font-size:15px}table{width:100%;border-collapse:collapse}
  td{padding:3px 0}hr{border:1px dashed #000}
  .total{font-size:16px;font-weight:bold;text-align:right}
  </style></head><body>
  <h2>Café Comunhão</h2><hr>
  <p>Pedido: #${p.numero_pedido}</p>
  <p>Data: ${new Date().toLocaleString('pt-BR')}</p><hr>
  <table>${itens}</table><hr>
  <div class="total">Total: R$ ${parseFloat(p.total||0).toFixed(2).replace('.',',')}</div>
  <p>Pagamento: ${p.forma_pagamento||''}</p><hr>
  <p style="text-align:center">Obrigado!</p>
  </body></html>`;
    }

    async function fazBackup(e) {
      e.preventDefault();
      const btn = document.getElementById('btn-backup');
      btn.textContent = '⏳ Gerando...';
      btn.style.pointerEvents = 'none';
      try {
        const res = await fetch('backup.php', {
          headers: {
            'X-CSRF-Token': CSRF
          }
        });
        const d = await res.json();
        if (d.success) toast('✅ ' + d.message, 'ok');
        else toast('Erro: ' + (d.error || 'falha'), 'err');
      } catch {
        toast('Erro ao conectar', 'err');
      }
      btn.textContent = '💾 Backup BD';
      btn.style.pointerEvents = '';
    }

    // ── Configurações sub-menu ───────────────────────────────────────────
    function toggleCfgMenu() {
      const btn = document.getElementById('nav-cfg-btn');
      const menu = document.getElementById('cfg-submenu');
      const isOpen = menu.classList.contains('open');
      if (isOpen) {
        menu.classList.remove('open');
        btn.classList.remove('open', 'active');
      } else {
        menu.classList.add('open');
        btn.classList.add('open', 'active');
        cfgTab('loja');
      }
    }

    function cfgTab(section) {
      // Verificar permissão para sub-item de configuração
      const cfgPermMap = {
        loja: 'cfg.loja',
        totem: 'cfg.totem_kds',
        pagamentos: 'cfg.pagamentos',
        impressora: 'cfg.impressora',
        fidelidade: 'cfg.fidelidade',
        integracoes: 'cfg.integracoes',
        alertas: 'cfg.alertas',
        backup: 'cfg.backup'
      };
      if (cfgPermMap[section] && !hasPerm(cfgPermMap[section])) {
        toast('Sem permissão para esta configuração', 'err');
        return;
      }
      // Activar painel
      document.querySelectorAll('[id^="panel-cfg-"]').forEach(p => p.classList.remove('active'));
      const panel = document.getElementById('panel-cfg-' + section);
      if (panel) {
        panel.classList.add('active');
        // Esconder outros painéis
        document.querySelectorAll('.panel.active:not(#panel-cfg-' + section + ')').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-cfg-' + section).classList.add('active');
      }
      // Marcar sub-item activo
      document.querySelectorAll('#cfg-submenu .nav-sub').forEach(el =>
        el.classList.toggle('active', el.dataset.cfg === section)
      );
      document.getElementById('cfg-submenu').classList.add('open');
      document.getElementById('nav-cfg-btn').classList.add('open', 'active');
      // Actualizar título do topbar
      const lbl = {
        'loja': '🏪 Loja',
        'totem': '🖥️ Totem & KDS',
        'pagamentos': '💳 Pagamentos',
        'impressora': '🖨️ Impressora',
        'fidelidade': '⭐ Fidelidade',
        'integracoes': '📲 Integrações',
        'alertas': '🔔 Alertas',
        'backup': '💾 Backup'
      };
      document.getElementById('topbar-title').textContent = 'Configurações — ' + (lbl[section] || section);
      // Carregar dados ao abrir pela primeira vez
      if (!window._cfgCarregado) {
        window._cfgCarregado = true;
        loadConfiguracoes();
      }
      // Simular fidelidade ao abrir
      if (section === 'fidelidade') atualizarSimFidelidade();
    }

    // Salvar por seção — função genérica
    async function _salvarCfg(payload, statusId) {
      const res = await api('configuracoes.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      const el = document.getElementById(statusId);
      if (res?.success) {
        toast('Configurações salvas!');
        if (el) {
          el.textContent = '✓ Salvo!';
          el.style.color = 'var(--green)';
          el.style.display = 'inline';
          setTimeout(() => el.style.display = 'none', 3000);
        }
      } else {
        toast('Erro: ' + (res?.error || 'desconhecido'), 'err');
        if (el) {
          el.textContent = '✗ Erro ao salvar';
          el.style.color = 'var(--red)';
          el.style.display = 'inline';
        }
      }
    }

    function salvarCfgLoja() {
      const dias = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
      const payload = {
        loja_nome: document.getElementById('cfg-nome')?.value.trim() || '',
        loja_cnpj: document.getElementById('cfg-cnpj')?.value.trim() || '',
        loja_endereco: document.getElementById('cfg-endereco')?.value.trim() || '',
        loja_telefone: document.getElementById('cfg-telefone')?.value.trim() || '',
        loja_email: document.getElementById('cfg-email')?.value.trim() || '',
        loja_instagram: document.getElementById('cfg-instagram')?.value.trim() || '',
        loja_logo_url: document.getElementById('cfg-logo')?.value.trim() || '',
        loja_url: document.getElementById('cfg-url')?.value.trim() || '',
        totem_mensagem_boasvindas: document.getElementById('cfg-msg-boasvindas')?.value.trim() || '',
      };
      dias.forEach(d => {
        payload['horario_' + d + '_ativo'] = document.getElementById('cfg-h-' + d + '-ativo')?.checked ? '1' : '0';
        payload['horario_' + d + '_abertura'] = document.getElementById('cfg-h-' + d + '-ab')?.value || '';
        payload['horario_' + d + '_fechamento'] = document.getElementById('cfg-h-' + d + '-fc')?.value || '';
      });
      _salvarCfg(payload, 'cfg-loja-status');
    }

    function salvarCfgTotem() {
      _salvarCfg({
        totem_idle_segundos: document.getElementById('cfg-idle')?.value || '120',
        totem_confirmar_segundos: document.getElementById('cfg-confirm')?.value || '30',
        totem_max_itens_pedido: document.getElementById('cfg-max-itens')?.value || '20',
        totem_aviso_fechamento_min: document.getElementById('cfg-aviso-fechamento')?.value || '10',
        totem_autoreload_minutos: document.getElementById('cfg-autoreload')?.value || '0',
        kds_refresh_segundos: document.getElementById('cfg-kds-refresh')?.value || '5',
        kds_som: document.getElementById('cfg-kds-som')?.value || '0',
        taxa_servico_ativa: document.getElementById('cfg-taxa-serv-ativa')?.checked ? '1' : '0',
        taxa_servico_percentual: document.getElementById('cfg-taxa-servico')?.value || '0',
      }, 'cfg-totem-status');
    }

    function salvarCfgPagamentos() {
      _salvarCfg({
        pagamento_pix_ativo: document.getElementById('cfg-pag-pix')?.checked ? '1' : '0',
        pagamento_credito_ativo: document.getElementById('cfg-pag-credito')?.checked ? '1' : '0',
        pagamento_debito_ativo: document.getElementById('cfg-pag-debito')?.checked ? '1' : '0',
        pagamento_dinheiro_ativo: document.getElementById('cfg-pag-dinheiro')?.checked ? '1' : '0',
        taxa_credito: document.getElementById('cfg-taxa-cred')?.value || '2.5',
        taxa_debito: document.getElementById('cfg-taxa-deb')?.value || '1.5',
        taxa_pix: document.getElementById('cfg-taxa-pix')?.value || '0',
        pix_chave: document.getElementById('cfg-pix-chave')?.value.trim() || '',
        pix_beneficiario: document.getElementById('cfg-pix-benef')?.value.trim() || '',
        pix_cidade: document.getElementById('cfg-pix-cidade')?.value.trim() || '',
      }, 'cfg-pag-status');
    }

    function salvarCfgImpressora() {
      _salvarCfg({
        impressora_ativa: document.getElementById('cfg-imp-ativa')?.checked ? 'true' : 'false',
        impressora_ip: document.getElementById('cfg-imp-ip')?.value.trim() || '',
        impressora_porta: document.getElementById('cfg-imp-porta')?.value || '9100',
        impressora_largura: document.getElementById('cfg-imp-largura')?.value || '42',
        impressora_copias: document.getElementById('cfg-imp-copias')?.value || '1',
        impressora_cozinha: document.getElementById('cfg-imp-cozinha')?.value || '0',
      }, 'cfg-imp-status');
    }

    function salvarCfgFidelidade() {
      _salvarCfg({
        fidelidade_ativa: document.getElementById('cfg-fid-ativa')?.checked ? '1' : '0',
        pontos_por_real: document.getElementById('cfg-fid-pts-real')?.value || '1',
        real_por_ponto: document.getElementById('cfg-fid-real-pts')?.value || '0.05',
        validade_dias: document.getElementById('cfg-fid-val-dias')?.value || '365',
        pontos_minimo_resgate: document.getElementById('cfg-fid-min-resgate')?.value || '100',
        pontos_max_desc_pct: document.getElementById('cfg-fid-max-desc')?.value || '20',
      }, 'cfg-fid-status');
    }

    function salvarCfgIntegracoes() {
      _salvarCfg({
        n8n_webhook_base: document.getElementById('cfg-n8n-url')?.value.trim() || '',
        n8n_whatsapp: document.getElementById('cfg-n8n-whatsapp')?.value.trim() || '',
        webhook_novo_pedido: document.getElementById('cfg-wh-pedido')?.checked ? '1' : '0',
        webhook_status: document.getElementById('cfg-wh-status')?.checked ? '1' : '0',
        webhook_estoque: document.getElementById('cfg-wh-estoque')?.checked ? '1' : '0',
      }, 'cfg-int-status');
    }

    function salvarCfgAlertas() {
      _salvarCfg({
        alerta_estoque_zap: document.getElementById('cfg-alerta-zap-ativo')?.checked ? '1' : '0',
        alerta_estoque_dias: document.getElementById('cfg-alerta-est-dias')?.value || '3',
        alerta_validade_dias: document.getElementById('cfg-alerta-validade-dias')?.value || '7',
        alerta_email: document.getElementById('cfg-alerta-email')?.value.trim() || '',
        alerta_email_diario: document.getElementById('cfg-email-diario')?.checked ? '1' : '0',
        alerta_email_semanal: document.getElementById('cfg-email-semanal')?.checked ? '1' : '0',
        alerta_pedido_min: document.getElementById('cfg-alerta-pedido-min')?.value || '30',
        alerta_caixa_max: document.getElementById('cfg-alerta-caixa-max')?.value || '500',
      }, 'cfg-alertas-status');
    }

    // Simular fidelidade
    function atualizarSimFidelidade() {
      const pts = parseFloat(document.getElementById('cfg-fid-pts-real')?.value || 1);
      const val = parseFloat(document.getElementById('cfg-fid-real-pts')?.value || 0.05);
      const ganho = (25 * pts).toFixed(0);
      const emReais = (ganho * val).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL'
      });
      document.getElementById('cfg-fid-sim-pts').textContent = ganho + ' pontos';
      document.getElementById('cfg-fid-sim-val').textContent = emReais;
    }
    ['cfg-fid-pts-real', 'cfg-fid-real-pts'].forEach(id =>
      document.getElementById(id)?.addEventListener('input', atualizarSimFidelidade)
    );

    // ── Estoque sub-menu e iframe ────────────────────────────────────────
    let _estFrameReady = false;
    let _estPendingTab = null;

    document.getElementById('estoque-frame').addEventListener('load', () => {
      _estFrameReady = true;
      if (_estPendingTab) {
        _doEstTab(_estPendingTab);
        _estPendingTab = null;
      }
    });

    function toggleEstoqueMenu() {
      const btn = document.getElementById('nav-estoque-btn');
      const menu = document.getElementById('estoque-submenu');
      const isOpen = menu.classList.contains('open');
      if (isOpen) {
        menu.classList.remove('open');
        btn.classList.remove('open', 'active');
      } else {
        menu.classList.add('open');
        btn.classList.add('open', 'active');
        // Abrir e já activar Insumos por padrão
        estTab('insumos');
      }
    }

    function estTab(tab) {
      // Verificar permissão para sub-item de estoque
      if (!hasPerm('estoque.' + tab)) {
        toast('Sem permissão para esta seção do estoque', 'err');
        return;
      }
      // Garantir que o painel estoque está activo
      switchTab('estoque');
      // Marcar sub-item activo
      document.querySelectorAll('#estoque-submenu .nav-sub').forEach(el =>
        el.classList.toggle('active', el.dataset.est === tab)
      );
      // Abrir sub-menu se fechado
      document.getElementById('estoque-submenu').classList.add('open');
      document.getElementById('nav-estoque-btn').classList.add('open', 'active');
      if (_estFrameReady) {
        _doEstTab(tab);
      } else {
        _estPendingTab = tab;
      }
    }

    function _doEstTab(tab) {
      try {
        const frame = document.getElementById('estoque-frame');
        if (frame?.contentWindow?.switchTab) {
          frame.contentWindow.switchTab(tab);
        }
      } catch (e) {
        console.warn('estoque iframe:', e);
      }
    }

    // Patch no switchTab original para sincronizar o botão de Estoque
    document.addEventListener('DOMContentLoaded', () => {
      const tabs = document.querySelectorAll('.nav-item[data-tab]');
      tabs.forEach(btn => btn.addEventListener('click', () => {
        const estoqueBtn = document.getElementById('nav-estoque-btn');
        if (btn.dataset.tab !== 'estoque') {
          estoqueBtn?.classList.remove('active');
          document.querySelectorAll('#estoque-submenu .nav-sub').forEach(el => el.classList.remove('active'));
        }
      }));
    });

    // ── Session timeout warning — avisa 2 min antes ──────────────────────
    (function() {
      const TIMEOUT = 1800 * 1000; // 30 min em ms
      const WARN_BEFORE = 120 * 1000; // avisa 2 min antes
      let warnTimer, logoutTimer;

      function resetTimers() {
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);
        warnTimer = setTimeout(() => {
          toast('⏱ Sessão expira em 2 minutos por inatividade', 'err');
        }, TIMEOUT - WARN_BEFORE);
        logoutTimer = setTimeout(() => {
          window.location.reload();
        }, TIMEOUT);
      }

      ['click', 'keydown', 'mousemove', 'touchstart'].forEach(e =>
        document.addEventListener(e, resetTimers, {
          passive: true
        }));

      resetTimers();
    })();

    // ── Boot: aplicar permissões depois de tudo definido ─────────────────
    aplicarPermissoes();
  </script>
<?php endif; ?>
</body>

</html>
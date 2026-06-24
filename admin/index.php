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
    } catch (Throwable) {}
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
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;--card2:#22253a;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;--acc-gl:rgba(255,85,0,.12);
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;--purple:#8b5cf6;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;--text4:#4b5563;
  --sidebar-w:220px;
}
html,body{height:100%;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── LOGIN ──────────────────────────────────────────────────────────── */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse at 20% 40%,rgba(255,85,0,.08) 0%,transparent 60%)}
.login-box{width:400px;background:var(--surf);border:1px solid var(--border2);border-radius:20px;padding:44px;display:flex;flex-direction:column;gap:28px;box-shadow:0 24px 80px rgba(0,0,0,.6)}
.login-logo{text-align:center}
.login-logo h1{font-size:26px;font-weight:900;color:var(--acc)}
.login-logo p{color:var(--text2);font-size:14px;margin-top:6px}
.field{display:flex;flex-direction:column;gap:7px}
.field label{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.field input,.field select,.field textarea{padding:13px 16px;background:var(--card);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:inherit;font-size:14px;outline:none;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--acc)}
.field textarea{resize:vertical;min-height:70px}
.field select{cursor:pointer}
.field input[type=checkbox]{width:18px;height:18px;cursor:pointer}
.err-msg{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.25);padding:11px 14px;border-radius:10px;font-size:13px}
.btn-login{padding:14px;background:var(--acc);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s}
.btn-login:hover{background:var(--acc-l);transform:translateY(-1px)}

/* ── LAYOUT ─────────────────────────────────────────────────────────── */
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr;height:100vh;overflow:hidden}

/* ── SIDEBAR ────────────────────────────────────────────────────────── */
.sidebar{background:var(--surf);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.sb-brand{padding:20px 18px 16px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-brand h2{font-size:15px;font-weight:900;color:var(--acc)}
.sb-brand p{font-size:11px;color:var(--text3);margin-top:2px}
.sb-nav{flex:1;overflow-y:auto;padding:10px 10px}
.sb-nav::-webkit-scrollbar{width:0}
.sb-section{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);padding:14px 8px 6px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;cursor:pointer;transition:all .15s;font-size:13px;font-weight:500;color:var(--text2);border:none;background:transparent;width:100%;font-family:inherit;text-align:left}
.nav-item:hover{background:var(--card);color:var(--text)}
.nav-item.active{background:var(--acc-gl);color:var(--acc);font-weight:600}
.nav-item .nav-icon{font-size:16px;width:20px;text-align:center;flex-shrink:0}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:999px;min-width:18px;text-align:center}
.sb-links{padding:10px;border-top:1px solid var(--border);flex-shrink:0}
.sb-link{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:8px;color:var(--text3);text-decoration:none;font-size:12px;font-weight:500;transition:all .15s}
.sb-link:hover{background:var(--card);color:var(--text2)}
.sb-user{padding:12px;border-top:1px solid var(--border);flex-shrink:0}
.sb-user-box{display:flex;align-items:center;gap:10px;padding:10px;background:var(--card);border-radius:10px}
.sb-avatar{width:34px;height:34px;border-radius:50%;background:var(--acc-gl);border:2px solid var(--acc);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--acc);flex-shrink:0}
.sb-user-info{flex:1;min-width:0}
.sb-user-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-role{font-size:11px;color:var(--text3)}
.sb-logout{color:var(--text3);text-decoration:none;font-size:12px;padding:4px}
.sb-logout:hover{color:var(--red)}

/* ── MAIN ────────────────────────────────────────────────────────────── */
.main{display:flex;flex-direction:column;overflow:hidden}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:0 24px;height:54px;background:var(--surf);border-bottom:1px solid var(--border);flex-shrink:0}
.topbar-title{font-size:16px;font-weight:700}
.topbar-right{display:flex;align-items:center;gap:12px}
.topbar-clock{font-size:14px;font-weight:600;color:var(--text2)}
.pulse-dot{display:inline-block;width:7px;height:7px;background:var(--green);border-radius:50%;animation:pulse 2s infinite;margin-right:4px}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.content{flex:1;overflow:hidden}
.panel{display:none;height:100%;overflow-y:auto;padding:24px}
.panel.active{display:block}

/* ── COMPONENTS ──────────────────────────────────────────────────────── */
/* Cards */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.kpi-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;inset:0;opacity:.04;background:var(--c,var(--acc))}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:10px}
.kpi-value{font-size:28px;font-weight:900;color:var(--c,var(--acc))}
.kpi-sub{font-size:12px;color:var(--text3);margin-top:4px}

/* Tables */
.data-table-wrap{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.data-table-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.data-table-head h3{font-size:14px;font-weight:700}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{text-align:left;padding:10px 14px;color:var(--text2);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.data-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:rgba(255,255,255,.015)}
.data-table .price{font-weight:700;color:var(--acc-l)}

/* Badges */
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.3px}
.badge-aguardando{background:rgba(245,158,11,.15);color:var(--gold)}
.badge-preparando{background:rgba(59,130,246,.15);color:var(--blue)}
.badge-pronto{background:rgba(34,197,94,.15);color:var(--green)}
.badge-entregue{background:rgba(107,114,128,.15);color:var(--text3)}
.badge-cancelado{background:rgba(239,68,68,.15);color:var(--red)}
.badge-totem{background:rgba(139,92,246,.15);color:var(--purple)}
.badge-caixa{background:rgba(245,158,11,.15);color:var(--gold)}
.badge-admin{background:rgba(255,85,0,.15);color:var(--acc)}
.badge-operador{background:rgba(59,130,246,.15);color:var(--blue)}
.badge-cozinha{background:rgba(34,197,94,.15);color:var(--green)}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.toolbar-search{display:flex;align-items:center;background:var(--card);border:1px solid var(--border2);border-radius:9px;padding:0 12px;gap:8px;height:38px;flex:1;min-width:180px}
.toolbar-search input{background:transparent;border:none;outline:none;color:var(--text);font-size:13px;font-family:inherit;width:100%}
.toolbar-search input::placeholder{color:var(--text3)}
.toolbar select,.toolbar input[type=date]{background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;padding:8px 12px;outline:none;height:38px;cursor:pointer}
.toolbar select:focus,.toolbar input[type=date]:focus{border-color:var(--acc)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;border:none;height:38px}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{background:var(--acc-l);transform:translateY(-1px)}
.btn-secondary{background:var(--card);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text);border-color:var(--text3)}
.btn-danger{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-sm{padding:5px 12px;font-size:12px;height:30px}
.btn-icon{width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px}

/* Toggle */
.toggle-sw{position:relative;width:40px;height:22px;cursor:pointer;flex-shrink:0}
.toggle-sw input{display:none}
.toggle-track{position:absolute;inset:0;background:var(--card2);border-radius:999px;transition:background .2s}
.toggle-track::after{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:3px;left:3px;transition:transform .2s;box-shadow:0 1px 4px rgba(0,0,0,.4)}
input:checked+.toggle-track{background:var(--green)}
input:checked+.toggle-track::after{transform:translateX(18px)}

/* Grid 2col */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px}

/* Section card */
.section-card{background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:16px;overflow:hidden}
.section-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.section-head h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text2)}
.section-body{padding:16px 18px}

/* Chart */
.chart-wrap{position:relative;height:180px}

/* Pagination */
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;border-top:1px solid var(--border);justify-content:center}
.page-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:inherit;font-size:13px;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center}
.page-btn:hover{background:var(--card2);color:var(--text)}
.page-btn.active{background:var(--acc);color:#fff;border-color:var(--acc)}

/* Modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(4px)}
.overlay.open{display:flex}
.modal{background:var(--surf);border:1px solid var(--border2);border-radius:18px;padding:32px;width:500px;max-width:95vw;max-height:90vh;overflow-y:auto;display:flex;flex-direction:column;gap:20px;box-shadow:0 32px 120px rgba(0,0,0,.8)}
.modal h3{font-size:18px;font-weight:800}
.form-row{display:flex;gap:12px}
.form-row .field{flex:1}
.form-check{display:flex;align-items:center;gap:10px;cursor:pointer}
.form-check label{font-size:14px;cursor:pointer}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:4px}

/* Order detail modal */
.detail-items table{width:100%;border-collapse:collapse;font-size:13px}
.detail-items th{text-align:left;padding:7px 10px;border-bottom:1px solid var(--border);font-size:11px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.detail-items td{padding:8px 10px;border-bottom:1px solid var(--border)}
.detail-items tr:last-child td{border-bottom:none}
.detail-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.detail-row:last-child{border-bottom:none}

/* Toast */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;background:var(--card2);border:1px solid var(--border2);border-radius:10px;font-size:13px;font-weight:500;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}

/* Bar chart CSS */
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;font-size:12px}
.bar-label{width:90px;color:var(--text2);text-align:right;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-track{flex:1;height:8px;background:var(--card2);border-radius:4px;overflow:hidden}
.bar-fill{height:100%;border-radius:4px;transition:width .4s ease}
.bar-val{width:70px;color:var(--text);font-weight:600}

/* Insight strip */
.insight-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}
.insight-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:5px;position:relative;overflow:hidden}
.insight-card::before{content:'';position:absolute;top:-28px;right:-28px;width:70px;height:70px;border-radius:50%;background:var(--ic,var(--acc));opacity:.06;pointer-events:none}
.insight-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3)}
.insight-val{font-size:24px;font-weight:900;line-height:1.1;font-variant-numeric:tabular-nums}
.insight-sub{font-size:12px;color:var(--text4)}
.insight-ibar{height:5px;background:var(--card2);border-radius:3px;overflow:hidden;margin-top:4px}
.insight-ibar-fill{height:100%;border-radius:3px}
.idelta{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-top:5px}
.idelta-up{background:rgba(34,197,94,.12);color:var(--green)}
.idelta-dn{background:rgba(239,68,68,.12);color:var(--red)}

/* Heatmap */
.hm-wrap{overflow-x:auto;padding:12px 16px}
.hm-table{border-collapse:collapse;font-size:11px;width:100%}
.hm-table th{padding:2px 4px;color:var(--text3);font-weight:600;text-align:center;white-space:nowrap}
.hm-table td{padding:2px}
.hm-cell{width:100%;height:24px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:transparent;cursor:default;min-width:24px}
.hm-cell:hover{color:#fff!important}
.hm-day{color:var(--text2);font-weight:600;white-space:nowrap;padding-right:8px!important;text-align:right}
.hm-legend{display:flex;align-items:center;gap:5px;margin-top:8px;font-size:11px;color:var(--text3)}
.hm-swatch{width:18px;height:10px;border-radius:2px}

/* Previsao estoque */
.days-badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.days-ok{background:rgba(34,197,94,.12);color:var(--green)}
.days-warn{background:rgba(245,158,11,.12);color:var(--gold)}
.days-crit{background:rgba(239,68,68,.12);color:var(--red)}

/* Status flow */
.status-flow{display:flex;gap:4px;margin-top:12px}
.flow-step{flex:1;padding:8px 6px;text-align:center;border-radius:8px;font-size:11px;font-weight:700;border:2px solid transparent;cursor:pointer;transition:all .15s}
.flow-step.done{opacity:.4}
.flow-step.current{border-color:currentColor}

/* Audit log */
.audit-row{display:flex;gap:12px;padding:10px 14px;border-bottom:1px solid var(--border);font-size:12px;align-items:flex-start}
.audit-row:last-child{border-bottom:none}
.audit-time{color:var(--text3);white-space:nowrap;flex-shrink:0;width:120px}
.audit-user{color:var(--text2);white-space:nowrap;flex-shrink:0;width:120px;overflow:hidden;text-overflow:ellipsis}
.audit-acao{flex:1}
.audit-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;margin-right:6px;text-transform:uppercase}

/* Relatórios — Fases 2 e 3 */
.rel-cfg-input{background:var(--card2);border:1px solid var(--border2,#2a2d3e);border-radius:7px;color:var(--text);font-family:inherit;font-size:12px;padding:6px 10px;outline:none;width:140px}
.rel-cfg-input:focus{border-color:var(--acc)}
.rel-meta-bar-wrap{height:10px;background:var(--card2);border-radius:5px;overflow:hidden;margin:6px 0}
.rel-meta-bar-fill{height:100%;border-radius:5px;transition:width .6s ease}
.rel-meta-label{font-size:12px;color:var(--text2);display:flex;justify-content:space-between;align-items:center}
.rel-meta-proj{font-size:11px;color:var(--text3);margin-top:2px}
.rel-cross-card{background:var(--card2);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:4px}
.rel-cross-tag{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;background:rgba(255,255,255,.06);padding:3px 8px;border-radius:5px}
.boston-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.boston-quad{border-radius:12px;padding:14px;border:1px solid}
.boston-quad.estrela{background:rgba(251,191,36,.06);border-color:rgba(251,191,36,.2)}
.boston-quad.vaca{background:rgba(34,197,94,.06);border-color:rgba(34,197,94,.2)}
.boston-quad.interrogacao{background:rgba(59,130,246,.06);border-color:rgba(59,130,246,.2)}
.boston-quad.abacaxi{background:rgba(107,114,128,.06);border-color:rgba(107,114,128,.18)}
.boston-quad-title{font-size:12px;font-weight:700;margin-bottom:8px}
.boston-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:3px 8px;border-radius:5px;background:rgba(255,255,255,.06);margin:2px}
.boston-tip{font-size:10px;color:var(--text3);margin-top:6px;font-style:italic}
.rel-whatif-slider{display:flex;flex-direction:column;gap:8px;background:var(--card2);border-radius:12px;padding:14px}
.rel-whatif-lbl{font-size:12px;font-weight:600;color:var(--text2)}
.rel-whatif-result{font-size:20px;font-weight:900;margin-top:4px}
.rel-whatif-range{font-size:11px;color:var(--text3)}
input[type=range].rel-slider{width:100%;accent-color:var(--acc);cursor:pointer}
.rel-custo-table{width:100%;border-collapse:collapse;font-size:12px}
.rel-custo-table th{text-align:left;padding:8px 12px;color:var(--text3);font-size:10px;font-weight:700;text-transform:uppercase;border-bottom:1px solid var(--border)}
.rel-custo-table td{padding:8px 12px;border-bottom:1px solid var(--border)}
.rel-custo-table tr:last-child td{border-bottom:none}
.rel-custo-rec{background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.2);border-radius:8px;padding:10px 14px;font-size:12px;color:#86efac;margin-top:8px}
/* ── Turno · Waterfall · Clientes · Recordes ─────────────────────────── */
.rel-turno-card{background:var(--card2);border-radius:10px;padding:14px}
.rel-turno-icon{font-size:22px;margin-bottom:4px}
.rel-turno-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text3)}
.rel-turno-sub{font-size:9px;color:var(--text3)}
.rel-turno-fat{font-size:17px;font-weight:900;margin:5px 0}
.rel-turno-info{font-size:10px;color:var(--text3)}
.rel-turno-bar{height:4px;background:var(--card);border-radius:2px;margin-top:8px;overflow:hidden}
.rel-turno-bar-fill{height:100%;border-radius:2px}
.wf-row{display:flex;align-items:center;gap:14px;padding:8px 0}
.wf-row+.wf-row{border-top:1px solid var(--border)}
.wf-label{width:170px;font-size:12px;font-weight:600;flex-shrink:0}
.wf-bar-wrap{flex:1;height:8px;background:var(--card2);border-radius:4px;overflow:hidden}
.wf-bar-fill{height:100%;border-radius:4px;transition:width .5s ease}
.wf-val{width:130px;text-align:right;font-size:13px;font-weight:700;flex-shrink:0}
.wf-total .wf-label{color:var(--green);font-size:13px;font-weight:800}
.wf-total .wf-val{font-size:15px}
.rel-cli-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)}
.rel-cli-row:last-child{border-bottom:none}
.rel-cli-medal{font-size:16px;flex-shrink:0}
.rel-cli-nome{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rel-cli-sub{font-size:10px;color:var(--text3)}
.rel-cli-val{font-size:12px;font-weight:700;color:var(--green);text-align:right;flex-shrink:0}
.rel-cli-pts{font-size:10px;color:var(--text3);text-align:right}
.rel-rec-card{display:flex;align-items:center;gap:10px;background:var(--card2);border-radius:10px;padding:12px}
.rel-rec-icon{font-size:26px;flex-shrink:0}
.rel-rec-lbl{font-size:10px;color:var(--text3);font-weight:700;text-transform:uppercase}
.rel-rec-val{font-size:18px;font-weight:900;color:var(--gold)}
.rel-rec-date{font-size:11px;color:var(--text3)}
.btn-sm{font-size:11px;padding:5px 10px}

/* ══ PAINEL ESTRATÉGIAS — Design v2 ══════════════════════════════════ */
/* Header */
.est-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.est-title{font-size:22px;font-weight:900;color:var(--text);letter-spacing:-.3px}
.est-subtitle{font-size:13px;color:var(--text3);margin-top:3px}
.est-section-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--text3);margin:24px 0 12px;display:flex;align-items:center;gap:8px}
.est-section-label::after{content:'';flex:1;height:1px;background:var(--border)}

/* KPI Row */
.est-kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:4px}
.est-kpi-skeleton{background:var(--card);border:1px solid var(--border);border-radius:16px;height:120px;animation:skeleton-pulse 1.5s ease-in-out infinite}
@keyframes skeleton-pulse{0%,100%{opacity:.6}50%{opacity:1}}
.est-kpi-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;position:relative;overflow:hidden;transition:transform .2s,border-color .2s}
.est-kpi-card:hover{transform:translateY(-2px);border-color:var(--border2)}
.est-kpi-card::before{content:'';position:absolute;top:-30px;right:-30px;width:90px;height:90px;border-radius:50%;background:var(--ek,var(--acc));opacity:.06;pointer-events:none}
.est-kpi-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--ek,var(--acc));opacity:.4}
.est-kpi-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.est-kpi-emoji{font-size:18px}
.est-kpi-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;background:var(--card2)}
.est-kpi-chip.up{background:rgba(34,197,94,.12);color:#4ade80}
.est-kpi-chip.dn{background:rgba(239,68,68,.12);color:#f87171}
.est-kpi-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text3)}
.est-kpi-val{font-size:28px;font-weight:900;color:var(--ek,var(--acc));line-height:1;margin:5px 0 4px}
.est-kpi-sub{font-size:11px;color:var(--text3)}

/* Strategy 2x2 grid */
.est-strat-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:4px}
@media(max-width:900px){.est-strat-grid{grid-template-columns:1fr}}
.est-strat-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px;display:flex;flex-direction:column;gap:0;overflow:hidden;position:relative;transition:border-color .2s}
.est-strat-card:hover{border-color:var(--est-accent,var(--acc))}
.est-strat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--est-accent,var(--acc));opacity:.6}
.est-strat-card-header{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.est-strat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.est-strat-title{font-size:14px;font-weight:800;line-height:1.2}
.est-strat-sub{font-size:11px;color:var(--text3);margin-top:2px}
.est-strat-badge-count{margin-left:auto;font-size:14px;font-weight:900;padding:4px 10px;border-radius:8px;background:rgba(255,255,255,.06);color:var(--text2);flex-shrink:0}
.est-strat-body{flex:1;display:flex;flex-direction:column;gap:0;margin-bottom:16px}
.est-strat-cta{display:inline-flex;align-items:center;font-size:12px;font-weight:600;color:var(--est-accent,var(--acc));text-decoration:none;background:transparent;border:none;cursor:pointer;padding:0;opacity:.8;transition:opacity .15s;margin-top:auto}
.est-strat-cta:hover{opacity:1}

/* Combo rows */
.est-combo-row{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.est-combo-row:last-child{border-bottom:none}
.est-combo-chip{font-size:11px;font-weight:600;background:rgba(255,255,255,.06);padding:3px 8px;border-radius:5px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px;flex-shrink:0}
.est-combo-plus{font-size:11px;color:var(--text3);flex-shrink:0}
.est-combo-gain{margin-left:auto;font-size:11px;font-weight:800;color:#4ade80;flex-shrink:0;white-space:nowrap}
.est-combo-pct{font-size:10px;color:var(--text3);flex-shrink:0}

/* Horário bar chart */
.est-hora-chart{display:flex;align-items:flex-end;gap:4px;height:72px;padding-bottom:4px}
.est-hora-bar-wrap{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1}
.est-hora-bar{border-radius:3px 3px 0 0;transition:height .4s ease;min-height:3px;width:100%}
.est-hora-lbl{font-size:8px;color:var(--text3);white-space:nowrap}

/* Fidelização */
.est-fidel-body{display:flex;align-items:center;gap:16px}
.est-fidel-ring{flex-shrink:0}
.est-fidel-ring svg{display:block}
.est-fidel-stats{display:flex;flex-direction:column;gap:8px;flex:1}
.est-fidel-stat{display:flex;justify-content:space-between;align-items:center;font-size:12px}
.est-fidel-stat-lbl{color:var(--text3)}
.est-fidel-stat-val{font-weight:700;color:var(--text)}

/* Produtos parados */
.est-prod-row{padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.est-prod-row:last-child{border-bottom:none}
.est-prod-name{font-size:12px;font-weight:600;margin-bottom:5px;color:var(--text2)}
.est-prod-bar-wrap{height:5px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden}
.est-prod-bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#ef4444,#f59e0b)}

/* Insights */
.est-insights-wrap{display:flex;flex-direction:column;gap:0;background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:24px}
.est-insights-loading{padding:24px;color:var(--text3);font-size:13px;text-align:center}
.est-insight-item{display:flex;align-items:flex-start;gap:14px;padding:16px 18px;border-bottom:1px solid var(--border);transition:background .15s;position:relative;overflow:hidden}
.est-insight-item:last-child{border-bottom:none}
.est-insight-item:hover{background:rgba(255,255,255,.02)}
.est-insight-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--ins-col,var(--text3))}
.est-insight-left{display:flex;flex-direction:column;align-items:center;gap:6px;flex-shrink:0;padding-top:2px}
.est-insight-dot{width:10px;height:10px;border-radius:50%;background:var(--ins-col)}
.est-insight-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px;line-height:1.3}
.est-insight-text{font-size:12px;color:var(--text3);line-height:1.6}

/* Badges compartilhados */
.est-badge{display:inline-flex;align-items:center;font-size:10px;font-weight:700;padding:2px 9px;border-radius:999px;flex-shrink:0}
.est-bg-green{background:rgba(34,197,94,.12);color:#4ade80}
.est-bg-red{background:rgba(239,68,68,.12);color:#f87171}
.est-bg-gold{background:rgba(245,158,11,.12);color:#fbbf24}
.est-bg-blue{background:rgba(59,130,246,.12);color:#60a5fa}
.est-bg-gray{background:rgba(107,114,128,.12);color:#9ca3af}

/* Action buttons row */
.est-actions-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.est-action-big{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 16px;cursor:pointer;text-decoration:none;transition:all .2s}
.est-action-big:hover{background:var(--card2);border-color:var(--border2);transform:translateY(-1px)}
.est-action-big-icon{font-size:24px;flex-shrink:0}
.est-action-big-title{font-size:13px;font-weight:700;color:var(--text)}
.est-action-big-sub{font-size:10px;color:var(--text3);margin-top:2px}
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
  <aside class="sidebar">
    <div class="sb-brand">
      <h2>Café Comunhão</h2>
      <p>Sistema de Gestão</p>
    </div>

    <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <button class="nav-item active" data-tab="dashboard"><span class="nav-icon">📊</span>Dashboard</button>
      <button class="nav-item" data-tab="pedidos"><span class="nav-icon">📋</span>Pedidos<span class="nav-badge" id="badge-ativos" style="display:none">0</span></button>

      <div class="sb-section">Cardápio</div>
      <button class="nav-item" data-tab="produtos"><span class="nav-icon">🍽️</span>Produtos</button>
      <button class="nav-item" data-tab="categorias"><span class="nav-icon">🗂️</span>Categorias</button>

      <div class="sb-section">Gestão</div>
      <button class="nav-item" data-tab="relatorios"><span class="nav-icon">📈</span>Relatórios</button>
      <button class="nav-item" data-tab="estrategias"><span class="nav-icon">🎯</span>Estratégias</button>
      <?php if ($isAdmin): ?>
      <button class="nav-item" data-tab="usuarios"><span class="nav-icon">👥</span>Usuários</button>
      <button class="nav-item" data-tab="auditoria"><span class="nav-icon">🔒</span>Auditoria</button>
      <button class="nav-item" data-tab="configuracoes"><span class="nav-icon">⚙️</span>Configurações</button>
      <button class="nav-item" data-tab="backup"><span class="nav-icon">💾</span>Backup</button>
      <?php endif; ?>
    </nav>

    <div class="sb-links">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);padding:8px 10px 4px">Operação</div>
      <a href="../" class="sb-link" target="_blank">🖥️ Totem</a>
      <a href="../kds/" class="sb-link" target="_blank">👨‍🍳 KDS Cozinha</a>
      <a href="../caixa/" class="sb-link" target="_blank">💰 Caixa</a>
      <a href="caixa/turno.php" class="sb-link" target="_blank">🔓 Abrir/Fechar Caixa</a>
      <a href="../garcom/" class="sb-link" target="_blank">👨‍💼 App Garçom</a>
      <a href="../garcom/comanda.php" class="sb-link" target="_blank">📝 Comanda Digital</a>
      <a href="../painel/" class="sb-link" target="_blank">📺 Painel TV</a>
      <a href="mesas/" class="sb-link" target="_blank">🪑 Mesas</a>
      <a href="delivery/" class="sb-link" target="_blank">🛵 Delivery</a>

      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);padding:8px 10px 4px;margin-top:4px">Financeiro</div>
      <a href="dashboard/" class="sb-link" target="_blank">📊 Dashboard</a>
      <a href="dre/" class="sb-link" target="_blank">📑 DRE / Despesas</a>
      <a href="relatorios/" class="sb-link" target="_blank">📋 Relatórios</a>
      <a href="relatorios/cardapio.php" class="sb-link" target="_blank">🍽️ Análise Cardápio</a>

      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);padding:8px 10px 4px;margin-top:4px">Clientes</div>
      <a href="clientes/" class="sb-link" target="_blank">⭐ Fidelidade</a>
      <a href="../status/fidelidade.php" class="sb-link" target="_blank">🏆 Pontos no Totem</a>
      <a href="estoque/" class="sb-link" target="_blank">📦 Estoque</a>

      <?php if ($isAdmin): ?>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);padding:8px 10px 4px;margin-top:4px">Configurações</div>
      <a href="2fa/setup.php" class="sb-link" target="_blank">🔐 Segurança 2FA</a>
      <a href="email/" class="sb-link" target="_blank">📧 E-mail Semanal</a>
      <a href="#" class="sb-link" id="btn-backup" onclick="fazBackup(event)">💾 Backup BD</a>
      <?php endif; ?>
    </div>

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
      <div class="topbar-title" id="topbar-title">Dashboard</div>
      <div class="topbar-right">
        <span><span class="pulse-dot"></span><span id="topbar-status" style="font-size:12px;color:var(--text3)">Ao vivo</span></span>
        <span class="topbar-clock" id="topbar-clock"></span>
      </div>
    </div>

    <div class="content">

      <!-- ─── DASHBOARD ──────────────────────────────────────────────── -->
      <div class="panel active" id="panel-dashboard">
        <div class="kpi-grid" id="dash-kpis"></div>

        <!-- Insight strip: margem, projeção, comparativo -->
        <div class="insight-strip" id="dash-insights" style="display:none"></div>

        <div class="grid-2">
          <div class="section-card">
            <div class="section-head"><h3>Pedidos ativos</h3><span id="dash-ativos-time" style="font-size:11px;color:var(--text3)"></span></div>
            <div class="section-body" id="dash-ativos"></div>
          </div>
          <div class="section-card">
            <div class="section-head"><h3>Faturamento — últimos 7 dias</h3></div>
            <div class="section-body"><div class="chart-wrap"><canvas id="chart-7d"></canvas></div></div>
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
          <div class="section-head"><h3>Últimos pedidos</h3><button class="btn btn-secondary btn-sm" onclick="switchTab('pedidos')">Ver todos →</button></div>
          <div class="data-table-wrap" style="border:none;border-radius:0">
            <table class="data-table" id="dash-recent">
              <thead><tr><th>#</th><th>Hora</th><th>Tipo</th><th>Pagamento</th><th>Total</th><th>Status</th><th>Origem</th></tr></thead>
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
            <thead><tr><th>#</th><th>Data/Hora</th><th>Tipo</th><th>Itens</th><th>Pagamento</th><th>Total</th><th>Status</th><th>Origem</th><th></th></tr></thead>
            <tbody id="ped-tbody"></tbody>
          </table>
          <div class="pagination" id="ped-pagination"></div>
        </div>
      </div>

      <!-- ─── PRODUTOS ───────────────────────────────────────────────── -->
      <div class="panel" id="panel-produtos">
        <div class="toolbar">
          <div class="toolbar-search"><span>🔍</span><input type="text" id="pr-busca" placeholder="Buscar produto..."></div>
          <select id="pr-cat"><option value="">Todas categorias</option></select>
          <button class="btn btn-primary" id="btn-new-prod">+ Novo produto</button>
        </div>
        <div class="data-table-wrap">
          <div class="data-table-head">
            <h3 id="pr-count">Produtos</h3>
            <div style="display:flex;gap:8px">
              <button class="btn btn-secondary btn-sm" id="btn-bulk-on">Ativar selecionados</button>
              <button class="btn btn-secondary btn-sm" id="btn-bulk-off">Desativar selecionados</button>
            </div>
          </div>
          <table class="data-table">
            <thead><tr>
              <th style="width:36px"><input type="checkbox" id="select-all" style="width:16px;height:16px;cursor:pointer"></th>
              <th>Produto</th><th>Categoria</th><th>Preço</th><th>Disponível</th><th>Destaque</th><th></th>
            </tr></thead>
            <tbody id="pr-tbody"></tbody>
          </table>
        </div>
      </div>

      <!-- ─── CATEGORIAS ─────────────────────────────────────────────── -->
      <div class="panel" id="panel-categorias">
        <div class="toolbar">
          <button class="btn btn-primary" id="btn-new-cat">+ Nova categoria</button>
        </div>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead><tr><th>Ícone</th><th>Nome</th><th>Ordem</th><th>Produtos</th><th>Ativos</th><th></th></tr></thead>
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
          <div class="section-head"><h3>Custo real por forma de pagamento</h3></div>
          <div class="section-body" id="rel-custos"></div>
        </div>

        <!-- ── Matriz Boston ─────────────────────────────────────────────── -->
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head"><h3>Matriz estratégica de produtos</h3></div>
          <div id="rel-boston" style="padding:16px"></div>
        </div>

        <div class="grid-2">
          <div class="section-card">
            <div class="section-head"><h3>Por forma de pagamento</h3></div>
            <div class="section-body" id="rel-pag"></div>
          </div>
          <div class="section-card">
            <div class="section-head"><h3>Por origem</h3></div>
            <div class="section-body" id="rel-origem"></div>
          </div>
        </div>
        <div class="grid-2">
          <div class="section-card">
            <div class="section-head"><h3>Faturamento por dia</h3></div>
            <div class="section-body"><div class="chart-wrap"><canvas id="chart-rel-dias"></canvas></div></div>
          </div>
          <div class="section-card">
            <div class="section-head"><h3>Hora pico</h3></div>
            <div class="section-body"><div class="chart-wrap"><canvas id="chart-rel-hora"></canvas></div></div>
          </div>
        </div>

        <!-- ── Projeção de faturamento (15 dias) ─────────────────────────── -->
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head">
            <h3>Projeção de faturamento</h3>
            <span style="font-size:11px;color:var(--text3)">Linha tracejada = projeção 15 dias</span>
          </div>
          <div class="section-body"><div class="chart-wrap" style="height:220px"><canvas id="chart-rel-proj"></canvas></div></div>
        </div>

        <!-- ── Ranking produtos + Cross-sell ──────────────────────────────── -->
        <div class="grid-2">
          <div class="section-card">
            <div class="section-head"><h3>Top 15 produtos</h3></div>
            <div class="section-body" id="rel-top"></div>
          </div>
          <div class="section-card">
            <div class="section-head"><h3>Vendidos juntos (cross-sell)</h3></div>
            <div id="rel-crosssell" style="padding:12px 16px;display:flex;flex-direction:column;gap:10px"></div>
          </div>
        </div>

        <!-- ── Simulador E se? ───────────────────────────────────────────── -->
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head"><h3>Simulador "E se?" — impacto estimado</h3></div>
          <div id="rel-whatif" style="padding:16px;display:flex;flex-direction:column;gap:20px"></div>
        </div>

        <!-- ── Análise por turno ─────────────────────────────────────────── -->
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head"><h3>Análise por turno</h3><span style="font-size:11px;color:var(--text3)">Manhã · Almoço · Tarde · Noite</span></div>
          <div id="rel-turnos" style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px"></div>
        </div>

        <!-- ── Waterfall de receita ───────────────────────────────────────── -->
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head"><h3>Waterfall de receita</h3><span style="font-size:11px;color:var(--text3)">Bruto → Cancelamentos → Taxas → Líquido</span></div>
          <div id="rel-waterfall" style="padding:16px 20px"></div>
        </div>

        <!-- ── Top clientes + Recordes ───────────────────────────────────── -->
        <div class="grid-2">
          <div class="section-card">
            <div class="section-head"><h3>Top 5 clientes do período</h3></div>
            <div id="rel-top-clientes"></div>
          </div>
          <div class="section-card">
            <div class="section-head"><h3>Recordes históricos</h3></div>
            <div id="rel-records" style="padding:14px 16px;display:flex;flex-direction:column;gap:10px"></div>
          </div>
        </div>

        <!-- ── Lista de pedidos ──────────────────────────────────────────── -->
        <div class="section-card">
          <div class="section-head"><h3 id="rel-lista-title">Lista de pedidos do período</h3></div>
          <div class="data-table-wrap" style="border:none;border-radius:0">
            <table class="data-table">
              <thead><tr><th>#</th><th>Data/Hora</th><th>Consumo</th><th>Pagamento</th><th>Itens</th><th>Total</th><th>Status</th><th>Origem</th></tr></thead>
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

      <?php if ($isAdmin): ?>
      <!-- ─── USUÁRIOS ────────────────────────────────────────────────── -->
      <div class="panel" id="panel-usuarios">
        <div class="toolbar">
          <button class="btn btn-primary" id="btn-new-user">+ Novo usuário</button>
        </div>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Status</th><th>Último login</th><th>Logins</th><th></th></tr></thead>
            <tbody id="user-tbody"></tbody>
          </table>
        </div>
      </div>

      <!-- ─── AUDITORIA ──────────────────────────────────────────────── -->
      <div class="panel" id="panel-auditoria">
        <div class="toolbar">
          <div class="toolbar-search"><span>🔍</span><input type="text" id="aud-busca" placeholder="Filtrar por descrição..."></div>
          <select id="aud-acao"><option value="">Todas ações</option></select>
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
      <!-- ─── CONFIGURAÇÕES ──────────────────────────────────────────── -->
      <div class="panel" id="panel-configuracoes">
        <div class="data-table-wrap">
          <div class="data-table-head"><h3>Configurações da Loja</h3></div>
          <div style="padding:24px;display:flex;flex-direction:column;gap:20px;max-width:640px">
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
          </div>
        </div>
      </div>

      <!-- ─── BACKUP ────────────────────────────────────────────────────── -->
      <div class="panel" id="panel-backup">
        <div class="data-table-wrap">
          <div class="data-table-head"><h3>Backup e Exportação</h3></div>
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
          <select id="fp-cat" required><option value="">Selecione</option></select>
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
const BASE  = window.location.pathname.replace(/\/[^/]*$/, '/') + 'api/';
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

// ── Utils ────────────────────────────────────────────────────────────
const fmt   = v => 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
const fmtDt = iso => { try { return new Date(iso).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}); } catch{return iso;} };
const fmtDate = iso => { try { return new Date(iso).toLocaleDateString('pt-BR'); } catch{return iso;} };
const esc   = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function toast(msg, type='ok') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = 'show '+type;
  clearTimeout(el._t); el._t = setTimeout(()=>el.className='', 3200);
}

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function api(path, opts={}) {
  const headers = {'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN,...(opts.headers||{})};
  const res = await fetch(BASE+path, {...opts, headers});
  if (res.status === 401) { window.location.reload(); return {}; }
  return res.json();
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) o.classList.remove('open'); }));

// ── Clock ────────────────────────────────────────────────────────────
function tickClock() {
  document.getElementById('topbar-clock').textContent =
    new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
tickClock(); setInterval(tickClock, 1000);

// ── Tab switching ────────────────────────────────────────────────────
const TITLES = { dashboard:'Dashboard', pedidos:'Pedidos', produtos:'Produtos',
  categorias:'Categorias', relatorios:'Relatórios', usuarios:'Usuários', auditoria:'Auditoria',
  configuracoes:'Configurações', backup:'Backup & Exportação' };

let currentTab = 'dashboard';
function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.tab===tab));
  document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.id==='panel-'+tab));
  document.getElementById('topbar-title').textContent = TITLES[tab] || tab;
  loadTab(tab);
}

document.querySelectorAll('.nav-item[data-tab]').forEach(btn =>
  btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

function loadTab(tab) {
  switch(tab) {
    case 'dashboard':     loadDashboard(); break;
    case 'pedidos':       loadPedidos(); break;
    case 'produtos':      loadProdutos(); break;
    case 'categorias':    loadCategorias(); break;
    case 'relatorios':    initRelatorios(); break;
    case 'estrategias':   loadEstrategias(); break;
    case 'usuarios':      loadUsuarios(); break;
    case 'auditoria':     loadAuditoria(); break;
    case 'configuracoes': loadConfiguracoes(); break;
    case 'backup':        initBackup(); break;
  }
}

// ─────────────────────────────────────────────────────────────────────
// ── DASHBOARD ─────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
let chart7d = null;
async function loadDashboard() {
  const today = new Date().toISOString().slice(0,10);
  const [rel, ped] = await Promise.all([
    api('relatorios.php?data_ini='+today+'&data_fim='+today),
    api('pedidos.php?status=ativos'),
  ]);

  // KPIs
  if (rel.success) {
    const d = rel.data;
    document.getElementById('dash-kpis').innerHTML = [
      {label:'Faturamento hoje', value:fmt(d.faturamento), color:'var(--green)', sub:'Pedidos pagos'},
      {label:'Pedidos hoje',     value:d.pedidos_total,    color:'var(--blue)',  sub:'Confirmados'},
      {label:'Ticket médio',     value:fmt(d.ticket_medio),color:'var(--acc)',   sub:'Por pedido'},
      {label:'Itens vendidos',   value:d.itens_total,      color:'var(--gold)',  sub:'Unidades'},
      {label:'Cancelados',       value:d.cancelados,       color:'var(--red)',   sub:'No dia'},
    ].map(k =>
      '<div class="kpi-card" style="--c:'+k.color+'">'+
        '<div class="kpi-label">'+k.label+'</div>'+
        '<div class="kpi-value">'+k.value+'</div>'+
        '<div class="kpi-sub">'+k.sub+'</div>'+
      '</div>'
    ).join('');

    // 7-day chart — load last 7 days
    load7dChart();

    // Insights (margem, projeção, heatmap, previsão estoque)
    loadDashboardInsights();

    // Recent orders
    const rows = (d.pedidos_lista||[]).slice(0,10);
    document.getElementById('dash-recent-body').innerHTML = rows.map(p =>
      '<tr>'+
        '<td><strong>#'+esc(p.numero)+'</strong></td>'+
        '<td style="color:var(--text2)">'+fmtDt(p.criado_em)+'</td>'+
        '<td>'+(p.tipo_consumo==='local'?'Aqui':'Viagem')+'</td>'+
        '<td>'+esc(p.forma_pagamento)+'</td>'+
        '<td class="price">'+fmt(p.total)+'</td>'+
        '<td><span class="badge badge-'+p.status+'">'+p.status+'</span></td>'+
        '<td><span class="badge badge-'+(p.origem||'totem')+'">'+(p.origem||'totem')+'</span></td>'+
      '</tr>'
    ).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:30px">Sem pedidos hoje</td></tr>';
  }

  // Active orders
  if (ped.success) {
    const ativos = ped.data || [];
    const badge = document.getElementById('badge-ativos');
    if (ativos.length > 0) { badge.textContent = ativos.length; badge.style.display=''; }
    else badge.style.display = 'none';

    const grps = { aguardando:[], preparando:[], pronto:[] };
    ativos.forEach(p => { if(grps[p.status]) grps[p.status].push(p); });

    const STATUS_CLR = {aguardando:'var(--gold)',preparando:'var(--blue)',pronto:'var(--green)'};
    document.getElementById('dash-ativos').innerHTML =
      Object.entries(grps).map(([st, list]) =>
        '<div style="margin-bottom:12px">'+
          '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:'+STATUS_CLR[st]+';margin-bottom:6px">'+
            st+' ('+list.length+')</div>'+
          (list.length ? list.map(p =>
            '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 10px;background:var(--card2);border-radius:8px;margin-bottom:4px;font-size:13px">'+
              '<strong>#'+esc(p.numero_pedido)+'</strong>'+
              '<span style="color:var(--text2)">'+p.itens.map(i=>i.qtd+'x '+i.nome).join(', ').slice(0,40)+'</span>'+
              '<button class="btn btn-secondary btn-sm" onclick="openPedidoDetail('+p.id+')">Ver</button>'+
            '</div>'
          ).join('') : '<div style="color:var(--text3);font-size:12px;padding:6px">Nenhum</div>')
        +'</div>'
      ).join('');

    document.getElementById('dash-ativos-time').textContent =
      'Atualizado '+new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
}

async function load7dChart() {
  const fim = new Date().toISOString().slice(0,10);
  const ini = new Date(Date.now()-6*864e5).toISOString().slice(0,10);
  const res = await api('relatorios.php?data_ini='+ini+'&data_fim='+fim);
  if (!res.success) return;

  const dias = res.data.por_dia || [];
  const labels = dias.map(d => new Date(d.dia+'T12:00').toLocaleDateString('pt-BR',{weekday:'short',day:'2-digit'}));
  const values = dias.map(d => parseFloat(d.total));

  const ctx = document.getElementById('chart-7d');
  if (!ctx) return;
  if (chart7d) chart7d.destroy();
  chart7d = new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: [{ data:values, backgroundColor:'rgba(255,85,0,0.7)', borderRadius:6, borderSkipped:false }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
      scales:{x:{grid:{display:false},ticks:{color:'#6b7280',font:{size:11}}},
              y:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#6b7280',font:{size:11},callback:v=>'R$'+v.toFixed(0)}}} }
  });
}

// ─────────────────────────────────────────────────────────────────────
// ── DASHBOARD INSIGHTS ────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
async function loadDashboardInsights() {
  const res = await api('dashboard.php');
  if (!res || !res.success) return;

  const {
    fat_hoje, ped_hoje, fat_ontem, ped_ontem,
    custo_hoje, fat_mes, dias_passados, dias_mes, fat_mes_ant,
    heatmap, previsao,
  } = res;

  // ── Helpers ──
  const brl = v => 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
  function deltaHtml(novo, ant) {
    if (ant <= 0) return '';
    const d = Math.round(((novo - ant) / ant) * 1000) / 10;
    const cls = d >= 0 ? 'idelta-up' : 'idelta-dn';
    return '<span class="idelta '+cls+'">'+(d>=0?'↑':'↓')+' '+Math.abs(d)+'% vs ontem</span>';
  }

  // ── INSIGHT STRIP ─────────────────────────────────────────────────
  const margemVal = Math.max(0, fat_hoje - custo_hoje);
  const margemPct = fat_hoje > 0 ? Math.round((margemVal / fat_hoje) * 1000) / 10 : 0;
  const diasP     = Math.max(1, dias_passados);
  const projecao  = (fat_mes / diasP) * dias_mes;
  const varMes    = fat_mes_ant > 0 ? Math.round(((projecao - fat_mes_ant) / fat_mes_ant) * 1000) / 10 : null;
  const media     = fat_mes / diasP;
  const varHoje   = media > 0 ? Math.round(((fat_hoje / media) - 1) * 1000) / 10 : null;
  const progPct   = dias_mes > 0 ? Math.round((diasP / dias_mes) * 100) : 0;

  const strip = document.getElementById('dash-insights');
  strip.innerHTML =
    // Margem bruta
    '<div class="insight-card" style="--ic:var(--green)">'+
      '<div class="insight-lbl">📊 Margem bruta estimada — hoje</div>'+
      '<div class="insight-val" style="color:var(--green)">'+brl(margemVal)+'</div>'+
      '<div class="insight-sub">Fat. '+brl(fat_hoje)+' − Custo '+brl(custo_hoje)+'</div>'+
      '<div class="insight-ibar"><div class="insight-ibar-fill" style="width:'+Math.min(100,margemPct)+'%;background:var(--green)"></div></div>'+
      '<span style="font-size:12px;color:var(--green);font-weight:700">'+margemPct+'% de margem</span>'+
      (custo_hoje<=0?'<span style="font-size:11px;color:var(--text3)">* Cadastre custo nos insumos</span>':'')+
    '</div>'+
    // Projeção do mês
    '<div class="insight-card" style="--ic:var(--blue)">'+
      '<div class="insight-lbl">📈 Projeção — mês atual</div>'+
      '<div class="insight-val" style="color:var(--blue)">'+brl(projecao)+'</div>'+
      '<div class="insight-sub">Realizado: '+brl(fat_mes)+' ('+diasP+'/'+dias_mes+' dias)</div>'+
      '<div class="insight-ibar"><div class="insight-ibar-fill" style="width:'+progPct+'%;background:var(--blue)"></div></div>'+
      (varMes!==null?'<span class="idelta '+(varMes>=0?'idelta-up':'idelta-dn')+'">'+(varMes>=0?'↑':'↓')+' '+Math.abs(varMes)+'% vs mês anterior</span>':'')+
    '</div>'+
    // Hoje vs média
    '<div class="insight-card" style="--ic:var(--gold)">'+
      '<div class="insight-lbl">📅 Média diária — este mês</div>'+
      '<div class="insight-val" style="color:var(--gold)">'+brl(media)+'</div>'+
      '<div class="insight-sub">Base: '+diasP+' dias no mês</div>'+
      (varHoje!==null?'<span class="idelta '+(varHoje>=0?'idelta-up':'idelta-dn')+'">'+(varHoje>=0?'↑':'↓')+' '+Math.abs(varHoje)+'% — hoje vs média</span>':'')+
      '<div style="font-size:12px;color:var(--text3);margin-top:6px">Fechamento estimado: <strong style="color:var(--text)">'+brl(projecao)+'</strong></div>'+
    '</div>';
  strip.style.display = 'grid';

  // ── HEATMAP ───────────────────────────────────────────────────────
  const hm = {}; let hmMax = 1;
  (heatmap||[]).forEach(r => {
    if (!hm[r.dow]) hm[r.dow] = {};
    hm[r.dow][r.hora] = parseInt(r.cnt);
    if (parseInt(r.cnt) > hmMax) hmMax = parseInt(r.cnt);
  });

  const DIAS_PT = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
  const HORAS   = Array.from({length:16}, (_,i)=>i+6); // 6–21
  const ORDEM   = [1,2,3,4,5,6,0]; // Seg→Dom

  if (Object.keys(hm).length > 0) {
    let hmHtml = '<table class="hm-table"><thead><tr><th></th>';
    HORAS.forEach(h => { hmHtml += '<th>'+h+'h</th>'; });
    hmHtml += '</tr></thead><tbody>';
    ORDEM.forEach(dow => {
      hmHtml += '<tr><td class="hm-day">'+DIAS_PT[dow]+'</td>';
      HORAS.forEach(h => {
        const cnt = (hm[dow]||{})[h] || 0;
        const int = cnt / hmMax;
        let bg;
        if (int <= 0) bg = 'rgba(255,255,255,.04)';
        else if (int < 0.25) bg = 'rgba(59,130,246,'+(0.15+int*0.8).toFixed(2)+')';
        else if (int < 0.6)  bg = 'rgba(245,158,11,'+(0.3+int*0.7).toFixed(2)+')';
        else                  bg = 'rgba(255,85,0,'+(0.5+int*0.5).toFixed(2)+')';
        const title = cnt>0 ? cnt+' pedido'+(cnt!=1?'s':'')+' às '+h+'h ('+DIAS_PT[dow]+')' : '';
        hmHtml += '<td title="'+title+'"><div class="hm-cell" style="background:'+bg+';color:'+(cnt>0?'rgba(255,255,255,.7)':'transparent')+'">'+
          (cnt>0?cnt:'')+'</div></td>';
      });
      hmHtml += '</tr>';
    });
    hmHtml += '</tbody></table>';
    hmHtml += '<div class="hm-legend"><span>Menos</span><div style="display:flex;gap:3px">';
    ['rgba(255,255,255,.04)','rgba(59,130,246,.3)','rgba(59,130,246,.6)','rgba(245,158,11,.5)','rgba(245,158,11,.8)','rgba(255,85,0,.7)','rgba(255,85,0,1)']
      .forEach(c => { hmHtml += '<div class="hm-swatch" style="background:'+c+'"></div>'; });
    hmHtml += '</div><span>Mais</span></div>';
    document.getElementById('dash-heatmap').innerHTML = hmHtml;
    document.getElementById('dash-heatmap-card').style.display = '';
  }

  // ── PREVISÃO DE ESTOQUE ────────────────────────────────────────────
  if (previsao && previsao.length > 0) {
    const tbl = document.getElementById('dash-previsao');
    tbl.innerHTML =
      '<thead><tr>'+
        '<th>Insumo</th>'+
        '<th style="text-align:right">Estoque</th>'+
        '<th style="text-align:right">Consumo/dia</th>'+
        '<th style="text-align:right">Dias restantes</th>'+
        '<th>Status</th>'+
      '</tr></thead><tbody>'+
      previsao.map(p => {
        const dias2 = p.consumo_dia > 0 ? Math.floor(parseFloat(p.estoque_atual) / parseFloat(p.consumo_dia)) : 999;
        const cls  = dias2 <= 3 ? 'days-crit' : (dias2 <= 7 ? 'days-warn' : 'days-ok');
        const ico  = dias2 <= 3 ? '🔴' : (dias2 <= 7 ? '⚠️' : '✅');
        const lbl  = dias2 >= 999 ? 'Estável' : (dias2 <= 3 ? 'Comprar urgente' : (dias2 <= 7 ? 'Comprar em breve' : 'OK por '+dias2+' dias'));
        return '<tr>'+
          '<td style="font-weight:600">'+esc(p.nome)+'</td>'+
          '<td style="text-align:right;color:var(--text3);font-size:12px">'+parseFloat(p.estoque_atual).toFixed(2).replace('.',',')+' '+esc(p.unidade)+'</td>'+
          '<td style="text-align:right;color:var(--text3);font-size:12px">'+parseFloat(p.consumo_dia).toFixed(2).replace('.',',')+'/dia</td>'+
          '<td style="text-align:right;font-weight:700;font-size:15px">'+(dias2>=999?'—':dias2)+'</td>'+
          '<td><span class="days-badge '+cls+'">'+ico+' '+lbl+'</span></td>'+
        '</tr>';
      }).join('')+'</tbody>';
    document.getElementById('dash-previsao-card').style.display = '';
  }
}

// ─────────────────────────────────────────────────────────────────────
// ── PEDIDOS ────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
let pedPage = 1, pedTotal = 0, pedPages = 1, pedTimer = null;
let cancelingId = null;

function startPedTimer() {
  clearInterval(pedTimer);
  pedTimer = setInterval(loadPedidos, 10000);
}

async function loadPedidos(page) {
  if (page) pedPage = page;
  const busca  = document.getElementById('ped-busca').value;
  const status = document.getElementById('ped-status').value;
  const ini    = document.getElementById('ped-ini').value;
  const fim    = document.getElementById('ped-fim').value;
  const origem = document.getElementById('ped-origem').value;

  const params = new URLSearchParams({ status, page: pedPage });
  if (busca)  params.set('busca', busca);
  if (ini)    params.set('data_ini', ini);
  if (fim)    params.set('data_fim', fim);
  if (origem) params.set('origem', origem);

  const res = await api('pedidos.php?' + params);
  if (!res.success) return;

  pedTotal = res.total; pedPages = res.pages; pedPage = res.page;
  document.getElementById('ped-count').textContent = pedTotal + ' pedido(s)';

  const S = {aguardando:'Aguardando',preparando:'Preparando',pronto:'Pronto',entregue:'Entregue',cancelado:'Cancelado'};
  const N = {aguardando:'preparando',preparando:'pronto',pronto:'entregue'};
  const NL = {aguardando:'Iniciar',preparando:'Pronto',pronto:'Entregar'};

  document.getElementById('ped-tbody').innerHTML = res.data.map(p =>
    '<tr>'+
      '<td><strong>#'+esc(p.numero_pedido)+'</strong></td>'+
      '<td style="color:var(--text2);font-size:12px">'+fmtDt(p.criado_em)+'</td>'+
      '<td>'+(p.tipo_consumo==='local'?'Aqui':'Viagem')+'</td>'+
      '<td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+
        (p.itens||[]).map(i=>i.qtd+'x '+i.nome).join(', ')+'</td>'+
      '<td>'+esc(p.forma_pagamento)+'</td>'+
      '<td class="price">'+fmt(p.total)+'</td>'+
      '<td><span class="badge badge-'+p.status+'">'+(S[p.status]||p.status)+'</span></td>'+
      '<td><span class="badge badge-'+(p.origem||'totem')+'">'+(p.origem||'totem')+'</span></td>'+
      '<td style="white-space:nowrap">'+
        (N[p.status]?'<button class="btn btn-secondary btn-sm" style="margin-right:4px" onclick="advancePedido('+p.id+',\''+N[p.status]+'\')">'+NL[p.status]+'</button>':'')+
        (p.status!=='cancelado'&&p.status!=='entregue'?'<button class="btn btn-sm" style="background:var(--card);border:1px solid var(--border2);color:var(--text3);margin-right:4px" onclick="openCancel('+p.id+')">✕</button>':'')+
        '<button class="btn btn-secondary btn-sm" onclick="openPedidoDetail('+p.id+')">Detalhes</button>'+
      '</td>'+
    '</tr>'
  ).join('') || '<tr><td colspan="9" style="text-align:center;color:var(--text3);padding:40px">Nenhum pedido encontrado</td></tr>';

  renderPagination('ped-pagination', pedPage, pedPages, p => loadPedidos(p));
  startPedTimer();
}

async function advancePedido(id, status) {
  const res = await api('pedidos.php', {method:'POST',body:JSON.stringify({id,status})});
  if (res.success) { toast('Status atualizado!'); loadPedidos(); }
  else toast(res.error||'Erro', 'err');
}

function openCancel(id) {
  cancelingId = id;
  document.getElementById('cancel-motivo').value = '';
  openModal('modal-cancel');
}

document.getElementById('btn-confirm-cancel').addEventListener('click', async () => {
  if (!cancelingId) return;
  const motivo = document.getElementById('cancel-motivo').value;
  const res = await api('pedidos.php', {method:'POST',body:JSON.stringify({id:cancelingId,status:'cancelado',motivo})});
  if (res.success) { toast('Pedido cancelado'); closeModal('modal-cancel'); loadPedidos(); cancelingId = null; }
  else toast(res.error||'Erro','err');
});

async function openPedidoDetail(id) {
  const res = await api('pedidos.php?id='+id);
  if (!res.success) { toast('Pedido nao encontrado','err'); return; }
  const p = res.pedido;
  const S = {aguardando:'Aguardando',preparando:'Preparando',pronto:'Pronto',entregue:'Entregue',cancelado:'Cancelado'};

  document.getElementById('modal-ped-title').innerHTML = 'Pedido <span style="color:var(--acc)">#'+esc(p.numero_pedido)+'</span>';
  document.getElementById('modal-ped-body').innerHTML =
    '<div class="detail-row"><span>Status</span><span class="badge badge-'+p.status+'">'+(S[p.status]||p.status)+'</span></div>'+
    '<div class="detail-row"><span>Data/Hora</span><span>'+fmtDt(p.criado_em)+'</span></div>'+
    '<div class="detail-row"><span>Consumo</span><span>'+(p.tipo_consumo==='local'?'Comer aqui':'Para viagem')+'</span></div>'+
    '<div class="detail-row"><span>Pagamento</span><span>'+esc(p.forma_pagamento)+'</span></div>'+
    '<div class="detail-row"><span>Origem</span><span><span class="badge badge-'+(p.origem||'totem')+'">'+(p.origem||'totem')+'</span></span></div>'+
    (p.cpf?'<div class="detail-row"><span>CPF</span><span>'+esc(p.cpf)+'</span></div>':'')+
    (p.operador_nome?'<div class="detail-row"><span>Operador</span><span>'+esc(p.operador_nome)+'</span></div>':'')+
    (p.cancelado_motivo?'<div class="detail-row"><span>Motivo cancel.</span><span style="color:var(--red)">'+esc(p.cancelado_motivo)+'</span></div>':'')+
    '<div class="detail-items" style="margin-top:14px"><table>'+
      '<thead><tr><th>Produto</th><th>Qtd</th><th>Unitário</th><th>Subtotal</th></tr></thead><tbody>'+
      (p.itens||[]).map(i=>'<tr><td>'+esc(i.nome_produto)+'</td><td>'+i.quantidade+'</td><td>'+fmt(i.preco_unitario)+'</td><td class="price">'+fmt(i.subtotal)+'</td></tr>').join('')+
      '</tbody></table></div>'+
    '<div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);padding-top:12px">'+
      '<span style="font-size:13px;color:var(--text2)">Total</span>'+
      '<strong style="font-size:20px;color:var(--acc)">'+fmt(p.total)+'</strong>'+
    '</div>';

  const N={aguardando:'preparando',preparando:'pronto',pronto:'entregue'};
  const NL={aguardando:'Iniciar preparo',preparando:'Marcar pronto',pronto:'Marcar entregue'};
  let actHtml = '<button class="btn btn-secondary" onclick="closeModal(\'modal-pedido\')">Fechar</button>';
  actHtml = '<button class="btn btn-secondary" onclick="imprimirPedido('+p.id+')" title="Imprimir comanda">🖨️ Imprimir</button>' + actHtml;
  if (N[p.status]) actHtml = '<button class="btn btn-primary" onclick="advancePedido('+p.id+',\''+N[p.status]+'\');closeModal(\'modal-pedido\')">'+NL[p.status]+' →</button>' + actHtml;
  if (p.status!=='cancelado'&&p.status!=='entregue')
    actHtml = '<button class="btn btn-danger btn-sm" onclick="openCancel('+p.id+');closeModal(\'modal-pedido\')">Cancelar pedido</button>' + actHtml;
  document.getElementById('modal-ped-actions').innerHTML = actHtml;

  window._pedidoAtual = p;
  openModal('modal-pedido');
}

document.getElementById('ped-refresh').addEventListener('click', () => loadPedidos(1));
['ped-status','ped-ini','ped-fim','ped-origem'].forEach(id =>
  document.getElementById(id).addEventListener('change', () => loadPedidos(1)));
document.getElementById('ped-busca').addEventListener('input',
  debounce(() => loadPedidos(1), 400));

// ─────────────────────────────────────────────────────────────────────
// ── PRODUTOS ──────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
let allProdData = [], catData = [];
let selectedProds = new Set();

async function loadProdutos() {
  const catId = document.getElementById('pr-cat').value;
  const busca = document.getElementById('pr-busca').value;
  const params = new URLSearchParams();
  if (catId) params.set('categoria_id', catId);
  if (busca) params.set('busca', busca);

  const [prods, cats] = await Promise.all([
    api('produtos.php?'+params),
    api('categorias.php'),
  ]);

  if (cats.success) {
    catData = cats.data;
    const sel = document.getElementById('pr-cat');
    const current = sel.value;
    sel.innerHTML = '<option value="">Todas categorias</option>' +
      cats.data.map(c=>'<option value="'+c.id+'">'+ esc(c.nome)+'</option>').join('');
    sel.value = current;

    const fpCat = document.getElementById('fp-cat');
    fpCat.innerHTML = '<option value="">Selecione</option>' +
      cats.data.map(c=>'<option value="'+c.id+'">'+esc(c.icone)+' '+esc(c.nome)+'</option>').join('');
  }

  if (!prods.success) return;
  allProdData = prods.data;
  selectedProds.clear();

  document.getElementById('pr-count').textContent = prods.data.length + ' produto(s)';
  document.getElementById('pr-tbody').innerHTML = prods.data.map(p =>
    '<tr data-id="'+p.id+'">'+
      '<td><input type="checkbox" class="prod-check" data-id="'+p.id+'" style="width:16px;height:16px;cursor:pointer"></td>'+
      '<td>'+
        '<div style="font-weight:600">'+esc(p.nome)+'</div>'+
        (p.descricao?'<div style="font-size:11px;color:var(--text3)">'+esc(p.descricao.slice(0,50))+(p.descricao.length>50?'…':'')+'</div>':'')+
      '</td>'+
      '<td><span style="font-size:12px">'+esc(p.cat_icone||'')+'</span> '+esc(p.categoria)+'</td>'+
      '<td class="price">'+fmt(p.preco)+'</td>'+
      '<td><label class="toggle-sw"><input type="checkbox" '+(p.disponivel?'checked':'')+' onchange="toggleProd('+p.id+',this)"><span class="toggle-track"></span></label></td>'+
      '<td>'+(p.destaque?'<span style="color:var(--gold)">⭐</span>':'—')+'</td>'+
      '<td><button class="btn btn-secondary btn-sm" onclick="openProdModal('+p.id+')">Editar</button></td>'+
    '</tr>'
  ).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:40px">Nenhum produto</td></tr>';
}

async function toggleProd(id, el) {
  const res = await api('produtos.php', {method:'POST',body:JSON.stringify({toggle_disponivel:true,id})});
  if (res.success) toast(res.disponivel?'Produto ativado':'Produto desativado');
  else { el.checked=!el.checked; toast(res.error||'Erro','err'); }
}

document.getElementById('select-all').addEventListener('change', function() {
  document.querySelectorAll('.prod-check').forEach(cb => { cb.checked=this.checked; if(this.checked) selectedProds.add(+cb.dataset.id); else selectedProds.delete(+cb.dataset.id); });
});
document.getElementById('pr-tbody').addEventListener('change', e => {
  const cb = e.target.closest('.prod-check');
  if (!cb) return;
  if (cb.checked) selectedProds.add(+cb.dataset.id); else selectedProds.delete(+cb.dataset.id);
});

async function bulkToggle(disp) {
  if (!selectedProds.size) { toast('Selecione ao menos um produto','err'); return; }
  const res = await api('produtos.php',{method:'POST',body:JSON.stringify({bulk_disponivel:true,ids:[...selectedProds],disponivel:disp})});
  if (res.success) { toast(res.affected+' produto(s) '+(disp?'ativado(s)':'desativado(s)')); loadProdutos(); }
  else toast(res.error||'Erro','err');
}
document.getElementById('btn-bulk-on').addEventListener('click',  () => bulkToggle(true));
document.getElementById('btn-bulk-off').addEventListener('click', () => bulkToggle(false));
document.getElementById('btn-new-prod').addEventListener('click', () => openProdModal());
document.getElementById('pr-busca').addEventListener('input', debounce(loadProdutos, 350));
document.getElementById('pr-cat').addEventListener('change', loadProdutos);

function openProdModal(id) {
  const data = id ? allProdData.find(p=>p.id===id) : null;
  document.getElementById('modal-prod-title').textContent = data ? 'Editar produto' : 'Novo produto';
  document.getElementById('fp-id').value         = data?.id   || '';
  document.getElementById('fp-nome').value       = data?.nome || '';
  document.getElementById('fp-preco').value      = data?.preco || '';
  document.getElementById('fp-cat').value        = data?.categoria_id || '';
  document.getElementById('fp-desc').value       = data?.descricao || '';
  document.getElementById('fp-destaque').checked = data?.destaque || false;
  document.getElementById('fp-ordem').value      = data?.ordem || 99;
  const estoqueAtivo = data?.controlar_estoque || false;
  document.getElementById('fp-estoque-ativo').checked  = estoqueAtivo;
  document.getElementById('fp-estoque-qtd').value      = data?.estoque_qtd ?? 0;
  document.getElementById('fp-estoque-alerta').value   = data?.estoque_alerta ?? 5;
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
    id:                parseInt(document.getElementById('fp-id').value)||undefined,
    nome:              document.getElementById('fp-nome').value,
    preco:             parseFloat(document.getElementById('fp-preco').value),
    categoria_id:      parseInt(document.getElementById('fp-cat').value),
    descricao:         document.getElementById('fp-desc').value,
    destaque:          document.getElementById('fp-destaque').checked,
    ordem:             parseInt(document.getElementById('fp-ordem').value),
    controlar_estoque: document.getElementById('fp-estoque-ativo').checked,
    estoque_qtd:       parseInt(document.getElementById('fp-estoque-qtd').value) || 0,
    estoque_alerta:    parseInt(document.getElementById('fp-estoque-alerta').value) || 5,
    imagem:            document.getElementById('fp-imagem').value || null,
  };
  if (!body.id) delete body.id;
  if (!body.imagem) delete body.imagem;
  const res = await api('produtos.php',{method:'POST',body:JSON.stringify(body)});
  if (res.success) { toast(res.action==='created'?'Produto criado!':'Produto atualizado!'); closeModal('modal-prod'); loadProdutos(); }
  else toast(res.error||'Erro','err');
});

// ── Product image upload ──────────────────────────────────────────────
document.getElementById('fp-img-file').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const statusEl  = document.getElementById('fp-img-status');
  const previewEl = document.getElementById('fp-img-preview');
  const placeholderEl = document.getElementById('fp-img-placeholder');
  statusEl.textContent = 'Enviando...';
  statusEl.style.color = 'var(--text2)';
  const fd = new FormData();
  fd.append('imagem', file);
  fd.append('_csrf', CSRF_TOKEN);
  try {
    const res = await fetch('api/upload.php', { method:'POST', body:fd });
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
    '<tr>'+
      '<td style="font-size:22px;text-align:center">'+esc(c.icone)+'</td>'+
      '<td><strong>'+esc(c.nome)+'</strong></td>'+
      '<td>'+c.ordem+'</td>'+
      '<td>'+c.total_produtos+'</td>'+
      '<td><span style="color:var(--green);font-weight:600">'+c.produtos_ativos+'</span></td>'+
      '<td style="white-space:nowrap">'+
        '<button class="btn btn-secondary btn-sm" style="margin-right:6px" onclick="openCatModal('+c.id+')">Editar</button>'+
        (IS_ADMIN&&c.total_produtos==0?'<button class="btn btn-danger btn-sm" onclick="deleteCategoria('+c.id+')">Excluir</button>':'')+
      '</td>'+
    '</tr>'
  ).join('');
}

function openCatModal(id) {
  const data = id ? allCatData.find(c=>c.id===id) : null;
  document.getElementById('modal-cat-title').textContent = data ? 'Editar categoria' : 'Nova categoria';
  document.getElementById('fc-id').value    = data?.id    || '';
  document.getElementById('fc-nome').value  = data?.nome  || '';
  document.getElementById('fc-icone').value = data?.icone || '';
  document.getElementById('fc-ordem').value = data?.ordem || 99;
  openModal('modal-cat');
}

document.getElementById('btn-new-cat').addEventListener('click', () => openCatModal());

document.getElementById('form-cat').addEventListener('submit', async e => {
  e.preventDefault();
  const body = {
    id:    parseInt(document.getElementById('fc-id').value)||undefined,
    nome:  document.getElementById('fc-nome').value,
    icone: document.getElementById('fc-icone').value,
    ordem: parseInt(document.getElementById('fc-ordem').value),
  };
  if (!body.id) delete body.id;
  const res = await api('categorias.php',{method:'POST',body:JSON.stringify(body)});
  if (res.success) { toast(res.action==='created'?'Categoria criada!':'Categoria atualizada!'); closeModal('modal-cat'); loadCategorias(); }
  else toast(res.error||'Erro','err');
});

async function deleteCategoria(id) {
  if (!confirm('Excluir esta categoria? Essa acao nao pode ser desfeita.')) return;
  const res = await api('categorias.php',{method:'DELETE',body:JSON.stringify({id})});
  if (res.success) { toast('Categoria excluida'); loadCategorias(); }
  else toast(res.error||'Erro','err');
}

// ─────────────────────────────────────────────────────────────────────
// ── RELATÓRIOS ────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
let chartDias = null, chartHora = null, chartProj = null;

function initRelatorios() {
  const today = new Date().toISOString().slice(0,10);
  const ini = new Date(Date.now()-29*864e5).toISOString().slice(0,10);
  document.getElementById('rel-ini').value = ini;
  document.getElementById('rel-fim').value = today;

  // Config metas toggle
  document.getElementById('btn-cfg-metas')?.addEventListener('click', () => {
    const cfg = document.getElementById('rel-metas-cfg');
    if (cfg) cfg.style.display = cfg.style.display==='flex'?'none':'flex';
  });

  // Salvar metas
  document.getElementById('btn-salvar-metas')?.addEventListener('click', async () => {
    const payload = {
      meta_fat_mes:     document.getElementById('cfg-meta-fat')?.value||'0',
      meta_pedidos_mes: document.getElementById('cfg-meta-ped')?.value||'0',
      taxa_credito:     document.getElementById('cfg-taxa-cred')?.value||'2.5',
      taxa_debito:      document.getElementById('cfg-taxa-deb')?.value||'1.5',
    };
    const res = await api('configuracoes.php', {method:'POST', body:JSON.stringify(payload)});
    if (res?.success) { toast('Configuracoes salvas!'); loadRelatorios(); }
    else toast('Erro ao salvar','err');
  });

  loadRelatorios();
}

async function loadRelatorios() {
  const ini = document.getElementById('rel-ini').value;
  const fim = document.getElementById('rel-fim').value;
  const res = await api('relatorios.php?action=analytics&data_ini='+ini+'&data_fim='+fim);
  if (!res?.success) { toast((res&&res.error)||'Erro ao carregar relatorio','err'); return; }
  const d = res;

  // ── KPIs ─────────────────────────────────────────────────────────────
  const kpi = d.kpi||{};
  document.getElementById('rel-kpis').innerHTML = [
    {label:'Faturamento',   value:fmt(kpi.faturamento),   color:'var(--green)', sub:'Sem cancelados'},
    {label:'Pedidos',       value:kpi.pedidos||0,          color:'var(--blue)',  sub:'Confirmados'},
    {label:'Ticket medio',  value:fmt(kpi.ticket_medio),  color:'var(--acc)',   sub:'Por pedido'},
    {label:'Itens vendidos',value:kpi.itens_total||0,      color:'var(--gold)',  sub:'Unidades'},
    {label:'Cancelados',    value:kpi.cancelados||0,       color:'var(--red)',   sub:'No periodo'},
  ].map(k=>
    '<div class="kpi-card" style="--c:'+k.color+'">'+
      '<div class="kpi-label">'+k.label+'</div>'+
      '<div class="kpi-value">'+k.value+'</div>'+
      '<div class="kpi-sub">'+k.sub+'</div>'+
    '</div>'
  ).join('');

  // ── Metas mensais ─────────────────────────────────────────────────────
  renderMetas(d.metas, d.taxas);

  // ── Custo por pagamento ───────────────────────────────────────────────
  renderCustosPagamento(d.custos_pagamento, d.total_custo_periodo, d.taxas);

  // ── Matriz Boston ─────────────────────────────────────────────────────
  renderBostonMatrix(d.produtos||[]);

  // ── Pagamento bars ────────────────────────────────────────────────────
  const maxPag = Math.max(...(d.por_pagamento||[]).map(r=>parseFloat(r.total)),1);
  const PAG_LABEL = {pix:'PIX',credito:'Credito',debito:'Debito',dinheiro:'Dinheiro'};
  const PAG_COLOR = {pix:'var(--blue)',credito:'var(--green)',debito:'var(--gold)',dinheiro:'var(--acc)'};
  document.getElementById('rel-pag').innerHTML = (d.por_pagamento||[]).map(r=>
    '<div class="bar-row">'+
      '<div class="bar-label">'+(PAG_LABEL[r.forma_pagamento]||r.forma_pagamento)+'</div>'+
      '<div class="bar-track"><div class="bar-fill" style="width:'+((parseFloat(r.total)/maxPag)*100).toFixed(1)+'%;background:'+(PAG_COLOR[r.forma_pagamento]||'var(--acc)')+'"></div></div>'+
      '<div class="bar-val">'+fmt(r.total)+'<span style="color:var(--text3);font-size:10px"> ('+r.qtd+')</span></div>'+
    '</div>'
  ).join('') || '<div style="color:var(--text3);font-size:13px">Sem dados</div>';

  // ── Origem ────────────────────────────────────────────────────────────
  const maxOri = Math.max(...(d.por_origem||[]).map(r=>parseFloat(r.total)),1);
  const ORI_COLOR = {totem:'var(--purple)',caixa:'var(--gold)',admin:'var(--acc)'};
  document.getElementById('rel-origem').innerHTML = (d.por_origem||[]).map(r=>
    '<div class="bar-row">'+
      '<div class="bar-label">'+esc(r.origem)+'</div>'+
      '<div class="bar-track"><div class="bar-fill" style="width:'+((parseFloat(r.total)/maxOri)*100).toFixed(1)+'%;background:'+(ORI_COLOR[r.origem]||'var(--text3)')+'"></div></div>'+
      '<div class="bar-val">'+fmt(r.total)+'<span style="color:var(--text3);font-size:10px"> ('+r.qtd+')</span></div>'+
    '</div>'
  ).join('') || '<div style="color:var(--text3);font-size:13px">Sem dados</div>';

  // ── Chart faturamento por dia ─────────────────────────────────────────
  const dias = d.por_dia||[];
  const ctxD = document.getElementById('chart-rel-dias');
  if (ctxD) {
    if (chartDias) chartDias.destroy();
    chartDias = new Chart(ctxD, {
      type:'bar',
      data:{labels:dias.map(x=>new Date(x.dia+'T12:00').toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'})),
            datasets:[{data:dias.map(x=>parseFloat(x.total)),backgroundColor:'rgba(255,85,0,0.7)',borderRadius:5,borderSkipped:false}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
               scales:{x:{grid:{display:false},ticks:{color:'#6b7280',font:{size:10}}},
                       y:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#6b7280',font:{size:10},callback:v=>'R$'+v.toFixed(0)}}}}
    });
  }

  // ── Chart hora pico ───────────────────────────────────────────────────
  const horas = new Array(24).fill(0);
  (d.hora_pico||[]).forEach(h => { horas[parseInt(h.hora)] = parseInt(h.qtd); });
  const ctxH = document.getElementById('chart-rel-hora');
  if (ctxH) {
    if (chartHora) chartHora.destroy();
    chartHora = new Chart(ctxH, {
      type:'bar',
      data:{labels:Array.from({length:24},(_,i)=>i+'h'),
            datasets:[{data:horas,backgroundColor:'rgba(59,130,246,0.6)',borderRadius:4,borderSkipped:false}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
               scales:{x:{grid:{display:false},ticks:{color:'#6b7280',font:{size:9}}},
                       y:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#6b7280',font:{size:10},stepSize:1}}}}
    });
  }

  // ── Projeção de faturamento ───────────────────────────────────────────
  renderProjecao(d.por_dia||[], d.hora_pico||[]);

  // ── Top produtos ──────────────────────────────────────────────────────
  const prods = d.produtos||[];
  const maxTop = Math.max(...prods.map(r=>parseInt(r.qtd)),1);
  document.getElementById('rel-top').innerHTML = prods.slice(0,15).map((r,i)=>
    '<div class="bar-row">'+
      '<div style="width:20px;text-align:center;color:var(--text3);font-size:11px;font-weight:700;flex-shrink:0">'+(i+1)+'</div>'+
      '<div class="bar-label" style="width:200px;text-align:left">'+esc(r.nome_produto)+'</div>'+
      '<div class="bar-track"><div class="bar-fill" style="width:'+((parseInt(r.qtd)/maxTop)*100).toFixed(1)+'%;background:var(--acc)"></div></div>'+
      '<div class="bar-val">'+r.qtd+'x <span style="color:var(--text3)"> '+fmt(r.receita||r.total||0)+'</span></div>'+
    '</div>'
  ).join('') || '<div style="color:var(--text3);font-size:13px">Sem dados</div>';

  // ── Cross-sell ────────────────────────────────────────────────────────
  const cs = d.crosssell||[];
  const crossEl = document.getElementById('rel-crosssell');
  if (crossEl) {
    crossEl.innerHTML = cs.length
      ? cs.map(c=>{
          const precoCombo = Math.round((parseFloat(c.preco_combo)||0)*0.9);
          return '<div class="rel-cross-card">'+
            '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">'+
              '<span class="rel-cross-tag">📦 '+esc(c.prod_a)+'</span>'+
              '<span style="color:var(--text3);font-size:11px">+</span>'+
              '<span class="rel-cross-tag">📦 '+esc(c.prod_b)+'</span>'+
            '</div>'+
            '<div style="font-size:11px;color:var(--text2);margin-top:4px">'+
              'Pedidos juntos: <strong style="color:var(--text)">'+c.ocorrencias+'x</strong>'+
              (precoCombo>0?' · Combo sugerido: <strong style="color:var(--green)">R$ '+precoCombo+'</strong>':'')+
            '</div>'+
          '</div>';
        }).join('')
      : '<div style="color:var(--text3);font-size:12px">Sem pares detectados no periodo</div>';
  }

  // ── Simulador E se? ───────────────────────────────────────────────────
  renderWhatIf(d.kpi||{}, d.produtos||[], d.crosssell||[]);
  renderTurnos(d.por_turno||[]);
  renderWaterfall(d.kpi||{}, d.total_custo_periodo||0, d.kpi?.cancelados||0);
  renderTopClientes(d.top_clientes||[]);
  renderRecords(d.records||{});

  // ── Lista pedidos ─────────────────────────────────────────────────────
  const S={aguardando:'Aguardando',preparando:'Preparando',pronto:'Pronto',entregue:'Entregue',cancelado:'Cancelado'};
  document.getElementById('rel-lista').innerHTML = (d.pedidos_lista||[]).map(p=>
    '<tr>'+
      '<td><strong>#'+esc(p.numero)+'</strong></td>'+
      '<td style="font-size:12px;color:var(--text2)">'+fmtDt(p.criado_em)+'</td>'+
      '<td>'+(p.tipo_consumo==='local'?'Aqui':'Viagem')+'</td>'+
      '<td>'+esc(PAG_LABEL[p.forma_pagamento]||p.forma_pagamento)+'</td>'+
      '<td>'+p.total_itens+'</td>'+
      '<td class="price">'+fmt(p.total)+'</td>'+
      '<td><span class="badge badge-'+p.status+'">'+(S[p.status]||p.status)+'</span></td>'+
      '<td><span class="badge badge-'+(p.origem||'totem')+'">'+(p.origem||'totem')+'</span></td>'+
    '</tr>'
  ).join('') || '<tr><td colspan="8" style="text-align:center;color:var(--text3);padding:30px">Sem pedidos no periodo</td></tr>';
}

// ── Helpers de relatório ──────────────────────────────────────────────
function renderMetas(metas, taxas) {
  if (!metas) return;

  const inFat  = document.getElementById('cfg-meta-fat');
  const inPed  = document.getElementById('cfg-meta-ped');
  const inCred = document.getElementById('cfg-taxa-cred');
  const inDeb  = document.getElementById('cfg-taxa-deb');
  if (inFat  && metas.fat_meta)  inFat.value  = metas.fat_meta;
  if (inPed  && metas.ped_meta)  inPed.value  = metas.ped_meta;
  if (inCred && taxas?.credito)  inCred.value = taxas.credito;
  if (inDeb  && taxas?.debito)   inDeb.value  = taxas.debito;

  const body = document.getElementById('rel-metas-body');
  if (!body) return;

  const bars = [];

  if (metas.fat_meta > 0) {
    const pct = Math.min(100, Math.round((metas.fat_atual / metas.fat_meta)*100));
    const cor = pct >= 100 ? 'var(--green)' : pct >= 70 ? 'var(--gold)' : 'var(--acc)';
    const proj = metas.fat_projecao;
    const projPct = Math.round((proj/metas.fat_meta)*100);
    bars.push(
      '<div>'+
        '<div class="rel-meta-label">'+
          '<span style="font-weight:700">💰 Faturamento do mes</span>'+
          '<span style="color:'+cor+';font-weight:800">'+pct+'% — '+fmt(metas.fat_atual)+'</span>'+
        '</div>'+
        '<div class="rel-meta-bar-wrap"><div class="rel-meta-bar-fill" style="width:'+pct+'%;background:'+cor+'"></div></div>'+
        '<div class="rel-meta-proj">'+
          'Meta: '+fmt(metas.fat_meta)+' &nbsp;·&nbsp; '+
          'Projecao: <strong style="color:'+(projPct>=100?'var(--green)':projPct>=80?'var(--gold)':'var(--red)')+'">'+fmt(proj)+'</strong>'+
          ' ('+projPct+'% da meta) &nbsp;·&nbsp; Dia '+metas.dia_atual+' de '+metas.dias_mes+
        '</div>'+
      '</div>'
    );
  } else {
    bars.push('<div style="color:var(--text3);font-size:12px">Meta de faturamento nao configurada. Clique em ⚙ Configurar.</div>');
  }

  if (metas.ped_meta > 0) {
    const pct = Math.min(100, Math.round((metas.ped_atual / metas.ped_meta)*100));
    const cor = pct >= 100 ? 'var(--green)' : pct >= 70 ? 'var(--gold)' : 'var(--blue)';
    bars.push(
      '<div>'+
        '<div class="rel-meta-label">'+
          '<span style="font-weight:700">📦 Pedidos do mes</span>'+
          '<span style="color:'+cor+';font-weight:800">'+pct+'% — '+metas.ped_atual+' pedidos</span>'+
        '</div>'+
        '<div class="rel-meta-bar-wrap"><div class="rel-meta-bar-fill" style="width:'+pct+'%;background:'+cor+'"></div></div>'+
        '<div class="rel-meta-proj">Meta: '+metas.ped_meta+' &nbsp;·&nbsp; Projecao: <strong>'+metas.ped_projecao+'</strong> pedidos</div>'+
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
  const PAG = {pix:'PIX',credito:'Credito',debito:'Debito',dinheiro:'Dinheiro'};
  const totalRec = custos.reduce((s,r)=>s+parseFloat(r.total),0);
  const econPix  = custos.filter(r=>r.forma_pagamento!=='pix'&&r.forma_pagamento!=='dinheiro').reduce((s,r)=>s+(r.custo||0),0);

  let html = '<table class="rel-custo-table">'+
    '<thead><tr><th>Metodo</th><th>Receita</th><th>Taxa</th><th>Custo R$</th><th>Liquido</th></tr></thead><tbody>';
  custos.forEach(r => {
    const nome    = PAG[r.forma_pagamento]||r.forma_pagamento;
    const taxaStr = r.taxa>0 ? r.taxa.toFixed(1)+'%' : '<span style="color:var(--green)">Gratis</span>';
    const custoStr= r.custo>0 ? '<span style="color:var(--red)">-'+fmt(r.custo)+'</span>' : '<span style="color:var(--green)">R$0,00</span>';
    html += '<tr><td style="font-weight:600">'+nome+'</td><td>'+fmt(r.total)+'</td><td>'+taxaStr+'</td><td>'+custoStr+'</td><td style="font-weight:700">'+fmt(r.liquido??r.total)+'</td></tr>';
  });
  html += '<tr style="font-weight:700;border-top:2px solid var(--border2,#2a2d3e)"><td>TOTAL</td><td>'+fmt(totalRec)+'</td><td></td><td style="color:var(--red)">-'+fmt(totalCusto||0)+'</td><td style="color:var(--green)">'+fmt(totalRec-(totalCusto||0))+'</td></tr>';
  html += '</tbody></table>';
  if (econPix > 0.01)
    html += '<div class="rel-custo-rec">💡 Se todos os pagamentos fossem em PIX, voce economizaria <strong>R$ '+econPix.toFixed(2).replace('.',',')+'</strong> em taxas neste periodo.</div>';
  el.innerHTML = html;
}

function renderBostonMatrix(produtos) {
  const el = document.getElementById('rel-boston');
  if (!el) return;
  if (!produtos?.length) { el.innerHTML='<div style="color:var(--text3);font-size:12px">Sem dados de produtos</div>'; return; }

  const qtds = produtos.map(p=>+p.qtd).sort((a,b)=>a-b);
  const recs  = produtos.map(p=>+p.receita).sort((a,b)=>a-b);
  const medQtd = qtds[Math.floor(qtds.length/2)];
  const medRec  = recs[Math.floor(recs.length/2)];

  const grupos = {estrela:[],vaca:[],interrogacao:[],abacaxi:[]};
  produtos.forEach(p => {
    const q=+p.qtd>medQtd, r=+p.receita>medRec;
    const key = q&&r?'estrela':!q&&r?'vaca':q&&!r?'interrogacao':'abacaxi';
    grupos[key].push(p);
  });

  const defs = {
    estrela:     {icon:'⭐',label:'Estrelas',       cor:'#fbbf24',tip:'Priorize no totem, destaque visual, nunca tire do cardapio.'},
    vaca:        {icon:'🐄',label:'Vacas Leiteiras', cor:'#22c55e',tip:'Alta margem — expanda com combos e promocoes.'},
    interrogacao:{icon:'❓',label:'Interrogacoes',   cor:'#3b82f6',tip:'Volume alto mas receita baixa — considere ajuste de preco.'},
    abacaxi:     {icon:'🍌',label:'Abacaxis',        cor:'#6b7280',tip:'Baixo volume e receita — remova ou transforme em combo.'},
  };

  const chips = grp => grp.slice(0,6).map(p=>'<span class="boston-chip">'+esc(p.nome_produto)+' <span style="opacity:.5">'+p.qtd+'x</span></span>').join('');
  const keys = ['estrela','vaca','interrogacao','abacaxi'];

  el.innerHTML = '<div class="boston-grid">'+keys.map(k=>{
    const def=defs[k]; const grp=grupos[k];
    return '<div class="boston-quad '+k+'">'+
      '<div class="boston-quad-title" style="color:'+def.cor+'">'+def.icon+' '+def.label+' ('+grp.length+')</div>'+
      '<div>'+(chips(grp)||'<span style="color:var(--text3);font-size:11px">Nenhum produto</span>')+'</div>'+
      '<div class="boston-tip">'+def.tip+'</div>'+
    '</div>';
  }).join('')+'</div>';
}

function renderProjecao(porDia, horaPico) {
  const ctxP = document.getElementById('chart-rel-proj');
  if (!ctxP || !porDia?.length) return;

  // Média por dia da semana a partir dos dados do período
  const dowSum = new Array(7).fill(0), dowCnt = new Array(7).fill(0);
  porDia.forEach(d => {
    const dow = new Date(d.dia+'T12:00').getDay();
    dowSum[dow] += parseFloat(d.total)||0;
    dowCnt[dow]++;
  });
  const dowAvg = dowSum.map((s,i)=>dowCnt[i]>0?s/dowCnt[i]:0);

  // Tendência linear (regressão simples)
  const vals = porDia.map(d=>parseFloat(d.total)||0);
  const n = vals.length;
  let sumX=0,sumY=0,sumXY=0,sumX2=0;
  vals.forEach((v,i)=>{sumX+=i;sumY+=v;sumXY+=i*v;sumX2+=i*i;});
  const slope = n>1?(n*sumXY-sumX*sumY)/(n*sumX2-sumX*sumX):0;
  const intercept = n>0?(sumY-slope*sumX)/n:0;

  const labelsReal = porDia.map(d=>new Date(d.dia+'T12:00').toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'}));

  // Projeção: próximos 15 dias
  const labelsPrj=[],dataPrj=[],lastDate = porDia.length ? new Date(porDia[porDia.length-1].dia+'T12:00') : new Date();
  const lastIdx = n - 1;
  for (let i=1;i<=15;i++) {
    const d = new Date(lastDate); d.setDate(d.getDate()+i);
    labelsPrj.push(d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'}));
    const trendVal = intercept + slope*(lastIdx+i);
    const dowVal   = dowAvg[d.getDay()];
    const proj = dowVal > 0 ? (trendVal*0.4 + dowVal*0.6) : Math.max(0,trendVal);
    dataPrj.push(Math.round(proj*100)/100);
  }

  const allLabels = [...labelsReal, ...labelsPrj];
  const realData  = [...vals, ...new Array(15).fill(null)];
  const projData  = [...new Array(n).fill(null), ...dataPrj];
  const projHigh  = dataPrj.map(v=>Math.round(v*1.20*100)/100);
  const projLow   = dataPrj.map(v=>Math.round(v*0.80*100)/100);
  const highData  = [...new Array(n).fill(null), ...projHigh];
  const lowData   = [...new Array(n).fill(null), ...projLow];

  if (chartProj) chartProj.destroy();
  chartProj = new Chart(ctxP, {
    data:{
      labels:allLabels,
      datasets:[
        {type:'line',label:'Faturamento real',data:realData,borderColor:'#ff5500',backgroundColor:'rgba(255,85,0,.1)',borderWidth:2,fill:true,tension:.3,pointRadius:3,spanGaps:false},
        {type:'line',label:'Projecao',data:projData,borderColor:'rgba(59,130,246,.8)',borderDash:[6,4],borderWidth:2,fill:false,tension:.3,pointRadius:0,spanGaps:false},
        {type:'line',label:'Max projecao',data:highData,borderColor:'transparent',backgroundColor:'rgba(59,130,246,.08)',fill:'+1',pointRadius:0,tension:.3,spanGaps:false},
        {type:'line',label:'Min projecao',data:lowData,borderColor:'transparent',backgroundColor:'rgba(59,130,246,.08)',fill:false,pointRadius:0,tension:.3,spanGaps:false},
      ]
    },
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.raw||0)}}},
      scales:{
        x:{grid:{display:false},ticks:{color:'#6b7280',font:{size:9},maxTicksLimit:12}},
        y:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#6b7280',font:{size:10},callback:v=>v?'R$'+parseFloat(v).toFixed(0):''}},
      }
    }
  });
}

function renderWhatIf(kpi, produtos, crosssell) {
  const el = document.getElementById('rel-whatif');
  if (!el) return;

  const ticketMedio = parseFloat(kpi.ticket_medio)||0;
  const pedMedio    = parseFloat(kpi.pedidos)||0;
  const topProd = produtos[0];
  const cs = crosssell[0];

  el.innerHTML =
    '<div class="rel-whatif-slider">'+
      '<div class="rel-whatif-lbl">📈 E se aumentar o preco de "'+esc(topProd?.nome_produto||'produto principal')+'" em <span id="wi-preco-val">10</span>%?</div>'+
      '<input type="range" class="rel-slider" id="wi-preco" min="1" max="50" value="10">'+
      '<div class="rel-whatif-result" id="wi-preco-result" style="color:var(--green)">+R$0,00/mes</div>'+
      '<div class="rel-whatif-range" id="wi-preco-range"></div>'+
    '</div>'+
    (cs ?
      '<div class="rel-whatif-slider">'+
        '<div class="rel-whatif-lbl">🎁 E se criar combo "'+esc(cs.prod_a)+'" + "'+esc(cs.prod_b)+'" com desconto de <span id="wi-combo-val">10</span>%?</div>'+
        '<input type="range" class="rel-slider" id="wi-combo" min="5" max="30" value="10">'+
        '<div class="rel-whatif-result" id="wi-combo-result" style="color:var(--green)">+R$0,00/mes</div>'+
        '<div class="rel-whatif-range" id="wi-combo-range"></div>'+
      '</div>' : '')+
    '<div class="rel-whatif-slider">'+
      '<div class="rel-whatif-lbl">⏰ E se abrir <span id="wi-horas-val">1</span> hora(s) a mais por dia?</div>'+
      '<input type="range" class="rel-slider" id="wi-horas" min="1" max="4" value="1">'+
      '<div class="rel-whatif-result" id="wi-horas-result" style="color:var(--blue)">+R$0,00/mes</div>'+
      '<div class="rel-whatif-range" id="wi-horas-range"></div>'+
    '</div>';

  const topQtd = parseFloat(topProd?.qtd)||0;
  const topRec = parseFloat(topProd?.receita)||0;

  function calcWI() {
    const deltap = parseInt(document.getElementById('wi-preco')?.value||10);
    const deltac = parseInt(document.getElementById('wi-combo')?.value||10);
    const deltah = parseInt(document.getElementById('wi-horas')?.value||1);
    const periodoMult = 30;

    // Impacto preço (receita_do_produto × delta%)
    const impPreco = topRec * deltap/100;
    const impPes   = impPreco * 0.70;
    const elPrecoV = document.getElementById('wi-preco-val');
    if (elPrecoV) elPrecoV.textContent = deltap;
    const rPreco = document.getElementById('wi-preco-result');
    if (rPreco) { rPreco.textContent = '+'+fmt(impPreco)+'/periodo'; rPreco.style.color='var(--green)'; }
    const rPrecoR = document.getElementById('wi-preco-range');
    if (rPrecoR) rPrecoR.textContent = 'Pessimista: +'+fmt(impPes)+' · Otimista: +'+fmt(impPreco)+' (se volume mantiver)';

    // Combo
    if (cs) {
      const precoCombo = parseFloat(cs.preco_combo)||0;
      const oc = parseInt(cs.ocorrencias)||0;
      const adocao = 0.4;
      const impCombo = oc * adocao * precoCombo * (1-deltac/100);
      const elComboV = document.getElementById('wi-combo-val');
      if (elComboV) elComboV.textContent = deltac;
      const rCombo = document.getElementById('wi-combo-result');
      if (rCombo) { rCombo.textContent = '+'+fmt(impCombo)+'/periodo'; rCombo.style.color='var(--green)'; }
      const rComboR = document.getElementById('wi-combo-range');
      if (rComboR) rComboR.textContent = 'Baseado em '+oc+' ocorrencias no periodo · '+Math.round(adocao*100)+'% adocao estimada';
    }

    // Horas extras
    const pedPorHora = ticketMedio > 0 && pedMedio > 0 ? (pedMedio / 8) : 0;
    const impHoras = deltah * pedPorHora * ticketMedio * periodoMult;
    const elHorasV = document.getElementById('wi-horas-val');
    if (elHorasV) elHorasV.textContent = deltah;
    const rHoras = document.getElementById('wi-horas-result');
    if (rHoras) { rHoras.textContent = '+'+fmt(impHoras)+'/mes'; rHoras.style.color='var(--blue)'; }
    const rHorasR = document.getElementById('wi-horas-range');
    if (rHorasR) rHorasR.textContent = '~'+(pedPorHora*deltah*periodoMult).toFixed(0)+' pedidos extras/mes · Ticket medio '+fmt(ticketMedio);
  }

  calcWI();
  ['wi-preco','wi-combo','wi-horas'].forEach(id => {
    const el2 = document.getElementById(id);
    if (el2) el2.addEventListener('input', calcWI);
  });
}

document.getElementById('btn-rel-load').addEventListener('click', loadRelatorios);
document.getElementById('btn-rel-csv').addEventListener('click', () => {
  const ini = document.getElementById('rel-ini').value;
  const fim = document.getElementById('rel-fim').value;
  window.location.href = BASE + 'relatorios.php?export=csv&data_ini='+ini+'&data_fim='+fim;
});

// ─────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
// ── ESTRATÉGIAS & INSIGHTS ───────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
async function loadEstrategias() {
  const btn = document.getElementById('btn-est-refresh');
  if (btn) { btn.textContent = '↺ Carregando...'; btn.disabled = true; }

  const res = await api('estrategias.php');

  if (btn) { btn.textContent = '↺ Atualizar'; btn.disabled = false; }
  if (!res.success) { toast(res.error||'Erro ao carregar estratégias','err'); return; }

  const now = new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
  const luEl = document.getElementById('est-last-update');
  if (luEl) luEl.textContent = `Atualizado às ${now}`;

  const d = res;
  const r = d.resumo||{};

  // ── KPI Cards ──────────────────────────────────────────────────────
  const metaTk  = parseFloat(r.meta_ticket||0);
  const horaIni = r.hora_pico_ini;
  const fatDelta = r.fat_delta_pct;

  const kpis = [
    {
      emoji:'💰', lbl:'Faturamento hoje', ek:'var(--green)',
      val: fmt(r.faturamento),
      chip: fatDelta !== null ? {cls: fatDelta>=0?'up':'dn', txt: (fatDelta>=0?'↑':'↓')+Math.abs(fatDelta)+'% ontem'} : null,
      sub: fatDelta === null ? 'sem dados de ontem' : `vs R$${parseFloat(r.faturamento||0).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')} ontem`,
    },
    {
      emoji:'🎯', lbl:'Ticket médio', ek:'var(--acc)',
      val: fmt(r.ticket_medio),
      chip: metaTk > 0 ? {cls: parseFloat(r.ticket_medio||0)>=metaTk?'up':'dn', txt: parseFloat(r.ticket_medio||0)>=metaTk?'✓ meta':'abaixo'} : null,
      sub: metaTk > 0 ? `Meta: ${fmt(metaTk)}` : 'configure uma meta',
    },
    {
      emoji:'📦', lbl:'Pedidos hoje', ek:'var(--blue)',
      val: r.pedidos||0,
      chip: (r.em_aberto||0) > 0 ? {cls:'', txt:`${r.em_aberto} abertos`} : null,
      sub: `${r.cancelados||0} cancelado${r.cancelados!==1?'s':''}`,
    },
    {
      emoji:'⏰', lbl:'Hora de pico', ek:'var(--gold)',
      val: horaIni !== null ? `${horaIni}h–${horaIni+2}h` : '—',
      chip: r.hora_pico_qtd ? {cls:'', txt:`${r.hora_pico_qtd} pedidos`} : null,
      sub: 'janela de maior movimento',
    },
  ];

  document.getElementById('est-resumo').innerHTML = kpis.map(k => {
    const chipHtml = k.chip
      ? `<div class="est-kpi-chip ${k.chip.cls}">${k.chip.txt}</div>`
      : `<div style="height:20px"></div>`;
    return `<div class="est-kpi-card" style="--ek:${k.ek}">
      <div class="est-kpi-header"><span class="est-kpi-emoji">${k.emoji}</span>${chipHtml}</div>
      <div class="est-kpi-lbl">${k.lbl}</div>
      <div class="est-kpi-val">${k.val}</div>
      <div class="est-kpi-sub">${k.sub}</div>
    </div>`;
  }).join('');

  // ── Combos inteligentes ──────────────────────────────────────────
  const combos = d.combos||[];
  const combEl = document.getElementById('est-combos-count');
  if (combEl) combEl.textContent = combos.length ? `${combos.length} detectados` : '—';

  document.getElementById('est-combos').innerHTML = combos.slice(0,4).map(c => {
    const ganho = parseFloat(c.ganho)||0;
    const pct   = c.pct||0;
    const nameA = c.prod_a.length > 16 ? c.prod_a.slice(0,14)+'…' : c.prod_a;
    const nameB = c.prod_b.length > 16 ? c.prod_b.slice(0,14)+'…' : c.prod_b;
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
  const faixas = d.faixas_horario||[];
  const maxQtd = Math.max(...faixas.map(f=>f.qtd||0), 1);
  const COR_H  = {forte:'#22c55e', normal:'rgba(255,255,255,.2)', fraco:'#ef4444'};

  document.getElementById('est-horarios').innerHTML = faixas.length
    ? `<div class="est-hora-chart">${faixas.map(f => {
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
      </div>`
    : '<div style="color:var(--text3);font-size:12px;padding:8px 0">Sem dados suficientes</div>';

  // ── Fidelização — anel SVG + stats ──────────────────────────────
  const fidel  = d.fidelizacao||{};
  const pctRet = Math.min(100, fidel.retorno_7dias_pct||0);
  const radius = 36, circ = 2*Math.PI*radius;
  const dash   = (pctRet/100)*circ;
  const ringCol= pctRet>=60?'#22c55e':pctRet>=30?'#f59e0b':'#ef4444';

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
  const parados = d.produtos_parados||[];
  const paradEl = document.getElementById('est-parados-count');
  if (paradEl) paradEl.textContent = parados.length ? `${parados.length}` : '✓ 0';

  document.getElementById('est-parados').innerHTML = parados.length
    ? parados.slice(0,4).map(p => {
        const qtd30  = parseInt(p.qtd_30d)||1;
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
      }).join('')
    : `<div style="text-align:center;padding:16px 0">
        <div style="font-size:28px;margin-bottom:6px">🎉</div>
        <div style="font-size:12px;font-weight:600;color:var(--green)">Nenhum produto parado!</div>
        <div style="font-size:11px;color:var(--text3);margin-top:3px">Todos os itens venderam nos últimos 7 dias</div>
      </div>`;

  // ── Insights com ícone e cor por tipo ─────────────────────────────
  const insights = d.insights||[];
  const INS_CFG  = {
    green:  {cor:'#22c55e', icon:'💡', bg:'rgba(34,197,94,.06)'},
    yellow: {cor:'#f59e0b', icon:'⚠️', bg:'rgba(245,158,11,.06)'},
    blue:   {cor:'#3b82f6', icon:'📌', bg:'rgba(59,130,246,.06)'},
    red:    {cor:'#ef4444', icon:'🔴', bg:'rgba(239,68,68,.06)'},
  };
  document.getElementById('est-insights').innerHTML = insights.length
    ? insights.map(ins => {
        const cfg = INS_CFG[ins.cor]||INS_CFG.blue;
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
      }).join('')
    : '<div class="est-insights-loading">Nenhum insight gerado ainda</div>';
}

// ── RELATÓRIOS — Turno, Waterfall, Clientes, Recordes ─────────────────
// ─────────────────────────────────────────────────────────────────────
function renderTurnos(porTurno) {
  const el = document.getElementById('rel-turnos');
  if (!el) return;
  const DEF = {
    manha:  {icon:'🌅', lbl:'Manhã',   sub:'06h–11h', cor:'var(--gold)'},
    almoco: {icon:'☀️',  lbl:'Almoço',  sub:'12h–14h', cor:'var(--acc)'},
    tarde:  {icon:'🌤️', lbl:'Tarde',   sub:'15h–18h', cor:'var(--blue)'},
    noite:  {icon:'🌙', lbl:'Noite',   sub:'19h–23h', cor:'var(--purple)'},
  };
  if (!porTurno?.length) { el.innerHTML='<div style="color:var(--text3);font-size:12px">Sem dados de turno</div>'; return; }
  const maxFat = Math.max(...porTurno.map(t=>parseFloat(t.faturamento)||0), 1);
  el.innerHTML = porTurno.map(t => {
    const d = DEF[t.turno] || {icon:'⏰', lbl:t.turno, sub:'', cor:'var(--acc)'};
    const pct = Math.round((parseFloat(t.faturamento)||0)/maxFat*100);
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
  const fat     = parseFloat(kpi.faturamento)||0;
  const tktM    = parseFloat(kpi.ticket_medio)||0;
  const canVal  = Math.min(fat * 0.15, parseFloat(cancelados||0) * tktM);
  const taxas   = parseFloat(custoTotal)||0;
  const liquido = fat - taxas;
  const steps = [
    {lbl:'Receita bruta',       val: fat,     cor:'var(--blue)',  cls:''},
    {lbl:'− Cancelamentos',     val: -canVal, cor:'var(--red)',   cls:''},
    {lbl:'− Taxas (máquina)',   val: -taxas,  cor:'var(--gold)',  cls:''},
    {lbl:'= Receita líquida',   val: liquido, cor:'var(--green)', cls:'wf-total'},
  ];
  const maxV = Math.max(...steps.map(s=>Math.abs(s.val)), 1);
  el.innerHTML = steps.map(s => {
    const pct   = Math.round(Math.abs(s.val)/maxV*100);
    const disp  = s.val < 0 ? `-${fmt(Math.abs(s.val))}` : fmt(s.val);
    const vCor  = s.val < 0 ? 'var(--red)' : s.cor;
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
    el.innerHTML='<div style="color:var(--text3);font-size:12px;padding:14px 16px">Nenhum cliente identificado no período.<br><span style="font-size:10px">Os pedidos precisam ser feitos com CPF.</span></div>';
    return;
  }
  const medals = ['🥇','🥈','🥉','4️⃣','5️⃣'];
  el.innerHTML = clientes.map((c,i) => {
    const cpfMask = c.cpf ? c.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4') : '—';
    const ultima  = c.ultima_visita ? new Date(c.ultima_visita).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'}) : '—';
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
    const d = new Date(records.dia_maior_fat.dia+'T12:00').toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'});
    items.push({icon:'🏆', lbl:'Recorde de faturamento', val:fmt(records.dia_maior_fat.total), date:d});
  }
  if (records.dia_mais_ped?.qtd > 0) {
    const d = new Date(records.dia_mais_ped.dia+'T12:00').toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'});
    items.push({icon:'📦', lbl:'Recorde de pedidos', val:records.dia_mais_ped.qtd+' pedidos', date:d});
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

  const ROLE_LBL = {admin:'Admin',operador:'Operador',cozinha:'Cozinha'};
  document.getElementById('user-tbody').innerHTML = res.data.map(u =>
    '<tr>'+
      '<td>'+
        '<div class="sb-avatar" style="display:inline-flex;width:28px;height:28px;font-size:12px;margin-right:8px">'+esc(u.nome.charAt(0).toUpperCase())+'</div>'+
        '<strong>'+esc(u.nome)+'</strong>'+
      '</td>'+
      '<td style="color:var(--text2)">'+esc(u.email)+'</td>'+
      '<td><span class="badge badge-'+u.role+'">'+(ROLE_LBL[u.role]||u.role)+'</span></td>'+
      '<td>'+(u.ativo?'<span style="color:var(--green);font-weight:600">Ativo</span>':'<span style="color:var(--red)">Inativo</span>')+'</td>'+
      '<td style="color:var(--text2);font-size:12px">'+(u.ultimo_login?fmtDt(u.ultimo_login):'Nunca')+'</td>'+
      '<td>'+u.total_logins+'</td>'+
      '<td><button class="btn btn-secondary btn-sm" onclick="openUserModal('+u.id+')">Editar</button></td>'+
    '</tr>'
  ).join('');
}

function openUserModal(id) {
  const data = id ? allUserData.find(u=>u.id===id) : null;
  document.getElementById('modal-user-title').textContent = data ? 'Editar usuário' : 'Novo usuário';
  document.getElementById('fu-id').value    = data?.id    || '';
  document.getElementById('fu-nome').value  = data?.nome  || '';
  document.getElementById('fu-email').value = data?.email || '';
  document.getElementById('fu-role').value  = data?.role  || 'operador';
  document.getElementById('fu-ativo').checked = data ? data.ativo : true;
  document.getElementById('fu-senha').value = '';
  document.getElementById('fu-senha-hint').style.display = data ? '' : 'none';
  openModal('modal-user');
}

document.getElementById('btn-new-user').addEventListener('click', () => openUserModal());

document.getElementById('form-user').addEventListener('submit', async e => {
  e.preventDefault();
  const body = {
    id:    parseInt(document.getElementById('fu-id').value)||undefined,
    nome:  document.getElementById('fu-nome').value,
    email: document.getElementById('fu-email').value,
    role:  document.getElementById('fu-role').value,
    ativo: document.getElementById('fu-ativo').checked,
    senha: document.getElementById('fu-senha').value || undefined,
  };
  if (!body.id) delete body.id;
  if (!body.senha) delete body.senha;
  const res = await api('usuarios.php',{method:'POST',body:JSON.stringify(body)});
  if (res.success) { toast(res.action==='created'?'Usuario criado!':'Usuario atualizado!'); closeModal('modal-user'); loadUsuarios(); }
  else toast(res.error||'Erro','err');
});

// ─────────────────────────────────────────────────────────────────────
// ── AUDITORIA ────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
let audPage = 1;

async function loadAuditoria(page) {
  if (!IS_ADMIN) return;
  if (page) audPage = page;

  const busca  = document.getElementById('aud-busca').value;
  const acao   = document.getElementById('aud-acao').value;
  const ini    = document.getElementById('aud-ini').value;
  const fim    = document.getElementById('aud-fim').value;
  const params = new URLSearchParams({ page: audPage });
  if (ini)  params.set('data_ini', ini);
  if (fim)  params.set('data_fim', fim);
  if (acao) params.set('acao', acao);

  const res = await api('audit.php?'+params);
  if (!res.success) return;

  // populate acoes filter
  const sel = document.getElementById('aud-acao');
  const cur = sel.value;
  sel.innerHTML = '<option value="">Todas ações</option>' +
    (res.acoes||[]).map(a=>'<option value="'+esc(a)+'">'+esc(a)+'</option>').join('');
  sel.value = cur;

  document.getElementById('aud-count').textContent = res.total + ' registros';

  const ACAO_CLR = {
    login:'#22c55e',logout:'#6b7280',pedido_criado:'#3b82f6',pedido_status_alterado:'#f59e0b',
    pedido_cancelado:'#ef4444',produto_editado:'#8b5cf6',produto_criado:'#22c55e',
    produto_ativado:'#22c55e',produto_desativado:'#ef4444',usuario_criado:'#3b82f6',
    usuario_editado:'#f59e0b',categoria_criada:'#22c55e',categoria_editada:'#f59e0b',
  };

  let rows = res.data || [];
  if (busca) rows = rows.filter(r => (r.descricao||'').toLowerCase().includes(busca.toLowerCase()) ||
    (r.usuario_nome||'').toLowerCase().includes(busca.toLowerCase()));

  document.getElementById('aud-list').innerHTML = rows.map(r =>
    '<div class="audit-row">'+
      '<div class="audit-time">'+fmtDt(r.criado_em)+'</div>'+
      '<div class="audit-user">'+esc(r.usuario_nome||'Sistema')+'</div>'+
      '<div class="audit-acao">'+
        '<span class="audit-badge" style="background:'+( ACAO_CLR[r.acao]||'#6b7280' )+'22;color:'+( ACAO_CLR[r.acao]||'#6b7280' )+'">'+esc(r.acao)+'</span>'+
        '<span style="color:var(--text2)">'+esc(r.descricao||'')+'</span>'+
      '</div>'+
      '<div style="color:var(--text3);font-size:11px;flex-shrink:0">'+esc(r.ip||'')+'</div>'+
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
  if (total <= 1) { el.innerHTML=''; return; }
  let html = '';
  if (current>1) html += '<button class="page-btn" onclick="('+onClick+')('+( current-1)+')">‹</button>';
  for (let i=Math.max(1,current-2); i<=Math.min(total,current+2); i++) {
    html += '<button class="page-btn'+(i===current?' active':'') +'" onclick="('+onClick+')('+i+')">'+i+'</button>';
  }
  if (current<total) html += '<button class="page-btn" onclick="('+onClick+')('+( current+1)+')">›</button>';
  el.innerHTML = html;
}

function debounce(fn, ms) {
  let t; return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
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
  const g = (id, chave) => { const el = document.getElementById(id); if (el && d[chave]) el.value = d[chave].valor ?? ''; };
  g('cfg-nome',       'loja_nome');
  g('cfg-cnpj',       'loja_cnpj');
  g('cfg-endereco',   'loja_endereco');
  g('cfg-telefone',   'loja_telefone');
  g('cfg-url',        'loja_url');
  g('cfg-logo',       'loja_logo_url');
  g('cfg-idle',       'totem_idle_segundos');
  g('cfg-confirm',    'totem_confirmar_segundos');
  g('cfg-kds-refresh','kds_refresh_segundos');
  // Impressora
  const impAtiva = d['impressora_ativa']?.valor === 'true';
  const impAtivaEl = document.getElementById('cfg-imp-ativa');
  if (impAtivaEl) impAtivaEl.checked = impAtiva;
  g('cfg-imp-ip',     'impressora_ip');
  g('cfg-imp-porta',  'impressora_porta');
  const largEl = document.getElementById('cfg-imp-largura');
  if (largEl && d['impressora_largura']) largEl.value = d['impressora_largura'].valor;
  // PIX
  g('cfg-pix-chave',  'pix_chave');
  g('cfg-pix-benef',  'pix_beneficiario');
  g('cfg-pix-cidade', 'pix_cidade');
}

document.getElementById('btn-salvar-cfg')?.addEventListener('click', async () => {
  const payload = {
    loja_nome:                document.getElementById('cfg-nome').value.trim(),
    loja_cnpj:                document.getElementById('cfg-cnpj').value.trim(),
    loja_endereco:            document.getElementById('cfg-endereco').value.trim(),
    loja_telefone:            document.getElementById('cfg-telefone').value.trim(),
    loja_url:                 document.getElementById('cfg-url')?.value.trim() || '',
    loja_logo_url:            document.getElementById('cfg-logo').value.trim(),
    totem_idle_segundos:      document.getElementById('cfg-idle').value,
    totem_confirmar_segundos: document.getElementById('cfg-confirm').value,
    kds_refresh_segundos:     document.getElementById('cfg-kds-refresh').value,
    impressora_ativa:         (document.getElementById('cfg-imp-ativa')?.checked ? 'true' : 'false'),
    impressora_ip:            document.getElementById('cfg-imp-ip')?.value.trim() || '',
    impressora_porta:         document.getElementById('cfg-imp-porta')?.value || '9100',
    impressora_largura:       document.getElementById('cfg-imp-largura')?.value || '42',
    pix_chave:                document.getElementById('cfg-pix-chave')?.value.trim() || '',
    pix_beneficiario:         document.getElementById('cfg-pix-benef')?.value.trim() || '',
    pix_cidade:               document.getElementById('cfg-pix-cidade')?.value.trim() || '',
  };
  const res = await api('configuracoes.php', { method:'POST', body: JSON.stringify(payload) });
  const st = document.getElementById('cfg-status');
  if (res.success) {
    st.textContent = '✓ Salvo com sucesso!'; st.style.color = 'var(--green)'; st.style.display = 'inline';
    toast('Configurações salvas!');
    setTimeout(() => st.style.display = 'none', 3000);
  } else {
    st.textContent = '✗ Erro ao salvar'; st.style.color = 'var(--red)'; st.style.display = 'inline';
    toast('Erro: ' + (res.error || 'desconhecido'), 'err');
  }
});

// ─────────────────────────────────────────────────────────────────────
// ── BACKUP & EXPORTAÇÃO ───────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
function initBackup() {
  const today = new Date().toISOString().slice(0,10);
  const ini30 = new Date(Date.now()-29*864e5).toISOString().slice(0,10);
  const iniEl = document.getElementById('bkp-ini');
  const fimEl = document.getElementById('bkp-fim');
  if (iniEl && !iniEl.value) { iniEl.value = ini30; fimEl.value = today; }
}

document.getElementById('btn-bkp-csv')?.addEventListener('click', () => {
  const ini = document.getElementById('bkp-ini').value;
  const fim = document.getElementById('bkp-fim').value;
  if (!ini || !fim) { toast('Selecione o período', 'err'); return; }
  window.open(BASE + 'relatorios.php?export=csv&data_ini=' + ini + '&data_fim=' + fim);
});

document.getElementById('btn-bkp-pdf')?.addEventListener('click', () => {
  const ini = document.getElementById('bkp-ini').value;
  const fim = document.getElementById('bkp-fim').value;
  if (!ini || !fim) { toast('Selecione o período', 'err'); return; }
  window.open('relatorio_pdf.php?data_ini=' + ini + '&data_fim=' + fim);
});

document.getElementById('btn-bkp-json')?.addEventListener('click', async () => {
  const ini = document.getElementById('bkp-ini').value;
  const fim = document.getElementById('bkp-fim').value;
  if (!ini || !fim) { toast('Selecione o período', 'err'); return; }
  const st = document.getElementById('bkp-status');
  st.textContent = 'Gerando backup...'; st.style.display = 'block';

  const [pedidos, produtos, cats] = await Promise.all([
    api('pedidos.php?data_ini='+ini+'&data_fim='+fim+'&page=1'),
    api('produtos.php'),
    api('categorias.php'),
  ]);

  const dump = {
    gerado_em: new Date().toISOString(),
    periodo: { ini, fim },
    pedidos: pedidos.data || [],
    produtos: produtos.data || [],
    categorias: cats.data || [],
  };

  const blob = new Blob([JSON.stringify(dump, null, 2)], { type: 'application/json' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = 'backup_' + ini + '_' + fim + '.json';
  a.click(); URL.revokeObjectURL(url);
  st.textContent = '✓ Backup gerado!'; st.style.color = 'var(--green)';
  setTimeout(() => st.style.display = 'none', 4000);
});

// ─────────────────────────────────────────────────────────────────────
// ── INIT ────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
(function init() {
  const today = new Date().toISOString().slice(0,10);
  const ini30 = new Date(Date.now()-29*864e5).toISOString().slice(0,10);
  document.getElementById('ped-ini').value  = today;
  document.getElementById('ped-fim').value  = today;
  if (document.getElementById('aud-ini')) document.getElementById('aud-ini').value = ini30;
  if (document.getElementById('aud-fim')) document.getElementById('aud-fim').value = today;

  loadDashboard();
  setInterval(loadDashboard, 15000);

  // Alertas de estoque baixo no painel
  (async () => {
    try {
      const res = await fetch('../../totem/api/estoque_alertas.php'.replace('../../totem','..'), {
        headers: {'X-CSRF-Token': CSRF}
      });
      // usa api relativa
      const r = await fetch('../api/estoque_alertas.php', {headers:{'X-CSRF-Token':CSRF}});
      const d = await r.json();
      if (d.success && d.total > 0) {
        const banner = document.createElement('div');
        banner.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#7f1d1d;border:1px solid #ef4444;color:#fca5a5;padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:9998;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.5)';
        banner.textContent = '⚠️ ' + d.total + ' insumo(s) com estoque baixo — clique para ver';
        banner.onclick = () => { window.open('estoque/','_blank'); banner.remove(); };
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
      headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({pedido_id: pedidoId}),
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
    const res = await fetch('backup.php', {headers:{'X-CSRF-Token':CSRF}});
    const d = await res.json();
    if (d.success) toast('✅ ' + d.message, 'ok');
    else toast('Erro: ' + (d.error||'falha'), 'err');
  } catch { toast('Erro ao conectar', 'err'); }
  btn.textContent = '💾 Backup BD';
  btn.style.pointerEvents = '';
}

// ── Session timeout warning — avisa 2 min antes ──────────────────────
(function() {
  const TIMEOUT     = 1800 * 1000; // 30 min em ms
  const WARN_BEFORE = 120  * 1000; // avisa 2 min antes
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
    document.addEventListener(e, resetTimers, {passive: true}));

  resetTimers();
})();
</script>
<?php endif; ?>
</body>
</html>

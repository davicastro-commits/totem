<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: ../');
    exit;
}

require_once '../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$isAdmin   = ($_SESSION['admin_role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestão de Mesas — Café Comunhão</title>
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f1117;--surf:#13151e;--card:#161924;--card2:#1e2130;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;--acc-gl:rgba(255,85,0,.12);
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;--orange:#f97316;--gray:#6b7280;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;--text4:#4b5563;
}
html,body{height:100%;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
body{display:flex;flex-direction:column;min-height:100vh}

/* ── HEADER ── */
.header{display:flex;align-items:center;justify-content:space-between;padding:0 24px;height:56px;background:var(--surf);border-bottom:1px solid var(--border);flex-shrink:0;position:sticky;top:0;z-index:50}
.header-brand{font-size:16px;font-weight:800;color:var(--acc)}
.header-title{font-size:15px;font-weight:700}
.header-right{display:flex;align-items:center;gap:12px}
.back-link{display:flex;align-items:center;gap:6px;color:var(--text2);text-decoration:none;font-size:13px;font-weight:500;padding:7px 14px;border:1px solid var(--border2);border-radius:8px;transition:all .15s}
.back-link:hover{color:var(--text);border-color:var(--text3)}
.pulse-dot{display:inline-block;width:7px;height:7px;background:var(--green);border-radius:50%;animation:pulse 2s infinite;margin-right:5px}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* ── LAYOUT ── */
.layout{display:flex;flex:1;overflow:hidden}
.main-area{flex:1;overflow-y:auto;padding:24px}
.sidebar-right{width:280px;background:var(--surf);border-left:1px solid var(--border);padding:20px;overflow-y:auto;flex-shrink:0}

/* ── SECTION TITLE ── */
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text3);margin-bottom:14px}

/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filter-btn{padding:6px 14px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.filter-btn:hover{border-color:var(--text3);color:var(--text)}
.filter-btn.active{background:var(--acc);color:#fff;border-color:var(--acc)}
.filter-sep{width:1px;height:24px;background:var(--border);margin:0 4px}

/* ── MESA GRID ── */
.mesa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:14px;margin-bottom:24px}
.mesa-card{background:var(--card);border:2px solid transparent;border-radius:14px;padding:16px;cursor:pointer;transition:all .2s;position:relative;user-select:none}
.mesa-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.4)}
.mesa-card.selected{box-shadow:0 0 0 3px var(--acc)}
.mesa-num{font-size:22px;font-weight:900;margin-bottom:6px}
.mesa-loc{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;opacity:.7;margin-bottom:8px}
.mesa-cap{font-size:11px;color:var(--text3);margin-bottom:8px}
.mesa-status-badge{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;text-transform:uppercase;letter-spacing:.3px}

/* Status colors */
.status-livre    .mesa-card{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.05)}
.status-livre    .mesa-num{color:var(--green)}
.status-livre    .mesa-status-badge{background:rgba(34,197,94,.15);color:var(--green)}
.status-ocupada  .mesa-card{border-color:rgba(249,115,22,.4);background:rgba(249,115,22,.07)}
.status-ocupada  .mesa-num{color:var(--orange)}
.status-ocupada  .mesa-status-badge{background:rgba(249,115,22,.15);color:var(--orange)}
.status-reservada .mesa-card{border-color:rgba(59,130,246,.4);background:rgba(59,130,246,.07)}
.status-reservada .mesa-num{color:var(--blue)}
.mesa-card .status-reservada .mesa-status-badge{background:rgba(59,130,246,.15);color:var(--blue)}
.status-bloqueada .mesa-card{border-color:rgba(107,114,128,.4);background:rgba(107,114,128,.05);opacity:.6}
.status-bloqueada .mesa-num{color:var(--gray)}
.mesa-total{font-size:13px;font-weight:700;color:var(--acc-l);margin-top:6px}
.mesa-itens{font-size:11px;color:var(--text3)}
.mesa-time{font-size:10px;color:var(--text3);margin-top:4px}

/* ── SIDEBAR KPIs ── */
.kpi-item{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px}
.kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:8px}
.kpi-value{font-size:24px;font-weight:900}
.kpi-sub{font-size:12px;color:var(--text3);margin-top:4px}

/* ── BTN ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;border:none}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{background:var(--acc-l)}
.btn-secondary{background:var(--card2);border:1px solid var(--border2);color:var(--text2)}
.btn-secondary:hover{color:var(--text);border-color:var(--text3)}
.btn-danger{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-success{background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.2)}
.btn-success:hover{background:var(--green);color:#000}
.btn-sm{padding:5px 12px;font-size:12px}
.btn-full{width:100%;justify-content:center}

/* ── OVERLAY / MODAL ── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);display:none;align-items:flex-start;justify-content:flex-end;z-index:200;backdrop-filter:blur(3px)}
.overlay.open{display:flex}
.side-panel{width:420px;max-width:95vw;height:100vh;background:var(--surf);border-left:1px solid var(--border2);display:flex;flex-direction:column;overflow:hidden;animation:slideIn .2s ease}
@keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
.panel-header{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;border-bottom:1px solid var(--border);flex-shrink:0}
.panel-header h2{font-size:17px;font-weight:800}
.panel-close{width:32px;height:32px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--text2);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.panel-close:hover{background:var(--card2);color:var(--text)}
.panel-body{flex:1;overflow-y:auto;padding:20px 22px}
.panel-footer{padding:16px 22px;border-top:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap;flex-shrink:0}

/* ── MODAL CENTRAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);display:none;align-items:center;justify-content:center;z-index:300;backdrop-filter:blur(3px)}
.modal-overlay.open{display:flex}
.modal-box{background:var(--surf);border:1px solid var(--border2);border-radius:18px;padding:28px;width:440px;max-width:95vw;max-height:88vh;overflow-y:auto;display:flex;flex-direction:column;gap:18px;box-shadow:0 32px 100px rgba(0,0,0,.8)}
.modal-box h3{font-size:17px;font-weight:800}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:4px}

/* ── FORM ── */
.field{display:flex;flex-direction:column;gap:7px}
.field label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text2)}
.field input,.field select,.field textarea{padding:11px 14px;background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--acc)}
.field textarea{resize:vertical;min-height:60px}
.form-row{display:flex;gap:12px}
.form-row .field{flex:1}
.form-check{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text2)}
.form-check input{width:17px;height:17px;cursor:pointer}

/* ── ITEMS LIST ── */
.item-row{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--card);border:1px solid var(--border);border-radius:10px;margin-bottom:8px}
.item-row-name{flex:1;font-size:13px;font-weight:600}
.item-row-qty{font-size:12px;color:var(--text3)}
.item-row-price{font-size:13px;font-weight:700;color:var(--acc-l)}
.item-row-obs{font-size:11px;color:var(--text3);margin-top:2px}
.item-status{font-size:10px;font-weight:700;padding:2px 8px;border-radius:5px;text-transform:uppercase}
.item-aguardando{background:rgba(245,158,11,.15);color:var(--gold)}
.item-preparando{background:rgba(59,130,246,.15);color:var(--blue)}
.item-pronto{background:rgba(34,197,94,.15);color:var(--green)}
.item-entregue{background:rgba(107,114,128,.15);color:var(--gray)}

/* ── DETAIL ROW ── */
.detail-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px}
.detail-row:last-child{border-bottom:none}
.detail-label{color:var(--text2)}
.detail-total{display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:15px;font-weight:700;border-top:2px solid var(--border);margin-top:8px}
.detail-total-val{font-size:20px;color:var(--acc)}

/* ── PRODUCT SEARCH ── */
.search-box{position:relative}
.search-box input{width:100%;padding:11px 14px;background:var(--card);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:border-color .15s}
.search-box input:focus{border-color:var(--acc)}
.search-results{position:absolute;top:100%;left:0;right:0;background:var(--surf);border:1px solid var(--border2);border-radius:10px;margin-top:4px;max-height:200px;overflow-y:auto;z-index:100;box-shadow:0 8px 32px rgba(0,0,0,.5)}
.search-result-item{padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);transition:background .1s}
.search-result-item:last-child{border-bottom:none}
.search-result-item:hover{background:var(--card)}
.search-result-name{font-weight:600}
.search-result-price{color:var(--acc-l);font-weight:700;float:right}

/* ── TOAST ── */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;background:var(--card2);border:1px solid var(--border2);border-radius:10px;font-size:13px;font-weight:500;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none;max-width:340px}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:var(--green)}
#toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}

/* ── DIVIDER ── */
.divider{height:1px;background:var(--border);margin:14px 0}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:40px 20px;color:var(--text3);font-size:13px}

/* ── BADGE ── */
.badge{display:inline-flex;align-items:center;font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;text-transform:uppercase}
.badge-livre{background:rgba(34,197,94,.15);color:var(--green)}
.badge-ocupada{background:rgba(249,115,22,.15);color:var(--orange)}
.badge-reservada{background:rgba(59,130,246,.15);color:var(--blue)}
.badge-bloqueada{background:rgba(107,114,128,.15);color:var(--gray)}
</style>
</head>
<body>

<!-- HEADER -->
<header class="header">
  <div class="header-brand">Café Comunhão</div>
  <div class="header-title">Gestão de Mesas</div>
  <div class="header-right">
    <span style="font-size:12px;color:var(--text3)"><span class="pulse-dot"></span>Ao vivo</span>
    <a href="../" class="back-link">← Painel Admin</a>
  </div>
</header>

<div class="layout">
  <!-- MAIN -->
  <div class="main-area">
    <!-- Filter bar -->
    <div class="filter-bar">
      <button class="filter-btn active" data-filter="todas">Todas</button>
      <div class="filter-sep"></div>
      <button class="filter-btn" data-filter="livre">Livres</button>
      <button class="filter-btn" data-filter="ocupada">Ocupadas</button>
      <button class="filter-btn" data-filter="reservada">Reservadas</button>
      <button class="filter-btn" data-filter="bloqueada">Bloqueadas</button>
      <div class="filter-sep"></div>
      <button class="filter-btn" data-filter="salao">Salão</button>
      <button class="filter-btn" data-filter="varanda">Varanda</button>
      <button class="filter-btn" data-filter="vip">VIP</button>
      <div style="margin-left:auto;display:flex;gap:8px">
        <?php if ($isAdmin): ?>
        <button class="btn btn-secondary btn-sm" onclick="openEditMesaModal()">+ Nova mesa</button>
        <?php endif; ?>
        <button class="btn btn-secondary btn-sm" onclick="loadMesas()">↻ Atualizar</button>
      </div>
    </div>

    <!-- Mesa grid -->
    <div class="mesa-grid" id="mesa-grid">
      <div class="empty-state">Carregando mesas...</div>
    </div>
  </div>

  <!-- SIDEBAR: Resumo do dia -->
  <aside class="sidebar-right">
    <div class="section-title">Resumo do dia</div>

    <div class="kpi-item">
      <div class="kpi-label">Faturamento hoje</div>
      <div class="kpi-value" id="kpi-faturamento" style="color:var(--green)">R$ 0,00</div>
      <div class="kpi-sub">Comandas pagas</div>
    </div>

    <div class="kpi-item">
      <div class="kpi-label">Mesas ocupadas</div>
      <div class="kpi-value" id="kpi-ocupadas" style="color:var(--orange)">0</div>
      <div class="kpi-sub">Agora</div>
    </div>

    <div class="kpi-item">
      <div class="kpi-label">Comandas abertas</div>
      <div class="kpi-value" id="kpi-abertas" style="color:var(--blue)">0</div>
      <div class="kpi-sub">Em andamento</div>
    </div>

    <div class="kpi-item">
      <div class="kpi-label">Ticket médio</div>
      <div class="kpi-value" id="kpi-ticket" style="color:var(--acc)">R$ 0,00</div>
      <div class="kpi-sub">Por comanda paga hoje</div>
    </div>

    <div class="divider"></div>

    <div class="section-title">Legenda</div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <div style="display:flex;align-items:center;gap:8px;font-size:12px"><span class="badge badge-livre">Livre</span>Mesa disponível</div>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px"><span class="badge badge-ocupada">Ocupada</span>Com comanda aberta</div>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px"><span class="badge badge-reservada">Reservada</span>Pré-reservada</div>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px"><span class="badge badge-bloqueada">Bloqueada</span>Indisponível</div>
    </div>

    <div class="divider"></div>

    <a href="../../garcom/" target="_blank" class="btn btn-secondary btn-full" style="margin-top:4px">
      📱 App do Garçom
    </a>
  </aside>
</div>

<!-- ── SIDE PANEL: Detalhes da mesa ── -->
<div class="overlay" id="overlay-mesa">
  <div class="side-panel">
    <div class="panel-header">
      <h2 id="panel-mesa-title">Mesa</h2>
      <button class="panel-close" onclick="closePanel()">✕</button>
    </div>
    <div class="panel-body" id="panel-mesa-body">
      <!-- conteúdo dinâmico -->
    </div>
    <div class="panel-footer" id="panel-mesa-footer">
      <!-- botões dinâmicos -->
    </div>
  </div>
</div>

<!-- ── MODAL: Abrir comanda ── -->
<div class="modal-overlay" id="modal-abrir">
  <div class="modal-box">
    <h3>Abrir Comanda</h3>
    <div id="modal-abrir-info" style="font-size:13px;color:var(--text2)"></div>
    <div class="field">
      <label>Garçom (opcional)</label>
      <select id="abrir-garcom"><option value="">— Sem garçom atribuído —</option></select>
    </div>
    <div class="field">
      <label>Observação</label>
      <textarea id="abrir-obs" placeholder="Ex: aniversariante, cadeira de bebê..."></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModalOverlay('modal-abrir')">Cancelar</button>
      <button class="btn btn-primary" id="btn-confirmar-abrir">Abrir Comanda</button>
    </div>
  </div>
</div>

<!-- ── MODAL: Adicionar item ── -->
<div class="modal-overlay" id="modal-add-item">
  <div class="modal-box">
    <h3>Adicionar Item</h3>
    <div class="field">
      <label>Buscar produto</label>
      <div class="search-box">
        <input type="text" id="item-busca" placeholder="Digite o nome do produto..." autocomplete="off">
        <div class="search-results" id="item-results" style="display:none"></div>
      </div>
    </div>
    <div id="item-selecionado" style="display:none;background:var(--card);border:1px solid var(--acc);border-radius:10px;padding:12px">
      <div style="font-size:13px;font-weight:700" id="item-sel-nome"></div>
      <div style="font-size:12px;color:var(--acc-l);margin-top:4px" id="item-sel-preco"></div>
    </div>
    <div class="form-row">
      <div class="field">
        <label>Quantidade</label>
        <input type="number" id="item-qty" min="1" value="1">
      </div>
      <div class="field">
        <label>Observação</label>
        <input type="text" id="item-obs" placeholder="Ex: sem cebola">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModalOverlay('modal-add-item')">Cancelar</button>
      <button class="btn btn-primary" id="btn-confirmar-item" disabled>Adicionar</button>
    </div>
  </div>
</div>

<!-- ── MODAL: Fechar conta ── -->
<div class="modal-overlay" id="modal-fechar">
  <div class="modal-box">
    <h3>Fechar Conta</h3>
    <div id="fechar-resumo" style="font-size:13px;color:var(--text2)"></div>
    <div class="field">
      <label class="form-check">
        <input type="checkbox" id="fechar-taxa" onchange="calcFechar()">
        Aplicar taxa de serviço (10%)
      </label>
    </div>
    <div class="field">
      <label>Desconto (R$)</label>
      <input type="number" id="fechar-desconto" min="0" step="0.01" value="0" oninput="calcFechar()">
    </div>
    <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:8px">
      <div class="detail-row"><span class="detail-label">Subtotal</span><span id="fc-sub">R$ 0,00</span></div>
      <div class="detail-row"><span class="detail-label">Taxa de serviço (10%)</span><span id="fc-taxa">R$ 0,00</span></div>
      <div class="detail-row"><span class="detail-label">Desconto</span><span id="fc-desc">R$ 0,00</span></div>
      <div class="detail-total"><span>Total</span><span class="detail-total-val" id="fc-total">R$ 0,00</span></div>
    </div>
    <div class="field">
      <label>Forma de pagamento</label>
      <select id="fechar-pag">
        <option value="">— Definir depois —</option>
        <option value="dinheiro">Dinheiro</option>
        <option value="pix">PIX</option>
        <option value="debito">Débito</option>
        <option value="credito">Crédito</option>
        <option value="voucher">Voucher</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModalOverlay('modal-fechar')">Cancelar</button>
      <button class="btn btn-primary" id="btn-confirmar-fechar">Fechar conta</button>
    </div>
  </div>
</div>

<!-- ── MODAL: Pagar ── -->
<div class="modal-overlay" id="modal-pagar">
  <div class="modal-box">
    <h3>Registrar Pagamento</h3>
    <div id="pagar-resumo" style="font-size:13px;color:var(--text2)"></div>
    <div class="field">
      <label>Forma de pagamento *</label>
      <select id="pagar-forma">
        <option value="dinheiro">Dinheiro</option>
        <option value="pix">PIX</option>
        <option value="debito">Débito</option>
        <option value="credito">Crédito</option>
        <option value="voucher">Voucher</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModalOverlay('modal-pagar')">Cancelar</button>
      <button class="btn btn-success" id="btn-confirmar-pagar">Confirmar Pagamento</button>
    </div>
  </div>
</div>

<!-- ── MODAL: Cancelar comanda ── -->
<div class="modal-overlay" id="modal-cancelar">
  <div class="modal-box">
    <h3>Cancelar Comanda</h3>
    <p style="font-size:13px;color:var(--text2)">Esta ação irá cancelar a comanda e liberar a mesa.</p>
    <div class="field">
      <label>Motivo (opcional)</label>
      <textarea id="cancelar-motivo" placeholder="Ex: cliente desistiu..."></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModalOverlay('modal-cancelar')">Voltar</button>
      <button class="btn btn-danger" id="btn-confirmar-cancelar">Cancelar Comanda</button>
    </div>
  </div>
</div>

<!-- ── MODAL: Editar mesa (admin) ── -->
<div class="modal-overlay" id="modal-mesa-edit">
  <div class="modal-box">
    <h3 id="edit-mesa-title">Editar Mesa</h3>
    <input type="hidden" id="edit-mesa-id">
    <div class="form-row">
      <div class="field">
        <label>Número *</label>
        <input type="text" id="edit-mesa-num" placeholder="01">
      </div>
      <div class="field">
        <label>Capacidade</label>
        <input type="number" id="edit-mesa-cap" min="1" max="50" value="4">
      </div>
    </div>
    <div class="field">
      <label>Localização</label>
      <select id="edit-mesa-loc">
        <option value="salao">Salão</option>
        <option value="varanda">Varanda</option>
        <option value="vip">VIP</option>
        <option value="">Outro</option>
      </select>
    </div>
    <label class="form-check" style="margin-top:4px">
      <input type="checkbox" id="edit-mesa-ativa" checked>
      Mesa ativa
    </label>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModalOverlay('modal-mesa-edit')">Cancelar</button>
      <button class="btn btn-primary" id="btn-salvar-mesa">Salvar</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
'use strict';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API  = '../../api/';

// Formato helpers
const fmt    = v => 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
const fmtDt  = iso => { try { return new Date(iso).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}); } catch{return iso;} };
const esc    = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

// ── Toast ────────────────────────────────────────────────────────────
function toast(msg, type='ok') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = 'show ' + type;
  clearTimeout(el._t); el._t = setTimeout(() => el.className = '', 3200);
}

// ── API ──────────────────────────────────────────────────────────────
async function apiCall(path, opts = {}) {
  const h = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, ...(opts.headers||{}) };
  try {
    const res = await fetch(API + path, { ...opts, headers: h });
    return res.json();
  } catch (e) {
    return { success: false, error: 'Erro de rede' };
  }
}

// ── Modals ──────────────────────────────────────────────────────────
function closePanel() { document.getElementById('overlay-mesa').classList.remove('open'); }
function openPanel()  { document.getElementById('overlay-mesa').classList.add('open'); }
function closeModalOverlay(id) { document.getElementById(id).classList.remove('open'); }
function openModalOverlay(id)  { document.getElementById(id).classList.add('open'); }

document.getElementById('overlay-mesa').addEventListener('click', function(e) {
  if (e.target === this) closePanel();
});
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', function(e) { if (e.target === this) closeModalOverlay(this.id); });
});

// ── State ────────────────────────────────────────────────────────────
let mesasData    = [];
let currentFilter = 'todas';
let selectedMesaId = null;
let currentComandaId = null;
let selectedProdutoId = null;
let selectedProdutoPreco = 0;

// ── Load mesas ───────────────────────────────────────────────────────
async function loadMesas() {
  const res = await apiCall('mesas.php');
  if (!res.success) { toast('Erro ao carregar mesas', 'err'); return; }
  mesasData = res.data || [];
  renderMesas();
  loadKpis();
}

function renderMesas() {
  const grid = document.getElementById('mesa-grid');
  let filtered = mesasData;

  if (['livre','ocupada','reservada','bloqueada'].includes(currentFilter)) {
    filtered = filtered.filter(m => m.status === currentFilter);
  } else if (['salao','varanda','vip'].includes(currentFilter)) {
    filtered = filtered.filter(m => m.localizacao === currentFilter);
  }

  if (!filtered.length) {
    grid.innerHTML = '<div class="empty-state">Nenhuma mesa encontrada</div>';
    return;
  }

  grid.innerHTML = filtered.map(m => {
    const badges = {livre:'verde',ocupada:'laranja',reservada:'azul',bloqueada:'cinza'};
    const statusLabel = {livre:'Livre',ocupada:'Ocupada',reservada:'Reservada',bloqueada:'Bloqueada'}[m.status] || m.status;

    return `<div class="status-${esc(m.status)}" style="display:contents">
      <div class="mesa-card" onclick="clickMesa(${m.id})">
        <div class="mesa-num">Mesa ${esc(m.numero)}</div>
        <div class="mesa-loc">${esc(m.localizacao||'—')}</div>
        <div class="mesa-cap">${esc(m.capacidade)} lugares</div>
        <div><span class="mesa-status-badge">${statusLabel}</span></div>
        ${m.comanda_id ? `<div class="mesa-total">${fmt(m.comanda_total||0)}</div>
          <div class="mesa-itens">${esc(m.qtd_itens||0)} itens</div>
          <div class="mesa-time">${fmtDt(m.comanda_aberta_em)}</div>` : ''}
      </div>
    </div>`;
  }).join('');
}

// ── Filter buttons ───────────────────────────────────────────────────
document.querySelectorAll('.filter-btn[data-filter]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentFilter = this.dataset.filter;
    renderMesas();
  });
});

// ── Click on mesa ───────────────────────────────────────────────────
async function clickMesa(mesaId) {
  selectedMesaId = mesaId;
  const res = await apiCall('mesas.php?id=' + mesaId);
  if (!res.success) { toast('Erro ao carregar mesa', 'err'); return; }

  const { mesa, comanda, itens } = res.data;
  currentComandaId = comanda ? comanda.id : null;

  const title = document.getElementById('panel-mesa-title');
  const body  = document.getElementById('panel-mesa-body');
  const footer = document.getElementById('panel-mesa-footer');

  title.innerHTML = `Mesa <span style="color:var(--acc)">${esc(mesa.numero)}</span>
    <span class="badge badge-${esc(mesa.status)}" style="margin-left:8px;font-size:12px">${esc(mesa.status)}</span>`;

  let html = '';
  html += `<div class="detail-row"><span class="detail-label">Localização</span><span>${esc(mesa.localizacao||'—')}</span></div>`;
  html += `<div class="detail-row"><span class="detail-label">Capacidade</span><span>${esc(mesa.capacidade)} lugares</span></div>`;
  if (mesa.garcom_nome) html += `<div class="detail-row"><span class="detail-label">Garçom</span><span>${esc(mesa.garcom_nome)}</span></div>`;

  if (comanda) {
    html += `<div class="divider"></div>`;
    html += `<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:12px">
      Comanda #${comanda.id} — aberta ${fmtDt(comanda.aberta_em)}</div>`;

    if (itens && itens.length) {
      html += itens.map(item => `
        <div class="item-row">
          <div style="flex:1">
            <div class="item-row-name">${esc(item.produto_nome)}</div>
            ${item.obs ? `<div class="item-row-obs">${esc(item.obs)}</div>` : ''}
          </div>
          <span class="item-status item-${esc(item.status)}">${esc(item.status)}</span>
          <div style="text-align:right">
            <div class="item-row-qty">${esc(item.quantidade)}×</div>
            <div class="item-row-price">${fmt(item.subtotal)}</div>
          </div>
          <button onclick="removeItem(${item.id})" title="Remover"
            style="width:26px;height:26px;border-radius:6px;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.1);color:var(--red);cursor:pointer;font-size:13px;flex-shrink:0">✕</button>
        </div>`).join('');
    } else {
      html += '<div class="empty-state" style="padding:20px">Nenhum item na comanda</div>';
    }

    html += `<div class="divider"></div>`;
    html += `<div class="detail-row"><span class="detail-label">Subtotal</span><strong>${fmt(comanda.subtotal)}</strong></div>`;
    if (parseFloat(comanda.taxa_servico) > 0)
      html += `<div class="detail-row"><span class="detail-label">Taxa de serviço</span><span>${fmt(comanda.taxa_servico)}</span></div>`;
    if (parseFloat(comanda.desconto) > 0)
      html += `<div class="detail-row"><span class="detail-label">Desconto</span><span>-${fmt(comanda.desconto)}</span></div>`;
    html += `<div class="detail-total"><span>Total</span><span class="detail-total-val">${fmt(comanda.total)}</span></div>`;

    footer.innerHTML = `
      <button class="btn btn-secondary btn-sm" onclick="openAddItem()">+ Item</button>
      <button class="btn btn-secondary btn-sm" onclick="enviarKds()">🍳 Cozinha</button>
      <button class="btn btn-primary btn-sm" onclick="openFecharConta()">Fechar conta</button>
      <button class="btn btn-success btn-sm" onclick="openPagar()">Pagar</button>
      <button class="btn btn-secondary btn-sm" onclick="imprimirPreConta()">🖨️ Pré-conta</button>
      <button class="btn btn-danger btn-sm" onclick="openCancelar()">Cancelar</button>`;
  } else {
    // Mesa livre ou sem comanda
    footer.innerHTML = '';
    if (mesa.status === 'livre') {
      footer.innerHTML = `<button class="btn btn-primary btn-full" onclick="openAbrirComanda()">Abrir Comanda</button>`;
      if (<?= $isAdmin ? 'true' : 'false' ?>) {
        footer.innerHTML += `<button class="btn btn-secondary btn-sm" onclick="openEditMesaModal(${mesa.id})">Editar</button>`;
      }
    } else if (mesa.status === 'ocupada') {
      html += '<div class="empty-state">Mesa ocupada sem comanda registrada</div>';
      footer.innerHTML = `<button class="btn btn-secondary btn-sm" onclick="openAbrirComanda()">Abrir Comanda</button>`;
    } else {
      if (<?= $isAdmin ? 'true' : 'false' ?>) {
        footer.innerHTML = `<button class="btn btn-secondary btn-sm" onclick="openEditMesaModal(${mesa.id})">Editar Mesa</button>`;
      }
    }
  }

  body.innerHTML = html;
  openPanel();
}

// ── Abrir comanda ────────────────────────────────────────────────────
async function openAbrirComanda() {
  const mesa = mesasData.find(m => m.id === selectedMesaId);
  document.getElementById('modal-abrir-info').textContent =
    mesa ? `Mesa ${mesa.numero} — ${mesa.localizacao||''} — ${mesa.capacidade} lugares` : '';
  document.getElementById('abrir-obs').value = '';

  // Carregar garçons
  await loadGarcons('abrir-garcom');
  openModalOverlay('modal-abrir');
}

async function loadGarcons(selectId) {
  // Buscar admins para o select de garçom
  const sel = document.getElementById(selectId);
  sel.innerHTML = '<option value="">— Sem garçom atribuído —</option>';
  // Usa a API de usuários do admin se disponível
  try {
    const res = await fetch('../../admin/api/usuarios.php', {
      headers: { 'X-CSRF-Token': CSRF }
    });
    const data = await res.json();
    if (data.success && data.data) {
      data.data.filter(u => u.ativo).forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id; opt.textContent = u.nome + ' (' + u.role + ')';
        sel.appendChild(opt);
      });
    }
  } catch (e) { /* silencioso */ }
}

document.getElementById('btn-confirmar-abrir').addEventListener('click', async () => {
  const garcom_id  = document.getElementById('abrir-garcom').value;
  const observacao = document.getElementById('abrir-obs').value;

  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'abrir_comanda',
      mesa_id: selectedMesaId,
      garcom_id: garcom_id ? parseInt(garcom_id) : null,
      observacao
    })
  });

  if (res.success) {
    toast('Comanda aberta!');
    closeModalOverlay('modal-abrir');
    await loadMesas();
    clickMesa(selectedMesaId);
  } else {
    toast(res.error || 'Erro ao abrir comanda', 'err');
  }
});

// ── Adicionar item ───────────────────────────────────────────────────
function openAddItem() {
  selectedProdutoId = null;
  selectedProdutoPreco = 0;
  document.getElementById('item-busca').value = '';
  document.getElementById('item-qty').value = 1;
  document.getElementById('item-obs').value = '';
  document.getElementById('item-selecionado').style.display = 'none';
  document.getElementById('item-results').style.display = 'none';
  document.getElementById('btn-confirmar-item').disabled = true;
  openModalOverlay('modal-add-item');
  setTimeout(() => document.getElementById('item-busca').focus(), 100);
}

let searchTimeout;
document.getElementById('item-busca').addEventListener('input', function() {
  clearTimeout(searchTimeout);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('item-results').style.display = 'none'; return; }
  searchTimeout = setTimeout(() => searchProdutos(q), 300);
});

async function searchProdutos(q) {
  const res = await apiCall('produtos.php');
  if (!res.success) return;

  const lower = q.toLowerCase();
  const matches = (res.data || []).filter(p => p.nome.toLowerCase().includes(lower)).slice(0, 10);

  const list = document.getElementById('item-results');
  if (!matches.length) { list.style.display = 'none'; return; }

  list.innerHTML = matches.map(p =>
    `<div class="search-result-item" data-id="${p.id}" data-price="${p.preco}" data-nome="${esc(p.nome)}">
      <span class="search-result-name">${esc(p.nome)}</span>
      <span class="search-result-price">${fmt(p.preco)}</span>
    </div>`
  ).join('');
  list.style.display = 'block';
}

document.getElementById('item-results').addEventListener('click', function(e) {
  const item = e.target.closest('.search-result-item');
  if (!item) return;
  selectedProdutoId    = parseInt(item.dataset.id);
  selectedProdutoPreco = parseFloat(item.dataset.price);
  document.getElementById('item-sel-nome').textContent  = item.dataset.nome;
  document.getElementById('item-sel-preco').textContent = fmt(selectedProdutoPreco);
  document.getElementById('item-selecionado').style.display = 'block';
  document.getElementById('item-results').style.display = 'none';
  document.getElementById('item-busca').value = item.dataset.nome;
  document.getElementById('btn-confirmar-item').disabled = false;
  document.getElementById('item-qty').focus();
});

document.getElementById('btn-confirmar-item').addEventListener('click', async () => {
  if (!selectedProdutoId || !currentComandaId) return;
  const qty = parseInt(document.getElementById('item-qty').value) || 1;
  const obs = document.getElementById('item-obs').value;

  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'adicionar_item',
      comanda_id: currentComandaId,
      produto_id: selectedProdutoId,
      quantidade: qty,
      obs
    })
  });

  if (res.success) {
    toast('Item adicionado!');
    closeModalOverlay('modal-add-item');
    await loadMesas();
    clickMesa(selectedMesaId);
  } else {
    toast(res.error || 'Erro ao adicionar item', 'err');
  }
});

// ── Remover item ─────────────────────────────────────────────────────
async function removeItem(itemId) {
  if (!confirm('Remover este item da comanda?')) return;
  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({ action: 'remover_item', item_id: itemId })
  });
  if (res.success) {
    toast('Item removido');
    await loadMesas();
    clickMesa(selectedMesaId);
  } else {
    toast(res.error || 'Erro', 'err');
  }
}

// ── Enviar para KDS ──────────────────────────────────────────────────
async function enviarKds() {
  if (!currentComandaId) return;
  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({ action: 'enviar_kds', comanda_id: currentComandaId })
  });
  if (res.success) {
    toast(`${res.data.itens_enviados} item(s) enviado(s) para a cozinha!`);
    await loadMesas();
    clickMesa(selectedMesaId);
  } else {
    toast(res.error || 'Erro ao enviar para KDS', 'err');
  }
}

// ── Fechar conta ─────────────────────────────────────────────────────
function openFecharConta() {
  const mesa = mesasData.find(m => m.id === selectedMesaId);
  document.getElementById('fechar-desconto').value = '0';
  document.getElementById('fechar-taxa').checked = false;
  document.getElementById('fechar-pag').value = '';
  calcFechar();
  openModalOverlay('modal-fechar');
}

function calcFechar() {
  const mesa = mesasData.find(m => m.id === selectedMesaId);
  const subtotal  = parseFloat(mesa?.comanda_subtotal || 0);
  const aplicaTaxa = document.getElementById('fechar-taxa').checked;
  const desconto  = parseFloat(document.getElementById('fechar-desconto').value) || 0;
  const taxa      = aplicaTaxa ? Math.round(subtotal * 0.10 * 100) / 100 : 0;
  const total     = subtotal + taxa - desconto;

  document.getElementById('fechar-resumo').textContent = `Mesa ${mesa?.numero||''} — ${mesa?.qtd_itens||0} itens`;
  document.getElementById('fc-sub').textContent  = fmt(subtotal);
  document.getElementById('fc-taxa').textContent = fmt(taxa);
  document.getElementById('fc-desc').textContent = '-' + fmt(desconto);
  document.getElementById('fc-total').textContent = fmt(total);
}

document.getElementById('btn-confirmar-fechar').addEventListener('click', async () => {
  if (!currentComandaId) return;
  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'fechar_conta',
      comanda_id: currentComandaId,
      aplicar_taxa_servico: document.getElementById('fechar-taxa').checked,
      desconto: parseFloat(document.getElementById('fechar-desconto').value) || 0,
      forma_pagamento: document.getElementById('fechar-pag').value
    })
  });
  if (res.success) {
    toast('Conta fechada!');
    closeModalOverlay('modal-fechar');
    await loadMesas();
    clickMesa(selectedMesaId);
  } else {
    toast(res.error || 'Erro', 'err');
  }
});

// ── Pagar ────────────────────────────────────────────────────────────
function openPagar() {
  const mesa = mesasData.find(m => m.id === selectedMesaId);
  document.getElementById('pagar-resumo').innerHTML =
    `Mesa ${esc(mesa?.numero||'')} — Total: <strong style="color:var(--acc)">${fmt(mesa?.comanda_total||0)}</strong>`;
  openModalOverlay('modal-pagar');
}

document.getElementById('btn-confirmar-pagar').addEventListener('click', async () => {
  if (!currentComandaId) return;
  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'pagar',
      comanda_id: currentComandaId,
      forma_pagamento: document.getElementById('pagar-forma').value
    })
  });
  if (res.success) {
    toast('Pagamento registrado! Mesa liberada.');
    closeModalOverlay('modal-pagar');
    closePanel();
    await loadMesas();
  } else {
    toast(res.error || 'Erro', 'err');
  }
});

// ── Imprimir pré-conta ───────────────────────────────────────────────
async function imprimirPreConta() {
  const res = await apiCall('mesas.php?id=' + selectedMesaId);
  if (!res.success) return;
  const { mesa, comanda, itens } = res.data;

  const win = window.open('', '_blank', 'width=360,height=600');
  win.document.write(`<!DOCTYPE html><html><head>
    <meta charset="UTF-8">
    <title>Pré-conta Mesa ${esc(mesa.numero)}</title>
    <style>
      body{font-family:monospace;font-size:13px;max-width:320px;margin:20px auto;padding:10px}
      h2,h3{text-align:center;margin:0 0 6px}
      .sep{border-top:1px dashed #000;margin:8px 0}
      .row{display:flex;justify-content:space-between}
      .total{font-weight:bold;font-size:15px}
      @media print{button{display:none}}
    </style>
  </head><body>
    <h2>Café Comunhão</h2>
    <h3>PRÉ-CONTA</h3>
    <div class="row"><span>Mesa:</span><span>${esc(mesa.numero)}</span></div>
    <div class="row"><span>Localização:</span><span>${esc(mesa.localizacao||'—')}</span></div>
    <div class="row"><span>Abertura:</span><span>${fmtDt(comanda?.aberta_em||'')}</span></div>
    <div class="sep"></div>
    <div><strong>Itens:</strong></div>
    ${(itens||[]).map(i => `
      <div class="row">
        <span>${i.quantidade}x ${esc(i.produto_nome)}</span>
        <span>${fmt(i.subtotal)}</span>
      </div>
      ${i.obs ? `<div style="font-size:11px;color:#666;padding-left:8px">Obs: ${esc(i.obs)}</div>` : ''}
    `).join('')}
    <div class="sep"></div>
    <div class="row"><span>Subtotal</span><span>${fmt(comanda?.subtotal||0)}</span></div>
    ${parseFloat(comanda?.taxa_servico||0) > 0 ? `<div class="row"><span>Taxa serviço</span><span>${fmt(comanda.taxa_servico)}</span></div>` : ''}
    ${parseFloat(comanda?.desconto||0) > 0 ? `<div class="row"><span>Desconto</span><span>-${fmt(comanda.desconto)}</span></div>` : ''}
    <div class="row total"><span>TOTAL</span><span>${fmt(comanda?.total||0)}</span></div>
    <div class="sep"></div>
    <div style="text-align:center;font-size:11px;color:#666">Obrigado pela preferência!</div>
    <div style="text-align:center;margin-top:12px"><button onclick="window.print()">🖨️ Imprimir</button></div>
  </body></html>`);
  win.document.close();
}

// ── Cancelar comanda ─────────────────────────────────────────────────
function openCancelar() {
  document.getElementById('cancelar-motivo').value = '';
  openModalOverlay('modal-cancelar');
}

document.getElementById('btn-confirmar-cancelar').addEventListener('click', async () => {
  if (!currentComandaId) return;
  const res = await apiCall('mesas.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'cancelar',
      comanda_id: currentComandaId,
      motivo: document.getElementById('cancelar-motivo').value
    })
  });
  if (res.success) {
    toast('Comanda cancelada');
    closeModalOverlay('modal-cancelar');
    closePanel();
    await loadMesas();
  } else {
    toast(res.error || 'Erro', 'err');
  }
});

// ── Editar mesa ──────────────────────────────────────────────────────
function openEditMesaModal(mesaId = null) {
  document.getElementById('edit-mesa-id').value = mesaId || '';
  if (mesaId) {
    const mesa = mesasData.find(m => m.id === mesaId);
    document.getElementById('edit-mesa-title').textContent = `Editar Mesa ${mesa?.numero||''}`;
    document.getElementById('edit-mesa-num').value  = mesa?.numero || '';
    document.getElementById('edit-mesa-cap').value  = mesa?.capacidade || 4;
    document.getElementById('edit-mesa-loc').value  = mesa?.localizacao || 'salao';
    document.getElementById('edit-mesa-ativa').checked = mesa?.ativa !== false;
  } else {
    document.getElementById('edit-mesa-title').textContent = 'Nova Mesa';
    document.getElementById('edit-mesa-num').value  = '';
    document.getElementById('edit-mesa-cap').value  = 4;
    document.getElementById('edit-mesa-loc').value  = 'salao';
    document.getElementById('edit-mesa-ativa').checked = true;
  }
  openModalOverlay('modal-mesa-edit');
}

document.getElementById('btn-salvar-mesa').addEventListener('click', async () => {
  const id  = parseInt(document.getElementById('edit-mesa-id').value) || null;
  const num = document.getElementById('edit-mesa-num').value.trim();
  if (!num) { toast('Número é obrigatório', 'err'); return; }

  const body = {
    id: id || 0,
    numero: num,
    capacidade: parseInt(document.getElementById('edit-mesa-cap').value) || 4,
    localizacao: document.getElementById('edit-mesa-loc').value,
    ativa: document.getElementById('edit-mesa-ativa').checked
  };

  const res = await apiCall('mesas.php', {
    method: id ? 'PUT' : 'POST',
    body: JSON.stringify(id ? body : { ...body, action: 'criar_mesa' })
  });

  if (res.success) {
    toast(id ? 'Mesa atualizada!' : 'Mesa criada!');
    closeModalOverlay('modal-mesa-edit');
    await loadMesas();
  } else {
    toast(res.error || 'Erro', 'err');
  }
});

// ── KPIs ─────────────────────────────────────────────────────────────
async function loadKpis() {
  const hoje = new Date().toISOString().slice(0,10);
  try {
    const res = await fetch(`../../admin/api/relatorios.php?data_ini=${hoje}&data_fim=${hoje}`, {
      headers: { 'X-CSRF-Token': CSRF }
    });
    const data = await res.json();
    if (data.success) {
      const d = data.data;
      document.getElementById('kpi-faturamento').textContent = fmt(d.faturamento || 0);
      document.getElementById('kpi-ticket').textContent      = fmt(d.ticket_medio || 0);
    }
  } catch (e) { /* silencioso */ }

  // Contar mesas e comandas abertas
  const ocupadas = mesasData.filter(m => m.status === 'ocupada').length;
  const abertas  = mesasData.filter(m => m.comanda_id).length;
  document.getElementById('kpi-ocupadas').textContent = ocupadas;
  document.getElementById('kpi-abertas').textContent  = abertas;
}

// ── Auto-refresh ─────────────────────────────────────────────────────
loadMesas();
setInterval(loadMesas, 10000);
</script>
</body>
</html>

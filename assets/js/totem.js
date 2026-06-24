'use strict';

const Totem = (() => {

  let cfg = {
    loja_nome:                    'Café Comunhão',
    loja_cnpj:                    '',
    loja_endereco:                '',
    loja_telefone:                '',
    loja_url:                     '',
    totem_idle_segundos:          '120',
    totem_confirmar_segundos:     '30',
    totem_mensagem_boasvindas:    '',
    totem_max_itens_pedido:       '20',
    totem_aviso_fechamento_min:   '10',
    totem_autoreload_minutos:     '0',
    pagamento_pix_ativo:          '1',
    pagamento_credito_ativo:      '1',
    pagamento_debito_ativo:       '1',
    pagamento_dinheiro_ativo:     '1',
    taxa_servico_ativa:           '0',
    taxa_servico_percentual:      '0',
  };

  let _avisoFechamentoTimer = null;
  let _autoreloadTimer      = null;
  const _removeCooldown     = new Map(); // id → timestamp de quando foi zerado

  let s = {
    screen:      'welcome',
    tipo:        null,
    categorias:  [],
    produtos:    {},
    categoriaId: null,
    cart:        [],
    cpfInput:    '',
    pagamento:   null,
    pedido:      null,
    autoPrint:   false,
  };

  let IDLE_MS      = 120000;
  let CONFIRM_SECS = 30;
  const COLORS = ['#C94B32','#1E6FA8','#1E8C45','#7B3BA8','#B5620A','#2A7A7A'];

  let idleTimer      = null;
  let countdownTimer = null;

  const app     = document.getElementById('app');
  const receipt = document.getElementById('receipt-container');

  const fmt = v => 'R$ ' + parseFloat(v || 0).toFixed(2).replace('.', ',');

  function fmtCPF(raw) {
    const d = (raw || '').replace(/\D/g, '').slice(0, 11);
    if (d.length <= 3) return d;
    if (d.length <= 6) return d.replace(/(\d{3})(\d+)/, '$1.$2');
    if (d.length <= 9) return d.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
    return d.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
  }

  function cardColor(id) { return COLORS[parseInt(id) % COLORS.length]; }
  function catIcon(id)   { const c = s.categorias.find(x => x.id === id); return c ? c.icone : ''; }

  async function api(path, opts = {}) {
    const base = window.location.pathname.replace(/\/[^/]*$/, '/');
    const res  = await fetch(base + 'api/' + path, {
      headers: { 'Content-Type': 'application/json' }, ...opts,
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  }

  async function loadConfig() {
    try {
      // Cache-bust para garantir que o browser nunca sirva versão antiga
      const base = window.location.pathname.replace(/\/[^/]*$/, '/');
      const r = await fetch(base + 'api/configuracoes.php?_=' + Date.now(), {
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
      }).then(res => res.json());
      if (r.success && r.data) {
        cfg = { ...cfg, ...r.data };
        IDLE_MS      = parseInt(cfg.totem_idle_segundos || '120') * 1000;
        CONFIRM_SECS = parseInt(cfg.totem_confirmar_segundos || '30');
      }
    } catch (_) {}
  }

  // ── Heartbeat único — 30s ────────────────────────────────────────────
  // Gerencia: abertura/fechamento, alerta de fechamento, relógio fechado, config
  let _hbTick = 0;
  setInterval(async () => {
    _hbTick++;

    // Recarregar config a cada 2 min (4 ticks de 30s)
    if (_hbTick % 4 === 0) await loadConfig();

    const aberto  = isStoreOpen();
    const avisMin = parseInt(cfg.totem_aviso_fechamento_min || '10');

    // ── Abertura / Fechamento automático ────────────────────────────
    if (aberto && s.screen === 'fechado') {
      go('welcome');
    } else if (!aberto && s.screen === 'welcome') {
      go('fechado');
    }

    // ── Relógio na tela fechada ──────────────────────────────────────
    if (s.screen === 'fechado') {
      const el = document.getElementById('clock-fechado');
      if (el) el.textContent = new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
    }

    // ── Alerta de fechamento ─────────────────────────────────────────
    // Mostra SEMPRE que há horário de fechamento configurado e a loja está aberta
    const minFecha   = minutosParaFechar();
    const existePill = document.getElementById('aviso-fechamento');
    // Mostrar apenas dentro da janela de aviso (≤ avisMin minutos para fechar)
    const dentroJanela = avisMin > 0 && minFecha > 0 && minFecha <= avisMin;

    if (aberto && dentroJanela && s.screen !== 'fechado') {
      if (!existePill) {
        showAvisoFechamento(minFecha);
      } else {
        const cd = document.getElementById('aviso-countdown');
        if (cd) cd.textContent = minFecha;
      }
    } else if (existePill) {
      existePill.remove();
    }

    // ── Auto-recarga ────────────────────────────────────────────────
    agendarAutoreload();
  }, 30000);

  function cartCount() { return s.cart.reduce((n, i) => n + i.quantidade, 0); }
  function cartTotal() { return s.cart.reduce((n, i) => n + i.preco * i.quantidade, 0); }

  function addItem(prod) {
    const maxItens = parseInt(cfg.totem_max_itens_pedido || '20') || 20;
    if (cartCount() >= maxItens) {
      const el = document.getElementById('cart-bar-total');
      if (el) { el.textContent = 'Máx. ' + maxItens + ' itens por pedido'; setTimeout(() => updateCartBar(), 2000); }
      return;
    }
    const ex = s.cart.find(i => i.id === prod.id);
    if (ex) ex.quantidade++;
    else s.cart.push({ ...prod, quantidade: 1, obs: '' });
    updateCartBar();
    flashCard(prod.id);
  }

  function changeQty(id, delta) {
    const it = s.cart.find(i => i.id === id);
    if (!it) return;
    it.quantidade += delta;
    if (it.quantidade <= 0) s.cart = s.cart.filter(i => i.id !== id);
  }

  function flashCard(id) {
    const el = document.querySelector('.product-card[data-id="' + id + '"]');
    if (!el) return;
    el.classList.remove('added');
    void el.offsetWidth;
    el.classList.add('added');
  }

  function updateCartBar() {
    const cnt  = cartCount();
    const tot  = cartTotal();
    const el   = id => document.getElementById(id);

    const badge = el('cart-count');
    const totEl = el('cart-total');
    const hBtn  = el('btn-cart');
    const fBtn  = el('btn-cart-footer');
    const fInfo = el('footer-cart-info');

    if (badge) badge.textContent = cnt;
    if (totEl) totEl.textContent = fmt(tot);
    if (hBtn)  { hBtn.disabled = cnt === 0; if (cnt > 0) hBtn.classList.add('has-items'); else hBtn.classList.remove('has-items'); }
    if (fBtn)  fBtn.disabled = cnt === 0;
    if (fInfo) fInfo.textContent = cnt > 0 ? cnt + ' ' + (cnt === 1 ? 'item' : 'itens') + ' · ' + fmt(tot) : 'Seu carrinho está vazio';
  }

  // ── Horário de funcionamento ─────────────────────────────────────────
  function isStoreOpen() {
    const dias = ['dom','seg','ter','qua','qui','sex','sab'];
    const dia  = dias[new Date().getDay()];

    // Se nenhum horário foi configurado, sempre aberto
    if (!cfg['horario_seg_abertura'] && !cfg['horario_dom_abertura']) return true;

    const ativo     = cfg['horario_'+dia+'_ativo'];
    if (ativo === '0') return false; // dia desabilitado

    const abertura  = cfg['horario_'+dia+'_abertura']  || '00:00';
    const fechamento= cfg['horario_'+dia+'_fechamento'] || '23:59';

    const now   = new Date();
    const nowM  = now.getHours() * 60 + now.getMinutes();
    const [hA, mA] = abertura.split(':').map(Number);
    const [hF, mF] = fechamento.split(':').map(Number);

    return nowM >= (hA * 60 + mA) && nowM < (hF * 60 + mF);
  }

  function proximaAbertura() {
    const dias   = ['dom','seg','ter','qua','qui','sex','sab'];
    const nomes  = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
    const hoje   = new Date().getDay();
    for (let i = 1; i <= 7; i++) {
      const idx = (hoje + i) % 7;
      const d   = dias[idx];
      if (cfg['horario_'+d+'_ativo'] !== '0') {
        const ab = cfg['horario_'+d+'_abertura'] || '08:00';
        return (i === 1 ? 'Amanhã' : nomes[idx]) + ' às ' + ab;
      }
    }
    return null;
  }

  function resetIdle() {
    clearTimeout(idleTimer);
    if (s.screen !== 'welcome' && s.screen !== 'fechado')
      idleTimer = setTimeout(() => { s.autoPrint = false; goWelcomeOrFechado(); }, IDLE_MS);
  }

  function goWelcomeOrFechado() {
    go(isStoreOpen() ? 'welcome' : 'fechado');
  }

  // ── Aviso de fechamento próximo ──────────────────────────────────────
  // Retorna minutos até fechar (0 se já fechou ou não configurado)
  function minutosParaFechar() {
    const dias = ['dom','seg','ter','qua','qui','sex','sab'];
    const dia  = dias[new Date().getDay()];
    const fech = cfg['horario_'+dia+'_fechamento'];
    if (!fech || fech === '23:59') return 999; // sem restrição
    const [hF, mF] = fech.split(':').map(Number);
    const now = new Date();
    const fechTs = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hF, mF, 0);
    return Math.ceil((fechTs - now) / 60000);
  }

  // Placeholder vazio — a lógica ficou no heartbeat
  function agendarAvisoFechamento() {}

  function showAvisoFechamento(minutos) {
    if (s.screen === 'fechado' || s.screen === 'processing') return;
    if (document.getElementById('aviso-fechamento')) return; // já exibido

    // Injetar CSS
    if (!document.getElementById('aviso-css')) {
      const st = document.createElement('style');
      st.id = 'aviso-css';
      st.textContent = `
        #aviso-fechamento {
          position:fixed; bottom:24px; z-index:9999;
          display:flex; align-items:center; gap:8px;
          background:rgba(18,20,30,.92); backdrop-filter:blur(16px);
          border:1px solid rgba(255,255,255,.08);
          border-radius:999px; padding:8px 16px 8px 12px;
          box-shadow:0 4px 20px rgba(0,0,0,.5);
          user-select:none; white-space:nowrap;
          transition:border-color .4s, box-shadow .4s;
          animation:avisoIn .45s cubic-bezier(.34,1.4,.64,1) forwards;
        }
        /* Modo urgente — dentro da janela de aviso */
        #aviso-fechamento.urgente {
          border-color:rgba(255,85,0,.45);
          box-shadow:0 4px 24px rgba(0,0,0,.6), 0 0 0 1px rgba(255,85,0,.15);
        }
        #aviso-fechamento.urgente #aviso-dot { animation:dotPulse 1.2s ease-in-out infinite; }
        #aviso-fechamento.urgente #aviso-label { color:#ff5500; }
        @keyframes avisoIn {
          from { transform:scale(.8); opacity:0; }
          to   { transform:scale(1);  opacity:1; }
        }
        @keyframes avisoOut {
          to { transform:scale(.8); opacity:0; }
        }
        #aviso-fechamento.saindo { animation:avisoOut .3s ease forwards; }
        #aviso-dot {
          width:8px; height:8px; flex-shrink:0; border-radius:50%;
          background:#ff5500;
          animation:dotPulse 2s ease-in-out infinite;
        }
        @keyframes dotPulse {
          0%,100% { box-shadow:0 0 0 0 rgba(255,85,0,.5); }
          60%     { box-shadow:0 0 0 6px rgba(255,85,0,0); }
        }
        #aviso-label {
          font-size:10px; font-weight:700; color:#ff7733;
          letter-spacing:.7px; text-transform:uppercase;
          max-width:80px; opacity:1;
          transition:max-width .35s ease, opacity .25s ease, margin .35s ease;
          overflow:hidden;
        }
        #aviso-time {
          font-size:13px; font-weight:800; color:#f0f2f8; white-space:nowrap;
        }
        #aviso-close-btn {
          max-width:22px; width:22px; height:22px; flex-shrink:0;
          border-radius:50%; border:none; background:rgba(255,255,255,.08);
          color:#6b7280; font-size:11px; cursor:pointer;
          display:flex; align-items:center; justify-content:center;
          transition:max-width .35s, opacity .25s, margin .35s, background .15s;
          font-family:inherit; overflow:hidden;
        }
        #aviso-close-btn:hover { background:rgba(239,68,68,.2); color:#f87171; }
      `;
      document.head.appendChild(st);
    }

    const pill = document.createElement('div');
    pill.id = 'aviso-fechamento';
    // Pegar horário de fechamento para exibir
    const _dias = ['dom','seg','ter','qua','qui','sex','sab'];
    const _dia  = _dias[new Date().getDay()];
    const _hrFecha = cfg['horario_'+_dia+'_fechamento'] || '';

    pill.innerHTML = `
      <div id="aviso-dot"></div>
      <span id="aviso-label">Fecha</span>
      <span id="aviso-time">${_hrFecha ? 'às ' + _hrFecha + ' · em ' : 'em '}<span id="aviso-countdown">${minutos}</span> min</span>
    `;
    // Posicionar via JS relativo ao #app para não ser destruído pelo innerHTML
    const _posicionarPill = () => {
      const rect = app.getBoundingClientRect();
      pill.style.left   = (rect.left + 24) + 'px';
      pill.style.bottom = '24px';
    };
    document.body.appendChild(pill);
    _posicionarPill();
    // Reposicionar se janela redimensionar
    window.addEventListener('resize', _posicionarPill);

    // Sem colapso — pill fica visível até o fechamento

    // O heartbeat cuida de atualizar o countdown e remover quando fechar
  }

  // ── Auto-recarga da página ───────────────────────────────────────────
  function agendarAutoreload() {
    clearTimeout(_autoreloadTimer);
    const min = parseInt(cfg.totem_autoreload_minutos || '0');
    if (!min) return;

    _autoreloadTimer = setTimeout(() => {
      // Só recarregar se o totem estiver na tela inicial (não no meio de um pedido)
      if (s.screen === 'welcome' || s.screen === 'fechado') {
        location.reload();
      } else {
        // Se estiver em uso, reagendar para 2 minutos depois
        cfg.totem_autoreload_minutos = '2';
        agendarAutoreload();
        cfg.totem_autoreload_minutos = String(min);
      }
    }, min * 60000);
  }

  function go(screen, patch) {
    clearInterval(countdownTimer);
    clearInterval(_pollTimer);
    if (patch) Object.assign(s, patch);
    s.screen = screen;
    app.style.transition = 'opacity 0.12s ease';
    app.style.opacity = '0';
    setTimeout(() => { render(); app.style.opacity = '1'; }, 120);
    resetIdle();
  }

  function render() {
    switch (s.screen) {
      case 'fechado':    renderFechado();    break;
      case 'welcome':    renderWelcome();    break;
      case 'tipo':       renderTipo();       break;
      case 'menu':       renderMenu();       break;
      case 'cart':       renderCart();       break;
      case 'cpf':        renderCPF();        break;
      case 'payment':    renderPayment();    break;
      case 'processing':       renderProcessing();     break;
      case 'awaiting-payment': renderAwaitingPayment(); break;
      case 'confirmed':        renderConfirmed();       break;
    }
  }

  function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

  function skeletonCards(n) {
    return Array.from({length: n}, () => '<div class="skeleton-card"></div>').join('');
  }

  // --- FECHADO ---
  function renderFechado() {
    const prox = proximaAbertura();
    const dias   = ['dom','seg','ter','qua','qui','sex','sab'];
    const nomes  = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
    const hoje   = new Date().getDay();
    const loja   = esc(cfg.loja_nome || 'Café Comunhão');

    const horariosHtml = dias.map((d, idx) => {
      const ativo    = cfg['horario_'+d+'_ativo'] !== '0';
      const abertura = cfg['horario_'+d+'_abertura']  || '—';
      const fech     = cfg['horario_'+d+'_fechamento'] || '—';
      const isHoje   = idx === hoje;
      return '<div style="display:flex;flex-direction:column;align-items:center;gap:4px;' +
        'padding:10px 12px;border-radius:10px;min-width:60px;' +
        (isHoje ? 'background:rgba(255,85,0,.15);border:1px solid rgba(255,85,0,.3)' : 'background:rgba(255,255,255,.04)') + '">' +
        '<span style="font-size:11px;font-weight:700;color:' + (isHoje ? '#ff7733' : '#6b7280') + '">' + nomes[idx] + '</span>' +
        (ativo
          ? '<span style="font-size:12px;color:#f0f2f8;font-weight:600">' + abertura + '</span>' +
            '<span style="font-size:10px;color:#6b7280">até ' + fech + '</span>'
          : '<span style="font-size:12px;color:#4b5563">Fechado</span>'
        ) +
      '</div>';
    }).join('');

    app.innerHTML =
      '<div class="screen" style="display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        'min-height:100vh;background:radial-gradient(ellipse at 50% 40%,rgba(15,23,42,.9) 0%,#0a0c14 100%);' +
        'text-align:center;padding:40px;gap:0">' +

        // Ícone animado
        '<div style="font-size:72px;margin-bottom:16px;filter:grayscale(.4)">🔒</div>' +

        // Nome da loja
        '<h1 style="font-size:clamp(28px,5vw,48px);font-weight:900;color:#f0f2f8;margin-bottom:8px">' + loja + '</h1>' +

        // Mensagem principal
        '<p style="font-size:clamp(18px,3vw,26px);font-weight:700;color:#ff5500;margin-bottom:6px">Estamos fechados agora</p>' +
        '<p style="font-size:14px;color:#9ca3af;margin-bottom:32px">' +
          (prox ? 'Próxima abertura: <strong style="color:#f0f2f8">' + prox + '</strong>' : 'Em breve!') +
        '</p>' +

        // Horários da semana
        '<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:40px">' +
          horariosHtml +
        '</div>' +

        // Relógio
        '<div id="clock-fechado" style="font-size:clamp(36px,6vw,64px);font-weight:900;color:#374151;' +
          'letter-spacing:4px;font-variant-numeric:tabular-nums"></div>' +

      '</div>';

    // Relógio inicial (o heartbeat atualiza a cada 30s)
    const elClock = document.getElementById('clock-fechado');
    if (elClock) elClock.textContent = new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
  }

  // --- WELCOME ---
  function renderWelcome() {
    const nomeLoja = esc(cfg.loja_nome);
    app.innerHTML =
      '<div class="screen welcome">' +
        '<div class="welcome-bg"><div class="bg-orb orb-1"></div><div class="bg-orb orb-2"></div><div class="bg-orb orb-3"></div></div>' +
        '<div class="welcome-body">' +
          '<div class="welcome-logo">' +
            '<div class="logo-icon">' + (cfg.loja_logo_url
              ? '<img src="' + esc(cfg.loja_logo_url) + '" alt="Logo" style="width:80px;height:80px;object-fit:contain">'
              : '<svg width="80" height="80" viewBox="0 0 80 80" fill="none"><circle cx="40" cy="40" r="40" fill="rgba(255,85,0,0.15)"/><text x="40" y="54" font-size="40" text-anchor="middle" font-family="serif">&#x2615;</text></svg>') +
            '</div>' +
            '<h1 class="logo-name">' + nomeLoja + '</h1>' +
            '<p class="logo-sub">' + (cfg.totem_mensagem_boasvindas || 'Peça, pague e aguarde seu número') + '</p>' +
          '</div>' +
          '<button class="btn-start" data-action="go-tipo">Toque para começar</button>' +
          '<div class="welcome-clock" id="clock"></div>' +
        '</div>' +
        '<div class="welcome-footer"><a href="admin/" class="admin-link">Painel Administrativo</a></div>' +
      '</div>';
    startClock();
  }

  function startClock() {
    const tick = () => {
      const el = document.getElementById('clock');
      if (!el) return;
      el.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      setTimeout(tick, 15000);
    };
    tick();
  }

  // --- TIPO ---
  function renderTipo() {
    app.innerHTML =
      '<div class="screen tipo">' +
        '<div class="screen-header"><button class="btn-back" data-action="go-welcome">← Voltar</button><h2>Início</h2></div>' +
        '<div class="tipo-content">' +
          '<h2 class="tipo-title">Como deseja consumir?</h2>' +
          '<div class="tipo-cards">' +
            '<button class="tipo-card" data-action="select-tipo" data-tipo="local"><div class="tipo-icon">🍽️</div><h3>Comer aqui</h3><p>Consumo no restaurante</p></button>' +
            '<button class="tipo-card" data-action="select-tipo" data-tipo="viagem"><div class="tipo-icon">🛍️</div><h3>Para viagem</h3><p>Levar para fora</p></button>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  // --- MENU ---
  function renderMenu() {
    const nomeLoja = esc(cfg.loja_nome || 'Cardápio');
    app.innerHTML =
      '<div class="screen menu">' +
        // Header full-width
        '<header class="menu-header">' +
          '<div class="header-brand"><span class="brand-name">' + nomeLoja + '</span></div>' +
          '<button class="cart-btn" id="btn-cart" data-action="go-cart" disabled>' +
            '<span class="cart-icon-wrap">🛒<span class="cart-badge" id="cart-count">0</span></span>' +
            '<span id="cart-total">' + fmt(0) + '</span>' +
          '</button>' +
        '</header>' +
        // Sidebar de categorias (esquerda, vertical)
        '<nav class="cats-nav" id="cats-nav">' + skeletonCats() + '</nav>' +
        // Área de produtos (direita)
        '<div class="products-area" id="products-area"><div class="loading-placeholder">' + skeletonCards(6) + '</div></div>' +
        // Footer full-width
        '<footer class="menu-footer">' +
          '<button class="btn-finalizar" id="btn-cart-footer" data-action="go-cart" disabled>' +
            '<span>🛒</span><span id="footer-cart-info">Seu carrinho está vazio</span><span class="btn-finalizar-arrow">→</span>' +
          '</button>' +
        '</footer>' +
      '</div>';
    loadMenu();
  }

  function skeletonCats() {
    return Array.from({length: 5}, () =>
      '<div style="height:44px;background:var(--card2);border-radius:12px;animation:shimmer 1.4s infinite"></div>'
    ).join('');
  }

  async function loadMenu() {
    if (!s.categorias.length) {
      const r = await api('categorias.php');
      if (r.success) s.categorias = r.data;
    }
    renderCats();
    if (!s.categoriaId && s.categorias.length) s.categoriaId = s.categorias[0].id;
    if (s.categoriaId) await loadProdutos(s.categoriaId);
    updateCartBar();
  }

  function renderCats() {
    const nav = document.getElementById('cats-nav');
    if (!nav) return;
    // Botões verticais na sidebar
    nav.innerHTML = s.categorias.map(c =>
      '<button class="cat-pill' + (c.id === s.categoriaId ? ' active' : '') + '" data-action="select-cat" data-id="' + c.id + '">' +
        '<span class="cat-icon">' + (c.icone || '🍽️') + '</span>' +
        '<span class="cat-name">' + esc(c.nome) + '</span>' +
      '</button>'
    ).join('');
  }

  async function loadProdutos(catId) {
    const area = document.getElementById('products-area');
    if (!s.produtos[catId]) {
      if (area) area.innerHTML = '<div class="loading-placeholder">' + skeletonCards(6) + '</div>';
      const r = await api('produtos.php?categoria_id=' + catId);
      if (r.success) s.produtos[catId] = r.data;
    }
    renderProdutos(catId, true); // animar ao trocar de categoria
  }

  function renderProdutos(catId, animate) {
    const area = document.getElementById('products-area');
    if (!area) return;
    const list = s.produtos[catId] || [];

    if (!list.length) {
      area.innerHTML = '<div class="empty-cat"><p>Nenhum produto nesta categoria</p></div>';
      return;
    }

    area.innerHTML = '<div class="products-grid' + (animate ? ' animate-in' : '') + '">' + list.map(function(p) {
      const inCart = s.cart.find(i => i.id === p.id);
      const qty    = inCart ? inCart.quantidade : 0;
      const icon   = catIcon(catId);

      const imgTag = p.imagem
        ? '<img src="' + esc(p.imagem) + '" alt="' + esc(p.nome) + '" loading="lazy" onerror="this.outerHTML=\'<span class=\\\'product-emoji\\\'>' + icon + '</span>\'">'
        : '<span class="product-emoji">' + icon + '</span>';

      // Alerta de estoque baixo
      const estoqueBaixo = p.controlar_estoque && parseInt(p.estoque_qtd) <= 0;
      const estoquePoucas = p.controlar_estoque && parseInt(p.estoque_qtd) > 0 && parseInt(p.estoque_qtd) <= 5;

      if (estoqueBaixo) {
        return '<div class="product-card esgotado" data-id="' + p.id + '">' +
          '<div class="product-img" style="background:' + cardColor(p.id) + ';opacity:.5">' + imgTag + '</div>' +
          '<div class="product-info">' +
            '<h3 class="product-name">' + esc(p.nome) + '</h3>' +
            '<span style="color:var(--red,#ef4444);font-size:12px;font-weight:600">Esgotado</span>' +
          '</div>' +
        '</div>';
      }

      const addAttrs  = 'data-action="add-item" data-id="' + p.id + '" data-nome="' + esc(p.nome) + '" data-preco="' + p.preco + '" data-cat="' + catId + '" data-imagem="' + esc(p.imagem||'') + '"';
      const viewAttrs = 'data-action="view-product" data-id="' + p.id + '" data-cat="' + catId + '"';

      return '<div class="product-card' + (p.destaque ? ' destaque' : '') + '" data-id="' + p.id + '">' +
        (p.destaque ? '<div class="badge-destaque">Destaque</div>' : '') +
        (estoquePoucas ? '<div class="badge-estoque">Últimas ' + p.estoque_qtd + '</div>' : '') +
        // Imagem abre detalhes; + adiciona ao carrinho
        '<div class="product-img" style="background:' + cardColor(p.id) + '" ' + viewAttrs + '>' + imgTag + '</div>' +
        '<div class="product-info">' +
          '<h3 class="product-name" ' + viewAttrs + '>' + esc(p.nome) + '</h3>' +
          (p.descricao ? '<p class="product-desc">' + esc(p.descricao) + '</p>' : '') +
          '<div class="product-footer">' +
            '<span class="product-price">' + fmt(p.preco) + '</span>' +
            '<div class="qty-controls' + (qty > 0 ? ' visible' : '') + '">' +
              '<button class="qty-btn" data-action="remove-item" data-id="' + p.id + '" data-cat="' + catId + '">−</button>' +
              '<span class="qty-num">' + qty + '</span>' +
              '<button class="qty-btn add" ' + addAttrs + '>+</button>' +
            '</div>' +
            '<button class="btn-add' + (qty > 0 ? ' hidden' : '') + '" ' + addAttrs + '>+</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('') + '</div>';
  }

  // --- CART ---
  function renderCart() {
    const items = s.cart;
    app.innerHTML =
      '<div class="screen cart">' +
        '<div class="screen-header"><button class="btn-back" data-action="go-menu">← Continuar comprando</button><h2>Meu Pedido</h2></div>' +
        '<div class="cart-body">' +
          (items.length === 0
            ? '<div class="cart-empty"><div class="cart-empty-icon">🛒</div><p>Seu carrinho está vazio</p><button class="btn-primary" data-action="go-menu">Ver cardápio</button></div>'
            : '<div class="cart-list">' +
                items.map((it, idx) =>
                  '<div class="cart-item">' +
                    (it.imagem
                      ? '<img class="cart-item-img" src="' + esc(it.imagem) + '" alt="' + esc(it.nome) + '" onerror="this.style.display=\'none\'">'
                      : '<div class="cart-item-img" style="background:' + cardColor(it.id) + '">' + (it.icone || '🍽️') + '</div>') +
                    '<div class="cart-item-details"><h4>' + esc(it.nome || 'Produto') + '</h4><span class="cart-item-price">' + fmt(it.preco) + ' / un.</span></div>' +
                    '<div class="cart-item-controls">' +
                      '<button class="qty-btn" data-action="cart-remove" data-id="' + it.id + '">−</button>' +
                      '<span class="qty-num">' + it.quantidade + '</span>' +
                      '<button class="qty-btn add" data-action="cart-add" data-id="' + it.id + '" data-nome="' + esc(it.nome) + '" data-preco="' + it.preco + '" data-icone="' + (it.icone||'') + '" data-imagem="' + esc(it.imagem||'') + '">+</button>' +
                      '<span class="cart-item-subtotal">' + fmt(it.preco * it.quantidade) + '</span>' +
                    '</div>' +
                    '<div class="cart-item-obs">' +
                      '<input type="text" class="obs-input" placeholder="📝 Observação (ex: sem açúcar)" ' +
                        'maxlength="80" value="' + esc(it.obs || '') + '" data-action="obs-change" data-idx="' + idx + '">' +
                    '</div>' +
                  '</div>'
                ).join('') +
              '</div>' +
              '<div class="cart-summary">' +
                '<div class="summary-row"><span>Subtotal (' + cartCount() + ' ' + (cartCount() === 1 ? 'item' : 'itens') + ')</span><span>' + fmt(cartTotal()) + '</span></div>' +
                '<div class="summary-row total"><span>Total</span><strong>' + fmt(cartTotal()) + '</strong></div>' +
              '</div>'
          ) +
        '</div>' +
        (items.length ? '<div class="cart-footer"><button class="btn-finalizar full" data-action="go-cpf">Finalizar pedido · ' + fmt(cartTotal()) + '</button></div>' : '') +
      '</div>';

    // Bind obs inputs diretamente (não via data-action do click handler)
    document.querySelectorAll('.obs-input').forEach(input => {
      input.addEventListener('input', e => {
        const idx = parseInt(e.target.dataset.idx);
        if (s.cart[idx]) s.cart[idx].obs = e.target.value;
      });
      input.addEventListener('click', e => e.stopPropagation());
    });
  }

  // --- CPF ---
  function renderCPF() {
    const raw  = s.cpfInput;
    const disp = raw.length ? fmtCPF(raw) : 'Digite seu CPF';

    app.innerHTML =
      '<div class="screen cpf">' +
        '<div class="screen-header"><button class="btn-back" data-action="go-cart">← Voltar</button><h2>CPF na nota</h2></div>' +
        '<div class="cpf-body">' +
          '<p class="cpf-subtitle">Deseja incluir CPF no comprovante?</p>' +
          '<div class="cpf-display">' + disp + '</div>' +
          '<div class="numpad">' +
            [1,2,3,4,5,6,7,8,9,'⌫',0,'✓'].map(k =>
              '<button class="numpad-key' + (k==='✓'?' confirm':k==='⌫'?' delete':'') + '" data-action="cpf-key" data-key="' + k + '">' + k + '</button>'
            ).join('') +
          '</div>' +
          '<button class="btn-skip" data-action="skip-cpf">Pular — sem CPF na nota</button>' +
        '</div>' +
      '</div>';

    if (raw.length === 11) setTimeout(() => go('payment'), 250);
  }

  // --- PAYMENT ---
  function renderPayment() {
    const allMethods = [
      { id:'pix',      label:'PIX',      icon:'📱', desc:'Rápido, gratuito e instantâneo', cfgKey:'pagamento_pix_ativo' },
      { id:'credito',  label:'Crédito',  icon:'💳', desc:'Visa, Mastercard, Elo',          cfgKey:'pagamento_credito_ativo' },
      { id:'debito',   label:'Débito',   icon:'💳', desc:'Débito em conta',                 cfgKey:'pagamento_debito_ativo' },
      { id:'dinheiro', label:'Dinheiro', icon:'💵', desc:'Pague no caixa',                  cfgKey:'pagamento_dinheiro_ativo' },
    ];
    // Filtrar apenas métodos habilitados nas configurações
    const methods = allMethods.filter(m => cfg[m.cfgKey] !== '0');

    app.innerHTML =
      '<div class="screen payment">' +
        '<div class="screen-header"><button class="btn-back" data-action="go-cpf">← Voltar</button><h2>Forma de pagamento</h2></div>' +
        '<div class="payment-body">' +
          (function() {
            const sub  = cartTotal();
            const taxa = cfg.taxa_servico_ativa === '1' ? sub * parseFloat(cfg.taxa_servico_percentual||0) / 100 : 0;
            const tot  = sub + taxa;
            return '<div class="payment-total">' +
              '<span>Subtotal</span><strong>' + fmt(sub) + '</strong>' +
              (taxa > 0 ? '<span style="font-size:13px;color:#9ca3af">Taxa de serviço (' + cfg.taxa_servico_percentual + '%)</span><strong style="font-size:15px">' + fmt(taxa) + '</strong>' : '') +
              '<span style="font-weight:800">Total a pagar</span><strong style="font-size:22px">' + fmt(tot) + '</strong>' +
            '</div>';
          })() +
          '<div class="payment-methods">' +
            methods.map(m =>
              '<button class="payment-method' + (s.pagamento===m.id?' selected':'') + '" data-action="select-payment" data-method="' + m.id + '">' +
                '<span class="method-icon">' + m.icon + '</span>' +
                '<div class="method-info"><strong>' + m.label + '</strong><span>' + m.desc + '</span></div>' +
                '<span class="method-check">' + (s.pagamento===m.id?'✓':'') + '</span>' +
              '</button>'
            ).join('') +
          '</div>' +
          (s.pagamento ? '<button class="btn-finalizar full" data-action="confirm-order">Confirmar pagamento →</button>' : '') +
        '</div>' +
      '</div>';
  }

  // --- PROCESSING ---
  function renderProcessing() {
    app.innerHTML =
      '<div class="screen processing">' +
        '<div class="processing-content">' +
          '<div class="processing-spinner"></div>' +
          '<h2 id="proc-msg">Confirmando pagamento...</h2>' +
          '<p>Aguarde um momento</p>' +
        '</div>' +
      '</div>';
    submitOrder();
  }

  async function submitOrder() {
    const setMsg = t => { const el = document.getElementById('proc-msg'); if (el) el.textContent = t; };
    await delay(1400);
    setMsg('Processando pedido...');
    // Dinheiro vai direto para cozinha; PIX/cartão aguardam confirmação de pagamento
    const pagamentoEletronico = ['pix', 'credito', 'debito'].includes(s.pagamento);
    try {
      const res = await api('pedido.php', {
        method: 'POST',
        body: JSON.stringify({
          tipo_consumo:         s.tipo,
          cpf:                  s.cpfInput || null,
          forma_pagamento:      s.pagamento,
          aguardando_pagamento: pagamentoEletronico,
          itens: s.cart.map(i => ({ id: i.id, quantidade: i.quantidade, obs: i.obs || '' })),
        }),
      });
      if (res.success) {
        s.pedido = res.pedido;
        s.cart = [];
        s.cpfInput = '';
        s.pagamento = null;
        await delay(600);
        // PHP confirms whether the order is actually awaiting payment
        const isAwaitingPayment = res.pedido.status === 'aguardando_pagamento';
        go(isAwaitingPayment ? 'awaiting-payment' : 'confirmed');
      } else {
        renderError(res.error || 'Erro ao processar pedido.');
      }
    } catch(e) {
      renderError('Falha na conexão. Verifique a rede e tente novamente.');
    }
  }

  function renderError(msg) {
    app.innerHTML =
      '<div class="screen error-screen">' +
        '<div class="error-content">' +
          '<div class="error-icon">⚠️</div>' +
          '<h2>Algo deu errado</h2>' +
          '<p>' + esc(msg) + '</p>' +
          '<button class="btn-primary" data-action="go-payment">Tentar novamente</button>' +
          '<button class="btn-secondary" data-action="go-welcome" style="margin-top:12px">Cancelar pedido</button>' +
        '</div>' +
      '</div>';
  }

  // --- AGUARDANDO PAGAMENTO ---
  function renderAwaitingPayment() {
    const p      = s.pedido;
    const isPix  = p.forma_pagamento === 'pix';
    const isCard = p.forma_pagamento === 'credito' || p.forma_pagamento === 'debito';

    const cardBlock = isCard
      ? '<div class="awp-card-block">' +
          '<div class="awp-card-icon">💳</div>' +
          '<p class="awp-card-title">Conclua o pagamento na maquininha</p>' +
          '<p class="awp-card-hint">Aproxime, insira ou passe seu cartão no terminal ao lado</p>' +
        '</div>'
      : '';

    const pixBlock = isPix
      ? '<div class="pix-qr-block">' +
          '<div class="pix-qr-label">📱 Pague com PIX</div>' +
          '<div id="pix-qr-canvas" class="pix-qr-canvas"><div class="pix-qr-loading">Gerando QR Code...</div></div>' +
          '<div class="pix-qr-total">' + fmt(p.total) + '</div>' +
          '<p class="pix-qr-hint">Escaneie o código no app do seu banco</p>' +
        '</div>'
      : '';

    app.innerHTML =
      '<div class="screen awaiting-payment">' +
        '<div class="awp-content">' +
          '<div class="awp-order-num">' +
            '<span class="awp-order-label">Nº do seu pedido</span>' +
            '<span class="awp-order-value">#' + p.numero + '</span>' +
          '</div>' +
          pixBlock +
          cardBlock +
          '<div class="awp-status-msg">' +
            '<div class="awp-spinner"></div>' +
            '<span id="awp-status-text">Aguardando confirmação do pagamento...</span>' +
          '</div>' +
          '<p class="awp-hint-small">Após a aprovação do pagamento, seu comprovante será gerado automaticamente</p>' +
        '</div>' +
      '</div>';

    if (isPix) loadPixQr(p);

    // Poll for payment confirmation
    pollPaymentConfirmation(p.id);
  }

  let _pollTimer = null;
  async function pollPaymentConfirmation(pedidoId) {
    clearInterval(_pollTimer);
    let tries = 0;
    const maxTries = 120; // 4 min polling
    _pollTimer = setInterval(async () => {
      tries++;
      if (tries > maxTries) {
        clearInterval(_pollTimer);
        const el = document.getElementById('awp-status-text');
        if (el) el.textContent = 'Tempo esgotado. Fale com o atendente.';
        return;
      }
      try {
        const res = await api('pedido_status.php?id=' + pedidoId);
        if (res.success && res.status && res.status !== 'aguardando_pagamento') {
          clearInterval(_pollTimer);
          // Payment confirmed — go to confirmed screen and auto-print
          s.autoPrint = true;
          go('confirmed');
        }
      } catch { /* keep polling */ }
    }, 2000);
  }

  // --- CONFIRMED ---
  function renderConfirmed() {
    const p    = s.pedido;
    let   secs = CONFIRM_SECS;
    const isPix = p.forma_pagamento === 'pix';
    const pgLbl = { pix:'PIX', credito:'Crédito', debito:'Débito', dinheiro:'Dinheiro' }[p.forma_pagamento] || p.forma_pagamento;
    const tpLbl = p.tipo_consumo === 'local' ? 'Comer aqui' : 'Para viagem';

    const pixBlock = isPix
      ? '<div class="pix-qr-block">' +
          '<div class="pix-qr-label">📱 Pague com PIX</div>' +
          '<div id="pix-qr-canvas" class="pix-qr-canvas"><div class="pix-qr-loading">Gerando QR Code...</div></div>' +
          '<div class="pix-qr-total">' + fmt(p.total) + '</div>' +
          '<p class="pix-qr-hint">Escaneie o código acima no app do seu banco</p>' +
        '</div>'
      : '';

    app.innerHTML =
      '<div class="screen confirmed">' +
        '<div class="confirmed-content">' +
          '<div class="confirmed-icon">✅</div>' +
          '<h1 class="confirmed-title">Pedido confirmado!</h1>' +
          '<div class="order-number-box"><span class="order-label">Seu número</span><span class="order-number">#' + p.numero + '</span></div>' +
          pixBlock +
          '<div class="confirmed-details">' +
            '<div class="detail-row"><span>' + tpLbl + '</span><span>' + pgLbl + '</span></div>' +
            '<div class="detail-row"><span>' + p.criado_em + '</span>' + (p.cpf ? '<span>CPF: ' + p.cpf + '</span>' : '<span style="color:var(--t3)">Sem CPF</span>') + '</div>' +
            '<div class="detail-row total-row"><strong>Total pago</strong><strong>' + fmt(p.total) + '</strong></div>' +
          '</div>' +
          '<p class="wait-msg">Aguarde ser chamado pelo número <strong>#' + p.numero + '</strong></p>' +
          '<button class="btn-print" data-action="print-receipt">Imprimir comprovante</button>' +
          '<div class="confirmed-countdown">Voltando ao início em <span id="cdown">' + secs + 's</span></div>' +
        '</div>' +
      '</div>';

    if (isPix) loadPixQr(p);
    buildReceipt();

    // Auto-print after payment confirmation (cashier approved)
    if (s.autoPrint) {
      s.autoPrint = false;
      setTimeout(doPrint, 800); // small delay to let receipt render
    }

    clearInterval(countdownTimer);
    countdownTimer = setInterval(() => {
      secs--;
      const el = document.getElementById('cdown');
      if (el) el.textContent = secs + 's';
      if (secs <= 0) { clearInterval(countdownTimer); goWelcomeOrFechado(); }
    }, 1000);
  }

  // --- PIX QR CODE ---
  async function loadPixQr(pedido) {
    const container = document.getElementById('pix-qr-canvas');
    if (!container) return;
    try {
      const ref = String(pedido.numero || pedido.id || '0');
      const res = await fetch('api/pix.php?total=' + encodeURIComponent(pedido.total) + '&ref=' + encodeURIComponent(ref));
      const data = await res.json();
      if (!data.success) {
        container.innerHTML = '<p style="color:var(--t3);font-size:13px">' + (data.error || 'PIX não configurado') + '</p>';
        return;
      }
      container.innerHTML = '';
      // Use qrcodejs library (loaded via CDN in index.php)
      if (typeof QRCode !== 'undefined') {
        new QRCode(container, {
          text:          data.payload,
          width:         220,
          height:        220,
          colorDark:     '#000000',
          colorLight:    '#ffffff',
          correctLevel:  QRCode.CorrectLevel.M,
        });
      } else {
        // Fallback: show raw payload (rare, for debugging)
        container.innerHTML = '<p style="font-size:9px;word-break:break-all;color:var(--t3)">' + data.payload + '</p>';
      }
    } catch (err) {
      if (container) container.innerHTML = '<p style="color:var(--t3);font-size:13px">Erro ao gerar QR Code</p>';
    }
  }

  // --- RECEIPT ---
  function buildReceipt() {
    const p = s.pedido;
    if (!p || !receipt) return;

    const nomeLoja = (cfg.loja_nome || 'Café Comunhão').toUpperCase();
    const pgFmt = {
      pix:      'Pagamento Instantâneo (PIX)',
      credito:  'Cartão de Crédito',
      debito:   'Cartão de Débito',
      dinheiro: 'Dinheiro',
    }[p.forma_pagamento] || p.forma_pagamento;

    const totalItens = (p.itens || []).reduce((acc, i) => acc + (i.quantidade || 1), 0);

    const itemRows = (p.itens || []).map((it, idx) => {
      const unit = parseFloat(it.preco_unitario) || (parseFloat(it.subtotal) / Math.max(1, it.quantidade || 1));
      const seq  = String(idx + 1).padStart(3, '0');
      return '<tr>' +
        '<td class="rc-seq">' + seq + '</td>' +
        '<td class="rc-nome">' + esc(it.nome_produto || it.nome || '') +
          (it.obs ? '<div class="rc-obs">📝 ' + esc(it.obs) + '</div>' : '') +
        '</td>' +
        '<td class="rc-qty">' + (it.quantidade || 1) + ' UN</td>' +
        '<td class="rc-unit">R$ ' + unit.toFixed(2).replace('.', ',') + '</td>' +
        '<td class="rc-sub">R$ ' + parseFloat(it.subtotal || 0).toFixed(2).replace('.', ',') + '</td>' +
      '</tr>';
    }).join('');

    const statusUrl = p.status_url || (window.location.origin + '/totem/status/?p=' + encodeURIComponent(p.numero));

    receipt.innerHTML =
      '<div class="receipt">' +

        '<div class="receipt-header">' +
          '<h2>' + esc(nomeLoja) + '</h2>' +
          (cfg.loja_cnpj     ? '<p>CNPJ: ' + esc(cfg.loja_cnpj)     + '</p>' : '') +
          (cfg.loja_endereco ? '<p>'         + esc(cfg.loja_endereco) + '</p>' : '') +
          (cfg.loja_telefone ? '<p>Tel: '    + esc(cfg.loja_telefone) + '</p>' : '') +
        '</div>' +

        '<div class="receipt-badge">CUPOM NÃO FISCAL</div>' +
        '<hr class="receipt-divider">' +

        '<table class="receipt-meta-tbl">' +
          '<tr><td>Pedido</td><td><strong>#' + esc(p.numero) + '</strong></td></tr>' +
          '<tr><td>Data</td><td>' + esc(p.criado_em) + '</td></tr>' +
          '<tr><td>Consumo</td><td>' + (p.tipo_consumo === 'local' ? 'COMER AQUI' : 'PARA VIAGEM') + '</td></tr>' +
          (p.cpf ? '<tr><td>CPF</td><td>' + esc(p.cpf) + '</td></tr>' : '') +
        '</table>' +
        '<hr class="receipt-divider">' +

        '<table class="receipt-items">' +
          '<thead><tr>' +
            '<th>#</th><th class="rc-nome-h">Descrição</th><th>Qtd</th><th>Vl Unit</th><th>Total</th>' +
          '</tr></thead>' +
          '<tbody>' + itemRows + '</tbody>' +
        '</table>' +
        '<hr class="receipt-divider">' +

        '<table class="receipt-totals-tbl">' +
          '<tr><td>QTD. TOTAL DE ITENS</td><td class="rc-right"><strong>' + totalItens + '</strong></td></tr>' +
          '<tr class="rc-total-row"><td>VALOR TOTAL R$</td><td class="rc-right"><strong>' + fmt(p.total) + '</strong></td></tr>' +
        '</table>' +
        '<hr class="receipt-divider">' +

        '<div class="receipt-payment">' +
          '<p class="rc-pgto-label">FORMA DE PAGAMENTO</p>' +
          '<table class="rc-pgto-tbl">' +
            '<tr><td>' + esc(pgFmt) + '</td><td class="rc-right">Valor Pago</td></tr>' +
            '<tr><td></td><td class="rc-right"><strong>' + fmt(p.total) + '</strong></td></tr>' +
          '</table>' +
        '</div>' +
        '<hr class="receipt-divider">' +

        '<div class="receipt-qr-block">' +
          '<p class="rc-qr-label">Acompanhe seu pedido pelo celular:</p>' +
          '<div id="receipt-qr-canvas"></div>' +
          '<p class="rc-qr-url">' + esc(statusUrl) + '</p>' +
        '</div>' +
        '<hr class="receipt-divider">' +

        '<div class="receipt-footer">' +
          '<p>Obrigado pela preferência!</p>' +
          '<p>Volte sempre!</p>' +
        '</div>' +

      '</div>';

    try {
      const qrEl = receipt.querySelector('#receipt-qr-canvas');
      if (qrEl && typeof QRCode !== 'undefined') {
        new QRCode(qrEl, { text: statusUrl, width: 110, height: 110, correctLevel: QRCode.CorrectLevel.M });
      }
    } catch (_) {}
  }

  async function doPrint() {
    const p = s.pedido;
    if (!p) return;
    // Try thermal printer first
    try {
      const res = await fetch('api/imprimir.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ pedido_id: p.id }),
      }).then(r => r.json());
      if (res.success) return; // printed via ESC/POS
      // If impressora not active, fall through to browser print
      if (!res.error?.includes('não está ativa')) return;
    } catch { /* network error — fallback below */ }
    // Browser print fallback (receipt div)
    if (!receipt) return;
    receipt.classList.remove('hidden');
    window.print();
    setTimeout(() => receipt.classList.add('hidden'), 500);
  }

  // --- DETALHE DO PRODUTO ---
  async function openProductDetail(prod, catId) {
    if (document.getElementById('modal-produto')) return;

    const icon = catIcon(catId);
    const imgTag = prod.imagem
      ? '<img src="' + esc(prod.imagem) + '" alt="' + esc(prod.nome) + '" onerror="this.outerHTML=\'<span class=\\\'product-emoji\\\'>' + icon + '</span>\'">'
      : '<span class="product-emoji">' + icon + '</span>';

    const inCart = s.cart.find(i => i.id === prod.id);
    const qty    = inCart ? inCart.quantidade : 0;

    const modal = document.createElement('div');
    modal.id = 'modal-produto';
    modal.innerHTML =
      '<div class="modal-produto-sheet">' +
        '<div class="modal-produto-img" style="background:' + cardColor(prod.id) + '">' + imgTag + '</div>' +
        '<div class="modal-produto-body">' +
          '<div>' +
            '<div class="modal-produto-titulo">' + esc(prod.nome) + '</div>' +
            '<div class="modal-produto-preco">' + fmt(prod.preco) + '</div>' +
          '</div>' +
          (prod.descricao ? '<div class="modal-produto-desc">' + esc(prod.descricao) + '</div>' : '') +
          '<div class="modal-produto-ingredientes" id="mp-ing">' +
            '<div class="modal-prod-ing-title">Ingredientes</div>' +
            '<div class="modal-prod-ing-list" id="mp-ing-list">' +
              '<div style="color:var(--t3);font-size:13px">Carregando...</div>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="modal-produto-footer">' +
          '<button class="modal-btn-fechar" id="mp-fechar">← Voltar</button>' +
          '<button class="modal-btn-adicionar" id="mp-adicionar">' +
            (qty > 0 ? '✓ No carrinho (' + qty + ')  +' : '+ Adicionar ao carrinho') +
          '</button>' +
        '</div>' +
      '</div>';

    app.appendChild(modal);

    // Fechar ao clicar no fundo ou no botão voltar
    const fechar = () => { modal.style.animation = 'modalBgIn .2s ease reverse both'; setTimeout(() => modal.remove(), 200); };
    modal.addEventListener('click', e => { if (e.target === modal) fechar(); });
    document.getElementById('mp-fechar').addEventListener('click', fechar);

    // Adicionar ao carrinho
    document.getElementById('mp-adicionar').addEventListener('click', () => {
      addItem({ id: prod.id, nome: prod.nome, preco: prod.preco, icone: icon, imagem: prod.imagem || null });
      updateCartBar();
      renderProdutos(catId, false);
      fechar();
    });

    // Buscar ficha técnica (ingredientes)
    try {
      const r = await api('estoque.php?action=ficha&produto_id=' + prod.id);
      const ingList = document.getElementById('mp-ing-list');
      if (!ingList) return;
      if (r.success && r.data && r.data.length) {
        ingList.innerHTML = r.data.map(f =>
          '<div class="modal-prod-ing-item">' +
            '<span class="modal-prod-ing-nome">' + esc(f.insumo_nome) + '</span>' +
            '<span class="modal-prod-ing-qty">' + parseFloat(f.quantidade).toFixed(3) + ' ' + esc(f.unidade) + '</span>' +
          '</div>'
        ).join('');
      } else {
        document.getElementById('mp-ing')?.remove(); // sem ficha, esconder seção
      }
    } catch { document.getElementById('mp-ing')?.remove(); }
  }

  // --- ACTIONS ---
  function handle(action, ds) {
    resetIdle();
    switch (action) {
      case 'go-welcome':      s.autoPrint = false; goWelcomeOrFechado(); break;
      case 'go-tipo':         go('tipo'); break;
      case 'go-menu':         go('menu'); break;
      case 'go-cart':         if (cartCount() > 0) go('cart'); break;
      case 'go-cpf':          go('cpf'); break;
      case 'go-payment':      go('payment'); break;
      case 'select-tipo':     go('menu', { tipo: ds.tipo }); break;
      case 'select-cat':
        s.categoriaId = parseInt(ds.id);
        renderCats();
        loadProdutos(s.categoriaId);
        break;
      case 'view-product': {
        const vpId  = parseInt(ds.id);
        const vpCat = parseInt(ds.cat);
        const vprod = (s.produtos[vpCat] || []).find(p => p.id === vpId);
        if (vprod) openProductDetail(vprod, vpCat);
        break;
      }
      case 'add-item': {
        const addId = parseInt(ds.id);
        const cooledAt = _removeCooldown.get(addId);
        // Ignorar clique de add se o produto foi zerado há menos de 3s
        if (cooledAt && Date.now() - cooledAt < 1000) break;
        _removeCooldown.delete(addId);
        addItem({ id: addId, nome: ds.nome, preco: parseFloat(ds.preco), icone: catIcon(parseInt(ds.cat)), imagem: ds.imagem || null });
        renderProdutos(s.categoriaId);
        break;
      }
      case 'remove-item': {
        const remId = parseInt(ds.id);
        const before = s.cart.find(i => i.id === remId);
        changeQty(remId, -1);
        // Se o item acabou de ser zerado, iniciar cooldown de 3s
        if (before && before.quantidade === 1) {
          _removeCooldown.set(remId, Date.now());
          setTimeout(() => _removeCooldown.delete(remId), 1000);
        }
        renderProdutos(s.categoriaId);
        updateCartBar();
        break;
      }
      case 'cart-add':
        addItem({ id: parseInt(ds.id), nome: ds.nome, preco: parseFloat(ds.preco), icone: ds.icone, imagem: ds.imagem || null });
        renderCart();
        break;
      case 'cart-remove':
        changeQty(parseInt(ds.id), -1);
        renderCart();
        break;
      case 'skip-cpf':        s.cpfInput = ''; go('payment'); break;
      case 'cpf-key':         handleCPFKey(ds.key); break;
      case 'select-payment':  s.pagamento = ds.method; renderPayment(); break;
      case 'confirm-order':   go('processing'); break;
      case 'print-receipt':   doPrint(); break;
    }
  }

  function handleCPFKey(key) {
    if (key === '⌫') s.cpfInput = s.cpfInput.slice(0, -1);
    else if (key === '✓') { if (!s.cpfInput || s.cpfInput.length === 11) go('payment'); }
    else if (s.cpfInput.length < 11) s.cpfInput += key;
    renderCPF();
  }

  async function init() {
    await loadConfig();

    document.addEventListener('click', e => {
      // Tela fechada não responde a nenhum toque
      if (s.screen === 'fechado') return;
      const el = e.target.closest('[data-action]');
      if (!el) return;
      handle(el.dataset.action, el.dataset);
    });
    document.addEventListener('touchstart', resetIdle, { passive: true });

    // Verificar horário e exibir tela correta
    goWelcomeOrFechado();

    // O heartbeat (definido acima) cuida de tudo mais
    agendarAutoreload();
  }

  return { init };
})();

document.addEventListener('DOMContentLoaded', () => Totem.init());

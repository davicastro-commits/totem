'use strict';

const Totem = (() => {

  let cfg = {
    loja_nome:               'Café Comunhão',
    loja_cnpj:               '',
    loja_endereco:           '',
    loja_telefone:           '',
    loja_url:                '',
    totem_idle_segundos:     '120',
    totem_confirmar_segundos:'30',
  };

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
      const r = await api('configuracoes.php');
      if (r.success && r.data) {
        cfg = { ...cfg, ...r.data };
        IDLE_MS      = parseInt(cfg.totem_idle_segundos || '120') * 1000;
        CONFIRM_SECS = parseInt(cfg.totem_confirmar_segundos || '30');
      }
    } catch (_) {}
  }

  function cartCount() { return s.cart.reduce((n, i) => n + i.quantidade, 0); }
  function cartTotal() { return s.cart.reduce((n, i) => n + i.preco * i.quantidade, 0); }

  function addItem(prod) {
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

  function resetIdle() {
    clearTimeout(idleTimer);
    if (s.screen !== 'welcome') idleTimer = setTimeout(() => { s.autoPrint = false; go('welcome'); }, IDLE_MS);
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
            '<p class="logo-sub">Peça, pague e aguarde seu número</p>' +
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
    const nomeLoja = esc(cfg.loja_nome);
    app.innerHTML =
      '<div class="screen menu">' +
        '<header class="menu-header">' +
          '<div class="header-brand"><span class="brand-name">' + nomeLoja + '</span></div>' +
          '<button class="cart-btn" id="btn-cart" data-action="go-cart" disabled>' +
            '<span class="cart-icon-wrap">🛒<span class="cart-badge" id="cart-count">0</span></span>' +
            '<span id="cart-total">' + fmt(0) + '</span>' +
          '</button>' +
        '</header>' +
        '<nav class="cats-nav" id="cats-nav"><div style="display:flex;gap:10px;padding:0 4px">' + skeletonCats() + '</div></nav>' +
        '<div class="products-area" id="products-area"><div class="loading-placeholder">' + skeletonCards(6) + '</div></div>' +
        '<footer class="menu-footer">' +
          '<button class="btn-finalizar" id="btn-cart-footer" data-action="go-cart" disabled>' +
            '<span>🛒</span><span id="footer-cart-info">Seu carrinho está vazio</span><span class="btn-finalizar-arrow">→</span>' +
          '</button>' +
        '</footer>' +
      '</div>';
    loadMenu();
  }

  function skeletonCats() {
    return Array.from({length:4}, () =>
      '<div style="height:44px;width:100px;background:var(--c-card);border-radius:999px;flex-shrink:0;animation:shimmer 1.4s infinite"></div>'
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
    nav.innerHTML = s.categorias.map(c =>
      '<button class="cat-pill' + (c.id === s.categoriaId ? ' active' : '') + '" data-action="select-cat" data-id="' + c.id + '">' +
        '<span class="cat-icon">' + c.icone + '</span>' +
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
    renderProdutos(catId);
  }

  function renderProdutos(catId) {
    const area = document.getElementById('products-area');
    if (!area) return;
    const list = s.produtos[catId] || [];

    if (!list.length) {
      area.innerHTML = '<div class="empty-cat"><p>Nenhum produto nesta categoria</p></div>';
      return;
    }

    area.innerHTML = '<div class="products-grid">' + list.map(function(p) {
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
            '<span style="color:var(--c-red,#ef4444);font-size:12px;font-weight:600">Esgotado</span>' +
          '</div>' +
        '</div>';
      }

      return '<div class="product-card' + (p.destaque ? ' destaque' : '') + '" data-id="' + p.id + '">' +
        (p.destaque ? '<div class="badge-destaque">Destaque</div>' : '') +
        (estoquePoucas ? '<div class="badge-estoque">Últimas ' + p.estoque_qtd + '</div>' : '') +
        '<div class="product-img" style="background:' + cardColor(p.id) + '">' + imgTag + '</div>' +
        '<div class="product-info">' +
          '<h3 class="product-name">' + esc(p.nome) + '</h3>' +
          (p.descricao ? '<p class="product-desc">' + esc(p.descricao) + '</p>' : '') +
          '<div class="product-footer">' +
            '<span class="product-price">' + fmt(p.preco) + '</span>' +
            '<div class="qty-controls' + (qty > 0 ? ' visible' : '') + '">' +
              '<button class="qty-btn" data-action="remove-item" data-id="' + p.id + '" data-cat="' + catId + '">−</button>' +
              '<span class="qty-num">' + qty + '</span>' +
              '<button class="qty-btn add" data-action="add-item" data-id="' + p.id + '" data-nome="' + esc(p.nome) + '" data-preco="' + p.preco + '" data-cat="' + catId + '" data-imagem="' + esc(p.imagem||'') + '">+</button>' +
            '</div>' +
            (qty === 0
              ? '<button class="btn-add" data-action="add-item" data-id="' + p.id + '" data-nome="' + esc(p.nome) + '" data-preco="' + p.preco + '" data-cat="' + catId + '" data-imagem="' + esc(p.imagem||'') + '">Adicionar +</button>'
              : '') +
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
    const methods = [
      { id:'pix',      label:'PIX',     icon:'📱', desc:'Rápido, gratuito e instantâneo' },
      { id:'credito',  label:'Crédito', icon:'💳', desc:'Visa, Mastercard, Elo' },
      { id:'debito',   label:'Débito',  icon:'💳', desc:'Débito em conta' },
      { id:'dinheiro', label:'Dinheiro', icon:'💵', desc:'Pague no caixa' },
    ];

    app.innerHTML =
      '<div class="screen payment">' +
        '<div class="screen-header"><button class="btn-back" data-action="go-cpf">← Voltar</button><h2>Forma de pagamento</h2></div>' +
        '<div class="payment-body">' +
          '<div class="payment-total"><span>Total a pagar</span><strong>' + fmt(cartTotal()) + '</strong></div>' +
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
            '<div class="detail-row"><span>' + p.criado_em + '</span>' + (p.cpf ? '<span>CPF: ' + p.cpf + '</span>' : '<span style="color:var(--c-text-3)">Sem CPF</span>') + '</div>' +
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
      if (secs <= 0) { clearInterval(countdownTimer); go('welcome'); }
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
        container.innerHTML = '<p style="color:var(--c-text-3);font-size:13px">' + (data.error || 'PIX não configurado') + '</p>';
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
        container.innerHTML = '<p style="font-size:9px;word-break:break-all;color:var(--c-text-3)">' + data.payload + '</p>';
      }
    } catch (err) {
      if (container) container.innerHTML = '<p style="color:var(--c-text-3);font-size:13px">Erro ao gerar QR Code</p>';
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

  // --- ACTIONS ---
  function handle(action, ds) {
    resetIdle();
    switch (action) {
      case 'go-welcome':      s.autoPrint = false; go('welcome'); break;
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
      case 'add-item':
        addItem({ id: parseInt(ds.id), nome: ds.nome, preco: parseFloat(ds.preco), icone: catIcon(parseInt(ds.cat)), imagem: ds.imagem || null });
        renderProdutos(s.categoriaId);
        break;
      case 'remove-item':
        changeQty(parseInt(ds.id), -1);
        renderProdutos(s.categoriaId);
        updateCartBar();
        break;
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
      const el = e.target.closest('[data-action]');
      if (!el) return;
      handle(el.dataset.action, el.dataset);
    });
    document.addEventListener('touchstart', resetIdle, { passive: true });
    go('welcome');
  }

  return { init };
})();

document.addEventListener('DOMContentLoaded', () => Totem.init());

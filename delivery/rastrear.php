<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
require_once '../config/db.php';

$entregaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pedidoId  = filter_input(INPUT_GET, 'pedido', FILTER_VALIDATE_INT);

$entrega = null;
$pedido  = null;
$endereco = null;

if ($entregaId || $pedidoId) {
    try {
        $db = getDB();

        if ($entregaId) {
            $stmt = $db->prepare("
                SELECT e.*,
                       p.numero_pedido, p.total, p.forma_pagamento,
                       p.criado_em AS pedido_criado_em,
                       en.logradouro, en.numero AS end_numero, en.complemento,
                       en.bairro, en.cidade, en.uf, en.referencia, en.cep
                  FROM totem_entregas e
                  JOIN totem_pedidos p ON p.id = e.pedido_id
                  LEFT JOIN totem_enderecos_entrega en ON en.id = e.endereco_id
                 WHERE e.id = ?
            ");
            $stmt->execute([$entregaId]);
        } else {
            $stmt = $db->prepare("
                SELECT e.*,
                       p.numero_pedido, p.total, p.forma_pagamento,
                       p.criado_em AS pedido_criado_em,
                       en.logradouro, en.numero AS end_numero, en.complemento,
                       en.bairro, en.cidade, en.uf, en.referencia, en.cep
                  FROM totem_entregas e
                  JOIN totem_pedidos p ON p.id = e.pedido_id
                  LEFT JOIN totem_enderecos_entrega en ON en.id = e.endereco_id
                 WHERE e.pedido_id = ?
                 ORDER BY e.id DESC LIMIT 1
            ");
            $stmt->execute([$pedidoId]);
        }
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {}
}

$active = $entrega && !in_array($entrega['status'] ?? '', ['entregue', 'cancelado']);

$steps = [
    ['key'=>'recebido',  'label'=>'Recebido',    'icon'=>'📋'],
    ['key'=>'preparo',   'label'=>'Em Preparo',   'icon'=>'👨‍🍳'],
    ['key'=>'saiu',      'label'=>'Saiu p/ Entrega','icon'=>'🛵'],
    ['key'=>'entregue',  'label'=>'Entregue',     'icon'=>'✅'],
];
$statusIdx = -1;
$statusColors = ['recebido'=>'#3b82f6','preparo'=>'#f97316','saiu'=>'#22c55e','entregue'=>'#a855f7','cancelado'=>'#ef4444'];
if ($entrega) {
    foreach ($steps as $i => $s) { if ($s['key'] === $entrega['status']) $statusIdx = $i; }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rastreio de Entrega<?= $entrega ? ' — Pedido #'.htmlspecialchars($entrega['numero_pedido']??'') : '' ?></title>
<?php if ($active): ?>
<meta http-equiv="refresh" content="15">
<?php endif; ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#0f1117;color:#e2e8f0;font-family:'Inter',system-ui,sans-serif;min-height:100vh}
.container{max-width:480px;margin:0 auto;padding:20px 16px}
.logo{text-align:center;padding:20px 0;font-size:22px;font-weight:900;color:#ff5500;margin-bottom:8px}
.logo span{color:#fff}
.card{background:#161924;border-radius:16px;padding:20px;margin-bottom:16px}
.pedido-num{font-size:32px;font-weight:900;text-align:center;color:#fff;margin-bottom:4px}
.pedido-hora{font-size:13px;color:#6b7280;text-align:center;margin-bottom:16px}

/* Status atual */
.status-atual{text-align:center;padding:20px 0}
.status-icone{font-size:56px;margin-bottom:8px}
.status-nome{font-size:22px;font-weight:800}
.status-desc{font-size:13px;color:#94a3b8;margin-top:4px}

/* Progress steps */
.steps{display:flex;align-items:flex-start;justify-content:space-between;margin:24px 0;position:relative}
.steps::before{content:'';position:absolute;top:20px;left:10%;right:10%;height:3px;background:#1e2535;z-index:0}
.step{display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;position:relative;z-index:1}
.step-circle{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;border:2px solid #1e2535;background:#0f1117;transition:all .3s}
.step-circle.done{background:#ff5500;border-color:#ff5500}
.step-circle.active{background:#ff5500;border-color:#ff5500;box-shadow:0 0 0 4px rgba(255,85,0,.25)}
.step-label{font-size:11px;color:#6b7280;text-align:center}
.step-label.done,.step-label.active{color:#e2e8f0;font-weight:600}

/* Previsão */
.previsao{background:#1e2535;border-radius:10px;padding:14px;text-align:center;margin:8px 0}
.previsao-num{font-size:36px;font-weight:900;color:#ff5500}
.previsao-label{font-size:12px;color:#94a3b8}

/* Entregador */
.entregador{display:flex;align-items:center;gap:12px;padding:12px;background:#1e2535;border-radius:10px;margin:8px 0}
.ent-icon{font-size:32px}
.ent-info{}
.ent-nome{font-weight:700}
.ent-tel{font-size:13px;color:#94a3b8}
.ent-tel a{color:#ff5500}

/* Endereço */
.endereco-row{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #1e2535}
.endereco-row:last-child{border:none}
.end-key{font-size:12px;color:#6b7280;width:90px;flex-shrink:0}
.end-val{font-size:14px}

/* Resumo */
.resumo-row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px;border-bottom:1px solid #1e2535}
.resumo-row:last-child{border:none;font-weight:800;font-size:16px}

/* Cancelado */
.cancelado-banner{background:#3d1f1f;border:1px solid #7f1d1d;border-radius:10px;padding:16px;text-align:center;color:#f87171}
.cancelado-icon{font-size:48px;margin-bottom:8px}

/* Form manual */
.form-card{background:#161924;border-radius:16px;padding:24px;max-width:400px;margin:40px auto}
.form-card h2{font-size:18px;font-weight:700;margin-bottom:16px;text-align:center}
.form-input{width:100%;padding:14px;background:#0f1117;border:2px solid #1e2535;border-radius:10px;color:#e2e8f0;font-size:18px;text-align:center;font-family:inherit;letter-spacing:4px;font-weight:700}
.form-input:focus{outline:none;border-color:#ff5500}
.btn{width:100%;padding:14px;background:#ff5500;color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;margin-top:12px;font-family:inherit}
.btn:hover{background:#e04400}
.hint{font-size:12px;color:#6b7280;text-align:center;margin-top:8px}

.refreshing{font-size:11px;color:#4b5563;text-align:center;margin-top:12px}
</style>
</head>
<body>
<div class="container">

  <div class="logo">☕ <span>Café Comunhão</span></div>

<?php if (!$entrega): ?>

  <?php if ($entregaId || $pedidoId): ?>
  <div class="card" style="text-align:center;padding:32px">
    <div style="font-size:48px;margin-bottom:12px">🔍</div>
    <div style="font-size:16px;font-weight:700;margin-bottom:6px">Entrega não encontrada</div>
    <div style="font-size:13px;color:#6b7280">Verifique o número do pedido</div>
  </div>
  <?php endif; ?>

  <div class="form-card">
    <h2>Rastrear Entrega</h2>
    <form method="GET" action="">
      <input class="form-input" type="number" name="pedido" placeholder="Nº do Pedido" min="1" required autofocus>
      <button type="submit" class="btn">Rastrear</button>
    </form>
    <p class="hint">Digite o número do pedido impresso na nota ou enviado por WhatsApp</p>
  </div>

<?php elseif ($entrega['status'] === 'cancelado'): ?>

  <div class="card">
    <div class="pedido-num">Pedido #<?= htmlspecialchars($entrega['numero_pedido']??'') ?></div>
    <div class="cancelado-banner">
      <div class="cancelado-icon">❌</div>
      <div style="font-size:18px;font-weight:700">Entrega Cancelada</div>
    </div>
  </div>

<?php else: ?>

  <?php
  $statusAtual = $entrega['status'] ?? 'recebido';
  $statusColor = $statusColors[$statusAtual] ?? '#ff5500';
  $statusDescs = [
    'recebido' => 'Pedido recebido, aguardando preparo',
    'preparo'  => 'Seu pedido está sendo preparado',
    'saiu'     => 'Entregador a caminho!',
    'entregue' => 'Pedido entregue com sucesso',
  ];
  $statusIcons = ['recebido'=>'📋','preparo'=>'👨‍🍳','saiu'=>'🛵','entregue'=>'✅'];
  ?>

  <div class="card">
    <div class="pedido-num">Pedido #<?= htmlspecialchars($entrega['numero_pedido']??'') ?></div>
    <?php if ($entrega['pedido_criado_em']): ?>
    <div class="pedido-hora"><?= (new DateTime($entrega['pedido_criado_em']))->format('d/m/Y \à\s H:i') ?></div>
    <?php endif; ?>

    <div class="status-atual">
      <div class="status-icone"><?= $statusIcons[$statusAtual] ?? '📦' ?></div>
      <div class="status-nome" style="color:<?= $statusColor ?>"><?= $steps[$statusIdx]['label'] ?? htmlspecialchars($statusAtual) ?></div>
      <div class="status-desc"><?= $statusDescs[$statusAtual] ?? '' ?></div>
    </div>

    <!-- Steps -->
    <div class="steps">
      <?php foreach ($steps as $i => $step): ?>
      <?php $cls = $i < $statusIdx ? 'done' : ($i === $statusIdx ? 'active' : ''); ?>
      <div class="step">
        <div class="step-circle <?= $cls ?>"><?= $step['icon'] ?></div>
        <span class="step-label <?= $cls ?>"><?= $step['label'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($statusAtual === 'saiu' && ($entrega['previsao_min'] ?? 0) > 0): ?>
    <div class="previsao">
      <div class="previsao-num"><?= (int)$entrega['previsao_min'] ?> min</div>
      <div class="previsao-label">Previsão de entrega</div>
    </div>
    <?php endif; ?>

    <?php if (!empty($entrega['entregador_nome'])): ?>
    <div class="entregador">
      <div class="ent-icon">🏍️</div>
      <div class="ent-info">
        <div class="ent-nome"><?= htmlspecialchars($entrega['entregador_nome']) ?></div>
        <?php if (!empty($entrega['entregador_telefone'])): ?>
        <div class="ent-tel">
          <a href="tel:<?= htmlspecialchars($entrega['entregador_telefone']) ?>">
            <?= htmlspecialchars($entrega['entregador_telefone']) ?>
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($entrega['logradouro'] || $entrega['bairro']): ?>
  <div class="card">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin-bottom:12px">Endereço de Entrega</div>
    <?php if ($entrega['logradouro']): ?>
    <div class="endereco-row">
      <span class="end-key">Rua</span>
      <span class="end-val"><?= htmlspecialchars($entrega['logradouro']) ?>, <?= htmlspecialchars($entrega['end_numero']??'') ?></span>
    </div>
    <?php endif; ?>
    <?php if ($entrega['complemento']): ?>
    <div class="endereco-row">
      <span class="end-key">Complemento</span>
      <span class="end-val"><?= htmlspecialchars($entrega['complemento']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($entrega['bairro']): ?>
    <div class="endereco-row">
      <span class="end-key">Bairro</span>
      <span class="end-val"><?= htmlspecialchars($entrega['bairro']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($entrega['referencia']): ?>
    <div class="endereco-row">
      <span class="end-key">Referência</span>
      <span class="end-val"><?= htmlspecialchars($entrega['referencia']) ?></span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin-bottom:12px">Resumo do Pedido</div>
    <div class="resumo-row"><span>Subtotal</span><span>R$ <?= number_format((float)($entrega['total']??0) - (float)($entrega['taxa_entrega']??0), 2, ',', '.') ?></span></div>
    <div class="resumo-row"><span>Taxa de entrega</span><span>R$ <?= number_format((float)($entrega['taxa_entrega']??0), 2, ',', '.') ?></span></div>
    <div class="resumo-row"><span>Total</span><span>R$ <?= number_format((float)($entrega['total']??0), 2, ',', '.') ?></span></div>
    <?php if ($entrega['forma_pagamento']): ?>
    <div class="resumo-row"><span>Pagamento</span><span><?= ucfirst(htmlspecialchars($entrega['forma_pagamento'])) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($active): ?>
  <p class="refreshing">↺ Atualizando automaticamente a cada 15 segundos</p>
  <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>

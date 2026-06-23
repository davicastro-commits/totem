<?php
/**
 * Página pública de rastreio de pedido.
 * URL: /totem/status/?p=0001
 *
 * O cliente escaneia o QR Code na nota e vê o status em tempo real.
 */
declare(strict_types=1);

require_once '../config/db.php';

$numero = preg_replace('/[^A-Za-z0-9]/', '', $_GET['p'] ?? '');
$pedido = null;
$itens  = [];

try {
    $db = getDB();

    if ($numero) {
        $stmt = $db->prepare("
            SELECT id, numero_pedido, status, tipo_consumo, forma_pagamento,
                   total, criado_em, concluido_em
              FROM totem_pedidos
             WHERE numero_pedido = ?
               AND DATE(criado_em) = CURRENT_DATE
             ORDER BY id DESC
             LIMIT 1
        ");
        $stmt->execute([$numero]);
        $pedido = $stmt->fetch();

        if ($pedido) {
            $si = $db->prepare("SELECT nome_produto, quantidade, preco_unitario, subtotal, obs FROM totem_itens_pedido WHERE pedido_id = ? ORDER BY id");
            $si->execute([$pedido['id']]);
            $itens = $si->fetchAll();
        }
    }

    $cfg = $db->query("SELECT chave, valor FROM totem_configuracoes WHERE chave IN ('loja_nome','loja_cnpj','loja_endereco','loja_telefone')")
               ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    $cfg = [];
}

$nomeLoja = htmlspecialchars($cfg['loja_nome']    ?? 'Café Comunhão');
$cnpj     = htmlspecialchars($cfg['loja_cnpj']    ?? '');
$ender    = htmlspecialchars($cfg['loja_endereco'] ?? '');
$tel      = htmlspecialchars($cfg['loja_telefone'] ?? '');

$statusInfo = [
    'aguardando_pagamento' => ['label' => 'Aguardando pagamento',  'icon' => '💳', 'color' => '#f59e0b', 'pct' => 10],
    'aguardando'           => ['label' => 'Pedido recebido',       'icon' => '✅', 'color' => '#3b82f6', 'pct' => 33],
    'preparando'           => ['label' => 'Em preparo na cozinha', 'icon' => '👨‍🍳', 'color' => '#f97316', 'pct' => 66],
    'pronto'               => ['label' => 'Pronto! Retire agora',  'icon' => '🔔', 'color' => '#22c55e', 'pct' => 100],
    'entregue'             => ['label' => 'Entregue ✓',            'icon' => '🎉', 'color' => '#22c55e', 'pct' => 100],
    'cancelado'            => ['label' => 'Cancelado',             'icon' => '❌', 'color' => '#ef4444', 'pct' => 0],
];
$st   = $pedido ? ($statusInfo[$pedido['status']] ?? ['label' => $pedido['status'], 'icon' => '⏳', 'color' => '#6b7280', 'pct' => 50]) : null;
$pgLb = ['pix' => 'PIX', 'credito' => 'Crédito', 'debito' => 'Débito', 'dinheiro' => 'Dinheiro'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pedido #<?= htmlspecialchars($numero ?: '—') ?> — <?= $nomeLoja ?></title>
<?php if ($pedido && !in_array($pedido['status'], ['entregue','cancelado'])): ?>
<meta http-equiv="refresh" content="8">
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#0f1117;color:#f0f2f8;font-family:'Inter',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:24px 16px}
.card{background:#161924;border:1px solid rgba(255,255,255,.07);border-radius:20px;width:100%;max-width:460px;overflow:hidden}
.card-header{background:#ff5500;padding:20px 24px;text-align:center}
.card-header .loja{font-size:22px;font-weight:900;color:#fff}
.card-header .sub{font-size:12px;color:rgba(255,255,255,.8);margin-top:2px}
.pedido-num{padding:20px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,.07)}
.pedido-num .label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#6b7280}
.pedido-num .num{font-size:52px;font-weight:900;color:#ff5500;line-height:1}
.status-block{padding:20px 24px;border-bottom:1px solid rgba(255,255,255,.07)}
.status-icon{font-size:36px;text-align:center;margin-bottom:8px}
.status-label{font-size:18px;font-weight:800;text-align:center;margin-bottom:12px}
.progress-bar{height:8px;background:rgba(255,255,255,.1);border-radius:999px;overflow:hidden}
.progress-fill{height:100%;border-radius:999px;transition:width 1s ease}
.status-steps{display:flex;justify-content:space-between;margin-top:8px}
.step{font-size:10px;color:#4b5563;text-align:center;flex:1}
.step.done{color:#22c55e}
.step.active{color:#f0f2f8;font-weight:700}
.items-block{padding:16px 24px;border-bottom:1px solid rgba(255,255,255,.07)}
.items-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin-bottom:10px}
.item-row{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:14px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.item-row:last-child{border-bottom:none}
.item-qty{font-size:12px;color:#6b7280;flex-shrink:0}
.item-nome{flex:1;color:#d1d5db}
.item-val{font-weight:700;flex-shrink:0}
.item-obs{font-size:11px;color:#6b7280;width:100%;padding:2px 0 4px 16px}
.total-row{display:flex;justify-content:space-between;padding:16px 24px;background:rgba(255,255,255,.03)}
.total-row span:first-child{color:#9ca3af}
.total-row span:last-child{font-size:20px;font-weight:900;color:#f0f2f8}
.pgto-row{padding:8px 24px 16px;text-align:center;font-size:13px;color:#6b7280}
.auto-refresh{padding:12px 24px;text-align:center;font-size:12px;color:#4b5563;border-top:1px solid rgba(255,255,255,.07)}
.not-found{padding:40px 24px;text-align:center;color:#6b7280}
.not-found .icon{font-size:64px;margin-bottom:16px}
.form-block{padding:20px 24px}
.form-block input{width:100%;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:14px 16px;color:#f0f2f8;font-size:20px;font-weight:700;text-align:center;text-transform:uppercase;letter-spacing:4px;outline:none;font-family:inherit}
.form-block button{width:100%;margin-top:10px;background:#ff5500;color:#fff;border:none;border-radius:10px;padding:14px;font-weight:700;font-size:16px;cursor:pointer;font-family:inherit}
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div class="loja"><?= $nomeLoja ?></div>
    <?php if ($cnpj): ?><div class="sub">CNPJ: <?= $cnpj ?></div><?php endif; ?>
    <?php if ($ender): ?><div class="sub"><?= $ender ?></div><?php endif; ?>
  </div>

  <?php if ($pedido && $st): ?>

    <div class="pedido-num">
      <div class="label">Seu pedido</div>
      <div class="num">#<?= htmlspecialchars($pedido['numero_pedido']) ?></div>
    </div>

    <div class="status-block">
      <div class="status-icon"><?= $st['icon'] ?></div>
      <div class="status-label" style="color:<?= $st['color'] ?>"><?= $st['label'] ?></div>
      <div class="progress-bar">
        <div class="progress-fill" style="width:<?= $st['pct'] ?>%;background:<?= $st['color'] ?>"></div>
      </div>
      <div class="status-steps">
        <?php
        $steps = ['✅ Recebido','👨‍🍳 Preparando','🔔 Pronto'];
        $pcts  = [33, 66, 100];
        foreach ($steps as $i => $sl):
            $done   = $st['pct'] >= $pcts[$i];
            $active = $st['pct'] > ($pcts[$i - 1] ?? 0) && $st['pct'] <= $pcts[$i];
            $cls    = $done ? 'done' : ($active ? 'active' : '');
        ?>
          <div class="step <?= $cls ?>"><?= $sl ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($itens): ?>
    <div class="items-block">
      <div class="items-title">Itens do pedido</div>
      <?php foreach ($itens as $it): ?>
        <div class="item-row">
          <span class="item-qty"><?= $it['quantidade'] ?>x</span>
          <span class="item-nome"><?= htmlspecialchars($it['nome_produto']) ?></span>
          <span class="item-val">R$ <?= number_format((float)$it['subtotal'], 2, ',', '.') ?></span>
        </div>
        <?php if ($it['obs']): ?>
          <div class="item-obs">📝 <?= htmlspecialchars($it['obs']) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="total-row">
      <span>Total</span>
      <span>R$ <?= number_format((float)$pedido['total'], 2, ',', '.') ?></span>
    </div>
    <div class="pgto-row">
      Pagamento: <?= $pgLb[$pedido['forma_pagamento']] ?? $pedido['forma_pagamento'] ?>
      · <?= $pedido['tipo_consumo'] === 'local' ? '🍽️ Comer aqui' : '🛍️ Para viagem' ?>
    </div>

    <?php if (!in_array($pedido['status'], ['entregue','cancelado'])): ?>
    <div class="auto-refresh">🔄 Atualiza automaticamente a cada 8 segundos</div>
    <?php endif; ?>

  <?php elseif ($numero): ?>

    <div class="not-found">
      <div class="icon">🔍</div>
      <p>Pedido <strong>#<?= htmlspecialchars($numero) ?></strong> não encontrado hoje.</p>
      <p style="margin-top:8px;font-size:13px">Verifique o número ou peça ajuda ao atendente.</p>
    </div>

  <?php else: ?>

    <div class="not-found">
      <div class="icon">📋</div>
      <p style="margin-bottom:16px">Digite o número do seu pedido:</p>
    </div>
    <div class="form-block">
      <form method="get">
        <input name="p" type="text" placeholder="0001" maxlength="10" autofocus>
        <button type="submit">Ver status →</button>
      </form>
    </div>

  <?php endif; ?>
</div>

</body>
</html>

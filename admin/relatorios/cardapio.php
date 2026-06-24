<?php
/**
 * Análise de cardápio — quadrante margem × volume
 * Classificação: Estrela / Potencial / Revisar Preço / Avaliar
 */

ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ../'); exit; }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
$csrfToken = csrfToken();
$adminNome = $_SESSION['admin_nome'] ?? '';

// ── Parâmetros ─────────────────────────────────────────────────────────────
$diasParam = (int)($_GET['dias'] ?? 30);
if ($diasParam < 1 || $diasParam > 365) $diasParam = 30;

// ── Buscar dados ───────────────────────────────────────────────────────────
$produtos   = [];
$erros      = [];
$medianaQtd = 0;

try {
    $db = getDB();

    // Faturamento e quantidade por produto no período
    $stmtProd = $db->prepare("
        SELECT
            pr.id,
            pr.nome,
            SUM(ip.quantidade)                        AS qtd_vendida,
            SUM(ip.quantidade * ip.preco_unitario)    AS receita
        FROM material.totem_itens_pedido ip
        JOIN material.totem_pedidos p   ON p.id  = ip.pedido_id
        JOIN material.totem_produtos pr ON pr.id = ip.produto_id
        WHERE p.criado_em >= CURRENT_DATE - CAST(? AS INTEGER) * INTERVAL '1 day'
          AND p.status NOT IN ('cancelado', 'aguardando_pagamento')
        GROUP BY pr.id, pr.nome
        HAVING SUM(ip.quantidade) > 0
        ORDER BY receita DESC
    ");
    $stmtProd->execute([$diasParam]);
    $rows = $stmtProd->fetchAll();

    // Custo via ficha técnica para cada produto
    $stmtCusto = $db->prepare("
        SELECT
            ft.produto_id,
            SUM(ft.quantidade * ins.custo_medio) AS custo_unitario
        FROM material.totem_ficha_tecnica ft
        JOIN material.totem_insumos ins ON ins.id = ft.insumo_id
        GROUP BY ft.produto_id
    ");
    $stmtCusto->execute();
    $custoMap = [];
    foreach ($stmtCusto->fetchAll() as $c) {
        $custoMap[(int)$c['produto_id']] = (float)$c['custo_unitario'];
    }

    foreach ($rows as $r) {
        $pid        = (int)$r['id'];
        $qtd        = (int)$r['qtd_vendida'];
        $receita    = (float)$r['receita'];
        $custoUnit  = $custoMap[$pid] ?? 0.0;
        $custoTotal = $custoUnit * $qtd;
        $precoMedio = $qtd > 0 ? $receita / $qtd : 0;
        $margemPct  = ($precoMedio > 0 && $custoUnit >= 0)
                      ? round((($precoMedio - $custoUnit) / $precoMedio) * 100, 1)
                      : null; // null = sem ficha técnica

        $produtos[] = [
            'id'          => $pid,
            'nome'        => $r['nome'],
            'qtd_vendida' => $qtd,
            'receita'     => $receita,
            'custo_unit'  => $custoUnit,
            'custo_total' => $custoTotal,
            'preco_medio' => $precoMedio,
            'margem_pct'  => $margemPct,
        ];
    }

    // Mediana de quantidade (só produtos com ficha técnica)
    $qtds = array_filter(array_map(
        fn($p) => $p['margem_pct'] !== null ? $p['qtd_vendida'] : null,
        $produtos
    ), fn($v) => $v !== null);
    sort($qtds);
    $n = count($qtds);
    $medianaQtd = $n > 0
        ? ($n % 2 === 0
            ? ($qtds[$n/2-1] + $qtds[$n/2]) / 2
            : $qtds[(int)($n/2)])
        : 0;

    // Classificar cada produto
    foreach ($produtos as &$p) {
        if ($p['margem_pct'] === null) {
            $p['avaliacao']  = 'sem_ficha';
            $p['avaliacao_label'] = 'Sem ficha técnica';
            $p['avaliacao_icon']  = '❓';
            $p['avaliacao_tip']   = 'Cadastre a ficha técnica para ver análise';
        } elseif ($p['margem_pct'] > 60 && $p['qtd_vendida'] > $medianaQtd) {
            $p['avaliacao']       = 'estrela';
            $p['avaliacao_label'] = 'Estrela';
            $p['avaliacao_icon']  = '🌟';
            $p['avaliacao_tip']   = 'Vende muito, margem alta';
        } elseif ($p['margem_pct'] > 60 && $p['qtd_vendida'] <= $medianaQtd) {
            $p['avaliacao']       = 'potencial';
            $p['avaliacao_label'] = 'Potencial';
            $p['avaliacao_icon']  = '💰';
            $p['avaliacao_tip']   = 'Alta margem, promover mais';
        } elseif ($p['margem_pct'] < 40 && $p['qtd_vendida'] > $medianaQtd) {
            $p['avaliacao']       = 'revisar';
            $p['avaliacao_label'] = 'Revisar preço';
            $p['avaliacao_icon']  = '⚠️';
            $p['avaliacao_tip']   = 'Vende muito mas margem baixa';
        } else {
            $p['avaliacao']       = 'avaliar';
            $p['avaliacao_label'] = 'Avaliar';
            $p['avaliacao_icon']  = '🗑️';
            $p['avaliacao_tip']   = 'Baixa margem e baixo volume';
        }
    }
    unset($p);

    // Ordenar: estrela → potencial → revisar → avaliar → sem_ficha, depois por margem DESC
    $ordem = ['estrela'=>0,'potencial'=>1,'revisar'=>2,'avaliar'=>3,'sem_ficha'=>4];
    usort($produtos, function($a, $b) use ($ordem) {
        $oa = $ordem[$a['avaliacao']] ?? 5;
        $ob = $ordem[$b['avaliacao']] ?? 5;
        if ($oa !== $ob) return $oa - $ob;
        return ($b['margem_pct'] ?? -999) <=> ($a['margem_pct'] ?? -999);
    });

} catch (Throwable $e) {
    $erros[] = $e->getMessage();
}

function brl(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<title>Análise de Cardápio — Café Comunhão</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f17;--surf:#13151e;--card:#1a1c27;--card2:#22253a;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --acc:#ff5500;--acc-l:#ff7733;--acc-gl:rgba(255,85,0,.12);
  --green:#22c55e;--red:#ef4444;--blue:#3b82f6;--gold:#f59e0b;--purple:#8b5cf6;
  --text:#f0f2f8;--text2:#9ca3af;--text3:#6b7280;--text4:#4b5563;
}
html,body{min-height:100vh;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

.topbar{display:flex;align-items:center;gap:14px;padding:0 24px;height:56px;background:var(--surf);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar a{color:var(--text3);font-size:13px;font-weight:500;text-decoration:none;padding:5px 10px;border-radius:7px;transition:all .15s}
.topbar a:hover{background:var(--card);color:var(--text)}
.topbar-title{font-size:16px;font-weight:800}
.topbar-badge{font-size:11px;font-weight:700;padding:3px 9px;background:var(--acc-gl);color:var(--acc);border:1px solid rgba(255,85,0,.25);border-radius:999px}
.topbar-right{margin-left:auto;font-size:13px;color:var(--text3)}

.main{max-width:1200px;margin:0 auto;padding:28px 24px 60px}

.card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:24px}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px}
.card-head h3{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);display:flex;align-items:center;gap:8px}

/* FILTRO */
.filtro-bar{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.filtro-bar label{font-size:13px;font-weight:600;color:var(--text2)}
select.dias-sel{background:var(--card);border:1px solid var(--border2);color:var(--text);border-radius:10px;padding:7px 12px;font-size:13px;font-family:inherit;cursor:pointer;outline:none}
select.dias-sel:focus{border-color:var(--acc)}
select.dias-sel option{background:var(--card)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-acc{background:var(--acc);color:#fff}
.btn-acc:hover{background:var(--acc-l)}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
@media(max-width:900px){.stats-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:500px){.stats-row{grid-template-columns:repeat(2,1fr)}}
.stat-box{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 18px}
.stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:6px}
.stat-value{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums}
.stat-sub{font-size:12px;color:var(--text4);margin-top:3px}

/* TABLE */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{text-align:left;padding:10px 16px;color:var(--text3);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);white-space:nowrap}
.tbl th.r,.tbl td.r{text-align:right}
.tbl td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.val{font-weight:700;color:var(--acc-l);font-variant-numeric:tabular-nums}

/* MARGEM BAR */
.mbar-wrap{display:flex;align-items:center;gap:8px}
.mbar-track{width:80px;height:6px;background:var(--card2);border-radius:3px;overflow:hidden;flex-shrink:0}
.mbar-fill{height:100%;border-radius:3px}
.mbar-fill.high{background:var(--green)}
.mbar-fill.mid {background:var(--gold)}
.mbar-fill.low {background:var(--red)}

/* BADGE AVALIAÇÃO */
.badge-aval{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;cursor:default}
.badge-estrela  {background:rgba(245,158,11,.15);color:var(--gold);border:1px solid rgba(245,158,11,.25)}
.badge-potencial{background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.2)}
.badge-revisar  {background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.badge-avaliar  {background:rgba(107,114,128,.1);color:var(--text3);border:1px solid var(--border2)}
.badge-sem_ficha{background:rgba(59,130,246,.1);color:var(--blue);border:1px solid rgba(59,130,246,.2)}

/* LEGENDA QUADRANTE */
.legenda{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px}
@media(max-width:640px){.legenda{grid-template-columns:1fr}}
.leg-item{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
.leg-title{font-size:13px;font-weight:700}
.leg-sub{font-size:12px;color:var(--text3)}

/* ALERT */
.alert-err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:12px;padding:14px 18px;color:var(--red);font-size:13px;margin-bottom:20px}

/* EMPTY */
.empty{padding:48px;text-align:center;color:var(--text3);font-size:14px}

/* TOOLTIP */
[title]{cursor:help}
</style>
</head>
<body>

<div class="topbar">
  <a href="../">← Admin</a>
  <a href="./index.php">Relatórios</a>
  <span class="topbar-title">Análise de Cardápio</span>
  <span class="topbar-badge">Rentabilidade</span>
  <div class="topbar-right"><?= htmlspecialchars($adminNome) ?></div>
</div>

<div class="main">

  <!-- FILTRO -->
  <form method="GET" class="filtro-bar">
    <label for="selDias">Período:</label>
    <select name="dias" id="selDias" class="dias-sel">
      <option value="7"  <?= $diasParam===7  ? 'selected' : '' ?>>Últimos 7 dias</option>
      <option value="14" <?= $diasParam===14 ? 'selected' : '' ?>>Últimos 14 dias</option>
      <option value="30" <?= $diasParam===30 ? 'selected' : '' ?>>Últimos 30 dias</option>
      <option value="60" <?= $diasParam===60 ? 'selected' : '' ?>>Últimos 60 dias</option>
      <option value="90" <?= $diasParam===90 ? 'selected' : '' ?>>Últimos 90 dias</option>
    </select>
    <button type="submit" class="btn btn-acc">Gerar análise</button>
    <?php if ($diasParam !== 30): ?>
      <a href="cardapio.php" class="btn" style="background:var(--card2);color:var(--text2);border:1px solid var(--border2)">Resetar</a>
    <?php endif; ?>
  </form>

  <?php if ($erros): ?>
  <div class="alert-err">⚠️ <?= htmlspecialchars(implode('; ', $erros)) ?></div>
  <?php endif; ?>

  <!-- LEGENDA DOS QUADRANTES -->
  <p class="section-label" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text4);margin-bottom:12px">
    Quadrantes de classificação (mediana de qtd vendida: <?= number_format($medianaQtd, 0, ',', '.') ?> un.)
  </p>
  <div class="legenda">
    <div class="leg-item" style="border-color:rgba(245,158,11,.2)">
      <div class="leg-title">🌟 Estrela — Margem &gt; 60% <em>e</em> Qtd &gt; mediana</div>
      <div class="leg-sub">Produto ideal: vende muito com boa margem. Priorize no cardápio.</div>
    </div>
    <div class="leg-item" style="border-color:rgba(34,197,94,.2)">
      <div class="leg-title">💰 Potencial — Margem &gt; 60% <em>e</em> Qtd ≤ mediana</div>
      <div class="leg-sub">Alta margem mas baixo volume. Invista em divulgação.</div>
    </div>
    <div class="leg-item" style="border-color:rgba(239,68,68,.2)">
      <div class="leg-title">⚠️ Revisar preço — Margem &lt; 40% <em>e</em> Qtd &gt; mediana</div>
      <div class="leg-sub">Vende bem mas corrói a margem. Renegocie insumos ou reajuste preço.</div>
    </div>
    <div class="leg-item">
      <div class="leg-title">🗑️ Avaliar — Margem &lt; 40% <em>e</em> Qtd ≤ mediana</div>
      <div class="leg-sub">Baixa margem e baixo volume. Considere remover do cardápio.</div>
    </div>
  </div>

  <?php
  // ── Stats resumo ──────────────────────────────────────────────────────
  $totalProd   = count($produtos);
  $totalReceita = array_sum(array_column($produtos, 'receita'));
  $totalCusto   = array_sum(array_column($produtos, 'custo_total'));
  $totalVendas  = array_sum(array_column($produtos, 'qtd_vendida'));
  $margemGeral  = $totalReceita > 0
      ? round((($totalReceita - $totalCusto) / $totalReceita) * 100, 1)
      : 0;

  $nEstrela   = count(array_filter($produtos, fn($p) => $p['avaliacao'] === 'estrela'));
  $nPotencial = count(array_filter($produtos, fn($p) => $p['avaliacao'] === 'potencial'));
  $nRevisar   = count(array_filter($produtos, fn($p) => $p['avaliacao'] === 'revisar'));
  $nAvaliar   = count(array_filter($produtos, fn($p) => $p['avaliacao'] === 'avaliar'));
  ?>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="stat-label">Produtos ativos</div>
      <div class="stat-value"><?= $totalProd ?></div>
      <div class="stat-sub"><?= $diasParam ?> dias</div>
    </div>
    <div class="stat-box">
      <div class="stat-label">Receita total</div>
      <div class="stat-value" style="color:var(--acc)"><?= brl($totalReceita) ?></div>
      <div class="stat-sub"><?= number_format($totalVendas, 0, ',', '.') ?> unidades</div>
    </div>
    <div class="stat-box">
      <div class="stat-label">Custo total</div>
      <div class="stat-value" style="color:var(--red)"><?= brl($totalCusto) ?></div>
      <div class="stat-sub">via ficha técnica</div>
    </div>
    <div class="stat-box">
      <div class="stat-label">Margem geral</div>
      <div class="stat-value" style="color:<?= $margemGeral >= 50 ? 'var(--green)' : ($margemGeral >= 30 ? 'var(--gold)' : 'var(--red)') ?>"><?= $margemGeral ?>%</div>
      <div class="stat-sub"><?= brl($totalReceita - $totalCusto) ?> lucro bruto</div>
    </div>
    <div class="stat-box">
      <div class="stat-label">Classificação</div>
      <div class="stat-value" style="font-size:14px;display:flex;flex-direction:column;gap:2px;font-weight:700">
        <span>🌟 <?= $nEstrela ?> &nbsp;💰 <?= $nPotencial ?></span>
        <span>⚠️ <?= $nRevisar ?> &nbsp;🗑️ <?= $nAvaliar ?></span>
      </div>
    </div>
  </div>

  <!-- TABELA PRINCIPAL -->
  <div class="card">
    <div class="card-head">
      <h3>📋 Produtos por rentabilidade</h3>
      <span style="font-size:12px;color:var(--text3)">Últimos <?= $diasParam ?> dias · ordenado por classificação</span>
    </div>
    <div style="overflow-x:auto">
      <?php if (!$produtos): ?>
        <div class="empty">Nenhuma venda encontrada no período selecionado.</div>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Produto</th>
            <th class="r">Vendidos</th>
            <th class="r">Receita</th>
            <th class="r">Custo total</th>
            <th class="r">Margem %</th>
            <th>Avaliação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($produtos as $i => $p): ?>
          <?php
            $m = $p['margem_pct'];
            $mClass = $m === null ? '' : ($m >= 60 ? 'high' : ($m >= 40 ? 'mid' : 'low'));
            $mWidth = $m !== null ? min(100, max(0, $m)) : 0;
            $mColor = $m === null ? 'var(--text4)' : ($m >= 60 ? 'var(--green)' : ($m >= 40 ? 'var(--gold)' : 'var(--red)'));
          ?>
          <tr>
            <td style="color:var(--text4);font-weight:700;font-size:12px"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($p['nome']) ?></td>
            <td class="r" style="font-variant-numeric:tabular-nums">
              <?= number_format($p['qtd_vendida'], 0, ',', '.') ?>
              <?php if ($p['qtd_vendida'] > $medianaQtd): ?>
                <span style="color:var(--green);font-size:10px;margin-left:3px">↑</span>
              <?php endif; ?>
            </td>
            <td class="r val"><?= brl($p['receita']) ?></td>
            <td class="r" style="color:var(--text3)">
              <?= $p['custo_total'] > 0 ? brl($p['custo_total']) : '<span style="color:var(--text4)">—</span>' ?>
            </td>
            <td class="r">
              <?php if ($m !== null): ?>
              <div class="mbar-wrap" style="justify-content:flex-end">
                <span style="font-weight:700;color:<?= $mColor ?>;font-variant-numeric:tabular-nums"><?= $m ?>%</span>
                <div class="mbar-track">
                  <div class="mbar-fill <?= $mClass ?>" style="width:<?= $mWidth ?>%"></div>
                </div>
              </div>
              <?php else: ?>
              <span style="color:var(--text4)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge-aval badge-<?= $p['avaliacao'] ?>" title="<?= htmlspecialchars($p['avaliacao_tip']) ?>">
                <?= $p['avaliacao_icon'] ?> <?= htmlspecialchars($p['avaliacao_label']) ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// Auto-submit ao mudar o select
document.getElementById('selDias').addEventListener('change', function(){
  this.closest('form').submit();
});
</script>
</body>
</html>

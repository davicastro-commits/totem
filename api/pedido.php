<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/db.php';
require_once '../config/audit.php';
require_once '../config/estoque_helper.php';
require_once '../config/fidelidade_helper.php';
require_once '../config/webhook.php';
require_once '../config/rate_limit_api.php';
rateLimit('pedido', 15, 60); // máx 15 pedidos/minuto por IP

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!$body || empty($body['itens'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$tipo      = in_array($body['tipo_consumo'] ?? '', ['local', 'viagem']) ? $body['tipo_consumo'] : 'local';
$pagamento = in_array($body['forma_pagamento'] ?? '', ['pix', 'credito', 'debito', 'dinheiro'])
    ? $body['forma_pagamento'] : 'pix';
$cpfRaw    = preg_replace('/\D/', '', $body['cpf'] ?? '');
$cpf       = strlen($cpfRaw) === 11 ? $cpfRaw : null;
$origem    = in_array($body['origem'] ?? '', ['totem','caixa','admin']) ? ($body['origem'] ?? 'totem') : 'totem';
$statusInicial = (!empty($body['aguardando_pagamento']) && $origem === 'totem')
    ? 'aguardando_pagamento' : 'aguardando';

// Carregar configurações relevantes para o pedido
try {
    $dbCfg    = getDB();
    $cfgStmt  = $dbCfg->query("
        SELECT chave, valor FROM totem_configuracoes
         WHERE chave IN (
            'pagamento_pix_ativo','pagamento_credito_ativo','pagamento_debito_ativo','pagamento_dinheiro_ativo',
            'taxa_servico_ativa','taxa_servico_percentual','totem_max_itens_pedido'
         )
    ");
    $cfgRows  = $cfgStmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable) { $cfgRows = []; }

// Validar método de pagamento habilitado
$pagToKey = ['pix'=>'pagamento_pix_ativo','credito'=>'pagamento_credito_ativo',
             'debito'=>'pagamento_debito_ativo','dinheiro'=>'pagamento_dinheiro_ativo'];
if (isset($pagToKey[$pagamento]) && ($cfgRows[$pagToKey[$pagamento]] ?? '1') === '0') {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>"Forma de pagamento '{$pagamento}' não está disponível no momento."]);
    exit;
}

// Validar máximo de itens por pedido
$maxItens = (int)($cfgRows['totem_max_itens_pedido'] ?? 20) ?: 20;
$totalItensCarrinho = array_sum(array_column($body['itens'], 'quantidade'));
if ($totalItensCarrinho > $maxItens) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>"Limite de {$maxItens} itens por pedido atingido."]);
    exit;
}

if ($cpf !== null && !validarCPF($cpf)) {
    $cpf = null;
}

function validarCPF(string $cpf): bool {
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += $cpf[$i] * ($t + 1 - $i);
        $r = ((10 * $sum) % 11) % 10;
        if ($cpf[$t] != $r) return false;
    }
    return true;
}

try {
    $db = getDB();
    $db->beginTransaction();

    $seqStmt = $db->query("
        SELECT COALESCE(MAX(CAST(numero_pedido AS INTEGER)), 0) + 1
          FROM totem_pedidos
         WHERE DATE(criado_em) = CURRENT_DATE
           AND numero_pedido ~ '^[0-9]+$'
    ");
    $seq    = (int)$seqStmt->fetchColumn();
    $numero = str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $stmtProd = $db->prepare(
        "SELECT id, nome, preco, controlar_estoque, estoque_qtd FROM totem_produtos WHERE id = ? AND disponivel = TRUE"
    );
    $itens = [];
    $total = 0.0;

    foreach ($body['itens'] as $item) {
        $id  = (int)($item['id'] ?? 0);
        $qty = max(1, (int)($item['quantidade'] ?? 1));
        $obs = trim(substr($item['obs'] ?? '', 0, 100));
        $stmtProd->execute([$id]);
        $prod = $stmtProd->fetch();
        if (!$prod) continue;

        if ($prod['controlar_estoque'] && (int)$prod['estoque_qtd'] < $qty) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'   => "Estoque insuficiente para '{$prod['nome']}'. Disponível: {$prod['estoque_qtd']}.",
            ]);
            exit;
        }

        $sub    = round($prod['preco'] * $qty, 2);
        $total += $sub;
        $itens[] = [
            'produto_id'        => $prod['id'],
            'nome_produto'      => $prod['nome'],
            'quantidade'        => $qty,
            'preco_unitario'    => (float)$prod['preco'],
            'subtotal'          => $sub,
            'obs'               => $obs,
            'controlar_estoque' => $prod['controlar_estoque'],
        ];
    }

    if (empty($itens)) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenhum item válido no pedido']);
        exit;
    }

    $operadorId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

    // Aplicar taxa de serviço se configurada
    $subtotal  = $total;
    $desconto  = 0.0;
    $taxaValor = 0.0;
    if (($cfgRows['taxa_servico_ativa'] ?? '0') === '1') {
        $taxaPct   = (float)($cfgRows['taxa_servico_percentual'] ?? 0);
        $taxaValor = round($subtotal * $taxaPct / 100, 2);
        $total     = round($subtotal + $taxaValor, 2);
    }

    $stmtPed = $db->prepare("
        INSERT INTO totem_pedidos
            (numero_pedido, tipo_consumo, cpf, subtotal, desconto, total, forma_pagamento, status, origem, operador_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmtPed->execute([$numero, $tipo, $cpf, $subtotal, $desconto, $total, $pagamento, $statusInicial, $origem, $operadorId]);
    $pedidoId = $stmtPed->fetchColumn();

    $stmtItem  = $db->prepare("
        INSERT INTO totem_itens_pedido (pedido_id, produto_id, nome_produto, quantidade, preco_unitario, subtotal, obs)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtStock = $db->prepare("
        UPDATE totem_produtos SET estoque_qtd = estoque_qtd - ? WHERE id = ? AND controlar_estoque = TRUE
    ");

    foreach ($itens as $it) {
        $stmtItem->execute([
            $pedidoId, $it['produto_id'], $it['nome_produto'],
            $it['quantidade'], $it['preco_unitario'], $it['subtotal'], $it['obs'] ?: null,
        ]);
        if ($it['controlar_estoque']) {
            $stmtStock->execute([$it['quantidade'], $it['produto_id']]);
        }
    }

    $db->commit();

    // Baixa insumos e acumula pontos imediatamente para pagamento em dinheiro
    if ($statusInicial === 'aguardando') {
        try { baixarEstoquePorPedido($db, $pedidoId); } catch (Throwable) {}
        try { acumularPontosPorPedido($db, $pedidoId); } catch (Throwable) {}
    }

    // Disparar webhook de novo pedido
    try {
        triggerN8n('novo_pedido', [
            'pedido_id'       => $pedidoId,
            'numero'          => $numero,
            'total'           => $total,
            'subtotal'        => $subtotal,
            'taxa_servico'    => $taxaValor,
            'forma_pagamento' => $pagamento,
            'tipo_consumo'    => $tipo,
            'status'          => $statusInicial,
            'origem'          => $origem,
            'itens_count'     => count($itens),
        ]);
    } catch (Throwable) {}

    auditLog($db, 'pedido_criado', 'pedidos', $pedidoId,
        "Pedido #{$numero} via {$origem} R$" . number_format($total, 2, ',', '.'),
        null, ['numero' => $numero, 'total' => $total, 'pagamento' => $pagamento]
    );

    $cpfFmt = $cpf
        ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf)
        : null;

    try {
        $lojaUrl = $db->query("SELECT valor FROM totem_configuracoes WHERE chave='loja_url'")->fetchColumn();
    } catch (Throwable $_) { $lojaUrl = ''; }
    if (!$lojaUrl || str_contains($lojaUrl, '/status/')) {
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $lojaUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/totem';
    }
    $statusUrl = rtrim($lojaUrl, '/') . '/status/?p=' . $numero;

    echo json_encode([
        'success' => true,
        'pedido'  => [
            'id'              => $pedidoId,
            'numero'          => $numero,
            'total'           => $total,
            'status'          => $statusInicial,
            'status_url'      => $statusUrl,
            'itens'           => array_map(fn($i) => [
                'nome_produto'   => $i['nome_produto'],
                'quantidade'     => $i['quantidade'],
                'preco_unitario' => $i['preco_unitario'],
                'subtotal'       => $i['subtotal'],
                'obs'            => $i['obs'],
            ], $itens),
            'tipo_consumo'    => $tipo,
            'cpf'             => $cpfFmt,
            'forma_pagamento' => $pagamento,
            'origem'          => $origem,
            'criado_em'       => date('d/m/Y \a\s H:i'),
        ],
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno ao processar pedido.']);
}

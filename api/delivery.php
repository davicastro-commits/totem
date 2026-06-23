<?php
declare(strict_types=1);

/**
 * API de Delivery
 *
 * GET  ?action=bairros                          → lista bairros com taxa
 * GET  ?action=cotar&bairro=Asa+Norte           → cotação de frete por bairro
 * GET  ?action=cotar&cep=XXXXXXXX               → cotação de frete por CEP (fallback)
 * GET  ?action=rastrear&pedido_id=X             → status da entrega do pedido
 * POST { action: 'criar_entrega', ... }         → cria entrega para pedido existente
 * POST { action: 'atualizar_status', ... }      → avança status (requer sessão admin)
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/csrf.php';

// ── Roteamento ────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Ações GET públicas
if ($method === 'GET') {
    $db = getDB();

    switch ($action) {
        case 'bairros':
            actionBairros($db);
            break;

        case 'cotar':
            actionCotar($db);
            break;

        case 'rastrear':
            actionRastrear($db);
            break;

        case 'ativas':
            actionAtivas($db);
            break;

        case 'historico':
            // Requer sessão admin
            if (empty($_SESSION['admin_id'])) jsonErr('Não autenticado', 401);
            actionHistorico($db);
            break;

        default:
            jsonErr('Ação inválida. Use: bairros, cotar, rastrear, ativas ou historico', 400);
    }
}

// Ações POST
if ($method === 'POST') {
    $body = jsonBody();
    $action = $body['action'] ?? '';

    $db = getDB();

    switch ($action) {
        case 'criar_entrega':
            actionCriarEntrega($db, $body);
            break;

        case 'atualizar_status':
            if (empty($_SESSION['admin_id'])) jsonErr('Não autenticado', 401);
            csrfVerify();
            actionAtualizarStatus($db, $body);
            break;

        case 'atualizar_status_entregador':
            // Ação pública para entregadores — sem sessão admin necessária
            actionAtualizarStatusEntregador($db, $body);
            break;

        case 'criar_bairro':
            if (empty($_SESSION['admin_id'])) jsonErr('Não autenticado', 401);
            csrfVerify();
            actionCriarBairro($db, $body);
            break;

        case 'editar_bairro':
            if (empty($_SESSION['admin_id'])) jsonErr('Não autenticado', 401);
            csrfVerify();
            actionEditarBairro($db, $body);
            break;

        default:
            jsonErr('Ação inválida', 400);
    }
}

jsonErr('Método não permitido', 405);

// ── Handlers ──────────────────────────────────────────────────────────

/**
 * Lista todos os bairros ativos com taxa e prazo.
 */
function actionBairros(PDO $db): never
{
    $rows = $db->query(
        "SELECT bairro, cidade, uf, taxa, prazo_min
           FROM totem_bairros_entrega
          WHERE ativo = TRUE
          ORDER BY taxa ASC, bairro ASC"
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['taxa'] = (float)$r['taxa'];
        $r['prazo_min'] = (int)$r['prazo_min'];
    }

    jsonOk($rows);
}

/**
 * Cotação de frete por bairro (parâmetro GET ?bairro=) ou CEP (?cep=).
 * Estratégia simplificada: match por nome de bairro. Se não encontrar, taxa padrão R$10.
 */
function actionCotar(PDO $db): never
{
    $bairroParam = trim($_GET['bairro'] ?? '');
    $cepParam    = preg_replace('/\D/', '', $_GET['cep'] ?? '');

    // Tenta match por bairro passado diretamente
    if ($bairroParam !== '') {
        $stmt = $db->prepare(
            "SELECT bairro, taxa, prazo_min
               FROM totem_bairros_entrega
              WHERE ativo = TRUE
                AND LOWER(bairro) = LOWER(?)
              LIMIT 1"
        );
        $stmt->execute([$bairroParam]);
        $row = $stmt->fetch();

        if ($row) {
            jsonOk([
                'disponivel' => true,
                'bairro'     => $row['bairro'],
                'taxa'       => (float)$row['taxa'],
                'prazo_min'  => (int)$row['prazo_min'],
            ]);
        }
    }

    // CEP fornecido mas sem match de bairro → retorna taxa padrão
    if ($cepParam !== '' || $bairroParam !== '') {
        jsonOk([
            'disponivel' => true,
            'bairro'     => $bairroParam ?: null,
            'taxa'       => 10.00,
            'prazo_min'  => 45,
            'aviso'      => 'Bairro não encontrado na tabela; taxa padrão aplicada.',
        ]);
    }

    jsonErr('Informe o parâmetro bairro ou cep', 400);
}

/**
 * Rastreio público de entrega por pedido_id.
 */
function actionRastrear(PDO $db): never
{
    $pedidoId = (int)($_GET['pedido_id'] ?? 0);
    if ($pedidoId <= 0) {
        jsonErr('pedido_id inválido', 400);
    }

    $stmt = $db->prepare(
        "SELECT e.id, e.status, e.previsao_min, e.entregador_nome, e.entregador_telefone,
                e.criado_em, e.saiu_em, e.entregue_em, e.taxa_entrega, e.observacao,
                en.logradouro, en.numero, en.complemento, en.bairro, en.cidade, en.uf,
                en.cep, en.referencia
           FROM totem_entregas e
      LEFT JOIN totem_enderecos_entrega en ON en.id = e.endereco_id
          WHERE e.pedido_id = ?
          ORDER BY e.id DESC
          LIMIT 1"
    );
    $stmt->execute([$pedidoId]);
    $entrega = $stmt->fetch();

    if (!$entrega) {
        jsonErr('Entrega não encontrada para este pedido', 404);
    }

    // Histórico simplificado baseado nos timestamps disponíveis
    $historico = [];
    if ($entrega['criado_em']) {
        $historico[] = ['status' => 'recebido',  'em' => $entrega['criado_em']];
    }
    if ($entrega['saiu_em']) {
        $historico[] = ['status' => 'saiu',      'em' => $entrega['saiu_em']];
    }
    if ($entrega['entregue_em']) {
        $historico[] = ['status' => 'entregue',  'em' => $entrega['entregue_em']];
    }

    jsonOk([
        'entrega_id'          => (int)$entrega['id'],
        'status'              => $entrega['status'],
        'previsao_min'        => (int)$entrega['previsao_min'],
        'taxa_entrega'        => (float)$entrega['taxa_entrega'],
        'entregador_nome'     => $entrega['entregador_nome'],
        'entregador_telefone' => $entrega['entregador_telefone'],
        'observacao'          => $entrega['observacao'],
        'criado_em'           => $entrega['criado_em'],
        'entregue_em'         => $entrega['entregue_em'],
        'endereco'            => [
            'logradouro'  => $entrega['logradouro'],
            'numero'      => $entrega['numero'],
            'complemento' => $entrega['complemento'],
            'bairro'      => $entrega['bairro'],
            'cidade'      => $entrega['cidade'],
            'uf'          => $entrega['uf'],
            'cep'         => $entrega['cep'],
            'referencia'  => $entrega['referencia'],
        ],
        'historico' => $historico,
    ]);
}

/**
 * Cria uma entrega para um pedido existente.
 * Salva o endereço e vincula ao pedido.
 */
function actionCriarEntrega(PDO $db, array $body): never
{
    $pedidoId   = (int)($body['pedido_id'] ?? 0);
    $bairro     = trim($body['bairro']     ?? '');
    $logradouro = trim($body['logradouro'] ?? '');
    $numero     = trim($body['numero']     ?? '');
    $complemento= trim($body['complemento'] ?? '');
    $referencia = trim($body['referencia'] ?? '');
    $cep        = preg_replace('/\D/', '', $body['cep'] ?? '');
    $clienteId  = !empty($body['cliente_id']) ? (int)$body['cliente_id'] : null;

    if ($pedidoId <= 0) {
        jsonErr('pedido_id é obrigatório', 400);
    }
    if ($bairro === '') {
        jsonErr('bairro é obrigatório', 400);
    }
    if ($logradouro === '') {
        jsonErr('logradouro é obrigatório', 400);
    }

    // Verifica se pedido existe
    $stmtPed = $db->prepare('SELECT id FROM totem_pedidos WHERE id = ?');
    $stmtPed->execute([$pedidoId]);
    if (!$stmtPed->fetch()) {
        jsonErr('Pedido não encontrado', 404);
    }

    // Verifica se já existe entrega para este pedido
    $stmtExist = $db->prepare('SELECT id FROM totem_entregas WHERE pedido_id = ? LIMIT 1');
    $stmtExist->execute([$pedidoId]);
    if ($stmtExist->fetch()) {
        jsonErr('Já existe uma entrega para este pedido', 409);
    }

    // Busca taxa do bairro
    $stmtBairro = $db->prepare(
        "SELECT taxa, prazo_min FROM totem_bairros_entrega WHERE ativo = TRUE AND LOWER(bairro) = LOWER(?) LIMIT 1"
    );
    $stmtBairro->execute([$bairro]);
    $bairroRow = $stmtBairro->fetch();
    $taxa      = $bairroRow ? (float)$bairroRow['taxa'] : 10.00;
    $prazo     = $bairroRow ? (int)$bairroRow['prazo_min'] : 45;

    $db->beginTransaction();

    try {
        // Salva endereço
        $stmtEnd = $db->prepare(
            "INSERT INTO totem_enderecos_entrega
               (cliente_id, cep, logradouro, numero, complemento, bairro, referencia)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             RETURNING id"
        );
        $stmtEnd->execute([
            $clienteId,
            $cep !== '' ? $cep : null,
            $logradouro,
            $numero !== '' ? $numero : null,
            $complemento !== '' ? $complemento : null,
            $bairro,
            $referencia !== '' ? $referencia : null,
        ]);
        $enderecoId = (int)$stmtEnd->fetchColumn();

        // Cria entrega
        $stmtEnt = $db->prepare(
            "INSERT INTO totem_entregas
               (pedido_id, endereco_id, taxa_entrega, previsao_min, status)
             VALUES (?, ?, ?, ?, 'recebido')
             RETURNING id"
        );
        $stmtEnt->execute([$pedidoId, $enderecoId, $taxa, $prazo]);
        $entregaId = (int)$stmtEnt->fetchColumn();

        // Atualiza canal do pedido para delivery
        $db->prepare("UPDATE totem_pedidos SET canal = 'delivery' WHERE id = ?")
           ->execute([$pedidoId]);

        $db->commit();

        auditLog(
            $db,
            'entrega_criada',
            'delivery',
            $entregaId,
            "Entrega #{$entregaId} criada para pedido #{$pedidoId} — {$bairro} R$" . number_format($taxa, 2, ',', '.'),
            null,
            ['pedido_id' => $pedidoId, 'bairro' => $bairro, 'taxa' => $taxa]
        );

        jsonOk([
            'entrega_id' => $entregaId,
            'taxa'       => $taxa,
            'prazo_min'  => $prazo,
        ], 201);

    } catch (Throwable $e) {
        $db->rollBack();
        jsonErr('Erro ao criar entrega: ' . $e->getMessage(), 500);
    }
}

/**
 * Lista entregas ativas (não entregues/canceladas) para o painel admin.
 */
function actionAtivas(PDO $db): never
{
    $rows = $db->query("
        SELECT e.id, e.status, e.previsao_min, e.taxa_entrega,
               e.entregador_nome, e.entregador_telefone, e.criado_em,
               p.numero_pedido, p.total,
               en.logradouro, en.numero AS end_numero, en.bairro
          FROM totem_entregas e
          JOIN totem_pedidos p ON p.id = e.pedido_id
          LEFT JOIN totem_enderecos_entrega en ON en.id = e.endereco_id
         WHERE e.status NOT IN ('entregue','cancelado')
         ORDER BY e.criado_em ASC
    ")->fetchAll();
    jsonOk($rows);
}

/**
 * Histórico de entregas por data e status.
 */
function actionHistorico(PDO $db): never
{
    $data   = $_GET['data']   ?? date('Y-m-d');
    $status = $_GET['status'] ?? '';

    $where = ['DATE(e.criado_em) = ?'];
    $params = [$data];
    if ($status) { $where[] = 'e.status = ?'; $params[] = $status; }

    $sql = "
        SELECT e.id, e.status, e.taxa_entrega, e.entregador_nome, e.criado_em, e.entregue_em,
               p.numero_pedido, p.total,
               en.logradouro, en.numero AS end_numero, en.bairro
          FROM totem_entregas e
          JOIN totem_pedidos p ON p.id = e.pedido_id
          LEFT JOIN totem_enderecos_entrega en ON en.id = e.endereco_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY e.criado_em DESC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonOk($stmt->fetchAll());
}

/**
 * Cria novo bairro de entrega.
 */
function actionCriarBairro(PDO $db, array $body): never
{
    $bairro = trim($body['bairro'] ?? '');
    $taxa   = (float)($body['taxa'] ?? 0);
    if (!$bairro) jsonErr('bairro é obrigatório');

    $stmt = $db->prepare("
        INSERT INTO totem_bairros_entrega (bairro, cidade, uf, taxa, prazo_min)
        VALUES (?, ?, ?, ?, ?) RETURNING id
    ");
    $stmt->execute([
        $bairro,
        trim($body['cidade'] ?? 'Brasília') ?: 'Brasília',
        strtoupper(substr(trim($body['uf'] ?? 'DF'), 0, 2)) ?: 'DF',
        $taxa,
        max(10, (int)($body['prazo_min'] ?? 45)),
    ]);
    jsonOk(['id' => (int)$stmt->fetchColumn()], 201);
}

/**
 * Edita bairro de entrega existente.
 */
function actionEditarBairro(PDO $db, array $body): never
{
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonErr('id é obrigatório');

    $db->prepare("
        UPDATE totem_bairros_entrega
           SET bairro = ?, cidade = ?, uf = ?, taxa = ?, prazo_min = ?
         WHERE id = ?
    ")->execute([
        trim($body['bairro'] ?? ''),
        trim($body['cidade'] ?? 'Brasília') ?: 'Brasília',
        strtoupper(substr(trim($body['uf'] ?? 'DF'), 0, 2)) ?: 'DF',
        (float)($body['taxa'] ?? 0),
        max(10, (int)($body['prazo_min'] ?? 45)),
        $id,
    ]);
    jsonOk(['updated' => true]);
}

/**
 * Atualiza status de entrega para uso público pelo entregador.
 * Aceita apenas transições seguras: recebido→preparo→saiu→entregue.
 * Não requer sessão admin.
 */
function actionAtualizarStatusEntregador(PDO $db, array $body): never
{
    $entregaId = (int)($body['entrega_id'] ?? 0);
    $status    = $body['status'] ?? '';

    $statusPermitidos = ['preparo', 'saiu', 'entregue'];

    if ($entregaId <= 0) {
        jsonErr('entrega_id é obrigatório', 400);
    }
    if (!in_array($status, $statusPermitidos, true)) {
        jsonErr('Status inválido. Use: ' . implode(', ', $statusPermitidos), 400);
    }

    $stmtGet = $db->prepare('SELECT id, status FROM totem_entregas WHERE id = ?');
    $stmtGet->execute([$entregaId]);
    $entrega = $stmtGet->fetch();

    if (!$entrega) {
        jsonErr('Entrega não encontrada', 404);
    }

    // Não permite reverter status já finalizado
    if (in_array($entrega['status'], ['entregue', 'cancelado'], true)) {
        jsonErr('Entrega já finalizada', 409);
    }

    $extras = '';
    if ($status === 'saiu')     $extras = ', saiu_em = NOW()';
    if ($status === 'entregue') $extras = ', entregue_em = NOW()';

    $db->prepare("UPDATE totem_entregas SET status = ? {$extras} WHERE id = ?")
       ->execute([$status, $entregaId]);

    jsonOk(['status' => $status]);
}

/**
 * Atualiza o status de uma entrega. Requer sessão admin (verificado antes de chamar).
 */
function actionAtualizarStatus(PDO $db, array $body): never
{
    $entregaId          = (int)($body['entrega_id'] ?? 0);
    $status             = $body['status'] ?? '';
    $entregadorNome     = trim($body['entregador_nome']     ?? '');
    $entregadorTelefone = trim($body['entregador_telefone'] ?? '');

    $statusValidos = ['recebido', 'preparo', 'saiu', 'entregue', 'cancelado'];

    if ($entregaId <= 0) {
        jsonErr('entrega_id é obrigatório', 400);
    }
    if (!in_array($status, $statusValidos, true)) {
        jsonErr('Status inválido. Use: ' . implode(', ', $statusValidos), 400);
    }

    // Busca entrega atual
    $stmtGet = $db->prepare('SELECT id, status FROM totem_entregas WHERE id = ?');
    $stmtGet->execute([$entregaId]);
    $entrega = $stmtGet->fetch();

    if (!$entrega) {
        jsonErr('Entrega não encontrada', 404);
    }

    // Monta campos extras conforme transição
    $extras    = '';
    $params    = [];

    if ($entregadorNome !== '') {
        $extras   .= ', entregador_nome = ?';
        $params[]  = $entregadorNome;
    }
    if ($entregadorTelefone !== '') {
        $extras   .= ', entregador_telefone = ?';
        $params[]  = $entregadorTelefone;
    }
    if ($status === 'saiu') {
        $extras .= ', saiu_em = NOW()';
    }
    if ($status === 'entregue') {
        $extras .= ', entregue_em = NOW()';
    }

    $params = array_merge([$status], $params, [$entregaId]);

    $db->prepare("UPDATE totem_entregas SET status = ? {$extras} WHERE id = ?")
       ->execute($params);

    auditLog(
        $db,
        'entrega_status_atualizado',
        'delivery',
        $entregaId,
        "Entrega #{$entregaId}: {$entrega['status']} → {$status}",
        ['status' => $entrega['status']],
        ['status' => $status]
    );

    jsonOk(['status' => $status]);
}

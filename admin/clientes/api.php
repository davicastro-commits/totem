<?php
/**
 * Admin interno de clientes/fidelidade.
 * Chamado pelo admin/clientes/index.php via fetch('api.php?...')
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../../config/db.php';
require_once '../../config/audit.php';
require_once '../api/auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $action = $body['action'] ?? $action;
}

try {
    $db = getDB();

    // ── GET pontos_config ─────────────────────────────────────────────
    if ($action === 'pontos_config') {
        $cfg = $db->query(
            "SELECT pontos_por_real, real_por_ponto, validade_dias
               FROM totem_pontos_config
              WHERE ativo = true
              ORDER BY id DESC LIMIT 1"
        )->fetch();
        if (!$cfg) $cfg = ['pontos_por_real' => 1.0, 'real_por_ponto' => 0.05, 'validade_dias' => 365];
        echo json_encode(['success' => true, 'config' => $cfg]);
        exit;
    }

    // ── POST salvar_pontos_config ─────────────────────────────────────
    if ($action === 'salvar_pontos_config') {
        requireRole('admin');
        $ppr = max(0.01, (float)($body['pontos_por_real'] ?? 1.0));
        $rpp = max(0.001, (float)($body['real_por_ponto']  ?? 0.05));
        $val = max(1, (int)($body['validade_dias'] ?? 365));

        // Desativa config anterior
        $db->exec("UPDATE totem_pontos_config SET ativo = false");

        $db->prepare(
            "INSERT INTO totem_pontos_config (pontos_por_real, real_por_ponto, validade_dias, ativo)
             VALUES (?, ?, ?, true)"
        )->execute([$ppr, $rpp, $val]);

        auditLog($db, 'pontos_config_atualizado', 'crm', null,
            "Config pontos: {$ppr} pts/R\$, R\$ {$rpp}/ponto, {$val} dias");

        echo json_encode(['success' => true]);
        exit;
    }

    // ── GET listagem de clientes (default) ────────────────────────────
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $busca   = trim($_GET['busca'] ?? '');
    $offset  = ($page - 1) * $perPage;

    $where  = ['c.ativo = true'];
    $params = [];
    if ($busca) {
        $where[]  = "(c.nome ILIKE ? OR c.cpf ILIKE ?)";
        $params[] = "%{$busca}%";
        $params[] = "%{$busca}%";
    }
    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $total = (int)$db->prepare("SELECT COUNT(*) FROM totem_clientes c {$whereSQL}")
                      ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM totem_clientes c {$whereSQL}")->execute($params) && 1 : 0;

    // Reexecuta o count corretamente
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM totem_clientes c {$whereSQL}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $dataStmt = $db->prepare("
        SELECT c.id, c.nome, c.cpf, c.telefone, c.email,
               c.pontos_saldo, c.total_gasto, c.total_pedidos, c.criado_em
          FROM totem_clientes c
         {$whereSQL}
         ORDER BY c.total_gasto DESC
         LIMIT {$perPage} OFFSET {$offset}
    ");
    $dataStmt->execute($params);
    $clientes = $dataStmt->fetchAll();

    // KPI extras
    $kpiStmt = $db->query("
        SELECT
            COALESCE(SUM(total_gasto),0)    AS total_gasto,
            COALESCE(SUM(pontos_saldo),0)   AS total_pontos
        FROM totem_clientes
        WHERE ativo = true
    ");
    $kpi = $kpiStmt->fetch();

    $cuponsAtivos = (int)$db->query(
        "SELECT COUNT(*) FROM totem_cupons WHERE ativo = true"
    )->fetchColumn();

    echo json_encode([
        'success'       => true,
        'data'          => $clientes,
        'total'         => $total,
        'pages'         => (int)ceil($total / $perPage),
        'page'          => $page,
        'total_gasto'   => (float)$kpi['total_gasto'],
        'total_pontos'  => (int)$kpi['total_pontos'],
        'cupons_ativos' => $cuponsAtivos,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de banco: ' . $e->getMessage()]);
}

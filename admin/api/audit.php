<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once 'auth.php';
requireAdmin();
requireRole('admin');

try {
    $db = getDB();

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = 50;
    $offset  = ($page - 1) * $limit;

    $dataIni = $_GET['data_ini'] ?? null;
    $dataFim = $_GET['data_fim'] ?? null;
    $acao    = $_GET['acao']    ?? null;
    $userId  = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : null;

    $where  = [];
    $params = [];

    if ($dataIni) { $where[] = "DATE(a.criado_em) >= ?"; $params[] = $dataIni; }
    if ($dataFim) { $where[] = "DATE(a.criado_em) <= ?"; $params[] = $dataFim; }
    if ($acao)    { $where[] = "a.acao = ?";              $params[] = $acao; }
    if ($userId)  { $where[] = "a.usuario_id = ?";        $params[] = $userId; }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM totem_audit a {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare("
        SELECT a.id, a.usuario_nome, a.usuario_email, a.acao, a.modulo,
               a.registro_id, a.descricao, a.dados_antes, a.dados_depois,
               a.ip, a.criado_em
          FROM totem_audit a
          {$whereSQL}
         ORDER BY a.criado_em DESC
         LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Decodificar JSON columns
    foreach ($rows as &$r) {
        $r['dados_antes']  = $r['dados_antes']  ? json_decode($r['dados_antes'],  true) : null;
        $r['dados_depois'] = $r['dados_depois'] ? json_decode($r['dados_depois'], true) : null;
    }

    // Lista de ações distintas para filtro
    $acoesStmt = $db->query("SELECT DISTINCT acao FROM totem_audit ORDER BY acao");
    $acoes = $acoesStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'total'   => $total,
        'pages'   => ceil($total / $limit),
        'page'    => $page,
        'acoes'   => $acoes,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

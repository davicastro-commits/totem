<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once '../../config/audit.php';
require_once 'auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $stmt = $db->query("SELECT chave, valor, descricao FROM totem_configuracoes ORDER BY chave");
        $rows = $stmt->fetchAll();
        $config = [];
        foreach ($rows as $r) {
            $config[$r['chave']] = ['valor' => $r['valor'], 'descricao' => $r['descricao']];
        }
        echo json_encode(['success' => true, 'data' => $config]);

    } elseif ($method === 'POST') {
        requireRole('admin');
        $body = json_decode(file_get_contents('php://input'), true);

        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Payload inválido']);
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO totem_configuracoes (chave, valor, atualizado_em)
            VALUES (?, ?, NOW())
            ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor, atualizado_em = NOW()
        ");

        $db->beginTransaction();
        $alterados = [];
        foreach ($body as $chave => $valor) {
            $chave = preg_replace('/[^a-z0-9_]/', '', strtolower($chave));
            if (!$chave) continue;
            $stmt->execute([$chave, (string)$valor]);
            $alterados[] = $chave;
        }
        $db->commit();

        auditLog($db, 'configuracoes_atualizadas', 'configuracoes', null,
            'Configurações atualizadas: ' . implode(', ', $alterados),
            null, $body);

        echo json_encode(['success' => true, 'updated' => count($alterados)]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

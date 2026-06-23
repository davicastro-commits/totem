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
        $stmt = $db->query("
            SELECT c.id, c.nome, c.icone, c.ordem,
                   COUNT(p.id) AS total_produtos,
                   SUM(CASE WHEN p.disponivel THEN 1 ELSE 0 END) AS produtos_ativos
              FROM totem_categorias c
              LEFT JOIN totem_produtos p ON p.categoria_id = c.id
             GROUP BY c.id
             ORDER BY c.ordem ASC, c.id ASC
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);

    } elseif ($method === 'POST') {
        requireRole('admin');
        $body  = json_decode(file_get_contents('php://input'), true);
        $id    = isset($body['id']) ? (int)$body['id'] : null;
        $nome  = trim($body['nome']  ?? '');
        $icone = trim($body['icone'] ?? '');
        $ordem = (int)($body['ordem'] ?? 99);

        if (!$nome) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome e obrigatorio']);
            exit;
        }

        if ($id) {
            $antes = $db->prepare("SELECT * FROM totem_categorias WHERE id = ?");
            $antes->execute([$id]);
            $dadosAntes = $antes->fetch();

            $stmt = $db->prepare("UPDATE totem_categorias SET nome = ?, icone = ?, ordem = ? WHERE id = ?");
            $stmt->execute([$nome, $icone, $ordem, $id]);

            auditLog($db, 'categoria_editada', 'categorias', $id,
                "Categoria #{$id} editada: {$dadosAntes['nome']} -> {$nome}",
                $dadosAntes, ['nome' => $nome, 'icone' => $icone, 'ordem' => $ordem]);
            echo json_encode(['success' => true, 'action' => 'updated']);
        } else {
            $stmt = $db->prepare("INSERT INTO totem_categorias (nome, icone, ordem) VALUES (?, ?, ?) RETURNING id");
            $stmt->execute([$nome, $icone, $ordem]);
            $novoId = $stmt->fetchColumn();

            auditLog($db, 'categoria_criada', 'categorias', $novoId,
                "Categoria criada: {$nome}",
                null, ['nome' => $nome, 'icone' => $icone, 'ordem' => $ordem]);
            echo json_encode(['success' => true, 'action' => 'created', 'id' => $novoId]);
        }

    } elseif ($method === 'DELETE') {
        requireRole('admin');
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($body['id'] ?? 0);

        if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'ID invalido']); exit; }

        $check = $db->prepare("SELECT COUNT(*) FROM totem_produtos WHERE categoria_id = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Categoria possui produtos. Remova-os primeiro.']);
            exit;
        }

        $cat = $db->prepare("SELECT nome FROM totem_categorias WHERE id = ?");
        $cat->execute([$id]);
        $nome = $cat->fetchColumn();

        $db->prepare("DELETE FROM totem_categorias WHERE id = ?")->execute([$id]);
        auditLog($db, 'categoria_excluida', 'categorias', $id, "Categoria excluida: {$nome}");
        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

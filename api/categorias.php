<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

try {
    $db = getDB();
    $stmt = $db->query(
        "SELECT id, nome, icone FROM totem_categorias WHERE ativo = TRUE ORDER BY ordem ASC, id ASC"
    );
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar categorias']);
}

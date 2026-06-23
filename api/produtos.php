<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

$catId = filter_input(INPUT_GET, 'categoria_id', FILTER_VALIDATE_INT);

try {
    $db = getDB();

    if ($catId) {
        $stmt = $db->prepare(
            "SELECT id, categoria_id, nome, descricao, preco, imagem, destaque
               FROM totem_produtos
              WHERE categoria_id = ? AND disponivel = TRUE
              ORDER BY destaque DESC, ordem ASC, id ASC"
        );
        $stmt->execute([$catId]);
    } else {
        $stmt = $db->query(
            "SELECT id, categoria_id, nome, descricao, preco, imagem, destaque
               FROM totem_produtos
              WHERE disponivel = TRUE
              ORDER BY categoria_id ASC, ordem ASC"
        );
    }

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar produtos']);
}

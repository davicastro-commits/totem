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
        $catId = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : null;
        $busca = trim($_GET['busca'] ?? '');

        $where  = [];
        $params = [];

        if ($catId) { $where[] = 'p.categoria_id = ?'; $params[] = $catId; }
        if ($busca) { $where[] = 'p.nome ILIKE ?';     $params[] = "%{$busca}%"; }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT p.id, p.categoria_id, c.nome AS categoria, c.icone AS cat_icone,
                   p.nome, p.descricao, p.preco, p.imagem, p.disponivel, p.destaque, p.ordem
              FROM totem_produtos p
              JOIN totem_categorias c ON c.id = p.categoria_id
              {$whereSQL}
             ORDER BY c.ordem ASC, p.ordem ASC, p.id ASC
        ");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);

    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = isset($body['id']) ? (int)$body['id'] : null;

        // ── Toggle disponível ─────────────────────────────────────────
        if (isset($body['toggle_disponivel'])) {
            requireRole('operador');
            $stmt = $db->prepare("UPDATE totem_produtos SET disponivel = NOT disponivel WHERE id = ? RETURNING disponivel, nome");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            auditLog($db, $row['disponivel'] ? 'produto_ativado' : 'produto_desativado',
                'produtos', $id, "Produto '{$row['nome']}' " . ($row['disponivel'] ? 'ativado' : 'desativado'));
            echo json_encode(['success' => true, 'disponivel' => $row['disponivel']]);
            exit;
        }

        // ── Bulk toggle ───────────────────────────────────────────────
        if (isset($body['bulk_disponivel'])) {
            requireRole('admin');
            $ids  = array_map('intval', $body['ids'] ?? []);
            $disp = !empty($body['disponivel']) ? 'true' : 'false';
            if (!$ids) { echo json_encode(['success' => false, 'error' => 'Nenhum produto']); exit; }

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE totem_produtos SET disponivel = ? WHERE id IN ({$ph})");
            $stmt->execute(array_merge([$disp], $ids));
            auditLog($db, 'bulk_disponivel', 'produtos', null,
                count($ids) . " produtos " . ($disp ? 'ativados' : 'desativados'),
                null, ['ids' => $ids, 'disponivel' => $disp]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
            exit;
        }

        requireRole('admin');
        $nome            = trim($body['nome']      ?? '');
        $desc            = trim($body['descricao'] ?? '');
        $preco           = filter_var($body['preco'] ?? 0, FILTER_VALIDATE_FLOAT);
        $catId           = (int)($body['categoria_id'] ?? 0);
        $destaque        = !empty($body['destaque'])    ? 'true' : 'false';
        $ordem           = (int)($body['ordem'] ?? 99);
        $ctrlEstoque     = !empty($body['controlar_estoque']) ? 'true' : 'false';
        $estoqueQtd      = max(0, (int)($body['estoque_qtd'] ?? 0));
        $estoqueAlerta   = max(0, (int)($body['estoque_alerta'] ?? 5));
        $imagem          = isset($body['imagem']) ? trim($body['imagem']) : null;

        if (!$nome || $preco === false || !$catId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome, preço e categoria são obrigatórios']);
            exit;
        }

        if ($id) {
            $antes = $db->prepare("SELECT * FROM totem_produtos WHERE id = ?");
            $antes->execute([$id]);
            $dadosAntes = $antes->fetch();

            $imgSql = $imagem !== null ? ', imagem=?' : '';
            $stmt = $db->prepare("
                UPDATE totem_produtos
                   SET nome=?, descricao=?, preco=?, categoria_id=?, destaque=?, ordem=?,
                       controlar_estoque=?, estoque_qtd=?, estoque_alerta=?{$imgSql}
                 WHERE id=?
            ");
            $params = [$nome, $desc, $preco, $catId, $destaque, $ordem, $ctrlEstoque, $estoqueQtd, $estoqueAlerta];
            if ($imagem !== null) $params[] = $imagem;
            $params[] = $id;
            $stmt->execute($params);

            $precoDiff = $dadosAntes && (float)$dadosAntes['preco'] !== (float)$preco
                ? " | Preço: R\${$dadosAntes['preco']} -> R\${$preco}" : '';
            auditLog($db, 'produto_editado', 'produtos', $id,
                "Produto '{$nome}' editado{$precoDiff}",
                $dadosAntes, ['nome' => $nome, 'preco' => $preco, 'categoria_id' => $catId,
                              'controlar_estoque' => ($ctrlEstoque === 'true'), 'estoque_qtd' => $estoqueQtd]);
            echo json_encode(['success' => true, 'action' => 'updated']);

        } else {
            $stmt = $db->prepare("
                INSERT INTO totem_produtos
                    (categoria_id, nome, descricao, preco, imagem, destaque, ordem, controlar_estoque, estoque_qtd, estoque_alerta)
                VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id
            ");
            $stmt->execute([$catId, $nome, $desc, $preco, $imagem, $destaque, $ordem,
                            $ctrlEstoque, $estoqueQtd, $estoqueAlerta]);
            $novoId = $stmt->fetchColumn();
            auditLog($db, 'produto_criado', 'produtos', $novoId,
                "Produto criado: '{$nome}' R\${$preco}");
            echo json_encode(['success' => true, 'action' => 'created', 'id' => $novoId]);
        }

    } elseif ($method === 'DELETE') {
        requireRole('admin');
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['success' => false]); exit; }

        $p = $db->prepare("SELECT nome FROM totem_produtos WHERE id=?");
        $p->execute([$id]);
        $nome = $p->fetchColumn();

        $db->prepare("UPDATE totem_produtos SET disponivel = FALSE WHERE id = ?")->execute([$id]);
        auditLog($db, 'produto_desativado', 'produtos', $id, "Produto '{$nome}' desativado via DELETE");
        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

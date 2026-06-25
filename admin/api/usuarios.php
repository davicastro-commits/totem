<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once '../../config/audit.php';
require_once 'auth.php';
requireAdmin();
requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        // Tenta incluir a coluna permissoes (pode não existir se migration não foi aplicada)
        try {
            $stmt = $db->query("
                SELECT u.id, u.nome, u.email, u.role, u.ativo, u.ultimo_login, u.criado_em,
                       COALESCE(u.permissoes::text, '{}') AS permissoes_raw,
                       (SELECT COUNT(*) FROM totem_sessoes s WHERE s.admin_id = u.id) AS total_logins
                  FROM totem_admin u
                 ORDER BY u.criado_em DESC
            ");
        } catch (PDOException $e) {
            // Coluna permissoes ainda não existe — fallback sem ela
            $stmt = $db->query("
                SELECT u.id, u.nome, u.email, u.role, u.ativo, u.ultimo_login, u.criado_em,
                       '{}' AS permissoes_raw,
                       (SELECT COUNT(*) FROM totem_sessoes s WHERE s.admin_id = u.id) AS total_logins
                  FROM totem_admin u
                 ORDER BY u.criado_em DESC
            ");
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['permissoes'] = json_decode($r['permissoes_raw'] ?? '{}', true) ?: (object)[];
            unset($r['permissoes_raw']);
        }
        echo json_encode(['success' => true, 'data' => $rows]);

    } elseif ($method === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true);

        // Salvar permissões granulares (ação dedicada)
        if (($body['action'] ?? '') === 'salvar_permissoes') {
            $id   = (int)($body['id'] ?? 0);
            $perm = $body['permissoes'] ?? [];
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID de usuário inválido']);
                exit;
            }
            // Admins sempre têm acesso total — não salvar restrições sobre eles
            $chk = $db->prepare("SELECT role FROM totem_admin WHERE id = ?");
            $chk->execute([$id]);
            $targetRole = $chk->fetchColumn();
            if ($targetRole === 'admin') {
                echo json_encode(['success' => true, 'msg' => 'Admin tem acesso total, nenhuma restrição aplicada']);
                exit;
            }
            $permJson = json_encode($perm, JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("UPDATE totem_admin SET permissoes = ?::jsonb WHERE id = ?");
            $stmt->execute([$permJson, $id]);
            auditLog($db, 'permissoes_editadas', 'usuarios', $id, "Permissões do usuário #{$id} atualizadas");
            echo json_encode(['success' => true]);
            exit;
        }

        $id    = isset($body['id']) ? (int)$body['id'] : null;
        $nome  = trim($body['nome']  ?? '');
        $email = trim($body['email'] ?? '');
        $role  = in_array($body['role'] ?? '', ['admin','operador','cozinha']) ? $body['role'] : 'operador';
        $ativo = isset($body['ativo']) ? (bool)$body['ativo'] : true;
        $senha = trim($body['senha'] ?? '');

        if (!$nome || !$email) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome e email sao obrigatorios']);
            exit;
        }

        // Checar email duplicado
        $dupStmt = $db->prepare("SELECT id FROM totem_admin WHERE email = ? AND id != ?");
        $dupStmt->execute([$email, $id ?? 0]);
        if ($dupStmt->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Email ja cadastrado']);
            exit;
        }

        if ($id) {
            $antes = $db->prepare("SELECT id, nome, email, role, ativo FROM totem_admin WHERE id = ?");
            $antes->execute([$id]);
            $dadosAntes = $antes->fetch();

            if ($senha) {
                $hash = password_hash($senha, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE totem_admin SET nome=?, email=?, role=?, ativo=?, senha=? WHERE id=?");
                $stmt->execute([$nome, $email, $role, $ativo, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE totem_admin SET nome=?, email=?, role=?, ativo=? WHERE id=?");
                $stmt->execute([$nome, $email, $role, $ativo, $id]);
            }

            auditLog($db, 'usuario_editado', 'usuarios', $id,
                "Usuario #{$id} {$nome} editado",
                $dadosAntes, ['nome' => $nome, 'email' => $email, 'role' => $role, 'ativo' => $ativo]);
            echo json_encode(['success' => true, 'action' => 'updated']);

        } else {
            if (!$senha) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Senha obrigatoria para novo usuario']);
                exit;
            }
            $hash = password_hash($senha, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO totem_admin (nome, email, senha, role, ativo) VALUES (?,?,?,?,?) RETURNING id");
            $stmt->execute([$nome, $email, $hash, $role, $ativo]);
            $novoId = $stmt->fetchColumn();

            auditLog($db, 'usuario_criado', 'usuarios', $novoId,
                "Usuario criado: {$nome} ({$email}) role={$role}");
            echo json_encode(['success' => true, 'action' => 'created', 'id' => $novoId]);
        }

    } elseif ($method === 'DELETE') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($body['id'] ?? 0);

        if (!$id || $id === adminId()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nao e possivel excluir o proprio usuario']);
            exit;
        }

        $u = $db->prepare("SELECT nome, email FROM totem_admin WHERE id=?");
        $u->execute([$id]);
        $row = $u->fetch();

        $db->prepare("UPDATE totem_admin SET ativo = FALSE WHERE id = ?")->execute([$id]);
        auditLog($db, 'usuario_desativado', 'usuarios', $id, "Usuario desativado: {$row['nome']} ({$row['email']})");
        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

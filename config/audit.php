<?php
/**
 * Audit logging — registra todas as ações administrativas.
 * Falha silenciosamente se a tabela totem_audit ainda nao existir.
 */

function auditLog(
    PDO     $db,
    string  $acao,
    ?string $modulo      = null,
    ?int    $registroId  = null,
    ?string $descricao   = null,
    mixed   $dadosAntes  = null,
    mixed   $dadosDepois = null
): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO totem_audit
                (usuario_id, usuario_nome, usuario_email, acao, modulo, registro_id, descricao, dados_antes, dados_depois, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id']    ?? null,
            $_SESSION['admin_nome']  ?? 'Sistema',
            $_SESSION['admin_email'] ?? null,
            $acao,
            $modulo,
            $registroId,
            $descricao,
            $dadosAntes  !== null ? json_encode($dadosAntes,  JSON_UNESCAPED_UNICODE) : null,
            $dadosDepois !== null ? json_encode($dadosDepois, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable) {
        // Silencioso — nao quebra a operacao principal se tabela nao existir
    }
}

/**
 * Verifica role minima. Lanca 403 JSON se insuficiente.
 * Hierarquia: admin > operador > cozinha
 */
function requireRole(string $minRole): void {
    $hier = ['cozinha' => 1, 'operador' => 2, 'admin' => 3];
    $role = $_SESSION['admin_role'] ?? 'cozinha';

    if (($hier[$role] ?? 0) < ($hier[$minRole] ?? 99)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Nivel insuficiente.']);
        exit;
    }
}

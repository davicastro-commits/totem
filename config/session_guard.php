<?php
/**
 * Middleware de proteção de sessão.
 * Inclua este arquivo em TODAS as páginas admin APÓS session_start().
 *
 * - Timeout de 30 minutos de inatividade.
 * - Requisições AJAX/API recebem 401 JSON.
 * - Páginas HTML recebem redirect para admin/index.php?timeout=1.
 */

define('SESSION_TIMEOUT', 1800); // 30 minutos em segundos

function sessionGuard(): void
{
    if (empty($_SESSION['admin_id'])) return;

    $now  = time();
    $last = $_SESSION['_last_activity'] ?? $now;

    if ($now - $last > SESSION_TIMEOUT) {
        // Destroi a sessão
        session_unset();
        session_destroy();

        // Detecta se é request AJAX / API (espera JSON)
        $isAjax = !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
               || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
               || str_contains($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest');

        if ($isAjax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => 'session_timeout',
                'message' => 'Sessão expirada. Faça login novamente.',
            ]);
            exit;
        }

        // Calcula nível de profundidade para montar o caminho relativo
        $depth = substr_count($_SERVER['PHP_SELF'] ?? '/admin/index.php', '/');
        // Queremos voltar para admin/index.php a partir da raiz
        // Raiz está sempre 1 nível acima de /admin, então depth - 2 pastas "../"
        $ups   = max(1, $depth - 2);
        $back  = str_repeat('../', $ups) . 'admin/index.php?timeout=1';

        header('Location: ' . $back);
        exit;
    }

    $_SESSION['_last_activity'] = $now;
}

sessionGuard();

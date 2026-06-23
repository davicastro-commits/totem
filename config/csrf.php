<?php
/**
 * Proteção CSRF simples baseada em token de sessão.
 * - csrfToken()   → retorna o token atual (gerado uma vez por sessão)
 * - csrfVerify()  → valida o header X-CSRF-Token ou POST _csrf; aborta com 403 se inválido
 * - csrfMeta()    → imprime a meta tag HTML para uso pelo JS
 */

function csrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrfVerify(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();

    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['_csrf']
        ?? null;

    if (!$token || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
        exit;
    }
}

function csrfMeta(): void
{
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
    echo "<meta name=\"csrf-token\" content=\"{$token}\">\n";
}

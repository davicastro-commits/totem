<?php
/**
 * Proteção contra brute-force de login.
 * Usa a tabela totem_login_tentativas para rastrear falhas por IP.
 * Bloqueia por 15 minutos após 5 tentativas em 10 minutos.
 */

define('LOGIN_MAX_TENTATIVAS', 5);
define('LOGIN_JANELA_SEG',     600);   // 10 min
define('LOGIN_BLOQUEIO_SEG',   900);   // 15 min

function loginVerificarBloqueio(PDO $db, string $ip): void
{
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM totem_login_tentativas
             WHERE ip = ? AND tentativa_em > NOW() - INTERVAL '15 minutes' AND bloqueado = TRUE
        ");
        $stmt->execute([$ip]);
        if ((int)$stmt->fetchColumn() > 0) {
            http_response_code(429);
            header('Content-Type: text/html; charset=utf-8');
            // Retornamos HTML pois isso vem do formulário de login, não de uma API
            exit('Muitas tentativas. Aguarde 15 minutos e tente novamente.');
        }

        $stmt2 = $db->prepare("
            SELECT COUNT(*) FROM totem_login_tentativas
             WHERE ip = ? AND tentativa_em > NOW() - INTERVAL '10 minutes' AND bloqueado = FALSE
        ");
        $stmt2->execute([$ip]);
        if ((int)$stmt2->fetchColumn() >= LOGIN_MAX_TENTATIVAS) {
            // Registrar bloqueio
            $db->prepare("
                INSERT INTO totem_login_tentativas (ip, email_tentado, bloqueado)
                VALUES (?, 'BLOQUEIO', TRUE)
            ")->execute([$ip]);
            http_response_code(429);
            exit('Conta temporariamente bloqueada por excesso de tentativas (15 min).');
        }
    } catch (Throwable) {
        // Tabela pode não existir ainda — fail open
    }
}

function loginRegistrarFalha(PDO $db, string $ip, string $email): void
{
    try {
        $db->prepare("
            INSERT INTO totem_login_tentativas (ip, email_tentado) VALUES (?, ?)
        ")->execute([$ip, $email]);
    } catch (Throwable) {}
}

function loginLimparTentativas(PDO $db, string $ip): void
{
    try {
        $db->prepare("DELETE FROM totem_login_tentativas WHERE ip = ?")->execute([$ip]);
    } catch (Throwable) {}
}

<?php
/**
 * TOTP puro PHP — RFC 6238 compatível com Google Authenticator
 * Sem dependências externas.
 */

/**
 * Gera um secret Base32 aleatório de 16 caracteres (80 bits = 10 bytes).
 */
function totpGenerateSecret(): string
{
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes  = random_bytes(10); // 80 bits
    $secret = '';

    // Encode Base32: cada 5 bits → 1 char Base32
    // 10 bytes = 80 bits → 16 chars Base32
    $bits = '';
    for ($i = 0; $i < 10; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < 80; $i += 5) {
        $secret .= $chars[bindec(substr($bits, $i, 5))];
    }

    return $secret;
}

/**
 * Decodifica uma string Base32 para bytes binários.
 */
function totpBase32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret   = strtoupper(str_replace(' ', '', $secret));

    $bits   = '';
    $output = '';

    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $pos = strpos($alphabet, $secret[$i]);
        if ($pos === false) continue; // ignora padding '='
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    // Agrupa de 8 em 8 bits → bytes
    for ($i = 0, $len = strlen($bits); $i + 8 <= $len; $i += 8) {
        $output .= chr(bindec(substr($bits, $i, 8)));
    }

    return $output;
}

/**
 * Calcula um código HOTP para o contador dado (RFC 4226).
 */
function totpHotp(string $keyBytes, int $counter): string
{
    // Empacota o contador como unsigned 64-bit big-endian
    $counterBytes = pack('N*', 0) . pack('N*', $counter);

    $hmac = hash_hmac('sha1', $counterBytes, $keyBytes, true);

    // Dynamic truncation
    $offset = ord($hmac[19]) & 0x0F;

    $code = (
        ((ord($hmac[$offset])     & 0x7F) << 24) |
        ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
        ((ord($hmac[$offset + 2]) & 0xFF) << 8)  |
         (ord($hmac[$offset + 3]) & 0xFF)
    );

    return str_pad((string)($code % 1_000_000), 6, '0', STR_PAD_LEFT);
}

/**
 * Verifica se um código TOTP de 6 dígitos é válido para o secret dado.
 *
 * @param string $secret  Secret Base32 armazenado no banco
 * @param string $code    Código de 6 dígitos informado pelo usuário
 * @param int    $window  Número de janelas de 30 s para tolerar (padrão 1 = ±30 s)
 */
function totpVerify(string $secret, string $code, int $window = 1): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;

    $keyBytes = totpBase32Decode($secret);
    if ($keyBytes === '') return false;

    $step = (int)floor(time() / 30);

    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpHotp($keyBytes, $step + $i), $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Retorna a URI otpauth:// para geração do QR Code.
 */
function totpGetUri(string $secret, string $account, string $issuer = 'Café Comunhão'): string
{
    return 'otpauth://totp/'
        . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret='  . rawurlencode($secret)
        . '&issuer='  . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

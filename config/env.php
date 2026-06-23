<?php
/**
 * Carrega variáveis do arquivo .env na raiz do projeto.
 * Chamado uma vez — leituras subsequentes usam getenv().
 */
(static function (): void {
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) return;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (!isset($_ENV[$key]) && !getenv($key)) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
        }
    }
})();

function env(string $key, mixed $default = null): mixed
{
    $v = getenv($key);
    return $v !== false ? $v : $default;
}

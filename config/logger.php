<?php
/**
 * Structured file logger.
 * Writes JSON-lines to logs/app.log — one JSON object per line.
 */

declare(strict_types=1);

define('LOG_DIR',  __DIR__ . '/../logs');
define('LOG_FILE', LOG_DIR . '/app.log');
define('LOG_MAX',  5 * 1024 * 1024); // 5 MB antes de rotacionar

function logWrite(string $level, string $message, array $context = []): void
{
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0750, true);

    // Rotação simples: renomeia se > 5 MB
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX) {
        @rename(LOG_FILE, LOG_DIR . '/app.' . date('Ymd-His') . '.log');
    }

    $entry = json_encode([
        'ts'      => date('c'),
        'level'   => $level,
        'msg'     => $message,
        'ctx'     => $context,
        'ip'      => $_SERVER['REMOTE_ADDR']  ?? null,
        'uri'     => $_SERVER['REQUEST_URI']  ?? null,
        'user_id' => $_SESSION['admin_id']    ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    @file_put_contents(LOG_FILE, $entry . "\n", FILE_APPEND | LOCK_EX);
}

function logInfo(string $msg, array $ctx = []): void  { logWrite('INFO',  $msg, $ctx); }
function logWarn(string $msg, array $ctx = []): void  { logWrite('WARN',  $msg, $ctx); }
function logError(string $msg, array $ctx = []): void { logWrite('ERROR', $msg, $ctx); }

/**
 * Register as PHP error/exception handler so uncaught errors go to log.
 */
function registerErrorHandlers(): void
{
    set_exception_handler(function (Throwable $e) {
        logError('Uncaught exception', [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
        if (headers_sent()) return;
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    });

    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) return false;
        logWarn('PHP error', [
            'errno'  => $errno,
            'msg'    => $errstr,
            'file'   => $errfile,
            'line'   => $errline,
        ]);
        return false; // Let PHP's default handler also run
    });
}

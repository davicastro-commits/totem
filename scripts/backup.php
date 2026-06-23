<?php
/**
 * Script de backup do banco PostgreSQL.
 * Uso via CLI: php backup.php
 * Uso via admin: GET /admin/backup.php (requer sessão admin)
 *
 * Salva em: backups/comunhao_YYYY-MM-DD_HHmmss.sql.gz
 * Mantém últimos 30 backups (remove os mais antigos).
 */

declare(strict_types=1);

define('BACKUP_DIR', __DIR__ . '/../backups/');
define('KEEP_BACKUPS', 30);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    session_start();
    if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// Configuração do banco
$host   = '192.168.1.237';
$port   = '5432';
$db     = 'comunhao';
$schema = 'material';
$user   = 'postgres';
$pgDump = 'C:/Program Files/PostgreSQL/16/bin/pg_dump.exe';

if (!file_exists($pgDump)) {
    // Tenta no PATH
    $pgDump = 'pg_dump';
}

if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
    // Bloqueia acesso web
    file_put_contents(BACKUP_DIR . '.htaccess', "Deny from all\n");
}

$timestamp = date('Y-m-d_His');
$filename  = "comunhao_{$timestamp}.sql.gz";
$filepath  = BACKUP_DIR . $filename;

// Monta o comando pg_dump
putenv("PGPASSWORD=postgres"); // ajuste a senha se necessário
$cmd = sprintf(
    '"%s" -h %s -p %s -U %s -n %s --format=custom --compress=9 %s',
    $pgDump, $host, $port, $user, $schema, $db
);

$output = [];
$code   = 0;

if ($isCli) {
    echo "Iniciando backup..." . PHP_EOL;
    $tmpFile = $filepath . '.tmp';
    exec($cmd . ' > "' . addslashes($tmpFile) . '" 2>&1', $output, $code);
    if ($code === 0 && file_exists($tmpFile)) {
        rename($tmpFile, str_replace('.gz', '.dump', $filepath));
        $filepath = str_replace('.gz', '.dump', $filepath);
        echo "Backup salvo: {$filepath}" . PHP_EOL;
    } else {
        echo "Erro ao gerar backup (code={$code}):" . PHP_EOL;
        echo implode(PHP_EOL, $output) . PHP_EOL;
        exit(1);
    }
} else {
    $tmpFile = $filepath . '.tmp';
    exec($cmd . ' > "' . addslashes($tmpFile) . '" 2>&1', $output, $code);
    if ($code === 0 && file_exists($tmpFile)) {
        $outFile = str_replace('.gz', '.dump', $filepath);
        rename($tmpFile, $outFile);
        $size = round(filesize($outFile) / 1024, 1);
        cleanOldBackups();
        echo json_encode([
            'success'  => true,
            'filename' => basename($outFile),
            'size_kb'  => $size,
            'message'  => "Backup criado: {$size} KB",
        ]);
    } else {
        @unlink($tmpFile);
        echo json_encode([
            'success' => false,
            'error'   => 'pg_dump falhou: ' . implode(' | ', $output),
        ]);
    }
    exit;
}

function cleanOldBackups(): void
{
    $files = glob(BACKUP_DIR . 'comunhao_*.dump');
    if (!$files) return;
    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
    while (count($files) > KEEP_BACKUPS) {
        $old = array_shift($files);
        @unlink($old);
    }
}

cleanOldBackups();

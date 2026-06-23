<?php
/**
 * Utilitário de reset do admin — SOMENTE VIA LINHA DE COMANDO.
 * Uso: php reset_admin.php
 * O acesso via navegador é bloqueado pelo .htaccess.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

require_once __DIR__ . '/config/db.php';

echo "=== Reset de Administrador ===\n";
echo "Email [admin@totem.com]: ";
$email = trim(fgets(STDIN)) ?: 'admin@totem.com';
echo "Nova senha: ";
$senha = trim(fgets(STDIN));
if (strlen($senha) < 8) {
    echo "Erro: a senha deve ter pelo menos 8 caracteres.\n";
    exit(1);
}
echo "Nome [Administrador]: ";
$nome = trim(fgets(STDIN)) ?: 'Administrador';

$hash = password_hash($senha, PASSWORD_BCRYPT);

try {
    $db = getDB();
    $db->beginTransaction();
    $db->prepare("DELETE FROM totem_admin WHERE email = ?")->execute([$email]);
    $db->prepare("INSERT INTO totem_admin (nome, email, senha, role) VALUES (?, ?, ?, 'admin')")
       ->execute([$nome, $email, $hash]);
    $db->commit();
    echo "Admin criado com sucesso!\n";
    echo "Email: {$email}\n";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}

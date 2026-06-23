<?php
echo "<pre>";
echo "PHP: " . PHP_VERSION . "\n";
echo "PDO drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n\n";

require_once 'config/db.php';

try {
    $db = getDB();
    echo "✅ Conexão OK!\n";
    $v = $db->query("SELECT version()")->fetchColumn();
    echo "PostgreSQL: " . $v . "\n";
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
echo "</pre>";

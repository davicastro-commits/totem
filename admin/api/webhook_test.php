<?php
require_once '../../config/db.php';
require_once '../../config/auth.php';
require_once '../../config/webhook.php';

requireAdmin();
header('Content-Type: application/json');

$ok = triggerN8n('teste', [
    'mensagem'  => '✅ Integração Café Comunhão + n8n funcionando!',
    'sistema'   => 'Café Comunhão — Sistema de Gestão',
    'usuario'   => $_SESSION['admin_nome'] ?? 'Admin',
    'horario'   => date('d/m/Y H:i:s'),
]);

echo json_encode([
    'success' => $ok,
    'msg'     => $ok ? 'Webhook disparado com sucesso!' : 'Falha ao conectar com o n8n. Verifique N8N_WEBHOOK_BASE no .env',
]);

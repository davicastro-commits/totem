<?php
/**
 * Dispara eventos para o n8n via webhook.
 * Configura N8N_WEBHOOK_BASE no .env (sem barra final).
 * Ex: N8N_WEBHOOK_BASE=http://192.168.1.100:5678/webhook
 */
function triggerN8n(string $event, array $data): bool {
    $base = rtrim(getenv('N8N_WEBHOOK_BASE') ?: '', '/');
    if (!$base) return false;

    $url = $base . '/' . $event;
    $payload = json_encode(array_merge($data, [
        '_event'     => $event,
        '_timestamp' => date('c'),
        '_origem'    => 'cafe-comunhao',
    ]));

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
        'content'       => $payload,
        'timeout'       => 4,
        'ignore_errors' => true,
    ]]);

    $result = @file_get_contents($url, false, $ctx);
    return $result !== false;
}

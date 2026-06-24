<?php
declare(strict_types=1);

/**
 * Dispara eventos para o n8n via webhook.
 * URL e toggles de evento são lidos da tabela totem_configuracoes.
 */
function triggerN8n(string $event, array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        $db = getDB();

        // Verificar se este evento específico está habilitado
        $eventKey = match ($event) {
            'novo_pedido'    => 'webhook_novo_pedido',
            'status_pedido'  => 'webhook_status',
            'alerta_estoque' => 'webhook_estoque',
            default          => null,
        };

        if ($eventKey !== null) {
            $ckStmt = $db->prepare("SELECT valor FROM totem_configuracoes WHERE chave = ?");
            $ckStmt->execute([$eventKey]);
            if ($ckStmt->fetchColumn() !== '1') return false;
        }

        // Buscar URL base do webhook
        $baseStmt = $db->prepare("SELECT valor FROM totem_configuracoes WHERE chave = 'n8n_webhook_base'");
        $baseStmt->execute();
        $base = rtrim((string)($baseStmt->fetchColumn() ?: ''), '/');
        if (!$base) return false;

        // Buscar número WhatsApp para incluir nos dados
        $zapStmt = $db->prepare("SELECT valor FROM totem_configuracoes WHERE chave = 'n8n_whatsapp'");
        $zapStmt->execute();
        $whatsapp = $zapStmt->fetchColumn() ?: '';

        $payload = json_encode(array_merge($data, [
            '_event'      => $event,
            '_timestamp'  => date('c'),
            '_origem'     => 'cafe-comunhao',
            '_whatsapp'   => $whatsapp,
        ]), JSON_UNESCAPED_UNICODE);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
            'content'       => $payload,
            'timeout'       => 4,
            'ignore_errors' => true,
        ]]);

        $result = @file_get_contents($base . '/' . $event, false, $ctx);
        return $result !== false;

    } catch (Throwable $e) {
        error_log('[webhook] triggerN8n erro: ' . $e->getMessage());
        return false;
    }
}

/**
 * Dispara alerta de estoque via WhatsApp se configurado.
 */
function alertarEstoqueBaixo(PDO $db, string $nomeInsumo, float $estoqueAtual, string $unidade): void
{
    try {
        $zapAtivo = $db->query("SELECT valor FROM totem_configuracoes WHERE chave = 'alerta_estoque_zap'")->fetchColumn();
        if ($zapAtivo !== '1') return;

        triggerN8n('alerta_estoque', [
            'insumo'         => $nomeInsumo,
            'estoque_atual'  => $estoqueAtual,
            'unidade'        => $unidade,
            'mensagem'       => "⚠️ Estoque baixo: {$nomeInsumo} — {$estoqueAtual} {$unidade} restante(s)",
        ]);
    } catch (Throwable $e) {
        error_log('[webhook] alertarEstoqueBaixo erro: ' . $e->getMessage());
    }
}

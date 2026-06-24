<?php
/**
 * admin/api/email.php — API de configuração de e-mail e relatório semanal.
 *
 * Requer autenticação de administrador + CSRF.
 *
 * GET  ?action=config         → retorna configurações (sem senha)
 * POST {action:'salvar_config',...}  → salva configurações no DB
 * POST {action:'testar'}      → envia e-mail de teste
 * POST {action:'enviar_agora'} → dispara relatório semanal imediatamente
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mailer.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Helper: lê todas as configurações como array chave => valor ──────────────
function getAllConfig(PDO $db): array
{
    $rows = $db->query("SELECT chave, valor FROM material.totem_configuracoes ORDER BY chave")->fetchAll();
    $cfg = [];
    foreach ($rows as $r) {
        $cfg[$r['chave']] = $r['valor'];
    }
    return $cfg;
}

// ── Helper: salva uma chave na tabela de configurações ───────────────────────
function setConfig(PDO $db, string $chave, string $valor): void
{
    $stmt = $db->prepare("
        INSERT INTO material.totem_configuracoes (chave, valor, atualizado_em)
        VALUES (?, ?, NOW())
        ON CONFLICT (chave) DO UPDATE
          SET valor = EXCLUDED.valor, atualizado_em = NOW()
    ");
    $stmt->execute([$chave, $valor]);
}

try {
    $db = getDB();

    // ── GET: retorna configurações (omite a senha) ───────────────────────────
    if ($method === 'GET' && $action === 'config') {
        $cfg = getAllConfig($db);

        // Últimos 10 logs de envio
        $logs = [];
        try {
            $logs = $db->query("
                SELECT id, enviado_em, destinatario, assunto, status, mensagem,
                       periodo_ini, periodo_fim
                FROM material.totem_email_log
                ORDER BY enviado_em DESC
                LIMIT 10
            ")->fetchAll();
        } catch (Throwable) {}

        echo json_encode([
            'success' => true,
            'data' => [
                'smtp_host'    => $cfg['email_smtp_host']        ?? '',
                'smtp_port'    => $cfg['email_smtp_port']        ?? '587',
                'smtp_user'    => $cfg['email_smtp_user']        ?? '',
                // senha omitida intencionalmente
                'smtp_from'    => $cfg['email_smtp_from']        ?? '',
                'from_nome'    => $cfg['email_smtp_from_nome']   ?? 'Café Comunhão',
                'destino'      => $cfg['relatorio_email_destino'] ?? '',
                'ativo'        => ($cfg['relatorio_email_ativo'] ?? 'false') === 'true',
                'dia_semana'   => $cfg['relatorio_email_dia']    ?? '1',
                'hora'         => $cfg['relatorio_email_hora']   ?? '08',
                'token_secreto'=> !empty($cfg['relatorio_token_secreto']),
            ],
            'logs' => $logs,
        ]);
        exit;
    }

    // ── POST: lê o body JSON ─────────────────────────────────────────────────
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método não permitido']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Payload inválido']);
        exit;
    }

    $postAction = $body['action'] ?? $action;

    // ── Salvar configurações ─────────────────────────────────────────────────
    if ($postAction === 'salvar_config') {
        requireAdminRole('admin');

        $campos = [
            'email_smtp_host'          => 'smtp_host',
            'email_smtp_port'          => 'smtp_port',
            'email_smtp_user'          => 'smtp_user',
            'email_smtp_from'          => 'smtp_from',
            'email_smtp_from_nome'     => 'from_nome',
            'relatorio_email_destino'  => 'destino',
            'relatorio_email_ativo'    => 'ativo',
            'relatorio_email_dia'      => 'dia_semana',
            'relatorio_email_hora'     => 'hora',
        ];

        $db->beginTransaction();
        foreach ($campos as $chaveDb => $chaveBody) {
            if (!array_key_exists($chaveBody, $body)) continue;
            $val = $chaveBody === 'ativo'
                ? ($body[$chaveBody] ? 'true' : 'false')
                : (string)$body[$chaveBody];
            setConfig($db, $chaveDb, $val);
        }

        // Senha: só atualiza se foi enviada (não vazia)
        if (!empty($body['smtp_pass'])) {
            setConfig($db, 'email_smtp_pass', (string)$body['smtp_pass']);
        }

        // Token secreto: gera se não existir
        $cfg = getAllConfig($db);
        if (empty($cfg['relatorio_token_secreto'])) {
            setConfig($db, 'relatorio_token_secreto', bin2hex(random_bytes(24)));
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Configurações salvas.']);
        exit;
    }

    // ── Testar envio ─────────────────────────────────────────────────────────
    if ($postAction === 'testar') {
        $cfg = getAllConfig($db);

        $smtpConfig = [
            'host'      => $cfg['email_smtp_host']      ?? '',
            'port'      => (int)($cfg['email_smtp_port'] ?? 587),
            'user'      => $cfg['email_smtp_user']      ?? '',
            'pass'      => $cfg['email_smtp_pass']      ?? '',
            'from'      => $cfg['email_smtp_from']      ?? '',
            'from_nome' => $cfg['email_smtp_from_nome'] ?? 'Café Comunhão',
        ];
        $destino = $cfg['relatorio_email_destino'] ?? '';

        if (empty($smtpConfig['host'])) {
            echo json_encode(['success' => false, 'error' => 'SMTP host não configurado.']);
            exit;
        }
        if (empty($destino)) {
            echo json_encode(['success' => false, 'error' => 'E-mail de destino não configurado.']);
            exit;
        }

        $htmlTeste = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body style="background:#0d0f17;color:#f0f2f8;font-family:Arial,sans-serif;padding:40px">'
            . '<div style="max-width:500px;margin:0 auto;background:#1a1c27;border-radius:12px;padding:32px;text-align:center">'
            . '<div style="font-size:48px;margin-bottom:16px">☕</div>'
            . '<h1 style="color:#ff5500;font-size:22px;margin-bottom:12px">E-mail de Teste</h1>'
            . '<p style="color:#9ca3af">As configurações de SMTP do <strong style="color:#f0f2f8">Café Comunhão</strong> estão funcionando corretamente!</p>'
            . '<p style="color:#6b7280;font-size:12px;margin-top:20px">Enviado em ' . date('d/m/Y H:i:s') . '</p>'
            . '</div></body></html>';

        $resultado = smtpSend($destino, '☕ Teste de E-mail — Café Comunhão', $htmlTeste, $smtpConfig);

        if ($resultado === true) {
            echo json_encode(['success' => true, 'message' => "E-mail de teste enviado para {$destino}."]);
        } else {
            echo json_encode(['success' => false, 'error' => (string)$resultado]);
        }
        exit;
    }

    // ── Enviar relatório agora ───────────────────────────────────────────────
    if ($postAction === 'enviar_agora') {
        $cfg = getAllConfig($db);
        $token = $cfg['relatorio_token_secreto'] ?? '';

        if (empty($token)) {
            // Gera token se não existir
            $token = bin2hex(random_bytes(24));
            setConfig($db, 'relatorio_token_secreto', $token);
        }

        // Determina a URL base
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl  = $scheme . '://' . $host;
        // Calcula o caminho relativo da raiz do projeto
        $scriptUrl = $baseUrl . '/totem/scripts/relatorio_semanal.php?token=' . urlencode($token);

        // Tenta via HTTP interno (mais simples que fork/exec)
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 60,
                'header'  => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($scriptUrl, false, $ctx);

        if ($response === false) {
            echo json_encode(['success' => false, 'error' => 'Não foi possível acionar o script de relatório via HTTP. Verifique se o servidor web está acessível.']);
            exit;
        }

        $json = json_decode($response, true);
        if (is_array($json)) {
            echo json_encode($json);
        } else {
            echo json_encode(['success' => true, 'message' => 'Relatório disparado.', 'raw' => substr($response, 0, 200)]);
        }
        exit;
    }

    // ── URL de prévia do relatório ───────────────────────────────────────────
    if ($postAction === 'preview_url') {
        $cfg = getAllConfig($db);
        $token = $cfg['relatorio_token_secreto'] ?? '';

        if (empty($token)) {
            $token = bin2hex(random_bytes(24));
            setConfig($db, 'relatorio_token_secreto', $token);
        }

        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/totem/scripts/relatorio_semanal.php'
             . '?token=' . urlencode($token) . '&preview=1';

        echo json_encode(['success' => true, 'url' => $url]);
        exit;
    }

    // ── Ação desconhecida ────────────────────────────────────────────────────
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Ação desconhecida: {$postAction}"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

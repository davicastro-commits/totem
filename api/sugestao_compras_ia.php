<?php
/**
 * Endpoint: Sugestão de Compras com IA
 * GET /api/sugestao_compras_ia.php?orcamento=5000
 *
 * Requer autenticação de admin.
 * Usa a Claude API via cURL direto (sem dependências externas).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/estoque_inteligente.php';
require_once __DIR__ . '/../admin/api/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonErr('Método não permitido', 405);
}

requireAdmin();

$apiKey = env('ANTHROPIC_API_KEY', '');
if (!$apiKey) {
    jsonErr('ANTHROPIC_API_KEY não configurada. Adicione no arquivo .env.', 500);
}

try {
    $db   = getDB();
    $stmt = $db->query("SELECT * FROM totem_insumos WHERE ativo = true ORDER BY nome ASC");
    $todos = $stmt->fetchAll();

    $criticos = [];
    $frios    = [];

    foreach ($todos as $ins) {
        $nivel = nivelAlerta($ins);
        $item  = [
            'nome'          => $ins['nome'],
            'estoque_atual' => round((float)$ins['estoque_atual'], 3),
            'unidade'       => $ins['unidade'],
            'rop'           => round((float)($ins['rop'] ?: $ins['estoque_minimo']), 3),
            'eoq'           => round((float)($ins['eoq'] ?? 0), 3),
            'safety_stock'  => round((float)($ins['safety_stock'] ?? 0), 3),
            'custo_medio'   => round((float)$ins['custo_medio'], 4),
            'classe_abc'    => $ins['classe_abc'] ?: '?',
            'nivel'         => $nivel,
        ];

        if (in_array($nivel, ['CRITICO', 'URGENTE', 'ATENCAO'])) {
            $criticos[] = $item;
        } elseif ($nivel === 'FRIO') {
            $frios[] = $item;
        }
    }

    if (empty($criticos) && empty($frios)) {
        jsonOk([
            'mensagem'            => 'Estoque saudável! Nenhum item em alerta no momento.',
            'itens'               => [],
            'total_estimado'      => 0,
            'observacoes'         => 'Todos os insumos estão dentro dos limites de ROP e Safety Stock.',
            'insumos_analisados'  => count($todos),
        ]);
    }

    $orcamento = max(0, (float)filter_input(INPUT_GET, 'orcamento', FILTER_VALIDATE_FLOAT) ?: 5000.0);
    $hoje      = date('Y-m-d');

    // Montar prompt
    $prompt  = "Data: {$hoje}\n";
    $prompt .= "Orcamento disponivel para compras: R\$ " . number_format($orcamento, 2, '.', '') . "\n\n";

    if ($criticos) {
        $prompt .= "INSUMOS EM ALERTA (precisam de reposicao imediata):\n";
        $prompt .= json_encode($criticos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    if ($frios) {
        $prompt .= "\nINSUMOS COM EXCESSO (estoque frio - evitar comprar):\n";
        $prompt .= json_encode($frios, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    $prompt .= "\nCom base nesses dados, retorne APENAS um JSON valido (sem markdown, sem texto adicional):\n";
    $prompt .= '{"itens":[{"nome":"nome do insumo","quantidade_sugerida":0.0,"justificativa":"motivo em 1 frase","prioridade":"ALTA"}],"total_estimado":0.0,"observacoes":"resumo geral"}';
    $prompt .= "\n\nprioridade deve ser: ALTA (CRITICO/URGENTE), MEDIA (ATENCAO) ou BAIXA.\n";
    $prompt .= "quantidade_sugerida deve ser proxima ao EOQ quando disponivel.\n";
    $prompt .= "total_estimado = soma(quantidade_sugerida * custo_medio) de cada item.\n";
    $prompt .= "Se o total estimado ultrapassar o orcamento, priorize itens ALTA e ajuste quantidades.";

    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1500,
        'system'     => (
            'Voce e um especialista em gestao de estoque para o Cafe Comunhao. ' .
            'Sua funcao e sugerir compras otimizadas, priorizando ruptura de estoque, ' .
            'respeitando o orcamento disponivel e usando EOQ como quantidade base. ' .
            'Retorne APENAS JSON valido, sem markdown, sem texto antes ou depois.'
        ),
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_UNICODE);

    // Chamada à API da Anthropic via cURL
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[sugestao_compras_ia] cURL error: ' . $curlErr);
        jsonErr('Erro de conexão com a API da Anthropic: ' . $curlErr, 502);
    }

    $resp = json_decode($raw, true);

    if ($httpCode !== 200 || empty($resp['content'][0]['text'])) {
        $msg = $resp['error']['message'] ?? ('HTTP ' . $httpCode);
        error_log('[sugestao_compras_ia] API error: ' . $msg . ' | raw: ' . $raw);
        jsonErr('Erro na Claude API: ' . $msg, 502);
    }

    $texto = trim($resp['content'][0]['text']);

    // Remover possíveis backticks de markdown
    if (str_starts_with($texto, '```')) {
        $texto = preg_replace('/^```\w*\n?/', '', $texto);
        $texto = preg_replace('/\n?```$/', '', trim($texto));
    }

    $sugestao = json_decode(trim($texto), true);
    if (!is_array($sugestao)) {
        error_log('[sugestao_compras_ia] JSON decode failed: ' . $texto);
        jsonErr('A IA retornou uma resposta em formato inválido.', 500);
    }

    jsonOk([
        'sugestao'           => $sugestao,
        'insumos_analisados' => count($todos),
        'em_alerta'          => count($criticos),
        'estoque_frio'       => count($frios),
        'orcamento'          => $orcamento,
    ]);

} catch (PDOException $e) {
    error_log('[sugestao_compras_ia] PDOException: ' . $e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    error_log('[sugestao_compras_ia] Error: ' . $e->getMessage());
    jsonErr('Erro interno do servidor', 500);
}

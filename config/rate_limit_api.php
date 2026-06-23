<?php
declare(strict_types=1);

/**
 * Rate limiting simples para APIs públicas.
 * Usa arquivos em sys_get_temp_dir() para rastrear requisições por IP.
 * Chame rateLimit('pedido', 10, 60) no topo de APIs públicas.
 *
 * @param string $scope   Identificador do recurso (ex: 'pedido', 'status')
 * @param int    $max     Máximo de requisições permitidas na janela
 * @param int    $janela  Tamanho da janela em segundos
 */
function rateLimit(string $scope, int $max = 20, int $janela = 60): void
{
    $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip   = trim(explode(',', $ip)[0]);
    $key  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $ip);
    $file = sys_get_temp_dir() . '/rl_' . $scope . '_' . $key . '.json';

    $now     = time();
    $janela  = max(1, $janela);
    $entries = [];

    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            // Remove entradas fora da janela
            $entries = array_filter($data, fn($t) => $t > $now - $janela);
        }
    }

    if (count($entries) >= $max) {
        $oldest  = min($entries);
        $resetIn = ($oldest + $janela) - $now;
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . max(1, $resetIn));
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => 'Muitas requisições. Tente novamente em ' . max(1, $resetIn) . 's.',
        ]);
        exit;
    }

    $entries[] = $now;
    @file_put_contents($file, json_encode(array_values($entries)), LOCK_EX);
}

<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
require_once '../config/db.php';

// Chaves públicas — não expõe configurações internas/sensíveis
$CHAVES_PUBLICAS = [
    'loja_nome', 'loja_cnpj', 'loja_endereco', 'loja_telefone', 'loja_logo_url', 'loja_url',
    'totem_idle_segundos', 'totem_confirmar_segundos',
];

try {
    $db   = getDB();
    $ph   = implode(',', array_fill(0, count($CHAVES_PUBLICAS), '?'));
    $stmt = $db->prepare("SELECT chave, valor FROM totem_configuracoes WHERE chave IN ({$ph})");
    $stmt->execute($CHAVES_PUBLICAS);
    $rows = $stmt->fetchAll();

    $config = [];
    foreach ($rows as $r) {
        $config[$r['chave']] = $r['valor'];
    }

    // Defaults caso a migration não tenha rodado ainda
    $defaults = [
        'loja_nome'                  => 'Café Comunhão',
        'loja_cnpj'                  => '',
        'loja_endereco'              => '',
        'loja_telefone'              => '',
        'loja_logo_url'              => '',
        'loja_url'                   => '',
        'totem_idle_segundos'        => '120',
        'totem_confirmar_segundos'   => '30',
    ];
    foreach ($defaults as $k => $v) {
        if (!isset($config[$k])) $config[$k] = $v;
    }

    echo json_encode(['success' => true, 'data' => $config]);
} catch (PDOException $e) {
    // Retornar defaults mesmo sem banco
    echo json_encode(['success' => true, 'data' => [
        'loja_nome'  => 'Café Comunhão',
        'loja_cnpj'  => '',
        'loja_endereco' => '',
        'loja_telefone' => '',
        'loja_logo_url' => '',
        'totem_idle_segundos' => '120',
        'totem_confirmar_segundos' => '30',
    ]]);
}

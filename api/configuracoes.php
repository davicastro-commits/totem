<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once '../config/db.php';

// Chaves públicas — não expõe configurações internas/sensíveis
$CHAVES_PUBLICAS = [
    'loja_nome', 'loja_cnpj', 'loja_endereco', 'loja_telefone', 'loja_logo_url', 'loja_url',
    'loja_email', 'loja_instagram',
    'totem_idle_segundos', 'totem_confirmar_segundos',
    'totem_mensagem_boasvindas', 'totem_max_itens_pedido',
    'pagamento_pix_ativo', 'pagamento_credito_ativo', 'pagamento_debito_ativo', 'pagamento_dinheiro_ativo',
    'taxa_servico_ativa', 'taxa_servico_percentual',
    'totem_autoreload_minutos', 'totem_aviso_fechamento_min',
    // Horários de funcionamento
    'horario_seg_ativo','horario_seg_abertura','horario_seg_fechamento',
    'horario_ter_ativo','horario_ter_abertura','horario_ter_fechamento',
    'horario_qua_ativo','horario_qua_abertura','horario_qua_fechamento',
    'horario_qui_ativo','horario_qui_abertura','horario_qui_fechamento',
    'horario_sex_ativo','horario_sex_abertura','horario_sex_fechamento',
    'horario_sab_ativo','horario_sab_abertura','horario_sab_fechamento',
    'horario_dom_ativo','horario_dom_abertura','horario_dom_fechamento',
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
        'loja_email'                 => '',
        'loja_instagram'             => '',
        'totem_idle_segundos'        => '120',
        'totem_confirmar_segundos'   => '30',
        'totem_mensagem_boasvindas'  => '',
        'totem_max_itens_pedido'     => '20',
        'pagamento_pix_ativo'        => '1',
        'pagamento_credito_ativo'    => '1',
        'pagamento_debito_ativo'     => '1',
        'pagamento_dinheiro_ativo'   => '1',
        'taxa_servico_ativa'              => '0',
        'taxa_servico_percentual'         => '0',
        'totem_autoreload_minutos'        => '0',
        'totem_aviso_fechamento_min'      => '10',
    ];
    // Defaults de horário (sem restrição se não configurado)
    $dias = ['seg','ter','qua','qui','sex','sab','dom'];
    foreach ($dias as $d) {
        $defaults["horario_{$d}_ativo"]     = '1';
        $defaults["horario_{$d}_abertura"]  = '00:00';
        $defaults["horario_{$d}_fechamento"]= '23:59';
    }
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

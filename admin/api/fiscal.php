<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once '../../config/audit.php';
require_once '../../config/NfceBuilder.php';
require_once '../../config/SefazMock.php';
require_once 'auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($body['action'] ?? '');

// Tabela de tradução de rejeições SEFAZ
$TRADUCOES_REJEICAO = [
    '539' => 'Duplicidade de NF-e: nota já foi enviada com este número.',
    '591' => 'O número da nota já foi usado anteriormente.',
    '204' => 'Remetente com IE inválida no estado de destino.',
    '205' => 'CNPJ do emitente inválido.',
    '206' => 'IE do emitente inválida.',
    '209' => 'IE do destinatário inválida.',
    '228' => 'Data de emissão muito antiga (prazo excedido).',
    '236' => 'Chave de acesso inválida.',
    '238' => 'Número máximo de numeração atingido.',
    '243' => 'CPF do destinatário inválido.',
    '244' => 'CNPJ do destinatário inválido.',
    '246' => 'NCM inválido.',
    '247' => 'CFOP inválido.',
    '357' => 'Certificado transmissor inválido.',
    '359' => 'Certificado de transmissão expirado.',
    '402' => 'Rejeição: Falha no schema XML da nota.',
    '409' => 'Data de emissão fora do prazo de transmissão.',
    '410' => 'UF do emitente diverge da UF de recepção.',
    '411' => 'Série da NF-e fora do intervalo permitido.',
    '453' => 'Destinatário não habilitado para receber NF-e.',
    '550' => 'CNPJ-Base do emitente difere do CNPJ-Base da nota.',
    '561' => 'PIS ou COFINS inválidos.',
    '999' => 'Erro interno da SEFAZ — tente novamente em instantes.',
];

function getCfg($db, array $chaves): array {
    $placeholders = implode(',', array_fill(0, count($chaves), '?'));
    $stmt = $db->prepare("SELECT chave, valor FROM totem_configuracoes WHERE chave IN ({$placeholders})");
    $stmt->execute($chaves);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['chave']] = $r['valor'];
    return $out;
}

function saveCfg($db, string $chave, string $valor): void {
    $db->prepare("INSERT INTO totem_configuracoes (chave, valor) VALUES (?,?) ON CONFLICT (chave) DO UPDATE SET valor=EXCLUDED.valor, atualizado_em=NOW()")
       ->execute([$chave, $valor]);
}

function proximoNumero($db): int {
    $atual = (int)($db->query("SELECT COALESCE(valor,'0') FROM totem_configuracoes WHERE chave='nfce_numero_atual'")->fetchColumn() ?: 0);
    $novo  = $atual + 1;
    $db->prepare("INSERT INTO totem_configuracoes (chave, valor) VALUES ('nfce_numero_atual',?) ON CONFLICT (chave) DO UPDATE SET valor=EXCLUDED.valor")->execute([$novo]);
    return $novo;
}

try {
    $db = getDB();

    // ─── GET dashboard ────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'dashboard') {
        $hoje = date('Y-m-d');

        $m = $db->prepare("
            SELECT
                COUNT(*)           FILTER (WHERE DATE(criado_em AT TIME ZONE 'America/Sao_Paulo') = ?) AS total,
                COUNT(*)           FILTER (WHERE DATE(criado_em AT TIME ZONE 'America/Sao_Paulo') = ? AND status = 'autorizada') AS autorizadas,
                COUNT(*)           FILTER (WHERE DATE(criado_em AT TIME ZONE 'America/Sao_Paulo') = ? AND status = 'rejeitada')  AS rejeitadas,
                COUNT(*)           FILTER (WHERE DATE(criado_em AT TIME ZONE 'America/Sao_Paulo') = ? AND status = 'cancelada')  AS canceladas,
                COUNT(*)           FILTER (WHERE status = 'contingencia')  AS contingencia,
                COALESCE(SUM(total) FILTER (WHERE DATE(criado_em AT TIME ZONE 'America/Sao_Paulo') = ? AND status IN ('autorizada','transmitindo')), 0) AS vendas
            FROM totem_nfce
        ");
        $m->execute([$hoje,$hoje,$hoje,$hoje,$hoje]);
        $met = $m->fetch();

        // Emissão recente
        $recStmt = $db->prepare("
            SELECT n.id, n.numero, n.serie, n.status, n.total, n.forma_pagamento,
                   n.criado_em, n.autorizado_em, n.motivo_rejeicao, n.protocolo,
                   p.numero_pedido
            FROM totem_nfce n
            LEFT JOIN totem_pedidos p ON p.id = n.pedido_id
            ORDER BY n.criado_em DESC
            LIMIT 15
        ");
        $recStmt->execute();
        $emissoes = $recStmt->fetchAll();

        // Rejeições recentes
        $rejStmt = $db->prepare("
            SELECT r.codigo, r.descricao_pt, r.criado_em, n.numero
            FROM totem_nfce_rejeicoes r
            JOIN totem_nfce n ON n.id = r.nfce_id
            WHERE r.criado_em >= NOW() - INTERVAL '24 hours'
            ORDER BY r.criado_em DESC LIMIT 10
        ");
        $rejStmt->execute();
        $rejeicoes = $rejStmt->fetchAll();

        // Config
        $cfg = getCfg($db, ['nfce_ativo','nfce_serie','nfce_numero_atual','nfce_ambiente',
                             'nfce_regime','nfce_uf','nfce_cert_validade','nfce_cnpj']);

        // Taxa de autorização
        $denominador = max(1, (int)$met['total'] - (int)$met['canceladas']);
        $taxa = round((int)$met['autorizadas'] / $denominador * 100, 1);

        // Status geral
        $cont  = (int)$met['contingencia'];
        $rejH  = (int)$met['rejeitadas'];
        if ($rejH > 0) {
            $sType = 'danger';
            $sTitle = 'ATENÇÃO — REJEIÇÕES PENDENTES';
            $sSub   = $rejH.' nota(s) rejeitada(s) aguardando correção.';
        } elseif ($cont > 0) {
            $sType = 'warning';
            $sTitle = 'CONTINGÊNCIA ATIVA';
            $sSub   = $cont.' nota(s) na fila de transmissão.';
        } else {
            $sType = 'ok';
            $sTitle = 'TUDO OPERANDO NORMALMENTE';
            $sSub   = 'Notas autorizadas em tempo real. Pré-validação ativa antes de cada envio.';
        }

        // Prazos — certificado
        $certVal = $cfg['nfce_cert_validade'] ?? '';
        if ($certVal) {
            $dias = (int)ceil((strtotime($certVal) - time()) / 86400);
            $certPrazo = $dias <= 0 ? ['label'=>'VENCIDO','tipo'=>'danger']
                       : ($dias <= 30 ? ['label'=>"vence em {$dias} dias",'tipo'=>'alert']
                                      : ['label'=>"válido por {$dias} dias",'tipo'=>'ok']);
        } else {
            $certPrazo = ['label'=>'não configurado','tipo'=>'alert'];
        }

        // Prazos — verificação real de gaps na numeração
        $serie = $cfg['nfce_serie'] ?? '001';
        $gapStmt = $db->prepare("
            SELECT numero, LAG(numero) OVER (PARTITION BY serie ORDER BY numero) AS anterior
            FROM totem_nfce
            WHERE serie = ?
            ORDER BY numero
        ");
        $gapStmt->execute([$serie]);
        $numeracao = $gapStmt->fetchAll();

        $gaps = [];
        foreach ($numeracao as $row) {
            if ($row['anterior'] !== null && ((int)$row['numero'] - (int)$row['anterior']) > 1) {
                $from = (int)$row['anterior'] + 1;
                $to   = (int)$row['numero'] - 1;
                $gaps[] = $from === $to ? "#$from" : "#$from–#$to";
            }
        }

        if (empty($gaps)) {
            $numPrazo = ['label' => 'sem falhas', 'tipo' => 'ok', 'gaps' => []];
        } else {
            $qtd     = count($gaps);
            $exemplo = implode(', ', array_slice($gaps, 0, 3)) . ($qtd > 3 ? '…' : '');
            $numPrazo = [
                'label' => $qtd === 1 ? "salto detectado: {$gaps[0]}" : "{$qtd} saltos: {$exemplo}",
                'tipo'  => 'danger',
                'gaps'  => $gaps,
            ];
        }

        echo json_encode([
            'success'         => true,
            'status_type'     => $sType,
            'status_title'    => $sTitle,
            'status_sub'      => $sSub,
            'vendas_hoje'     => (float)$met['vendas'],
            'notas_hoje'      => (int)$met['total'],
            'autorizadas'     => (int)$met['autorizadas'],
            'rejeitadas'      => $rejH,
            'canceladas'      => (int)$met['canceladas'],
            'contingencia'    => $cont,
            'taxa_autorizacao'=> $taxa,
            'emissao_recente' => $emissoes,
            'rejeicoes'       => $rejeicoes,
            'prazos'          => [
                'certificado' => $certPrazo,
                'contingencia'=> ['label'=> $cont > 0 ? "fila: {$cont} nota(s)" : 'dentro do prazo', 'tipo'=> $cont > 0 ? 'alert' : 'ok'],
                'numeracao'   => $numPrazo,
            ],
            'config'          => [
                'ativo'        => ($cfg['nfce_ativo'] ?? '0') === '1',
                'serie'        => $cfg['nfce_serie']        ?? '001',
                'numero_atual' => (int)($cfg['nfce_numero_atual'] ?? 0),
                'ambiente'     => $cfg['nfce_ambiente']     ?? 'homologacao',
                'regime'       => $cfg['nfce_regime']       ?? '1',
                'uf'           => $cfg['nfce_uf']           ?? '',
                'cnpj'         => $cfg['nfce_cnpj']         ?? '',
            ],
        ]);
        exit;
    }

    // ─── GET config ───────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'config') {
        $cfg = getCfg($db, ['nfce_ativo','nfce_cnpj','nfce_ie','nfce_uf','nfce_serie',
                             'nfce_ambiente','nfce_regime','nfce_csc','nfce_csc_id',
                             'nfce_cert_validade','nfce_numero_atual']);
        echo json_encode(['success' => true, 'data' => $cfg]);
        exit;
    }

    // ─── POST ─────────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true);
        $act   = $body['action'] ?? '';

        // Salvar config
        if ($act === 'salvar_config') {
            requireRole('admin');
            $campos = ['nfce_ativo','nfce_cnpj','nfce_ie','nfce_uf','nfce_serie',
                       'nfce_ambiente','nfce_regime','nfce_csc','nfce_csc_id',
                       'nfce_cert_validade'];
            foreach ($campos as $c) {
                if (array_key_exists($c, $body)) saveCfg($db, $c, (string)$body[$c]);
            }
            auditLog($db, 'nfce_config_salva', 'fiscal', null, 'Configuração NFC-e atualizada');
            echo json_encode(['success' => true]);
            exit;
        }

        // Criar NFC-e para um pedido
        if ($act === 'criar_nfce') {
            $pedidoId = (int)($body['pedido_id'] ?? 0);
            if (!$pedidoId) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'pedido_id obrigatório']); exit; }

            // Verificar se já existe
            $exist = $db->prepare("SELECT id, status FROM totem_nfce WHERE pedido_id=?");
            $exist->execute([$pedidoId]);
            if ($row = $exist->fetch()) {
                echo json_encode(['success'=>true,'existing'=>true,'nfce_id'=>$row['id'],'status'=>$row['status']]);
                exit;
            }

            // Dados do pedido
            $ped = $db->prepare("SELECT total, forma_pagamento, status FROM totem_pedidos WHERE id=?");
            $ped->execute([$pedidoId]);
            $pedRow = $ped->fetch();
            if (!$pedRow) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Pedido não encontrado']); exit; }

            $cfg = getCfg($db, ['nfce_serie','nfce_ambiente','nfce_ativo']);
            $num = proximoNumero($db);

            // Status baseado no pedido
            $statusNfce = match($pedRow['status']) {
                'cancelado'            => 'cancelada',
                'entregue', 'pronto'   => 'autorizada',
                'aguardando_pagamento' => 'pendente',
                default                => 'transmitindo',
            };

            $ins = $db->prepare("
                INSERT INTO totem_nfce (pedido_id, numero, serie, status, total, forma_pagamento, ambiente, autorizado_em)
                VALUES (?,?,?,?,?,?,?,?)
                RETURNING id
            ");
            $autorizadoEm = in_array($statusNfce, ['autorizada']) ? date('Y-m-d H:i:s') : null;
            $ins->execute([$pedidoId, $num, $cfg['nfce_serie'] ?? '001', $statusNfce,
                           $pedRow['total'], $pedRow['forma_pagamento'],
                           $cfg['nfce_ambiente'] ?? 'homologacao', $autorizadoEm]);
            $nfceId = $ins->fetchColumn();

            echo json_encode(['success'=>true,'nfce_id'=>$nfceId,'numero'=>$num,'status'=>$statusNfce]);
            exit;
        }

        // Atualizar status
        if ($act === 'atualizar_status') {
            $nfceId = (int)($body['nfce_id'] ?? 0);
            $status = $body['status'] ?? '';
            $allowed = ['pendente','transmitindo','autorizada','rejeitada','cancelada','contingencia'];
            if (!$nfceId || !in_array($status, $allowed)) {
                http_response_code(400); echo json_encode(['success'=>false,'error'=>'Dados inválidos']); exit;
            }
            if ($status === 'autorizada') {
                $db->prepare("UPDATE totem_nfce SET status=?, autorizado_em=NOW() WHERE id=?")->execute([$status,$nfceId]);
            } elseif ($status === 'cancelada') {
                $db->prepare("UPDATE totem_nfce SET status=?, cancelado_em=NOW() WHERE id=?")->execute([$status,$nfceId]);
            } else {
                $db->prepare("UPDATE totem_nfce SET status=? WHERE id=?")->execute([$status,$nfceId]);
            }
            echo json_encode(['success'=>true]);
            exit;
        }

        // Registrar rejeição
        if ($act === 'registrar_rejeicao') {
            global $TRADUCOES_REJEICAO;
            $nfceId  = (int)($body['nfce_id'] ?? 0);
            $codigo  = trim($body['codigo'] ?? '');
            $descPt  = $TRADUCOES_REJEICAO[$codigo] ?? ($body['descricao'] ?? 'Erro desconhecido da SEFAZ.');
            $db->prepare("INSERT INTO totem_nfce_rejeicoes (nfce_id, codigo, descricao, descricao_pt) VALUES (?,?,?,?)")
               ->execute([$nfceId, $codigo, $body['descricao'] ?? '', $descPt]);
            $db->prepare("UPDATE totem_nfce SET status='rejeitada', motivo_rejeicao=?, cod_rejeicao=? WHERE id=?")
               ->execute([$descPt, $codigo, $nfceId]);
            echo json_encode(['success'=>true,'descricao_pt'=>$descPt]);
            exit;
        }

        // Sincronizar: criar NFC-e para pedidos do dia que ainda não têm
        if ($act === 'sincronizar') {
            requireRole('admin');
            $stmt = $db->query("
                SELECT p.id, p.total, p.forma_pagamento, p.status
                FROM totem_pedidos p
                LEFT JOIN totem_nfce n ON n.pedido_id = p.id
                WHERE n.id IS NULL
                  AND DATE(p.criado_em AT TIME ZONE 'America/Sao_Paulo') = CURRENT_DATE
                  AND p.status NOT IN ('aguardando_pagamento')
                ORDER BY p.criado_em
            ");
            $pedidos = $stmt->fetchAll();
            $criados = 0;
            foreach ($pedidos as $ped) {
                $cfg    = getCfg($db, ['nfce_serie','nfce_ambiente']);
                $num    = proximoNumero($db);
                $st     = match($ped['status']) {
                    'cancelado' => 'cancelada',
                    'entregue', 'pronto' => 'autorizada',
                    default => 'transmitindo',
                };
                $authEm = in_array($st, ['autorizada']) ? date('Y-m-d H:i:s') : null;
                $db->prepare("INSERT INTO totem_nfce (pedido_id,numero,serie,status,total,forma_pagamento,ambiente,autorizado_em) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$ped['id'],$num,$cfg['nfce_serie']??'001',$st,$ped['total'],$ped['forma_pagamento'],$cfg['nfce_ambiente']??'homologacao',$authEm]);
                $criados++;
            }
            echo json_encode(['success'=>true,'sincronizados'=>$criados]);
            exit;
        }
    }

    // ── Emitir NFC-e (Fase 2 — dados fictícios / mock SEFAZ) ──────────────
    if ($method === 'POST' && ($body['action'] ?? '') === 'emitir_nfce') {
        $pedidoId = (int)($body['pedido_id'] ?? 0);
        $modo     = $body['modo'] ?? 'autorizar'; // autorizar | rejeitar | contingencia

        // Buscar pedido
        if (!$pedidoId) {
            // Pegar o pedido mais recente para teste
            $pedRow = $db->query("SELECT id, total, forma_pagamento, numero_pedido FROM totem_pedidos ORDER BY criado_em DESC LIMIT 1")->fetch();
        } else {
            $st = $db->prepare("SELECT id, total, forma_pagamento, numero_pedido FROM totem_pedidos WHERE id=?");
            $st->execute([$pedidoId]);
            $pedRow = $st->fetch();
        }
        if (!$pedRow) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Nenhum pedido encontrado']); exit; }

        // Buscar itens
        $itensSt = $db->prepare("SELECT nome_produto, quantidade, preco_unitario, subtotal FROM totem_itens_pedido WHERE pedido_id=?");
        $itensSt->execute([$pedRow['id']]);
        $itens = $itensSt->fetchAll();

        // Configuração fiscal (usa valores padrão de teste se não configurado)
        $cfg = getCfg($db, ['nfce_cnpj','nfce_ie','nfce_uf','nfce_serie','nfce_ambiente',
                             'nfce_regime','nfce_csc','nfce_csc_id','nfce_numero_atual']);
        $cfgFiscal = [
            'cnpj'      => $cfg['nfce_cnpj']    ?: '00000000000191',   // CNPJ teste
            'ie'        => $cfg['nfce_ie']       ?: 'ISENTO',
            'uf'        => $cfg['nfce_uf']       ?: 'DF',
            'serie'     => $cfg['nfce_serie']    ?: '001',
            'ambiente'  => $cfg['nfce_ambiente'] ?: 'homologacao',
            'regime'    => $cfg['nfce_regime']   ?: '1',
            'csc'       => $cfg['nfce_csc']      ?: 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF',
            'csc_id'    => $cfg['nfce_csc_id']   ?: '000001',
            'nome_loja' => 'CAFÉ COMUNHÃO',
        ];

        // Buscar ou criar registro NFC-e
        $existing = $db->prepare("SELECT id, numero FROM totem_nfce WHERE pedido_id=?");
        $existing->execute([$pedRow['id']]);
        $nfceRow = $existing->fetch();

        if ($nfceRow) {
            $numero  = (int)$nfceRow['numero'];
            $nfceId  = (int)$nfceRow['id'];
        } else {
            $numero  = proximoNumero($db);
            $serie   = $cfgFiscal['serie'];
            $db->prepare("INSERT INTO totem_nfce (pedido_id,numero,serie,status,total,forma_pagamento,ambiente) VALUES (?,?,?,'transmitindo',?,?,?)")
               ->execute([$pedRow['id'],$numero,$serie,$pedRow['total'],$pedRow['forma_pagamento'],$cfgFiscal['ambiente']]);
            $nfceId = (int)$db->lastInsertId();
            if (!$nfceId) {
                $nfceId = (int)$db->query("SELECT id FROM totem_nfce WHERE pedido_id={$pedRow['id']}")->fetchColumn();
            }
        }

        // Gerar XML
        $builder = new NfceBuilder(
            $cfgFiscal,
            ['numero' => $numero, 'total' => $pedRow['total'], 'forma_pagamento' => $pedRow['forma_pagamento']],
            $itens ?: [['nome_produto'=>'Produto Teste','quantidade'=>1,'preco_unitario'=>$pedRow['total'],'subtotal'=>$pedRow['total']]]
        );
        $chave  = $builder->gerarChave();
        $qrCode = $builder->gerarQrCode();
        $xml    = $builder->gerarXml();

        // Transmitir (mock SEFAZ)
        $resp = SefazMock::transmitir($xml, $modo);

        // Atualizar registro NFC-e
        if ($resp['autorizada']) {
            $db->prepare("UPDATE totem_nfce SET status='autorizada', chave_acesso=?, protocolo=?, xml_nfe=?, autorizado_em=NOW() WHERE id=?")
               ->execute([$chave, $resp['protocolo'], $xml, $nfceId]);
            $statusFinal = 'autorizada';
        } elseif (!empty($resp['contingencia'])) {
            $db->prepare("UPDATE totem_nfce SET status='contingencia', chave_acesso=?, xml_nfe=? WHERE id=?")
               ->execute([$chave, $xml, $nfceId]);
            $statusFinal = 'contingencia';
        } else {
            $db->prepare("UPDATE totem_nfce SET status='rejeitada', chave_acesso=?, xml_nfe=?, motivo_rejeicao=?, cod_rejeicao=? WHERE id=?")
               ->execute([$chave, $xml, $resp['mensagem_pt'] ?? $resp['mensagem'], $resp['codigo'] ?? '', $nfceId]);
            $db->prepare("INSERT INTO totem_nfce_rejeicoes (nfce_id, codigo, descricao, descricao_pt) VALUES (?,?,?,?)")
               ->execute([$nfceId, $resp['codigo'] ?? '', $resp['mensagem'] ?? '', $resp['mensagem_pt'] ?? SefazMock::traduzir($resp['codigo'] ?? '')]);
            $statusFinal = 'rejeitada';
        }

        auditLog($db, 'nfce_emitida', 'fiscal', $nfceId,
            "NFC-e #{$numero} pedido #{$pedRow['numero_pedido']} — {$statusFinal} (mock)");

        echo json_encode([
            'success'      => true,
            'nfce_id'      => $nfceId,
            'numero'       => $numero,
            'chave'        => $chave,
            'status'       => $statusFinal,
            'protocolo'    => $resp['protocolo'] ?? null,
            'mensagem'     => $resp['mensagem_pt'] ?? $resp['mensagem'] ?? null,
            'qr_code'      => $qrCode,
            'xml'          => $xml,
            'simulado'     => true,
            'pedido_id'    => $pedRow['id'],
            'pedido_num'   => $pedRow['numero_pedido'],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Ação não reconhecida']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}

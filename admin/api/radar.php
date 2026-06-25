<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';
require_once '../../config/audit.php';
require_once '../../config/RadarFiscal.php';
require_once '../../config/NtScraper.php';
require_once 'auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $db    = getDB();
    $radar = new RadarFiscal($db);

    // ── GET: dashboard completo ────────────────────────────────────────────
    if ($method === 'GET' && $action === 'dashboard') {
        $radar->sincronizarAlertas();
        $checks   = $radar->verificarConformidade();
        $score    = $radar->calcularScore($checks);
        $timeline = $radar->getTimeline();
        $nts      = $radar->getNotasTecnicas();
        $alertas  = $radar->getAlertas();
        $ultimaVer= $radar->getUltimaVerificacao();

        // Status geral baseado no score
        $statusGeral = match(true) {
            $score >= 90 => ['tipo'=>'ok',      'titulo'=>'SISTEMA EM CONFORMIDADE',      'sub'=>'Todos os requisitos fiscais estão atendidos.'],
            $score >= 60 => ['tipo'=>'warning',  'titulo'=>'ATENÇÃO NECESSÁRIA',            'sub'=>'Alguns requisitos fiscais precisam de atenção.'],
            default      => ['tipo'=>'danger',   'titulo'=>'CONFORMIDADE COMPROMETIDA',     'sub'=>'Corrija os itens marcados antes de emitir notas.'],
        };

        // Contadores de alertas
        $totalAlertas  = count($alertas);
        $danger        = count(array_filter($alertas, fn($a) => $a['severidade'] === 'danger'));
        $warning       = count(array_filter($alertas, fn($a) => $a['severidade'] === 'warning'));
        $ntsNovas      = count(array_filter($nts, fn($n) => $n['status'] === 'nova'));

        echo json_encode([
            'success'          => true,
            'score'            => $score,
            'status_geral'     => $statusGeral,
            'conformidade'     => $checks,
            'timeline'         => $timeline,
            'notas_tecnicas'   => $nts,
            'alertas'          => $alertas,
            'total_alertas'    => $totalAlertas,
            'alertas_danger'   => $danger,
            'alertas_warning'  => $warning,
            'nts_novas'        => $ntsNovas,
            'ultima_verificacao'=> $ultimaVer,
            'verificado_em'    => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    // ── GET: apenas conformidade ───────────────────────────────────────────
    if ($method === 'GET' && $action === 'conformidade') {
        $checks = $radar->verificarConformidade();
        echo json_encode(['success'=>true,'data'=>$checks,'score'=>$radar->calcularScore($checks)]);
        exit;
    }

    // ── GET: alertas ───────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'alertas') {
        echo json_encode(['success'=>true,'data'=>$radar->getAlertas()]);
        exit;
    }

    // ── GET: NTs ───────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'notas_tecnicas') {
        echo json_encode(['success'=>true,'data'=>$radar->getNotasTecnicas()]);
        exit;
    }

    // ── POST ──────────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $act  = $body['action'] ?? '';

        // Verificar portal por novas NTs
        if ($act === 'verificar_nt') {
            $result = NtScraper::verificar($db);
            auditLog($db, 'radar_verificou_nt', 'fiscal', null, "Verificação portal NF-e: {$result['novas_registradas']} NT(s) nova(s)");
            echo json_encode(array_merge(['success'=>true], $result));
            exit;
        }

        // Dispensar alerta
        if ($act === 'dispensar_alerta') {
            $id = (int)($body['id'] ?? 0);
            $db->prepare("UPDATE totem_fiscal_alertas SET dispensado=TRUE WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // Atualizar status de NT
        if ($act === 'atualizar_nt') {
            $id     = (int)($body['id']     ?? 0);
            $status = $body['status'] ?? '';
            $ok = NtScraper::atualizarStatus($db, $id, $status);
            auditLog($db, 'nt_status_atualizado', 'fiscal', $id, "NT #{$id} marcada como: {$status}");
            echo json_encode(['success'=>$ok]);
            exit;
        }

        // Adicionar NT manualmente
        if ($act === 'add_nt') {
            requireRole('admin');
            $codigo = trim($body['codigo'] ?? '');
            $titulo = trim($body['titulo'] ?? '');
            $data   = $body['data_publicacao'] ?? date('Y-m-d');
            if (!$codigo) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Código obrigatório']); exit; }
            $statusNt = in_array($body['status'] ?? '', ['nova','analisada','aplicada','ignorada']) ? $body['status'] : 'nova';
            $db->prepare("INSERT INTO totem_fiscal_nt (codigo,titulo,data_publicacao,status) VALUES (?,?,?,?) ON CONFLICT (codigo) DO UPDATE SET titulo=EXCLUDED.titulo, data_publicacao=EXCLUDED.data_publicacao, status=EXCLUDED.status")
               ->execute([$codigo, $titulo, $data, $statusNt]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // Sincronizar alertas manualmente
        if ($act === 'sincronizar') {
            $radar->sincronizarAlertas();
            $alertas = $radar->getAlertas();
            echo json_encode(['success'=>true,'alertas'=>$alertas,'total'=>count($alertas)]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Ação não reconhecida']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}

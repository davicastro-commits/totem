<?php
/**
 * API REST — Metas mensais de faturamento
 *
 * GET  ?mes=YYYY-MM  → retorna meta do mês + faturamento_atual + percentual_atingido
 * POST {mes:YYYY-MM, meta_faturamento:9999.99}  → UPSERT
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // ── GET ───────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $mesParam = $_GET['mes'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $mesParam)) $mesParam = date('Y-m');
        $mesDate = $mesParam . '-01';

        // Meta cadastrada
        $stmtMeta = $db->prepare("
            SELECT id, meta_faturamento, criado_em
            FROM material.totem_metas
            WHERE DATE_TRUNC('month', mes) = DATE_TRUNC('month', ?::date)
        ");
        $stmtMeta->execute([$mesDate]);
        $metaRow = $stmtMeta->fetch();

        // Faturamento atual do mês
        $stmtFat = $db->prepare("
            SELECT COALESCE(SUM(total), 0)  AS faturamento_atual,
                   COUNT(*)                  AS num_pedidos
            FROM material.totem_pedidos
            WHERE DATE_TRUNC('month', criado_em) = DATE_TRUNC('month', ?::date)
              AND status NOT IN ('cancelado', 'aguardando_pagamento')
        ");
        $stmtFat->execute([$mesDate]);
        $fatRow = $stmtFat->fetch();

        $metaFaturamento   = $metaRow ? (float)$metaRow['meta_faturamento'] : null;
        $faturamentoAtual  = (float)$fatRow['faturamento_atual'];
        $numPedidos        = (int)$fatRow['num_pedidos'];

        $percentualAtingido = ($metaFaturamento && $metaFaturamento > 0)
                              ? round(($faturamentoAtual / $metaFaturamento) * 100, 1)
                              : null;

        echo json_encode([
            'success'             => true,
            'mes'                 => $mesParam,
            'meta_id'             => $metaRow ? (int)$metaRow['id'] : null,
            'meta_faturamento'    => $metaFaturamento,
            'faturamento_atual'   => $faturamentoAtual,
            'num_pedidos'         => $numPedidos,
            'percentual_atingido' => $percentualAtingido,
        ]);
        exit;
    }

    // ── POST — UPSERT ─────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $mesParam        = trim($body['mes']              ?? '');
        $metaFaturamento = (float)($body['meta_faturamento'] ?? 0);
        $adminId         = adminId();

        // Validações
        $erros = [];
        if (!preg_match('/^\d{4}-\d{2}$/', $mesParam)) $erros[] = 'Mês inválido (use YYYY-MM).';
        if ($metaFaturamento <= 0)                      $erros[] = 'Meta de faturamento deve ser positiva.';

        if ($erros) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => implode(' ', $erros)]);
            exit;
        }

        $mesDate = $mesParam . '-01';

        // Valor anterior para auditoria
        $stmtAntes = $db->prepare("
            SELECT meta_faturamento FROM material.totem_metas
            WHERE DATE_TRUNC('month', mes) = DATE_TRUNC('month', ?::date)
        ");
        $stmtAntes->execute([$mesDate]);
        $anterior = $stmtAntes->fetchColumn();

        // UPSERT
        $stmt = $db->prepare("
            INSERT INTO material.totem_metas (mes, meta_faturamento, admin_id)
            VALUES (DATE_TRUNC('month', ?::date), ?, ?)
            ON CONFLICT (mes) DO UPDATE
               SET meta_faturamento = EXCLUDED.meta_faturamento,
                   admin_id         = EXCLUDED.admin_id,
                   criado_em        = NOW()
            RETURNING id
        ");
        $stmt->execute([$mesDate, $metaFaturamento, $adminId ?: null]);
        $metaId = (int)$stmt->fetchColumn();

        auditLog(
            $db,
            $anterior !== false ? 'atualizar' : 'criar',
            'metas',
            $metaId,
            "Meta {$mesParam}: R$ {$metaFaturamento}",
            $anterior !== false ? ['meta_faturamento' => (float)$anterior] : null,
            ['mes' => $mesParam, 'meta_faturamento' => $metaFaturamento]
        );

        echo json_encode([
            'success'          => true,
            'id'               => $metaId,
            'mes'              => $mesParam,
            'meta_faturamento' => $metaFaturamento,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

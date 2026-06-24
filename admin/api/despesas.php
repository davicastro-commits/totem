<?php
/**
 * API REST — Despesas operacionais + DRE mensal
 *
 * GET  ?action=listar&data_ini=YYYY-MM-DD&data_fim=YYYY-MM-DD
 * GET  ?action=dre&mes=YYYY-MM
 * POST {action:"salvar", data, categoria, descricao, valor, recorrente}
 * DELETE ?id=X
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

    // ── DELETE ────────────────────────────────────────────────────────────
    if ($method === 'DELETE') {
        csrfVerify();
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID inválido.']);
            exit;
        }
        $stmt = $db->prepare('SELECT id, descricao, valor FROM material.totem_despesas WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Despesa não encontrada.']);
            exit;
        }
        $db->prepare('DELETE FROM material.totem_despesas WHERE id = ?')->execute([$id]);
        auditLog($db, 'deletar', 'despesas', $id, "Despesa excluída: {$row['descricao']} R$ {$row['valor']}", $row);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── POST ──────────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';

        if ($action === 'salvar') {
            $data       = trim($body['data']       ?? '');
            $categoria  = trim($body['categoria']  ?? '');
            $descricao  = trim($body['descricao']  ?? '');
            $valor      = (float)($body['valor']   ?? 0);
            $recorrente = !empty($body['recorrente']) ? 'true' : 'false';
            $adminId    = adminId();

            // Validações
            $erros = [];
            if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data))              $erros[] = 'Data inválida.';
            $cats = ['aluguel','fornecedor','energia','folha','marketing','outros'];
            if (!in_array($categoria, $cats, true))                                  $erros[] = 'Categoria inválida.';
            if (strlen($descricao) < 3)                                              $erros[] = 'Descrição muito curta.';
            if ($valor <= 0)                                                         $erros[] = 'Valor deve ser positivo.';

            if ($erros) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => implode(' ', $erros)]);
                exit;
            }

            $stmt = $db->prepare("
                INSERT INTO material.totem_despesas (data, categoria, descricao, valor, recorrente, admin_id)
                VALUES (?, ?, ?, ?, ?::boolean, ?)
                RETURNING id
            ");
            $stmt->execute([$data, $categoria, $descricao, $valor, $recorrente, $adminId ?: null]);
            $newId = (int)$stmt->fetchColumn();

            auditLog($db, 'criar', 'despesas', $newId,
                "Nova despesa: {$categoria} — {$descricao} R$ {$valor}",
                null,
                ['data'=>$data,'categoria'=>$categoria,'descricao'=>$descricao,'valor'=>$valor]
            );

            echo json_encode(['success' => true, 'id' => $newId]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
        exit;
    }

    // ── GET ───────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'listar';

        // ── Listar despesas com totais por categoria ──────────────────────
        if ($action === 'listar') {
            $dataIni = $_GET['data_ini'] ?? date('Y-m-01');
            $dataFim = $_GET['data_fim'] ?? date('Y-m-d');

            // Validação simples
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) $dataIni = date('Y-m-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) $dataFim = date('Y-m-d');

            $stmt = $db->prepare("
                SELECT id, data, categoria, descricao, valor, recorrente, criado_em
                FROM material.totem_despesas
                WHERE data BETWEEN ? AND ?
                ORDER BY data DESC, id DESC
            ");
            $stmt->execute([$dataIni, $dataFim]);
            $despesas = $stmt->fetchAll();

            // Totais por categoria
            $stmtCat = $db->prepare("
                SELECT categoria, SUM(valor) AS total, COUNT(*) AS qtd
                FROM material.totem_despesas
                WHERE data BETWEEN ? AND ?
                GROUP BY categoria
                ORDER BY total DESC
            ");
            $stmtCat->execute([$dataIni, $dataFim]);
            $porCategoria = $stmtCat->fetchAll();

            $totalGeral = array_sum(array_column($porCategoria, 'total'));

            echo json_encode([
                'success'       => true,
                'despesas'      => $despesas,
                'por_categoria' => $porCategoria,
                'total_geral'   => (float)$totalGeral,
                'periodo'       => ['ini' => $dataIni, 'fim' => $dataFim],
            ]);
            exit;
        }

        // ── DRE mensal ───────────────────────────────────────────────────
        if ($action === 'dre') {
            $mesParam = $_GET['mes'] ?? date('Y-m');
            if (!preg_match('/^\d{4}-\d{2}$/', $mesParam)) $mesParam = date('Y-m');
            $mesDate = $mesParam . '-01'; // primeiro dia do mês

            // 1) Faturamento bruto (pedidos confirmados no mês)
            $stmtFat = $db->prepare("
                SELECT COALESCE(SUM(total), 0) AS faturamento,
                       COUNT(*) AS num_pedidos
                FROM material.totem_pedidos
                WHERE DATE_TRUNC('month', criado_em) = DATE_TRUNC('month', ?::date)
                  AND status NOT IN ('cancelado', 'aguardando_pagamento')
            ");
            $stmtFat->execute([$mesDate]);
            $fatRow = $stmtFat->fetch();
            $faturamento  = (float)$fatRow['faturamento'];
            $numPedidos   = (int)$fatRow['num_pedidos'];

            // 2) Custo de insumos (via ficha técnica)
            $custoInsumos = 0.0;
            try {
                $stmtCusto = $db->prepare("
                    SELECT COALESCE(SUM(ip.quantidade * ft.quantidade * ins.custo_medio), 0) AS custo
                    FROM material.totem_itens_pedido ip
                    JOIN material.totem_pedidos p       ON p.id = ip.pedido_id
                    JOIN material.totem_ficha_tecnica ft ON ft.produto_id = ip.produto_id
                    JOIN material.totem_insumos ins      ON ins.id = ft.insumo_id
                    WHERE DATE_TRUNC('month', p.criado_em) = DATE_TRUNC('month', ?::date)
                      AND p.status NOT IN ('cancelado', 'aguardando_pagamento')
                ");
                $stmtCusto->execute([$mesDate]);
                $custoInsumos = (float)$stmtCusto->fetchColumn();
            } catch (Throwable) {
                // Ficha técnica pode não existir para todos os produtos
            }

            // 3) Despesas operacionais por categoria
            $stmtDesp = $db->prepare("
                SELECT categoria,
                       SUM(valor)  AS total,
                       COUNT(*)    AS qtd
                FROM material.totem_despesas
                WHERE DATE_TRUNC('month', data) = DATE_TRUNC('month', ?::date)
                GROUP BY categoria
                ORDER BY total DESC
            ");
            $stmtDesp->execute([$mesDate]);
            $despesasCat = $stmtDesp->fetchAll();

            $totalDespesas = (float)array_sum(array_column($despesasCat, 'total'));

            // 4) Calcular DRE
            $lucroBruto     = $faturamento - $custoInsumos;
            $lucroLiquido   = $lucroBruto - $totalDespesas;

            $margemBruta    = $faturamento > 0 ? round(($lucroBruto  / $faturamento) * 100, 2) : 0;
            $margemLiquida  = $faturamento > 0 ? round(($lucroLiquido / $faturamento) * 100, 2) : 0;

            // 5) Meta do mês (se existir)
            $stmtMeta = $db->prepare("
                SELECT meta_faturamento FROM material.totem_metas
                WHERE DATE_TRUNC('month', mes) = DATE_TRUNC('month', ?::date)
            ");
            $stmtMeta->execute([$mesDate]);
            $metaRow = $stmtMeta->fetch();
            $metaFaturamento  = $metaRow ? (float)$metaRow['meta_faturamento'] : null;
            $pctMeta          = ($metaFaturamento && $metaFaturamento > 0)
                                ? round(($faturamento / $metaFaturamento) * 100, 1)
                                : null;

            // Organizar categorias como mapa para fácil acesso no frontend
            $despMap = [];
            foreach ($despesasCat as $d) {
                $despMap[$d['categoria']] = ['total' => (float)$d['total'], 'qtd' => (int)$d['qtd']];
            }

            echo json_encode([
                'success'          => true,
                'mes'              => $mesParam,
                'num_pedidos'      => $numPedidos,
                'faturamento'      => $faturamento,
                'custo_insumos'    => $custoInsumos,
                'lucro_bruto'      => $lucroBruto,
                'despesas_total'   => $totalDespesas,
                'despesas_cat'     => $despesasCat,
                'despesas_map'     => $despMap,
                'lucro_liquido'    => $lucroLiquido,
                'margem_bruta_pct' => $margemBruta,
                'margem_liq_pct'   => $margemLiquida,
                'meta_faturamento' => $metaFaturamento,
                'pct_meta'         => $pctMeta,
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação GET desconhecida.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

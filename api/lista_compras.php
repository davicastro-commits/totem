<?php
/**
 * API de Lista de Compras (Workflow)
 *
 * GET  (sem filtro)           → todos os itens
 * GET  ?status=pendente       → filtrar por status
 * GET  ?action=resumo         → KPIs por status
 * POST                        → criar item
 * PUT                         → atualizar item (status, quantidade, notas)
 * DELETE ?id=X                → cancelar item
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../admin/api/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/estoque_helper.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // KPIs
        if ($action === 'resumo') {
            $stmt = $db->query("
                SELECT
                    status,
                    COUNT(*) AS total,
                    SUM(quantidade * custo_estimado) AS valor_total
                  FROM totem_lista_compras
                 WHERE status != 'cancelado'
                 GROUP BY status
            ");
            $rows = $stmt->fetchAll();
            $resumo = ['pendente'=>0,'aprovado'=>0,'pedido'=>0,'recebido'=>0,'valor_pendente'=>0,'valor_aprovado'=>0];
            foreach ($rows as $r) {
                $resumo[$r['status']] = (int)$r['total'];
                if (in_array($r['status'],['pendente','aprovado'])) {
                    $resumo['valor_'.$r['status']] = round((float)$r['valor_total'],2);
                }
            }
            jsonOk($resumo);
        }

        $status = $_GET['status'] ?? '';
        $sql = "
            SELECT lc.*,
                   i.estoque_atual, i.estoque_minimo, i.rop,
                   a1.nome AS criado_por_nome,
                   a2.nome AS aprovado_por_nome
              FROM totem_lista_compras lc
              LEFT JOIN totem_insumos  i  ON i.id  = lc.insumo_id
              LEFT JOIN totem_admin    a1 ON a1.id = lc.criado_por
              LEFT JOIN totem_admin    a2 ON a2.id = lc.aprovado_por
             WHERE lc.status != 'cancelado'
        ";
        $params = [];
        if ($status) { $sql .= " AND lc.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY
                    CASE lc.prioridade WHEN 'ALTA' THEN 0 WHEN 'MEDIA' THEN 1 ELSE 2 END,
                    lc.criado_em DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonOk($stmt->fetchAll());
    }

    requireAdmin();

    if ($method === 'POST') {
        $body = jsonBody();

        $insumo_id       = ($body['insumo_id'] ?? null) ? (int)$body['insumo_id'] : null;
        $nome_insumo     = trim($body['nome_insumo']     ?? '');
        $unidade         = trim($body['unidade']         ?? 'UN');
        $quantidade      = (float)($body['quantidade']   ?? 0);
        $custo_estimado  = (float)($body['custo_estimado'] ?? 0);
        $fornecedor      = trim($body['fornecedor']      ?? '');
        $prioridade      = trim($body['prioridade']      ?? 'MEDIA');
        $notas           = trim($body['notas']           ?? '');
        $data_nec        = ($body['data_necessidade'] ?? '') ?: null;

        // Preencher nome do insumo automaticamente se veio insumo_id
        if ($insumo_id && !$nome_insumo) {
            $iRow = $db->prepare("SELECT nome, unidade, custo_medio, fornecedor FROM totem_insumos WHERE id = ?");
            $iRow->execute([$insumo_id]);
            $iData = $iRow->fetch();
            if ($iData) {
                $nome_insumo    = $iData['nome'];
                $unidade        = $unidade ?: $iData['unidade'];
                $custo_estimado = $custo_estimado ?: (float)$iData['custo_medio'];
                $fornecedor     = $fornecedor ?: $iData['fornecedor'];
            }
        }

        assert400($nome_insumo !== '', 'Nome do insumo é obrigatório.');
        assert400($quantidade  > 0,    'Quantidade deve ser maior que zero.');
        assert400(in_array($prioridade, ['ALTA','MEDIA','BAIXA']), 'Prioridade inválida.');

        $stmt = $db->prepare("
            INSERT INTO totem_lista_compras
                (insumo_id, nome_insumo, unidade, quantidade, custo_estimado,
                 fornecedor, prioridade, notas, data_necessidade, criado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            RETURNING id
        ");
        $stmt->execute([$insumo_id, $nome_insumo, $unidade, $quantidade, $custo_estimado,
                        $fornecedor, $prioridade, $notas, $data_nec, $_SESSION['admin_id'] ?? null]);
        $newId = $stmt->fetchColumn();

        auditLog($db,'criar','lista_compras',(int)$newId,"Solicitação criada: {$nome_insumo} ({$quantidade})");
        jsonOk(['id' => (int)$newId], 201);
    }

    if ($method === 'PUT') {
        $body    = jsonBody();
        $id      = (int)($body['id'] ?? 0);
        $novoSt  = $body['status'] ?? null;
        assert400($id > 0, 'ID inválido.');

        // Validar transição de status
        $cur = $db->prepare("SELECT * FROM totem_lista_compras WHERE id = ?");
        $cur->execute([$id]);
        $item = $cur->fetch();
        if (!$item) jsonErr('Item não encontrado', 404);

        $transitions = [
            'pendente' => ['aprovado','cancelado'],
            'aprovado' => ['pedido','pendente','cancelado'],
            'pedido'   => ['recebido','aprovado','cancelado'],
            'recebido' => [],
        ];
        if ($novoSt && !in_array($novoSt, $transitions[$item['status']] ?? [])) {
            jsonErr("Transição inválida: {$item['status']} → {$novoSt}", 422);
        }

        // Se recebido → dar entrada no estoque automaticamente
        if ($novoSt === 'recebido' && $item['insumo_id']) {
            $qtd   = (float)$item['quantidade'];
            $custo = (float)$item['custo_estimado'];
            $iRow  = $db->prepare("SELECT estoque_atual, custo_medio FROM totem_insumos WHERE id = ?");
            $iRow->execute([$item['insumo_id']]);
            $ins = $iRow->fetch();
            $novoEst  = (float)$ins['estoque_atual'] + $qtd;
            $novoCusto = $novoEst > 0
                ? (((float)$ins['estoque_atual'] * (float)$ins['custo_medio']) + ($qtd * $custo)) / $novoEst
                : $custo;
            $db->prepare("UPDATE totem_insumos SET estoque_atual=?, custo_medio=?, atualizado_em=NOW() WHERE id=?")
               ->execute([$novoEst, round($novoCusto,4), $item['insumo_id']]);
            $db->prepare("INSERT INTO totem_movimentacoes_estoque (insumo_id,tipo,quantidade,custo_unitario,motivo,usuario_id) VALUES (?,?,?,?,?,?)")
               ->execute([$item['insumo_id'],'entrada',$qtd,$custo,"Recebimento: lista de compras #{$id}",$_SESSION['admin_id']??null]);
        }

        $stmt = $db->prepare("
            UPDATE totem_lista_compras SET
                status           = COALESCE(?, status),
                quantidade       = COALESCE(?, quantidade),
                custo_estimado   = COALESCE(?, custo_estimado),
                fornecedor       = COALESCE(?, fornecedor),
                notas            = COALESCE(?, notas),
                aprovado_por     = CASE WHEN ? = 'aprovado' THEN ? ELSE aprovado_por END,
                atualizado_em    = NOW()
             WHERE id = ?
        ");
        $stmt->execute([
            $novoSt,
            isset($body['quantidade'])      ? (float)$body['quantidade']     : null,
            isset($body['custo_estimado'])   ? (float)$body['custo_estimado'] : null,
            isset($body['fornecedor'])        ? trim($body['fornecedor'])      : null,
            isset($body['notas'])             ? trim($body['notas'])           : null,
            $novoSt, $_SESSION['admin_id']??null,
            $id,
        ]);

        auditLog($db,'editar','lista_compras',$id,"Status: {$item['status']} → ".($novoSt??'sem mudança'));
        jsonOk(['id'=>$id,'novo_status'=>$novoSt??$item['status']]);
    }

    if ($method === 'DELETE') {
        $id = filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
        assert400($id > 0, 'ID inválido.');
        $db->prepare("UPDATE totem_lista_compras SET status='cancelado', atualizado_em=NOW() WHERE id=?")->execute([$id]);
        auditLog($db,'excluir','lista_compras',$id,'Item cancelado');
        jsonOk(['id'=>$id]);
    }

    jsonErr('Método não permitido', 405);

} catch (PDOException $e) {
    error_log('[lista_compras.php] '.$e->getMessage());
    jsonErr('Erro no banco de dados', 500);
} catch (Throwable $e) {
    error_log('[lista_compras.php] '.$e->getMessage());
    jsonErr('Erro interno', 500);
}

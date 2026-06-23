<?php
declare(strict_types=1);

/**
 * Dá baixa automática nos insumos quando um pedido é confirmado.
 * Percorre a ficha técnica de cada produto do pedido e registra saída.
 *
 * Retorna array com resumo das baixas efetuadas.
 * Silencia se o produto não tiver ficha técnica (não é erro).
 */
function baixarEstoquePorPedido(PDO $db, int $pedidoId): array
{
    $pedStmt = $db->prepare("
        SELECT produto_id, quantidade AS qtd_pedido
          FROM totem_itens_pedido
         WHERE pedido_id = ?
    ");
    $pedStmt->execute([$pedidoId]);
    $itens = $pedStmt->fetchAll();

    if (empty($itens)) return [];

    $fichaStmt = $db->prepare("
        SELECT ft.insumo_id, ft.quantidade AS consumo_unit, i.nome AS insumo_nome
          FROM totem_ficha_tecnica ft
          JOIN totem_insumos i ON i.id = ft.insumo_id
         WHERE ft.produto_id = ?
    ");
    $movStmt = $db->prepare("
        INSERT INTO totem_movimentacoes_estoque
            (insumo_id, tipo, quantidade, motivo, pedido_id)
        VALUES (?, 'saida', ?, ?, ?)
    ");
    $updStmt = $db->prepare("
        UPDATE totem_insumos
           SET estoque_atual = GREATEST(estoque_atual - ?, 0),
               atualizado_em = NOW()
         WHERE id = ?
    ");

    $baixas = [];

    foreach ($itens as $item) {
        $fichaStmt->execute([$item['produto_id']]);
        $ficha = $fichaStmt->fetchAll();

        foreach ($ficha as $fi) {
            $consumo = round((float)$fi['consumo_unit'] * (float)$item['qtd_pedido'], 4);
            $motivo  = "Baixa automática — pedido #{$pedidoId}";

            $updStmt->execute([$consumo, $fi['insumo_id']]);
            $movStmt->execute([$fi['insumo_id'], $consumo, $motivo, $pedidoId]);

            $baixas[] = ['insumo' => $fi['insumo_nome'], 'consumo' => $consumo];
        }
    }

    return $baixas;
}

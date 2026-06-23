<?php
declare(strict_types=1);

/**
 * Acumula pontos de fidelidade para o cliente associado a um pedido.
 * Silencia erros — nunca deve bloquear o fluxo do pedido.
 *
 * @return int pontos acumulados (0 se cliente não encontrado ou sem saldo)
 */
function acumularPontosPorPedido(PDO $db, int $pedidoId): int
{
    try {
        // Busca CPF e total do pedido
        $ped = $db->prepare("SELECT cpf, total FROM totem_pedidos WHERE id = ?");
        $ped->execute([$pedidoId]);
        $pedido = $ped->fetch();

        if (!$pedido || !$pedido['cpf']) return 0;

        // Busca cliente pelo CPF
        $cli = $db->prepare("SELECT id FROM totem_clientes WHERE cpf = ? AND ativo = true");
        $cli->execute([$pedido['cpf']]);
        $cliente = $cli->fetch();

        if (!$cliente) return 0;

        $clienteId = (int)$cliente['id'];
        $total     = (float)$pedido['total'];

        // Busca configuração de pontos
        $cfg = $db->query(
            "SELECT pontos_por_real, real_por_ponto, validade_dias
               FROM totem_pontos_config WHERE ativo = true ORDER BY id DESC LIMIT 1"
        )->fetch();

        if (!$cfg) $cfg = ['pontos_por_real' => 1.0, 'real_por_ponto' => 0.05, 'validade_dias' => 365];

        $pontosGanhos = (int)floor($total * (float)$cfg['pontos_por_real']);
        if ($pontosGanhos <= 0) return 0;

        $expira = (new DateTime())->modify('+' . (int)$cfg['validade_dias'] . ' days')->format('Y-m-d');

        // Evita duplicata — verifica se já acumulou pontos para este pedido
        $dup = $db->prepare(
            "SELECT COUNT(*) FROM totem_pontos_historico WHERE pedido_id = ? AND tipo = 'ganho'"
        );
        $dup->execute([$pedidoId]);
        if ((int)$dup->fetchColumn() > 0) return 0;

        $db->beginTransaction();

        $db->prepare(
            "INSERT INTO totem_pontos_historico (cliente_id, pedido_id, tipo, pontos, descricao, expira_em)
             VALUES (?, ?, 'ganho', ?, ?, ?)"
        )->execute([$clienteId, $pedidoId, $pontosGanhos,
                    "Pontos acumulados — pedido #{$pedidoId}", $expira]);

        $db->prepare(
            "UPDATE totem_clientes
                SET pontos_saldo  = pontos_saldo + ?,
                    total_gasto   = total_gasto + ?,
                    total_pedidos = total_pedidos + 1,
                    atualizado_em = NOW()
              WHERE id = ?"
        )->execute([$pontosGanhos, $total, $clienteId]);

        $db->prepare("UPDATE totem_pedidos SET pontos_ganhos = ? WHERE id = ?")
           ->execute([$pontosGanhos, $pedidoId]);

        $db->commit();

        return $pontosGanhos;

    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log('[fidelidade] Erro ao acumular pontos: ' . $e->getMessage());
        return 0;
    }
}

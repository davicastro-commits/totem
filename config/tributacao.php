<?php
declare(strict_types=1);

/**
 * Tributacao — calcula tributos aproximados por NCM (Lei 12.741/2012).
 * Regime: Simples Nacional (DF). O ICMS é recolhido no DAS, não destacado.
 * Os percentuais são baseados na tabela IBPT.
 *
 * Uso:
 *   require_once __DIR__ . '/tributacao.php';
 *   $trib = new Tributacao(getDB());
 *   $item = $trib->calcularItem('21069090', 15.90);
 *   $total = $trib->calcularPedido($itens);
 *   echo $trib->textoNota($total);
 */
class Tributacao
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Calcula tributo aproximado de um item pelo NCM.
     *
     * @param  string $ncm    Código NCM de 8 dígitos (com ou sem máscara)
     * @param  float  $valor  Valor monetário do item (ex.: subtotal)
     * @return array{tributo_valor: float, percentual: float, ncm_descricao: string}
     */
    public function calcularItem(string $ncm, float $valor): array
    {
        $ncm = preg_replace('/\D/', '', $ncm);

        if ($ncm === '' || strlen($ncm) !== 8) {
            return [
                'tributo_valor'  => round($valor * 0.1345, 2),
                'percentual'     => 13.45,
                'ncm_descricao'  => '',
            ];
        }

        $stmt = $this->db->prepare('SELECT * FROM totem_ncm WHERE ncm = ?');
        $stmt->execute([$ncm]);
        $row = $stmt->fetch();

        if (!$row) {
            return [
                'tributo_valor'  => round($valor * 0.1345, 2),
                'percentual'     => 13.45,
                'ncm_descricao'  => '',
            ];
        }

        $pct  = (float)$row['aliquota_nacional'] + (float)$row['aliquota_estadual'];
        $trib = round($valor * $pct / 100, 2);

        return [
            'tributo_valor'  => $trib,
            'percentual'     => $pct,
            'ncm_descricao'  => $row['descricao'],
        ];
    }

    /**
     * Calcula tributo total do pedido somando por item.
     *
     * @param  array<array{produto_id: int, subtotal: float}> $itens
     * @return float Total de tributos aproximados
     */
    public function calcularPedido(array $itens): float
    {
        if (empty($itens)) {
            return 0.0;
        }

        $total    = 0.0;
        $stmtNcm  = $this->db->prepare('SELECT ncm FROM totem_produtos WHERE id = ?');

        foreach ($itens as $it) {
            $stmtNcm->execute([(int)$it['produto_id']]);
            $ncm  = $stmtNcm->fetchColumn() ?: '';
            $calc = $this->calcularItem((string)$ncm, (float)$it['subtotal']);
            $total += $calc['tributo_valor'];
        }

        return round($total, 2);
    }

    /**
     * Texto formatado para impressão na nota fiscal (Lei 12.741/2012).
     * Exemplo: "Trib. aprox R$ 1,35 (Fonte: IBPT)"
     */
    public function textoNota(float $valor): string
    {
        return 'Trib. aprox R$ ' . number_format($valor, 2, ',', '.') . ' (Fonte: IBPT)';
    }
}

<?php
/**
 * NtScraper — Verifica o portal oficial NF-e por novas Notas Técnicas
 * Usa file_get_contents + regex (sem dependência de curl/DOMDocument)
 */
class NtScraper
{
    // URLs oficiais do portal NF-e para NTs
    private const URLS = [
        'https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=Yz6mSsEHn7g=',
        'https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=dJlJ9BxhSiY=',
    ];

    /**
     * Busca novas Notas Técnicas no portal oficial e registra no banco
     * Retorna array com resultado da operação
     */
    public static function verificar(PDO $db): array
    {
        $encontradas = [];
        $novas       = [];
        $erros       = [];

        foreach (self::URLS as $url) {
            try {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout'    => 12,
                        'user_agent' => 'Mozilla/5.0 (compatible; RadarFiscalCafeComunhao/1.0)',
                        'header'     => "Accept: text/html\r\nAccept-Language: pt-BR\r\n",
                    ],
                    'ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                    ],
                ]);

                $html = @file_get_contents($url, false, $ctx);
                if (!$html) {
                    $erros[] = "URL não respondeu: {$url}";
                    continue;
                }

                // Padrões de Notas Técnicas no HTML do portal
                $padroes = [
                    '/NT\s*(\d{4}\.\d{3})/i',           // NT 2024.001
                    '/Nota\s+T[eé]cnica\s+(\d{4}\.\d{3})/i',
                    '/NT\s+RTC[-\s]+[\w\/]+/i',           // NT RTC
                    '/(\d{4}\.\d{3})\s*[-–]\s*([^<\n]+)/i',
                ];

                foreach ($padroes as $padrao) {
                    preg_match_all($padrao, $html, $matches, PREG_SET_ORDER);
                    foreach ($matches as $m) {
                        $codigo = 'NT ' . trim($m[1] ?? $m[0]);
                        if (strlen($codigo) > 4) $encontradas[$codigo] = true;
                    }
                }
            } catch (Throwable $e) {
                $erros[] = $e->getMessage();
            }
        }

        // Registrar as não encontradas ainda no banco
        foreach (array_keys($encontradas) as $codigo) {
            try {
                $exist = $db->prepare("SELECT id FROM totem_fiscal_nt WHERE codigo=?");
                $exist->execute([$codigo]);
                if (!$exist->fetchColumn()) {
                    $db->prepare("INSERT INTO totem_fiscal_nt (codigo, titulo, status, data_publicacao) VALUES (?,?,?,?)")
                       ->execute([$codigo, "Nota Técnica {$codigo} — aguardando análise", 'nova', date('Y-m-d')]);
                    $novas[] = $codigo;
                }
            } catch (Throwable) {}
        }

        // Registrar hora da última verificação
        try {
            $db->prepare("INSERT INTO totem_configuracoes (chave,valor) VALUES ('radar_ultima_verificacao',?) ON CONFLICT (chave) DO UPDATE SET valor=EXCLUDED.valor, atualizado_em=NOW()")
               ->execute([date('Y-m-d H:i:s')]);
        } catch (Throwable) {}

        // Gerar alerta se houver NT nova
        if (!empty($novas)) {
            try {
                $descricao = 'Nova(s) NT detectada(s): ' . implode(', ', $novas) . '. Analise o impacto e atualize o sistema se necessário.';
                $db->prepare("INSERT INTO totem_fiscal_alertas (tipo, severidade, titulo, descricao) VALUES ('nova_nt','warning','Nova Nota Técnica detectada',?) ON CONFLICT (tipo) DO UPDATE SET descricao=EXCLUDED.descricao,resolvido=FALSE,resolvido_em=NULL")
                   ->execute([$descricao]);
            } catch (Throwable) {}
        }

        return [
            'success'         => empty($erros) || !empty($encontradas),
            'total_no_portal' => count($encontradas),
            'novas_registradas' => count($novas),
            'novas'           => $novas,
            'erros'           => $erros,
            'verificado_em'   => date('Y-m-d H:i:s'),
            'msg'             => empty($novas)
                ? (empty($erros) ? 'Nenhuma NT nova encontrada. Sistema atualizado.' : 'Portal não respondeu — tente novamente.')
                : count($novas) . ' nova(s) NT registrada(s): ' . implode(', ', $novas),
        ];
    }

    /**
     * Marcar NT como analisada ou aplicada
     */
    public static function atualizarStatus(PDO $db, int $id, string $status): bool
    {
        $allowed = ['nova', 'analisada', 'aplicada', 'ignorada'];
        if (!in_array($status, $allowed)) return false;
        $db->prepare("UPDATE totem_fiscal_nt SET status=? WHERE id=?")->execute([$status, $id]);
        return true;
    }
}

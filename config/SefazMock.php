<?php
/**
 * SefazMock — Simulador de respostas SEFAZ para testes (Fase 2)
 * Fase 3 substituirá por chamada SOAP real ao webservice da SEFAZ
 */
class SefazMock
{
    // Rejeições comuns para simulação
    private const REJEICOES = [
        ['codigo' => '539', 'descricao' => 'Duplicidade de NF-e com diferenca na Chave de Acesso'],
        ['codigo' => '246', 'descricao' => 'NCM invalido'],
        ['codigo' => '409', 'descricao' => 'Data de Emissao muito atrasada'],
        ['codigo' => '539', 'descricao' => 'Duplicidade de NF-e'],
        ['codigo' => '228', 'descricao' => 'Data de Emissao muito antiga'],
        ['codigo' => '402', 'descricao' => 'Rejeicao: Falha no Schema XML'],
    ];

    // Tradução para português
    private const TRADUCOES = [
        '539' => 'Nota duplicada: já foi enviada uma nota com este número.',
        '246' => 'Código NCM do produto está inválido. Verifique o cadastro.',
        '409' => 'Data de emissão fora do prazo permitido pela SEFAZ.',
        '228' => 'A data da nota é muito antiga e não pode mais ser transmitida.',
        '402' => 'Estrutura do XML inválida. Verifique os dados da nota.',
        '205' => 'CNPJ do emitente inválido.',
        '206' => 'Inscrição Estadual do emitente inválida.',
        '357' => 'Certificado digital inválido ou vencido.',
        '999' => 'Erro interno da SEFAZ. Tente novamente em instantes.',
    ];

    /**
     * Simula autorização com protocolo fictício
     */
    public static function autorizar(string $chave): array
    {
        // Gerar protocolo fictício no formato SEFAZ: 3 dígitos UF + data + sequencial
        $protocolo = '353' . date('Ymd') . str_pad((string)rand(0, 999999999), 9, '0', STR_PAD_LEFT);

        return [
            'autorizada'  => true,
            'protocolo'   => $protocolo,
            'codigo'      => '100',
            'mensagem'    => '100 - Autorizado o uso da NF-e',
            'dhRecbto'    => date('Y-m-d\TH:i:sP'),
            'simulado'    => true,
        ];
    }

    /**
     * Simula uma rejeição aleatória (para testar o fluxo de erro)
     */
    public static function simularRejeicao(): array
    {
        $rej = self::REJEICOES[array_rand(self::REJEICOES)];
        return [
            'autorizada'   => false,
            'protocolo'    => null,
            'codigo'       => $rej['codigo'],
            'mensagem'     => $rej['descricao'],
            'mensagem_pt'  => self::TRADUCOES[$rej['codigo']] ?? $rej['descricao'],
            'simulado'     => true,
        ];
    }

    /**
     * Simula contingência (SEFAZ offline)
     */
    public static function simularContingencia(): array
    {
        return [
            'autorizada'  => false,
            'contingencia'=> true,
            'protocolo'   => null,
            'codigo'      => '999',
            'mensagem'    => 'Serviço SEFAZ temporariamente indisponível',
            'mensagem_pt' => 'A SEFAZ está fora do ar. A nota foi salva em modo de contingência e será transmitida automaticamente quando a SEFAZ voltar.',
            'simulado'    => true,
        ];
    }

    /**
     * Traduz código de rejeição para português
     */
    public static function traduzir(string $codigo): string
    {
        return self::TRADUCOES[$codigo] ?? "Rejeição SEFAZ código {$codigo}.";
    }

    /**
     * Ponto de entrada principal: autorizar ou simular conforme modo
     * @param string $xml      XML gerado pelo NfceBuilder
     * @param string $modo     'autorizar' | 'rejeitar' | 'contingencia'
     */
    public static function transmitir(string $xml, string $modo = 'autorizar'): array
    {
        // Simular latência de rede (0.1–0.4s)
        usleep(rand(100000, 400000));

        return match($modo) {
            'rejeitar'    => self::simularRejeicao(),
            'contingencia'=> self::simularContingencia(),
            default       => self::autorizar(''),
        };
    }
}

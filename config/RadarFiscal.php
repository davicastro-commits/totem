<?php
/**
 * RadarFiscal — Verificador de conformidade fiscal em tempo real
 * Lê dados reais do banco e gera checklist, alertas e status da Reforma
 */
class RadarFiscal
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    // ─── Checklist completo de conformidade ────────────────────────────────
    public function verificarConformidade(): array
    {
        $cfg = $this->carregarCfg();
        $checks = [];

        // ── 1. NCM dos produtos ──
        $totalProd = (int)$this->db->query("SELECT COUNT(*) FROM totem_produtos WHERE disponivel=TRUE")->fetchColumn();
        $semNcm    = $this->db->query("SELECT id, nome FROM totem_produtos WHERE (ncm IS NULL OR TRIM(ncm)='') AND disponivel=TRUE ORDER BY nome LIMIT 20")->fetchAll();
        $checks[] = [
            'id'       => 'ncm_produtos',
            'grupo'    => 'Produtos',
            'icone'    => '📦',
            'titulo'   => 'NCM dos produtos',
            'status'   => empty($semNcm) ? 'ok' : 'danger',
            'descricao'=> empty($semNcm)
                ? "Todos os {$totalProd} produtos têm NCM cadastrado"
                : count($semNcm) . " de {$totalProd} produto(s) sem NCM: " . implode(', ', array_map(fn($p) => '"'.$p['nome'].'"', array_slice($semNcm, 0, 3))),
            'acao'     => empty($semNcm) ? null : ['label' => 'Corrigir produtos', 'tab' => 'produtos'],
            'itens'    => $semNcm,
        ];

        // ── 2. CFOP por produto ──
        $semCfop = $this->db->query("SELECT COUNT(*) FROM totem_produtos WHERE (cfop IS NULL OR TRIM(cfop)='') AND disponivel=TRUE")->fetchColumn();
        $checks[] = [
            'id'       => 'cfop_produtos',
            'grupo'    => 'Produtos',
            'icone'    => '🔢',
            'titulo'   => 'CFOP dos produtos',
            'status'   => $semCfop == 0 ? 'ok' : 'warning',
            'descricao'=> $semCfop == 0
                ? 'CFOP preenchido em todos os produtos'
                : "{$semCfop} produto(s) sem CFOP (padrão NFC-e: 5102 — venda ao consumidor)",
            'acao'     => $semCfop > 0 ? ['label' => 'Corrigir produtos', 'tab' => 'produtos'] : null,
        ];

        // ── 3. CNPJ configurado ──
        $cnpj = $cfg['nfce_cnpj'] ?? '';
        $cnpjFmt = $cnpj ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj) : '';
        $checks[] = [
            'id'       => 'cnpj',
            'grupo'    => 'Configuração NFC-e',
            'icone'    => '🏢',
            'titulo'   => 'CNPJ do emitente',
            'status'   => $cnpj ? 'ok' : 'danger',
            'descricao'=> $cnpj ? "CNPJ {$cnpjFmt} configurado" : 'CNPJ não configurado — emissão bloqueada',
            'acao'     => !$cnpj ? ['label' => 'Configurar agora', 'tab' => 'saude-fiscal'] : null,
        ];

        // ── 4. CSC ──
        $csc = $cfg['nfce_csc'] ?? '';
        $checks[] = [
            'id'       => 'csc',
            'grupo'    => 'Configuração NFC-e',
            'icone'    => '🔑',
            'titulo'   => 'CSC (Código de Segurança)',
            'status'   => $csc ? 'ok' : 'danger',
            'descricao'=> $csc ? 'CSC configurado — QR Code da NFC-e funcionará' : 'CSC não configurado — QR Code inválido',
            'acao'     => !$csc ? ['label' => 'Configurar CSC', 'tab' => 'saude-fiscal'] : null,
        ];

        // ── 5. Certificado digital A1 ──
        $certVal = $cfg['nfce_cert_validade'] ?? '';
        if ($certVal) {
            $dias = (int)ceil((strtotime($certVal) - time()) / 86400);
            [$certStatus, $certDesc] = $dias <= 0
                ? ['danger',  'Certificado VENCIDO há ' . abs($dias) . ' dias — emissão bloqueada!']
                : ($dias <= 30
                    ? ['warning', "Vence em {$dias} dias — providencie a renovação"]
                    : ['ok',      "Válido por {$dias} dias (até " . date('d/m/Y', strtotime($certVal)) . ')'   ]);
        } else {
            $certStatus = 'warning';
            $certDesc   = 'Data de validade não cadastrada';
        }
        $checks[] = [
            'id'       => 'certificado',
            'grupo'    => 'Certificado Digital',
            'icone'    => '🎖️',
            'titulo'   => 'Certificado digital A1',
            'status'   => $certStatus,
            'descricao'=> $certDesc,
        ];

        // ── 6. Ambiente de emissão ──
        $amb = $cfg['nfce_ambiente'] ?? 'homologacao';
        $checks[] = [
            'id'       => 'ambiente',
            'grupo'    => 'Emissão',
            'icone'    => '🌐',
            'titulo'   => 'Ambiente de emissão',
            'status'   => $amb === 'producao' ? 'ok' : 'info',
            'descricao'=> $amb === 'producao'
                ? 'Emitindo em PRODUÇÃO — notas com validade fiscal real'
                : 'Ambiente de HOMOLOGAÇÃO — notas sem validade fiscal (apenas testes)',
        ];

        // ── 7. Série e numeração ──
        $serie  = $cfg['nfce_serie'] ?? '';
        $numAt  = (int)($cfg['nfce_numero_atual'] ?? 0);
        $checks[] = [
            'id'       => 'serie',
            'grupo'    => 'Emissão',
            'icone'    => '🔢',
            'titulo'   => 'Série e numeração',
            'status'   => $serie ? 'ok' : 'warning',
            'descricao'=> $serie
                ? "Série {$serie} · Próxima nota: #" . ($numAt + 1)
                : 'Série não configurada',
        ];

        // ── 8. Conformidade Reforma 2026 ──
        $regime = $cfg['nfce_regime'] ?? '1';
        $mesAno = (int)date('Ym');
        $regimeDesc = ['1'=>'Simples Nacional','2'=>'Lucro Presumido','3'=>'Lucro Real'][$regime] ?? 'Simples';

        if ($regime === '1') {
            // SN: só muda em 2027
            $refStatus = 'ok';
            $refDesc = "Simples Nacional: sem alterações obrigatórias até jan/2027. Em dia!";
        } else {
            // Não-SN: destaque informativo desde jan/2026
            $refStatus = $mesAno >= 202601 ? 'warning' : 'ok';
            $refDesc   = $mesAno >= 202601
                ? "{$regimeDesc}: destaque informativo CBS/IBS obrigatório desde jan/2026. Verifique se os campos estão no XML."
                : "{$regimeDesc}: destaque informativo CBS/IBS obrigatório a partir de jan/2026.";
        }
        $checks[] = [
            'id'       => 'reforma_2026',
            'grupo'    => 'Reforma Tributária',
            'icone'    => '⚖️',
            'titulo'   => 'Conformidade — Reforma 2026',
            'status'   => $refStatus,
            'descricao'=> $refDesc,
        ];

        return $checks;
    }

    // ─── Score geral de conformidade (0-100) ───────────────────────────────
    public function calcularScore(array $checks): int
    {
        if (empty($checks)) return 0;
        $peso = ['ok' => 1, 'info' => 1, 'warning' => 0.5, 'danger' => 0];
        $soma = array_sum(array_map(fn($c) => $peso[$c['status']] ?? 0, $checks));
        return (int)round($soma / count($checks) * 100);
    }

    // ─── Timeline Reforma ──────────────────────────────────────────────────
    public function getTimeline(): array
    {
        return $this->db->query("SELECT * FROM totem_fiscal_reforma_timeline ORDER BY data_vigencia")->fetchAll();
    }

    // ─── Notas Técnicas ────────────────────────────────────────────────────
    public function getNotasTecnicas(): array
    {
        return $this->db->query("SELECT * FROM totem_fiscal_nt ORDER BY data_publicacao DESC LIMIT 20")->fetchAll();
    }

    // ─── Alertas ativos ────────────────────────────────────────────────────
    public function getAlertas(): array
    {
        return $this->db->query("
            SELECT * FROM totem_fiscal_alertas
            WHERE resolvido=FALSE AND dispensado=FALSE
            ORDER BY severidade DESC, criado_em DESC
        ")->fetchAll();
    }

    // ─── Sincronizar alertas com conformidade ──────────────────────────────
    public function sincronizarAlertas(): void
    {
        $checks = $this->verificarConformidade();
        foreach ($checks as $c) {
            if (in_array($c['status'], ['danger','warning'])) {
                $this->db->prepare("
                    INSERT INTO totem_fiscal_alertas (tipo, severidade, titulo, descricao)
                    VALUES (?,?,?,?)
                    ON CONFLICT (tipo) DO UPDATE SET
                        severidade=EXCLUDED.severidade,
                        titulo=EXCLUDED.titulo,
                        descricao=EXCLUDED.descricao,
                        resolvido=FALSE,
                        resolvido_em=NULL
                ")->execute([$c['id'], $c['status'], $c['titulo'], $c['descricao']]);
            } else {
                $this->db->prepare("
                    UPDATE totem_fiscal_alertas
                    SET resolvido=TRUE, resolvido_em=NOW()
                    WHERE tipo=? AND resolvido=FALSE
                ")->execute([$c['id']]);
            }
        }
    }

    // ─── Última verificação portal ─────────────────────────────────────────
    public function getUltimaVerificacao(): ?string
    {
        $val = $this->db->query("SELECT valor FROM totem_configuracoes WHERE chave='radar_ultima_verificacao'")->fetchColumn();
        return $val ?: null;
    }

    // ─── Helper: carregar configurações NFC-e ─────────────────────────────
    private function carregarCfg(): array
    {
        $rows = $this->db->query("SELECT chave, valor FROM totem_configuracoes WHERE chave LIKE 'nfce_%'")->fetchAll();
        $out  = [];
        foreach ($rows as $r) $out[$r['chave']] = $r['valor'];
        return $out;
    }
}

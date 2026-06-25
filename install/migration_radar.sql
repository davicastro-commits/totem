-- Migration: Radar Fiscal — tabelas de conformidade, NTs e timeline

-- Adicionar coluna NCM nos produtos (se não existir)
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS ncm       VARCHAR(8)  DEFAULT '';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS cfop      VARCHAR(5)  DEFAULT '5102';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS cst_csosn VARCHAR(10) DEFAULT '400';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS origem    SMALLINT    DEFAULT 0;
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS unidade   VARCHAR(6)  DEFAULT 'UN';

-- Notas Técnicas detectadas / monitoradas
CREATE TABLE IF NOT EXISTS totem_fiscal_nt (
    id               SERIAL PRIMARY KEY,
    codigo           VARCHAR(50) UNIQUE,
    titulo           TEXT,
    url              TEXT,
    data_publicacao  DATE,
    impacto          TEXT,
    status           VARCHAR(20) DEFAULT 'nova',
    -- nova | analisada | aplicada | ignorada
    criado_em        TIMESTAMPTZ DEFAULT NOW()
);

-- Timeline da Reforma Tributária
CREATE TABLE IF NOT EXISTS totem_fiscal_reforma_timeline (
    id               SERIAL PRIMARY KEY,
    data_vigencia    DATE NOT NULL,
    titulo           TEXT NOT NULL,
    descricao        TEXT,
    regime_afetado   VARCHAR(20) DEFAULT 'todos',
    -- todos | SN | nao_SN
    status_marco     VARCHAR(20) DEFAULT 'futuro',
    -- passado | vigente | futuro
    cor              VARCHAR(10) DEFAULT '#6b7280',
    criado_em        TIMESTAMPTZ DEFAULT NOW()
);

-- Alertas de conformidade gerados automaticamente
CREATE TABLE IF NOT EXISTS totem_fiscal_alertas (
    id               SERIAL PRIMARY KEY,
    tipo             VARCHAR(60) UNIQUE,
    severidade       VARCHAR(10) DEFAULT 'warning',
    -- danger | warning | info
    titulo           TEXT,
    descricao        TEXT,
    resolvido        BOOLEAN DEFAULT FALSE,
    dispensado       BOOLEAN DEFAULT FALSE,
    criado_em        TIMESTAMPTZ DEFAULT NOW(),
    resolvido_em     TIMESTAMPTZ
);

-- ── Seed: Notas Técnicas conhecidas ────────────────────────────────────────
INSERT INTO totem_fiscal_nt (codigo, titulo, data_publicacao, impacto, status) VALUES
('NT 2019.001', 'NF-e/NFC-e leiaute 4.00 — versão final',                    '2019-03-01', 'Leiaute atual obrigatório desde 2019', 'aplicada'),
('NT 2020.003', 'NFC-e — campos de destino e informações adicionais',          '2020-07-01', 'Ajustes menores no leiaute 4.00', 'aplicada'),
('NT 2023.004', 'Inclusão de campos para IBS, CBS e Imposto Seletivo',         '2023-09-01', 'Preparação para Reforma Tributária — grupos informati', 'aplicada'),
('NT 2024.001', 'Grupo de tributos da Reforma (IBS/CBS/IS) — validações',      '2024-03-01', 'Regras de validação para os novos grupos de tributos', 'aplicada'),
('NT 2024.002', 'cClassTrib — tabela de classificação tributária atualizada',  '2024-06-01', 'Nova tabela cClassTrib para IBS/CBS', 'aplicada'),
('NT RTC',      'Reforma Tributária Complementar — IBS/CBS/IS no leiaute NFC-e','2025-10-01', 'Grupo IBS/CBS/IS obrigatório por item a partir de 2026', 'aplicada')
ON CONFLICT (codigo) DO NOTHING;

-- ── Seed: Timeline Reforma Tributária ──────────────────────────────────────
INSERT INTO totem_fiscal_reforma_timeline (data_vigencia, titulo, descricao, regime_afetado, status_marco, cor) VALUES
('2023-06-30', 'LC 214/2023 aprovada', 'Lei Complementar que criou o IBS (Imposto sobre Bens e Serviços) e o CBS, substituindo gradualmente ICMS, ISS, PIS e COFINS.', 'todos', 'passado', '#22c55e'),
('2025-10-01', 'NT RTC publicada — leiaute NFC-e atualizado', 'Nota Técnica da Reforma inclui grupo IBS/CBS/IS no XML da NFC-e. Sistemas devem estar preparados.', 'todos', 'passado', '#22c55e'),
('2026-01-01', 'Destaque CBS/IBS informativo — não-Simples Nacional', 'Empresas fora do Simples Nacional passam a destacar CBS e IBS na nota fiscal de forma informativa. Sem cobrança efetiva ainda.', 'nao_SN', 'vigente', '#f59e0b'),
('2026-07-01', 'Período de testes e ajustes', 'SEFAZ e contribuintes ajustam sistemas. Fiscalização orientativa, não punitiva.', 'todos', 'futuro', '#3b82f6'),
('2027-01-01', 'Destaque CBS/IBS informativo — Simples Nacional', 'Simples Nacional passa a destacar CBS e IBS na NFC-e de forma informativa.', 'SN', 'futuro', '#3b82f6'),
('2027-01-01', 'IBS/CBS com cobrança efetiva — não-Simples Nacional', 'IBS e CBS passam a ser cobrados efetivamente para regimes fora do Simples.', 'nao_SN', 'futuro', '#8b5cf6'),
('2029-01-01', 'Redução gradual ICMS/ISS começa', 'ICMS e ISS passam a ser reduzidos progressivamente ao longo de 8 anos.', 'todos', 'futuro', '#6b7280'),
('2033-01-01', 'Extinção PIS/COFINS e IPI', 'PIS, COFINS e IPI são extintos. IBS e CBS em plena vigência.', 'todos', 'futuro', '#6b7280')
ON CONFLICT DO NOTHING;

COMMENT ON TABLE totem_fiscal_nt IS 'Notas Técnicas NF-e monitoradas pelo Radar Fiscal';
COMMENT ON TABLE totem_fiscal_reforma_timeline IS 'Timeline da Reforma Tributária brasileira';
COMMENT ON TABLE totem_fiscal_alertas IS 'Alertas de conformidade fiscal gerados automaticamente';

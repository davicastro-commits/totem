-- Migration: Sistema NFC-e Nível 1
-- Rastreamento de notas fiscais eletrônicas por pedido

-- Tabela principal de notas
CREATE TABLE IF NOT EXISTS totem_nfce (
    id               SERIAL PRIMARY KEY,
    pedido_id        INT REFERENCES totem_pedidos(id) ON DELETE SET NULL,
    numero           INT NOT NULL,
    serie            VARCHAR(3) NOT NULL DEFAULT '001',
    chave_acesso     VARCHAR(44),
    status           VARCHAR(20) NOT NULL DEFAULT 'pendente',
    -- pendente | transmitindo | autorizada | rejeitada | cancelada | contingencia
    protocolo        VARCHAR(60),
    ambiente         VARCHAR(12) NOT NULL DEFAULT 'homologacao',
    tipo_emissao     VARCHAR(12) NOT NULL DEFAULT 'normal',
    total            NUMERIC(10,2) DEFAULT 0,
    forma_pagamento  VARCHAR(50),
    xml_nfe          TEXT,
    xml_protocolo    TEXT,
    motivo_rejeicao  TEXT,
    cod_rejeicao     VARCHAR(10),
    criado_em        TIMESTAMPTZ DEFAULT NOW(),
    autorizado_em    TIMESTAMPTZ,
    cancelado_em     TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_nfce_pedido ON totem_nfce(pedido_id) WHERE pedido_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_nfce_status ON totem_nfce(status);
CREATE INDEX IF NOT EXISTS idx_nfce_criado ON totem_nfce(criado_em DESC);

-- Tabela de rejeições com tradução
CREATE TABLE IF NOT EXISTS totem_nfce_rejeicoes (
    id            SERIAL PRIMARY KEY,
    nfce_id       INT NOT NULL REFERENCES totem_nfce(id) ON DELETE CASCADE,
    codigo        VARCHAR(10),
    descricao     TEXT,
    descricao_pt  TEXT,
    tentativa     INT DEFAULT 1,
    criado_em     TIMESTAMPTZ DEFAULT NOW()
);

-- Configurações NFC-e (usa a tabela existente totem_configuracoes)
-- Chaves: nfce_ativo, nfce_cnpj, nfce_ie, nfce_uf, nfce_serie,
--         nfce_ambiente, nfce_regime, nfce_csc, nfce_csc_id,
--         nfce_cert_validade, nfce_numero_atual

INSERT INTO totem_configuracoes (chave, valor) VALUES
    ('nfce_ativo',         '0'),
    ('nfce_serie',         '001'),
    ('nfce_numero_atual',  '0'),
    ('nfce_ambiente',      'homologacao'),
    ('nfce_regime',        '1'),
    ('nfce_uf',            'DF'),
    ('nfce_cnpj',          ''),
    ('nfce_ie',            ''),
    ('nfce_csc',           ''),
    ('nfce_csc_id',        ''),
    ('nfce_cert_validade', '')
ON CONFLICT (chave) DO NOTHING;

COMMENT ON TABLE totem_nfce IS 'Registro de notas fiscais NFC-e por pedido';
COMMENT ON TABLE totem_nfce_rejeicoes IS 'Rejeições SEFAZ com tradução para português';

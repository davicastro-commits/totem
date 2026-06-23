-- ============================================================
-- Migration v8 — Mesas e Comandas (Module 13)
-- Execute após migration_v7_crm.sql:
--   psql -U postgres -d comunhao -f install/migration_v8_mesas.sql
-- ============================================================

SET search_path = material;

-- ── Mesas ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_mesas (
  id          SERIAL PRIMARY KEY,
  numero      VARCHAR(10) NOT NULL UNIQUE,
  capacidade  INTEGER DEFAULT 4,
  status      VARCHAR(15) DEFAULT 'livre' CHECK (status IN ('livre','ocupada','reservada','bloqueada')),
  garcom_id   INTEGER REFERENCES totem_admin(id),
  localizacao VARCHAR(50),  -- 'salao','varanda','vip'
  ativa       BOOLEAN DEFAULT true,
  criado_em   TIMESTAMP DEFAULT NOW()
);

-- ── Comandas ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_comandas (
  id              SERIAL PRIMARY KEY,
  mesa_id         INTEGER NOT NULL REFERENCES totem_mesas(id),
  status          VARCHAR(15) DEFAULT 'aberta' CHECK (status IN ('aberta','fechada','paga','cancelada')),
  garcom_id       INTEGER REFERENCES totem_admin(id),
  subtotal        DECIMAL(10,2) DEFAULT 0,
  desconto        DECIMAL(10,2) DEFAULT 0,
  taxa_servico    DECIMAL(10,2) DEFAULT 0,
  total           DECIMAL(10,2) DEFAULT 0,
  observacao      TEXT,
  forma_pagamento VARCHAR(20),
  aberta_em       TIMESTAMP DEFAULT NOW(),
  fechada_em      TIMESTAMP,
  paga_em         TIMESTAMP
);

-- ── Itens da Comanda ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_itens_comanda (
  id             SERIAL PRIMARY KEY,
  comanda_id     INTEGER NOT NULL REFERENCES totem_comandas(id) ON DELETE CASCADE,
  produto_id     INTEGER NOT NULL REFERENCES totem_produtos(id),
  quantidade     INTEGER NOT NULL DEFAULT 1,
  preco_unitario DECIMAL(10,2) NOT NULL,
  subtotal       DECIMAL(10,2) NOT NULL,
  obs            TEXT,
  status         VARCHAR(15) DEFAULT 'aguardando' CHECK (status IN ('aguardando','preparando','pronto','entregue')),
  enviado_kds    BOOLEAN DEFAULT false,
  criado_em      TIMESTAMP DEFAULT NOW()
);

-- ── Índices ───────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_comandas_mesa ON totem_comandas(mesa_id);
CREATE INDEX IF NOT EXISTS idx_itens_comanda ON totem_itens_comanda(comanda_id);

-- ── Seed: 10 mesas padrão ────────────────────────────────────────────
INSERT INTO totem_mesas (numero, capacidade, localizacao) VALUES
  ('01', 4, 'salao'),
  ('02', 4, 'salao'),
  ('03', 4, 'salao'),
  ('04', 6, 'salao'),
  ('05', 6, 'salao'),
  ('06', 2, 'varanda'),
  ('07', 2, 'varanda'),
  ('08', 8, 'vip'),
  ('09', 4, 'salao'),
  ('10', 4, 'salao')
ON CONFLICT (numero) DO NOTHING;

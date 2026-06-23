-- =============================================================
-- Migration v6 — Módulo de Estoque
-- Banco: comunhao | Schema: material
-- Criado em: 2026-06-23
-- =============================================================
SET search_path = material;

-- ── Insumos (ingredientes / matéria-prima) ────────────────────
CREATE TABLE IF NOT EXISTS totem_insumos (
  id              SERIAL PRIMARY KEY,
  nome            VARCHAR(100) NOT NULL,
  unidade         VARCHAR(10)  NOT NULL DEFAULT 'UN', -- UN, KG, L, G, ML
  custo_medio     DECIMAL(10,4) DEFAULT 0,
  estoque_atual   DECIMAL(10,3) DEFAULT 0,
  estoque_minimo  DECIMAL(10,3) DEFAULT 0,
  ativo           BOOLEAN       DEFAULT true,
  criado_em       TIMESTAMP     DEFAULT NOW(),
  atualizado_em   TIMESTAMP     DEFAULT NOW()
);

-- ── Ficha técnica (insumos por produto) ──────────────────────
CREATE TABLE IF NOT EXISTS totem_ficha_tecnica (
  id          SERIAL PRIMARY KEY,
  produto_id  INTEGER NOT NULL REFERENCES totem_produtos(id) ON DELETE CASCADE,
  insumo_id   INTEGER NOT NULL REFERENCES totem_insumos(id)  ON DELETE CASCADE,
  quantidade  DECIMAL(10,4) NOT NULL, -- consumo por unidade vendida
  UNIQUE (produto_id, insumo_id)
);

-- ── Movimentações de estoque ──────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_movimentacoes_estoque (
  id              SERIAL PRIMARY KEY,
  insumo_id       INTEGER NOT NULL REFERENCES totem_insumos(id),
  tipo            VARCHAR(10) NOT NULL CHECK (tipo IN ('entrada','saida','ajuste')),
  quantidade      DECIMAL(10,3) NOT NULL,
  custo_unitario  DECIMAL(10,4) DEFAULT 0,
  motivo          VARCHAR(200),
  pedido_id       INTEGER REFERENCES totem_pedidos(id),
  usuario_id      INTEGER,
  criado_em       TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_mov_insumo ON totem_movimentacoes_estoque(insumo_id);
CREATE INDEX IF NOT EXISTS idx_mov_pedido ON totem_movimentacoes_estoque(pedido_id);
CREATE INDEX IF NOT EXISTS idx_insumos_ativo ON totem_insumos(ativo);
CREATE INDEX IF NOT EXISTS idx_ficha_produto ON totem_ficha_tecnica(produto_id);
CREATE INDEX IF NOT EXISTS idx_ficha_insumo  ON totem_ficha_tecnica(insumo_id);

-- ============================================================
-- Migration: DRE (Demonstrativo de Resultado) + Metas mensais
-- Criado em: 2026-06-24
-- ============================================================

-- Despesas operacionais
CREATE TABLE IF NOT EXISTS material.totem_despesas (
  id          SERIAL PRIMARY KEY,
  data        DATE         NOT NULL DEFAULT CURRENT_DATE,
  categoria   VARCHAR(50)  NOT NULL CHECK (categoria IN ('aluguel','fornecedor','energia','folha','marketing','outros')),
  descricao   VARCHAR(200) NOT NULL,
  valor       NUMERIC(10,2) NOT NULL CHECK (valor > 0),
  recorrente  BOOLEAN      DEFAULT false,
  admin_id    INT          REFERENCES material.totem_admin(id),
  criado_em   TIMESTAMPTZ  DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_despesas_data     ON material.totem_despesas(data);
CREATE INDEX IF NOT EXISTS idx_despesas_categoria ON material.totem_despesas(categoria);
CREATE INDEX IF NOT EXISTS idx_despesas_admin_id  ON material.totem_despesas(admin_id);

COMMENT ON TABLE  material.totem_despesas IS 'Despesas operacionais da cafeteria (aluguel, energia, folha, etc.)';
COMMENT ON COLUMN material.totem_despesas.categoria  IS 'aluguel | fornecedor | energia | folha | marketing | outros';
COMMENT ON COLUMN material.totem_despesas.recorrente IS 'Indica se a despesa se repete todo mês';

-- Metas mensais de faturamento
CREATE TABLE IF NOT EXISTS material.totem_metas (
  id                 SERIAL PRIMARY KEY,
  mes                DATE          NOT NULL, -- sempre o primeiro dia do mês (DATE_TRUNC)
  meta_faturamento   NUMERIC(10,2) NOT NULL CHECK (meta_faturamento > 0),
  admin_id           INT           REFERENCES material.totem_admin(id),
  criado_em          TIMESTAMPTZ   DEFAULT NOW(),
  UNIQUE(mes)
);

CREATE INDEX IF NOT EXISTS idx_metas_mes ON material.totem_metas(mes);

COMMENT ON TABLE  material.totem_metas IS 'Metas mensais de faturamento';
COMMENT ON COLUMN material.totem_metas.mes IS 'Sempre o primeiro dia do mês (DATE_TRUNC month)';

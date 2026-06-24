-- Migration v10: Estoque Inteligente (ROP, EOQ, Safety Stock, ABC, Histórico)
-- Execute: SET search_path = material; \i migration_v10_estoque_inteligente.sql

SET search_path = material;

-- ── Fase 1: Novos campos em totem_insumos ────────────────────────────────────

-- Parâmetros configuráveis pelo usuário
ALTER TABLE totem_insumos
    ADD COLUMN IF NOT EXISTS lead_time_days    INTEGER         DEFAULT 2,
    ADD COLUMN IF NOT EXISTS custo_por_pedido  DECIMAL(8,2)    DEFAULT 25.00,
    ADD COLUMN IF NOT EXISTS dias_estoque_alvo INTEGER         DEFAULT 15;

-- Campos calculados automaticamente (atualizados pelo serviço)
ALTER TABLE totem_insumos
    ADD COLUMN IF NOT EXISTS consumo_medio_diario  DECIMAL(10,4) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS consumo_max_diario    DECIMAL(10,4) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS desvio_padrao_demanda DECIMAL(10,4) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS safety_stock          DECIMAL(10,4) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS rop                   DECIMAL(10,4) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS eoq                   DECIMAL(10,4) DEFAULT 0;

-- Classificação ABC/XYZ
ALTER TABLE totem_insumos
    ADD COLUMN IF NOT EXISTS classe_abc VARCHAR(1) DEFAULT '',
    ADD COLUMN IF NOT EXISTS classe_xyz VARCHAR(1) DEFAULT '';

-- ── Fase 2: Tabela de histórico diário de estoque ────────────────────────────

CREATE TABLE IF NOT EXISTS totem_historico_estoque (
    id                SERIAL PRIMARY KEY,
    insumo_id         INTEGER       NOT NULL REFERENCES totem_insumos(id) ON DELETE CASCADE,
    data              DATE          NOT NULL DEFAULT CURRENT_DATE,
    estoque_snapshot  DECIMAL(10,4) NOT NULL,
    consumo_dia       DECIMAL(10,4) DEFAULT 0,
    UNIQUE (insumo_id, data)
);

CREATE INDEX IF NOT EXISTS idx_historico_estoque_insumo ON totem_historico_estoque (insumo_id);
CREATE INDEX IF NOT EXISTS idx_historico_estoque_data   ON totem_historico_estoque (data DESC);

-- Verificação
SELECT 'Migration v10 aplicada com sucesso.' AS status;
SELECT column_name, data_type, column_default
  FROM information_schema.columns
 WHERE table_schema = 'material'
   AND table_name   = 'totem_insumos'
   AND column_name  IN ('lead_time_days','safety_stock','rop','eoq','classe_abc')
 ORDER BY ordinal_position;

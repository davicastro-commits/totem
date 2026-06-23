-- ============================================================
-- Migration v7 — CRM / Programa de Fidelidade
-- Execute após migration_v5.sql:
--   psql -U postgres -d comunhao -f install/migration_v7_crm.sql
-- ============================================================

SET search_path = material;

-- ── Clientes ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_clientes (
  id               SERIAL PRIMARY KEY,
  nome             VARCHAR(100),
  cpf              VARCHAR(11) UNIQUE,           -- apenas dígitos, sem máscara
  telefone         VARCHAR(20),
  email            VARCHAR(100),
  data_nascimento  DATE,
  pontos_saldo     INTEGER DEFAULT 0,
  total_gasto      DECIMAL(12,2) DEFAULT 0,
  total_pedidos    INTEGER DEFAULT 0,
  consentimento_lgpd BOOLEAN DEFAULT false,
  ativo            BOOLEAN DEFAULT true,
  criado_em        TIMESTAMP DEFAULT NOW(),
  atualizado_em    TIMESTAMP DEFAULT NOW()
);

-- ── Histórico de pontos ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_pontos_historico (
  id          SERIAL PRIMARY KEY,
  cliente_id  INTEGER NOT NULL REFERENCES totem_clientes(id),
  pedido_id   INTEGER REFERENCES totem_pedidos(id),
  tipo        VARCHAR(10) NOT NULL CHECK (tipo IN ('ganho','resgatado','expirado','ajuste')),
  pontos      INTEGER NOT NULL,     -- positivo=ganho, negativo=usado/expirado
  descricao   VARCHAR(200),
  expira_em   DATE,
  criado_em   TIMESTAMP DEFAULT NOW()
);

-- ── Cupons de desconto ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_cupons (
  id            SERIAL PRIMARY KEY,
  codigo        VARCHAR(30) UNIQUE NOT NULL,
  tipo          VARCHAR(12) NOT NULL CHECK (tipo IN ('percentual','fixo','frete_gratis')),
  valor         DECIMAL(10,2) NOT NULL,
  valor_minimo  DECIMAL(10,2) DEFAULT 0,   -- pedido mínimo para usar
  uso_maximo    INTEGER DEFAULT 1,
  usos_atuais   INTEGER DEFAULT 0,
  cliente_id    INTEGER REFERENCES totem_clientes(id),  -- null = cupom público
  validade      DATE,
  ativo         BOOLEAN DEFAULT true,
  criado_em     TIMESTAMP DEFAULT NOW()
);

-- ── Configuração do programa de pontos ──────────────────────────────
CREATE TABLE IF NOT EXISTS totem_pontos_config (
  id               SERIAL PRIMARY KEY,
  pontos_por_real  DECIMAL(5,2) DEFAULT 1.0,    -- pontos ganhos por R$1 gasto
  real_por_ponto   DECIMAL(5,4) DEFAULT 0.05,   -- R$ de desconto por ponto
  validade_dias    INTEGER DEFAULT 365,
  ativo            BOOLEAN DEFAULT true
);

-- Configuração padrão (inserir somente se a tabela estiver vazia)
INSERT INTO totem_pontos_config (pontos_por_real, real_por_ponto, validade_dias)
SELECT 1.0, 0.05, 365
WHERE NOT EXISTS (SELECT 1 FROM totem_pontos_config);

-- ── Índices ──────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_clientes_cpf       ON totem_clientes(cpf);
CREATE INDEX IF NOT EXISTS idx_clientes_telefone  ON totem_clientes(telefone);
CREATE INDEX IF NOT EXISTS idx_pontos_cliente     ON totem_pontos_historico(cliente_id);
CREATE INDEX IF NOT EXISTS idx_cupons_codigo      ON totem_cupons(codigo);

-- ── Colunas extras em totem_pedidos ──────────────────────────────────
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS cliente_id    INTEGER REFERENCES totem_clientes(id);
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS cupom_id      INTEGER REFERENCES totem_cupons(id);
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS desconto      DECIMAL(10,2) DEFAULT 0;
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS pontos_ganhos INTEGER DEFAULT 0;

DO $$
BEGIN
  RAISE NOTICE 'migration_v7_crm concluída com sucesso.';
END
$$;

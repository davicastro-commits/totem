-- ============================================================
-- Migration v3 — Performance indexes + scalability tweaks
-- Execute once against the 'comunhao' database:
--   psql -U postgres -d comunhao -f install/migration_v3.sql
-- ============================================================

SET search_path = material;

-- ── Pedidos ───────────────────────────────────────────────────────────
-- Most-used queries: list by status, list by date range, lookup by numero
CREATE INDEX IF NOT EXISTS idx_pedidos_status
    ON totem_pedidos (status)
    WHERE status NOT IN ('entregue','cancelado');

CREATE INDEX IF NOT EXISTS idx_pedidos_criado_em
    ON totem_pedidos (criado_em DESC);

CREATE INDEX IF NOT EXISTS idx_pedidos_status_criado
    ON totem_pedidos (status, criado_em DESC);

CREATE INDEX IF NOT EXISTS idx_pedidos_numero
    ON totem_pedidos (numero_pedido);

CREATE INDEX IF NOT EXISTS idx_pedidos_origem
    ON totem_pedidos (origem);

CREATE INDEX IF NOT EXISTS idx_pedidos_cpf
    ON totem_pedidos (cpf)
    WHERE cpf IS NOT NULL;

-- Date-range queries for reports
CREATE INDEX IF NOT EXISTS idx_pedidos_date_status
    ON totem_pedidos (DATE(criado_em), status);

-- ── Itens ─────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_itens_pedido_id
    ON totem_itens_pedido (pedido_id);

CREATE INDEX IF NOT EXISTS idx_itens_produto_id
    ON totem_itens_pedido (produto_id);

-- Report: top produtos by quantity
CREATE INDEX IF NOT EXISTS idx_itens_nome_produto
    ON totem_itens_pedido (nome_produto);

-- ── Produtos ──────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_produtos_categoria
    ON totem_produtos (categoria_id, ordem ASC)
    WHERE disponivel = TRUE;

CREATE INDEX IF NOT EXISTS idx_produtos_disponivel
    ON totem_produtos (disponivel)
    WHERE disponivel = TRUE;

CREATE INDEX IF NOT EXISTS idx_produtos_estoque_alerta
    ON totem_produtos (estoque_qtd)
    WHERE controlar_estoque = TRUE AND estoque_qtd <= estoque_alerta;

-- ── Audit ─────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_audit_usuario
    ON totem_audit (usuario_id, criado_em DESC)
    WHERE usuario_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_audit_modulo
    ON totem_audit (modulo, criado_em DESC);

-- ── Login tentativas ──────────────────────────────────────────────────
-- Cleanup: remove old attempts older than 24h (run via cron or manual)
CREATE INDEX IF NOT EXISTS idx_login_tent_ip_ts
    ON totem_login_tentativas (ip, tentativa_em DESC);

-- ── Configuracoes ─────────────────────────────────────────────────────
-- Already PK on chave, but add index on atualizado_em for cache busting
CREATE INDEX IF NOT EXISTS idx_cfg_updated
    ON totem_configuracoes (atualizado_em DESC);

-- ── Vacuum/Analyze after index creation ──────────────────────────────
ANALYZE totem_pedidos;
ANALYZE totem_itens_pedido;
ANALYZE totem_produtos;
ANALYZE totem_audit;
ANALYZE totem_login_tentativas;

-- ── Configurações extras de runtime (idempotente) ─────────────────────
INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('kds_refresh_segundos',   '5',    'Intervalo de refresh do KDS (SSE poll interval)')
ON CONFLICT (chave) DO NOTHING;

-- ── Adicionar coluna cancelado_motivo se não existir ──────────────────
ALTER TABLE totem_pedidos
    ADD COLUMN IF NOT EXISTS cancelado_por     INTEGER,
    ADD COLUMN IF NOT EXISTS cancelado_em      TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cancelado_motivo  TEXT;

-- ── Ensure obs existe em pedidos ─────────────────────────────────────
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS obs TEXT;

DO $$
BEGIN
  RAISE NOTICE 'migration_v3 concluída com sucesso.';
END
$$;

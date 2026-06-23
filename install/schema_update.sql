-- ============================================================
-- Schema Update — Sistema Café Comunhão
-- Execute: \c comunhao | SET search_path = material; | \i schema_update.sql
-- ============================================================

SET search_path = material;

-- ── Roles em totem_admin ──────────────────────────────────────────────
ALTER TABLE totem_admin
  ADD COLUMN IF NOT EXISTS role      TEXT    NOT NULL DEFAULT 'admin',
  ADD COLUMN IF NOT EXISTS ativo     BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS ultimo_login TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS criado_em TIMESTAMPTZ DEFAULT NOW();

-- roles disponíveis: 'admin' | 'operador' | 'cozinha'
-- admin    = acesso total (admin panel + caixa + kds)
-- operador = caixa + kds (sem gestão de produtos/usuários/auditoria)
-- cozinha  = apenas KDS

-- ── Campos extras em totem_pedidos ───────────────────────────────────
ALTER TABLE totem_pedidos
  ADD COLUMN IF NOT EXISTS obs              TEXT,
  ADD COLUMN IF NOT EXISTS cancelado_por    INTEGER REFERENCES totem_admin(id),
  ADD COLUMN IF NOT EXISTS cancelado_em     TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS cancelado_motivo TEXT,
  ADD COLUMN IF NOT EXISTS origem          TEXT NOT NULL DEFAULT 'totem';
  -- origem: 'totem' | 'caixa' | 'admin'

ALTER TABLE totem_pedidos
  ADD COLUMN IF NOT EXISTS operador_id INTEGER REFERENCES totem_admin(id);

-- ── Tabela de Auditoria ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_audit (
  id            BIGSERIAL    PRIMARY KEY,
  usuario_id    INTEGER      REFERENCES totem_admin(id) ON DELETE SET NULL,
  usuario_nome  TEXT,
  usuario_email TEXT,
  acao          TEXT         NOT NULL,
  modulo        TEXT,
  registro_id   INTEGER,
  descricao     TEXT,
  dados_antes   JSONB,
  dados_depois  JSONB,
  ip            TEXT,
  criado_em     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_acao      ON totem_audit(acao);
CREATE INDEX IF NOT EXISTS idx_audit_modulo    ON totem_audit(modulo);
CREATE INDEX IF NOT EXISTS idx_audit_usuario   ON totem_audit(usuario_id);
CREATE INDEX IF NOT EXISTS idx_audit_criado_em ON totem_audit(criado_em DESC);

-- ── Tabela de Sessões (para rastrear logins ativos) ──────────────────
CREATE TABLE IF NOT EXISTS totem_sessoes (
  id          BIGSERIAL   PRIMARY KEY,
  admin_id    INTEGER     REFERENCES totem_admin(id) ON DELETE CASCADE,
  ip          TEXT,
  user_agent  TEXT,
  login_em    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  logout_em   TIMESTAMPTZ,
  ativa       BOOLEAN     NOT NULL DEFAULT TRUE
);

-- ── Índice extra para performance ────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_pedidos_criado_em ON totem_pedidos(criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_pedidos_status    ON totem_pedidos(status);
CREATE INDEX IF NOT EXISTS idx_pedidos_numero    ON totem_pedidos(numero_pedido);

SELECT 'Schema atualizado com sucesso!' AS resultado;
SELECT 'Auditoria:  totem_audit' AS tabela
UNION ALL SELECT 'Sessões:    totem_sessoes'
UNION ALL SELECT 'Roles em:   totem_admin.role  (admin|operador|cozinha)';

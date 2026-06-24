-- ============================================================
-- Migration: Turnos de caixa (abertura/fechamento) + Sangrias
-- Criado em: 2026-06-24
-- ============================================================

CREATE TABLE IF NOT EXISTS material.totem_turnos (
  id                SERIAL PRIMARY KEY,
  admin_id          INT NOT NULL REFERENCES material.totem_admin(id),
  admin_nome        VARCHAR(100),
  abertura_em       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  fechamento_em     TIMESTAMPTZ,
  valor_abertura    NUMERIC(10,2) DEFAULT 0,   -- fundo de caixa ao abrir
  valor_fechamento  NUMERIC(10,2),             -- dinheiro contado ao fechar
  total_sangrias    NUMERIC(10,2) DEFAULT 0,   -- retiradas durante o turno
  status            VARCHAR(20) DEFAULT 'aberto',  -- aberto | fechado
  obs_abertura      TEXT,
  obs_fechamento    TEXT,
  criado_em         TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS material.totem_sangrias (
  id         SERIAL PRIMARY KEY,
  turno_id   INT NOT NULL REFERENCES material.totem_turnos(id),
  valor      NUMERIC(10,2) NOT NULL,
  motivo     VARCHAR(200),
  admin_id   INT REFERENCES material.totem_admin(id),
  criado_em  TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_turnos_status ON material.totem_turnos(status);
CREATE INDEX IF NOT EXISTS idx_turnos_admin  ON material.totem_turnos(admin_id);

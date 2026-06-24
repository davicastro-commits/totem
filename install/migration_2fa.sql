-- Migration: adiciona suporte a 2FA (TOTP) para admins
-- Executar em: DB comunhao, schema material
-- Data: 2026-06-24

ALTER TABLE material.totem_admin
  ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64),
  ADD COLUMN IF NOT EXISTS totp_ativo BOOLEAN DEFAULT false;

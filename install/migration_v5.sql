-- ============================================================
-- Migration v5 — Nota fiscal estilo NFC-e + QR de rastreio
-- Execute após migration_v4.sql:
--   psql -U postgres -d comunhao -f install/migration_v5.sql
-- ============================================================

SET search_path = material;

-- ── Dados da loja (complemento) ──────────────────────────────────────
INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('loja_cnpj',     '', 'CNPJ do estabelecimento (formato: XX.XXX.XXX/0001-XX)'),
  ('loja_telefone', '', 'Telefone da loja (ex: (48) 3258-0645)'),
  ('loja_url',      '', 'URL base do totem para rastreio (ex: http://192.168.1.10/totem)')
ON CONFLICT (chave) DO NOTHING;

DO $$
BEGIN
  RAISE NOTICE 'migration_v5 concluída com sucesso.';
END
$$;

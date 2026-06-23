-- ============================================================
-- Migration v4 — Impressora térmica, PIX e Painel de senhas
-- Execute after migration_v3.sql:
--   psql -U postgres -d comunhao -f install/migration_v4.sql
-- ============================================================

SET search_path = material;

-- ── Status aguardando_pagamento ───────────────────────────────────────
-- Adiciona o novo status ao CHECK constraint de totem_pedidos.status
ALTER TABLE totem_pedidos DROP CONSTRAINT IF EXISTS totem_pedidos_status_check;
ALTER TABLE totem_pedidos ADD CONSTRAINT totem_pedidos_status_check
  CHECK (status IN ('aguardando_pagamento','aguardando','preparando','pronto','entregue','cancelado'));

-- ── Impressora térmica ────────────────────────────────────────────────
INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('impressora_ativa',   'false',  'Habilitar impressão automática via rede (true/false)'),
  ('impressora_ip',      '',       'IP da impressora térmica (ex: 192.168.1.100)'),
  ('impressora_porta',   '9100',   'Porta TCP da impressora (padrão ESC/POS: 9100)'),
  ('impressora_largura', '42',     'Colunas: 32 para papel 58mm, 42 para papel 80mm')
ON CONFLICT (chave) DO NOTHING;

-- ── PIX ──────────────────────────────────────────────────────────────
INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('pix_chave',       '', 'Chave PIX do estabelecimento (CPF, CNPJ, email, telefone ou chave aleatória)'),
  ('pix_beneficiario','', 'Nome do recebedor no QR Code PIX (máx 25 chars)'),
  ('pix_cidade',      '', 'Cidade do recebedor no QR Code PIX (máx 15 chars)')
ON CONFLICT (chave) DO NOTHING;

DO $$
BEGIN
  RAISE NOTICE 'migration_v4 concluída com sucesso.';
END
$$;

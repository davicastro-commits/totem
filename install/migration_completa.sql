-- ============================================================
-- Migration completa — aplica v4 + v5 com segurança
-- Pode ser executada mesmo que partes já existam (IF NOT EXISTS / ON CONFLICT)
--
-- Execute:
--   psql -U postgres -d comunhao -f install/migration_completa.sql
-- ============================================================

SET search_path = material;

-- ── Status aguardando_pagamento ───────────────────────────────────────
-- Aumenta tamanho da coluna status (aguardando_pagamento tem 20 chars, limite anterior era 15)
ALTER TABLE totem_pedidos ALTER COLUMN status TYPE VARCHAR(25);

ALTER TABLE totem_pedidos DROP CONSTRAINT IF EXISTS totem_pedidos_status_check;
ALTER TABLE totem_pedidos ADD CONSTRAINT totem_pedidos_status_check
  CHECK (status IN ('aguardando_pagamento','aguardando','preparando','pronto','entregue','cancelado'));

-- ── Todas as configurações (impressora + PIX + loja + rastreio) ───────
INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('impressora_ativa',   'false',  'Habilitar impressão automática via rede (true/false)'),
  ('impressora_ip',      '',       'IP da impressora térmica (ex: 192.168.1.100)'),
  ('impressora_porta',   '9100',   'Porta TCP da impressora (padrão ESC/POS: 9100)'),
  ('impressora_largura', '42',     'Colunas: 32 para papel 58mm, 42 para papel 80mm'),
  ('pix_chave',          '',       'Chave PIX do estabelecimento'),
  ('pix_beneficiario',   '',       'Nome do recebedor no QR Code PIX (máx 25 chars)'),
  ('pix_cidade',         '',       'Cidade do recebedor no QR Code PIX (máx 15 chars)'),
  ('loja_cnpj',          '',       'CNPJ do estabelecimento (formato: XX.XXX.XXX/0001-XX)'),
  ('loja_telefone',      '',       'Telefone da loja (ex: (48) 3258-0645)'),
  ('loja_url',           '',       'URL base do totem para rastreio (ex: http://192.168.1.10/totem)')
ON CONFLICT (chave) DO NOTHING;

DO $$
BEGIN
  RAISE NOTICE 'migration_completa aplicada com sucesso.';
END
$$;

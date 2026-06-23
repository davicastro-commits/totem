-- ============================================================
-- Migration v2 — Sistema Café Comunhão
-- Execute: SET search_path = material; \i migration_v2.sql
-- ============================================================
SET search_path = material;

-- ── Tabela: tentativas de login (brute-force protection) ─────────────
CREATE TABLE IF NOT EXISTS totem_login_tentativas (
  id           BIGSERIAL    PRIMARY KEY,
  ip           TEXT         NOT NULL,
  email_tentado TEXT,
  bloqueado    BOOLEAN      NOT NULL DEFAULT FALSE,
  tentativa_em TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_login_tentativas_ip ON totem_login_tentativas(ip, tentativa_em DESC);

-- ── Tabela: configurações da loja ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_configuracoes (
  chave       TEXT  PRIMARY KEY,
  valor       TEXT,
  descricao   TEXT,
  atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Valores padrão
INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('loja_nome',           'Café Comunhão',          'Nome da loja exibido no totem e recibos'),
  ('loja_cnpj',           '00.000.000/0001-00',      'CNPJ da loja'),
  ('loja_endereco',       'Rua Exemplo, 123',        'Endereço da loja'),
  ('loja_telefone',       '',                        'Telefone para contato'),
  ('loja_logo_url',       '',                        'URL da logo (deixe vazio para usar texto)'),
  ('totem_idle_segundos', '120',                     'Segundos de inatividade para voltar à tela inicial'),
  ('totem_confirmar_segundos', '30',                 'Segundos de contagem regressiva na tela de confirmação'),
  ('kds_refresh_segundos','5',                       'Intervalo de atualização do KDS em segundos'),
  ('estoque_alerta_qtd',  '5',                       'Quantidade mínima para alerta de estoque baixo')
ON CONFLICT (chave) DO NOTHING;

-- ── Estoque em totem_produtos ─────────────────────────────────────────
ALTER TABLE totem_produtos
  ADD COLUMN IF NOT EXISTS controlar_estoque BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS estoque_qtd       INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS estoque_alerta    INTEGER NOT NULL DEFAULT 5;

-- ── Observação por item do pedido ─────────────────────────────────────
ALTER TABLE totem_itens_pedido
  ADD COLUMN IF NOT EXISTS obs TEXT;

-- ── Controle de tempo no KDS ─────────────────────────────────────────
ALTER TABLE totem_pedidos
  ADD COLUMN IF NOT EXISTS iniciado_em   TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS concluido_em  TIMESTAMPTZ;

-- Quando o pedido muda para 'preparando', salvar iniciado_em
-- Quando muda para 'pronto', salvar concluido_em
-- (feito via UPDATE na API ao mudar status)

-- ── Índices extras ────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_itens_pedido_id ON totem_itens_pedido(pedido_id);
CREATE INDEX IF NOT EXISTS idx_produtos_disponivel ON totem_produtos(disponivel) WHERE disponivel = TRUE;

SELECT 'Migration v2 aplicada com sucesso!' AS resultado;
SELECT 'Novas tabelas:' AS info
UNION ALL SELECT '  totem_login_tentativas (brute-force protection)'
UNION ALL SELECT '  totem_configuracoes (configurações da loja)'
UNION ALL SELECT 'Novas colunas:'
UNION ALL SELECT '  totem_produtos: controlar_estoque, estoque_qtd, estoque_alerta'
UNION ALL SELECT '  totem_itens_pedido: obs'
UNION ALL SELECT '  totem_pedidos: iniciado_em, concluido_em';

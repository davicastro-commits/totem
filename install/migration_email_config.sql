-- ============================================================
-- migration_email_config.sql
-- Configurações de e-mail e relatório semanal automático
-- Usa a tabela totem_configuracoes (chave/valor) já existente.
-- ============================================================

SET search_path = material;

INSERT INTO totem_configuracoes (chave, valor, descricao) VALUES
  ('email_smtp_host',        '',               'Servidor SMTP (ex: smtp.gmail.com)'),
  ('email_smtp_port',        '587',            'Porta SMTP (587=STARTTLS, 465=SSL, 25=sem TLS)'),
  ('email_smtp_user',        '',               'Usuário/login do SMTP'),
  ('email_smtp_pass',        '',               'Senha do SMTP (armazenada em texto simples)'),
  ('email_smtp_from',        '',               'Endereço de e-mail remetente'),
  ('email_smtp_from_nome',   'Café Comunhão',  'Nome exibido como remetente'),
  ('relatorio_email_destino','',               'E-mail destino do relatório semanal'),
  ('relatorio_email_ativo',  'false',          'Envio automático ativo (true/false)'),
  ('relatorio_email_dia',    '1',              'Dia da semana para envio (1=Segunda … 7=Domingo)'),
  ('relatorio_email_hora',   '08',             'Hora do envio automático (formato HH, 00-23)')
ON CONFLICT (chave) DO NOTHING;

-- Tabela de log de envios de e-mail
CREATE TABLE IF NOT EXISTS totem_email_log (
  id           BIGSERIAL    PRIMARY KEY,
  enviado_em   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  destinatario TEXT         NOT NULL,
  assunto      TEXT         NOT NULL,
  status       TEXT         NOT NULL DEFAULT 'enviado', -- 'enviado' | 'erro'
  mensagem     TEXT,                                    -- detalhes do erro, se houver
  periodo_ini  DATE,
  periodo_fim  DATE
);

CREATE INDEX IF NOT EXISTS idx_email_log_enviado
  ON totem_email_log (enviado_em DESC);

DO $$
BEGIN
  RAISE NOTICE 'migration_email_config aplicada com sucesso.';
END
$$;

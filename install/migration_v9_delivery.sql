-- ============================================================
-- Migration v9 — Módulo Delivery + Tributação Lei 12.741
-- Execute após migration_v7_crm.sql:
--   psql -U postgres -d comunhao -f install/migration_v9_delivery.sql
-- ============================================================

SET search_path = material;

-- ── Campos fiscais em totem_produtos ─────────────────────────────────
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS ncm CHAR(8);
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS cfop CHAR(4) DEFAULT '5102';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS origem SMALLINT DEFAULT 0;
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS csosn CHAR(3) DEFAULT '102';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS aliquota_pis DECIMAL(5,4) DEFAULT 0;
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS aliquota_cofins DECIMAL(5,4) DEFAULT 0;

-- ── Tabela NCM com alíquotas IBPT (para Lei 12.741) ──────────────────
CREATE TABLE IF NOT EXISTS totem_ncm (
  ncm                CHAR(8) PRIMARY KEY,
  descricao          VARCHAR(300) NOT NULL,
  aliquota_nacional  DECIMAL(5,2) DEFAULT 0,
  aliquota_importado DECIMAL(5,2) DEFAULT 0,
  aliquota_estadual  DECIMAL(5,2) DEFAULT 0,
  atualizado_em      DATE DEFAULT CURRENT_DATE
);

-- ── Endereços de entrega ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_enderecos_entrega (
  id          SERIAL PRIMARY KEY,
  cliente_id  INTEGER REFERENCES totem_clientes(id),
  cep         CHAR(9),
  logradouro  VARCHAR(200),
  numero      VARCHAR(20),
  complemento VARCHAR(100),
  bairro      VARCHAR(100),
  cidade      VARCHAR(100),
  uf          CHAR(2) DEFAULT 'DF',
  referencia  VARCHAR(200),
  criado_em   TIMESTAMP DEFAULT NOW()
);

-- ── Entregas ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_entregas (
  id                  SERIAL PRIMARY KEY,
  pedido_id           INTEGER NOT NULL REFERENCES totem_pedidos(id),
  endereco_id         INTEGER REFERENCES totem_enderecos_entrega(id),
  taxa_entrega        DECIMAL(10,2) DEFAULT 0,
  status              VARCHAR(20) DEFAULT 'recebido'
                        CHECK (status IN ('recebido','preparo','saiu','entregue','cancelado')),
  entregador_nome     VARCHAR(100),
  entregador_telefone VARCHAR(20),
  previsao_min        INTEGER DEFAULT 45,
  observacao          TEXT,
  retirada_em         TIMESTAMP,
  entregue_em         TIMESTAMP,
  criado_em           TIMESTAMP DEFAULT NOW()
);

-- ── Bairros com taxa de entrega ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS totem_bairros_entrega (
  id       SERIAL PRIMARY KEY,
  bairro   VARCHAR(100) NOT NULL,
  cidade   VARCHAR(100) DEFAULT 'Brasília',
  uf       CHAR(2) DEFAULT 'DF',
  taxa     DECIMAL(10,2) NOT NULL,
  prazo_min INTEGER DEFAULT 45,
  ativo    BOOLEAN DEFAULT true
);

-- ── Índices ───────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_entregas_pedido ON totem_entregas(pedido_id);
CREATE INDEX IF NOT EXISTS idx_entregas_status ON totem_entregas(status);

-- ── Campos extras em totem_pedidos ───────────────────────────────────
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS canal VARCHAR(20) DEFAULT 'totem'
  CHECK (canal IN ('totem','caixa','delivery','mesa'));
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS valor_tributos_aprox DECIMAL(10,2) DEFAULT 0;

-- ── NCMs comuns para food service (alíquotas IBPT aproximadas) ────────
INSERT INTO totem_ncm (ncm, descricao, aliquota_nacional, aliquota_estadual) VALUES
  ('21069090','Preparações alimentícias diversas',13.45,4.00),
  ('09011110','Café não torrado, não descafeinado',13.45,4.00),
  ('09012110','Café torrado não descafeinado',13.45,4.00),
  ('19059090','Outros produtos de padaria',13.45,4.00),
  ('18069000','Chocolate e outras preparações',13.45,4.00),
  ('20081990','Frutas preparadas',13.45,4.00),
  ('22021000','Águas com açúcar',13.45,4.00),
  ('22029000','Outras bebidas não alcoólicas',13.45,4.00),
  ('04012000','Leite semidesnatado',13.45,4.00),
  ('04039090','Outros produtos lácteos',13.45,4.00)
ON CONFLICT (ncm) DO NOTHING;

-- ── Bairros de Brasília com taxas exemplo ────────────────────────────
INSERT INTO totem_bairros_entrega (bairro, taxa, prazo_min) VALUES
  ('Asa Norte',5.00,30),
  ('Asa Sul',5.00,30),
  ('Lago Norte',8.00,40),
  ('Lago Sul',8.00,40),
  ('Águas Claras',12.00,50),
  ('Taguatinga',12.00,50),
  ('Ceilândia',15.00,60),
  ('Samambaia',15.00,60),
  ('Guará',10.00,45),
  ('Sudoeste',6.00,35),
  ('Noroeste',6.00,35),
  ('Park Way',12.00,50)
ON CONFLICT DO NOTHING;

DO $$
BEGIN
  RAISE NOTICE 'migration_v9_delivery concluída com sucesso.';
END
$$;

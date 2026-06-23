-- ============================================================
-- Migration v6 — Todos os módulos novos
-- Estoque + CRM/Fidelidade + Mesas/Comandas + Delivery/Fiscal
--
-- Execute:
--   psql -U postgres -d comunhao -f install/migration_v6_todos_modulos.sql
-- ============================================================

SET search_path = material;

-- ── ESTOQUE ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS totem_insumos (
  id SERIAL PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  unidade VARCHAR(10) NOT NULL DEFAULT 'UN',
  custo_medio DECIMAL(10,4) DEFAULT 0,
  estoque_atual DECIMAL(10,3) DEFAULT 0,
  estoque_minimo DECIMAL(10,3) DEFAULT 0,
  ativo BOOLEAN DEFAULT true,
  criado_em TIMESTAMP DEFAULT NOW(),
  atualizado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_ficha_tecnica (
  id SERIAL PRIMARY KEY,
  produto_id INTEGER NOT NULL REFERENCES totem_produtos(id) ON DELETE CASCADE,
  insumo_id INTEGER NOT NULL REFERENCES totem_insumos(id) ON DELETE CASCADE,
  quantidade DECIMAL(10,4) NOT NULL,
  UNIQUE(produto_id, insumo_id)
);

CREATE TABLE IF NOT EXISTS totem_movimentacoes_estoque (
  id SERIAL PRIMARY KEY,
  insumo_id INTEGER NOT NULL REFERENCES totem_insumos(id),
  tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('entrada','saida','ajuste')),
  quantidade DECIMAL(10,3) NOT NULL,
  custo_unitario DECIMAL(10,4) DEFAULT 0,
  motivo VARCHAR(200),
  pedido_id INTEGER REFERENCES totem_pedidos(id),
  usuario_id INTEGER,
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_mov_insumo ON totem_movimentacoes_estoque(insumo_id);
CREATE INDEX IF NOT EXISTS idx_mov_pedido ON totem_movimentacoes_estoque(pedido_id);
CREATE INDEX IF NOT EXISTS idx_ficha_produto ON totem_ficha_tecnica(produto_id);

-- ── CRM / FIDELIDADE ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS totem_clientes (
  id SERIAL PRIMARY KEY,
  nome VARCHAR(100),
  cpf VARCHAR(11) UNIQUE,
  telefone VARCHAR(20),
  email VARCHAR(100),
  data_nascimento DATE,
  pontos_saldo INTEGER DEFAULT 0,
  total_gasto DECIMAL(12,2) DEFAULT 0,
  total_pedidos INTEGER DEFAULT 0,
  consentimento_lgpd BOOLEAN DEFAULT false,
  ativo BOOLEAN DEFAULT true,
  criado_em TIMESTAMP DEFAULT NOW(),
  atualizado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_pontos_historico (
  id SERIAL PRIMARY KEY,
  cliente_id INTEGER NOT NULL REFERENCES totem_clientes(id),
  pedido_id INTEGER REFERENCES totem_pedidos(id),
  tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('ganho','resgatado','expirado','ajuste')),
  pontos INTEGER NOT NULL,
  descricao VARCHAR(200),
  expira_em DATE,
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_cupons (
  id SERIAL PRIMARY KEY,
  codigo VARCHAR(30) UNIQUE NOT NULL,
  tipo VARCHAR(15) NOT NULL CHECK (tipo IN ('percentual','fixo','frete_gratis')),
  valor DECIMAL(10,2) NOT NULL,
  valor_minimo DECIMAL(10,2) DEFAULT 0,
  uso_maximo INTEGER DEFAULT 1,
  usos_atuais INTEGER DEFAULT 0,
  cliente_id INTEGER REFERENCES totem_clientes(id),
  validade DATE,
  ativo BOOLEAN DEFAULT true,
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_pontos_config (
  id SERIAL PRIMARY KEY,
  pontos_por_real DECIMAL(5,2) DEFAULT 1.0,
  real_por_ponto DECIMAL(5,4) DEFAULT 0.05,
  validade_dias INTEGER DEFAULT 365,
  ativo BOOLEAN DEFAULT true
);

INSERT INTO totem_pontos_config (pontos_por_real, real_por_ponto, validade_dias)
  SELECT 1.0, 0.05, 365 WHERE NOT EXISTS (SELECT 1 FROM totem_pontos_config);

CREATE INDEX IF NOT EXISTS idx_clientes_cpf ON totem_clientes(cpf);
CREATE INDEX IF NOT EXISTS idx_clientes_telefone ON totem_clientes(telefone);
CREATE INDEX IF NOT EXISTS idx_pontos_cliente ON totem_pontos_historico(cliente_id);

-- Colunas extras em pedidos para CRM
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS cliente_id INTEGER REFERENCES totem_clientes(id);
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS cupom_id INTEGER REFERENCES totem_cupons(id);
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS desconto DECIMAL(10,2) DEFAULT 0;
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS pontos_ganhos INTEGER DEFAULT 0;
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS canal VARCHAR(20) DEFAULT 'totem';
ALTER TABLE totem_pedidos ADD COLUMN IF NOT EXISTS valor_tributos_aprox DECIMAL(10,2) DEFAULT 0;

-- ── MESAS E COMANDAS ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS totem_mesas (
  id SERIAL PRIMARY KEY,
  numero VARCHAR(10) NOT NULL UNIQUE,
  capacidade INTEGER DEFAULT 4,
  status VARCHAR(15) DEFAULT 'livre' CHECK (status IN ('livre','ocupada','reservada','bloqueada')),
  garcom_id INTEGER REFERENCES totem_admin(id),
  localizacao VARCHAR(50) DEFAULT 'salao',
  ativa BOOLEAN DEFAULT true,
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_comandas (
  id SERIAL PRIMARY KEY,
  mesa_id INTEGER NOT NULL REFERENCES totem_mesas(id),
  status VARCHAR(15) DEFAULT 'aberta' CHECK (status IN ('aberta','fechada','paga','cancelada')),
  garcom_id INTEGER REFERENCES totem_admin(id),
  subtotal DECIMAL(10,2) DEFAULT 0,
  desconto DECIMAL(10,2) DEFAULT 0,
  taxa_servico DECIMAL(10,2) DEFAULT 0,
  total DECIMAL(10,2) DEFAULT 0,
  observacao TEXT,
  forma_pagamento VARCHAR(20),
  aberta_em TIMESTAMP DEFAULT NOW(),
  fechada_em TIMESTAMP,
  paga_em TIMESTAMP
);

CREATE TABLE IF NOT EXISTS totem_itens_comanda (
  id SERIAL PRIMARY KEY,
  comanda_id INTEGER NOT NULL REFERENCES totem_comandas(id) ON DELETE CASCADE,
  produto_id INTEGER NOT NULL REFERENCES totem_produtos(id),
  quantidade INTEGER NOT NULL DEFAULT 1,
  preco_unitario DECIMAL(10,2) NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  obs TEXT,
  status VARCHAR(15) DEFAULT 'aguardando' CHECK (status IN ('aguardando','preparando','pronto','entregue')),
  enviado_kds BOOLEAN DEFAULT false,
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_comandas_mesa ON totem_comandas(mesa_id, status);
CREATE INDEX IF NOT EXISTS idx_itens_comanda ON totem_itens_comanda(comanda_id);

INSERT INTO totem_mesas (numero, capacidade, localizacao) VALUES
  ('01',4,'salao'),('02',4,'salao'),('03',4,'salao'),('04',6,'salao'),('05',6,'salao'),
  ('06',2,'varanda'),('07',2,'varanda'),('08',8,'vip'),('09',4,'salao'),('10',4,'salao')
ON CONFLICT (numero) DO NOTHING;

-- ── FISCAL / NCM ─────────────────────────────────────────────────────

ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS ncm CHAR(8);
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS cfop CHAR(4) DEFAULT '5102';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS origem SMALLINT DEFAULT 0;
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS csosn CHAR(3) DEFAULT '102';
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS aliquota_pis DECIMAL(5,4) DEFAULT 0;
ALTER TABLE totem_produtos ADD COLUMN IF NOT EXISTS aliquota_cofins DECIMAL(5,4) DEFAULT 0;

CREATE TABLE IF NOT EXISTS totem_ncm (
  ncm CHAR(8) PRIMARY KEY,
  descricao VARCHAR(300) NOT NULL,
  aliquota_nacional DECIMAL(5,2) DEFAULT 13.45,
  aliquota_importado DECIMAL(5,2) DEFAULT 0,
  aliquota_estadual DECIMAL(5,2) DEFAULT 4.00,
  atualizado_em DATE DEFAULT CURRENT_DATE
);

INSERT INTO totem_ncm (ncm, descricao, aliquota_nacional, aliquota_estadual) VALUES
  ('21069090','Preparações alimentícias diversas',13.45,4.00),
  ('09011110','Café não torrado, não descafeinado',13.45,4.00),
  ('09012110','Café torrado não descafeinado',13.45,4.00),
  ('19059090','Produtos de padaria',13.45,4.00),
  ('18069000','Chocolate e preparações',13.45,4.00),
  ('20081990','Frutas preparadas',13.45,4.00),
  ('22021000','Águas com açúcar',13.45,4.00),
  ('22029000','Outras bebidas não alcoólicas',13.45,4.00),
  ('04012000','Leite semidesnatado',13.45,4.00),
  ('04039090','Outros produtos lácteos',13.45,4.00),
  ('19021900','Outras massas alimentícias',13.45,4.00),
  ('16010010','Salsichas e similares',13.45,4.00),
  ('19053100','Biscoitos e bolachas',13.45,4.00),
  ('22011000','Água mineral sem gás',13.45,4.00)
ON CONFLICT (ncm) DO NOTHING;

-- ── DELIVERY ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS totem_enderecos_entrega (
  id SERIAL PRIMARY KEY,
  cliente_id INTEGER REFERENCES totem_clientes(id),
  cep CHAR(9),
  logradouro VARCHAR(200),
  numero VARCHAR(20),
  complemento VARCHAR(100),
  bairro VARCHAR(100),
  cidade VARCHAR(100) DEFAULT 'Brasília',
  uf CHAR(2) DEFAULT 'DF',
  referencia VARCHAR(200),
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_entregas (
  id SERIAL PRIMARY KEY,
  pedido_id INTEGER NOT NULL REFERENCES totem_pedidos(id),
  endereco_id INTEGER REFERENCES totem_enderecos_entrega(id),
  taxa_entrega DECIMAL(10,2) DEFAULT 0,
  status VARCHAR(20) DEFAULT 'recebido'
    CHECK (status IN ('recebido','preparo','saiu','entregue','cancelado')),
  entregador_nome VARCHAR(100),
  entregador_telefone VARCHAR(20),
  previsao_min INTEGER DEFAULT 45,
  observacao TEXT,
  saiu_em TIMESTAMP,
  entregue_em TIMESTAMP,
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS totem_bairros_entrega (
  id SERIAL PRIMARY KEY,
  bairro VARCHAR(100) NOT NULL,
  cidade VARCHAR(100) DEFAULT 'Brasília',
  uf CHAR(2) DEFAULT 'DF',
  taxa DECIMAL(10,2) NOT NULL,
  prazo_min INTEGER DEFAULT 45,
  ativo BOOLEAN DEFAULT true
);

CREATE INDEX IF NOT EXISTS idx_entregas_pedido ON totem_entregas(pedido_id);
CREATE INDEX IF NOT EXISTS idx_entregas_status ON totem_entregas(status);

INSERT INTO totem_bairros_entrega (bairro, taxa, prazo_min) VALUES
  ('Asa Norte',5.00,30),('Asa Sul',5.00,30),('Lago Norte',8.00,40),
  ('Lago Sul',8.00,40),('Águas Claras',12.00,50),('Taguatinga',12.00,50),
  ('Ceilândia',15.00,60),('Samambaia',15.00,60),('Guará',10.00,45),
  ('Sudoeste',6.00,35),('Noroeste',6.00,35),('Park Way',12.00,50),
  ('Gama',15.00,60),('Sobradinho',18.00,70),('Planaltina',20.00,80)
ON CONFLICT DO NOTHING;

DO $$
BEGIN
  RAISE NOTICE 'migration_v6_todos_modulos concluída com sucesso.';
END
$$;

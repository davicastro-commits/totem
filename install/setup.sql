-- Sistema de Totem - Setup PostgreSQL
-- Banco: comunhao | Schema: material
-- Execute no psql ou no seu cliente SQL:
--   \c comunhao
--   SET search_path = material;
--   \i setup.sql

SET search_path = material;

-- Categorias do cardápio
CREATE TABLE IF NOT EXISTS totem_categorias (
    id        SERIAL PRIMARY KEY,
    nome      VARCHAR(100)  NOT NULL,
    icone     VARCHAR(10)   DEFAULT '🍔',
    ordem     INT           DEFAULT 0,
    ativo     BOOLEAN       DEFAULT TRUE,
    criado_em TIMESTAMP     DEFAULT NOW()
);

-- Produtos
CREATE TABLE IF NOT EXISTS totem_produtos (
    id           SERIAL PRIMARY KEY,
    categoria_id INT            NOT NULL REFERENCES totem_categorias(id),
    nome         VARCHAR(150)   NOT NULL,
    descricao    TEXT,
    preco        NUMERIC(10,2)  NOT NULL,
    imagem       VARCHAR(255)   DEFAULT NULL,
    disponivel   BOOLEAN        DEFAULT TRUE,
    destaque     BOOLEAN        DEFAULT FALSE,
    ordem        INT            DEFAULT 0,
    criado_em    TIMESTAMP      DEFAULT NOW()
);

-- Pedidos
CREATE TABLE IF NOT EXISTS totem_pedidos (
    id              SERIAL PRIMARY KEY,
    numero_pedido   VARCHAR(10)    NOT NULL UNIQUE,
    tipo_consumo    VARCHAR(10)    DEFAULT 'local'  CHECK (tipo_consumo IN ('local','viagem')),
    cpf             VARCHAR(14)    DEFAULT NULL,
    subtotal        NUMERIC(10,2)  NOT NULL DEFAULT 0,
    total           NUMERIC(10,2)  NOT NULL DEFAULT 0,
    forma_pagamento VARCHAR(10)    DEFAULT 'pix'    CHECK (forma_pagamento IN ('credito','debito','pix','dinheiro')),
    status          VARCHAR(15)    DEFAULT 'aguardando' CHECK (status IN ('aguardando','preparando','pronto','entregue','cancelado')),
    criado_em       TIMESTAMP      DEFAULT NOW(),
    atualizado_em   TIMESTAMP      DEFAULT NOW()
);

-- Itens do pedido
CREATE TABLE IF NOT EXISTS totem_itens_pedido (
    id              SERIAL PRIMARY KEY,
    pedido_id       INT            NOT NULL REFERENCES totem_pedidos(id),
    produto_id      INT            NOT NULL REFERENCES totem_produtos(id),
    nome_produto    VARCHAR(150)   NOT NULL,
    quantidade      INT            NOT NULL DEFAULT 1,
    preco_unitario  NUMERIC(10,2)  NOT NULL,
    subtotal        NUMERIC(10,2)  NOT NULL
);

-- Admin
CREATE TABLE IF NOT EXISTS totem_admin (
    id        SERIAL PRIMARY KEY,
    nome      VARCHAR(100) NOT NULL,
    email     VARCHAR(150) NOT NULL UNIQUE,
    senha     VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP    DEFAULT NOW()
);

-- Trigger para atualizar atualizado_em automaticamente
CREATE OR REPLACE FUNCTION material.fn_update_atualizado_em()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_pedidos_atualizado_em ON material.totem_pedidos;
CREATE TRIGGER trg_pedidos_atualizado_em
    BEFORE UPDATE ON totem_pedidos
    FOR EACH ROW EXECUTE FUNCTION material.fn_update_atualizado_em();

-- Dados iniciais: Categorias
INSERT INTO totem_categorias (nome, icone, ordem) VALUES
('Lanches',    '🍔', 1),
('Porções',    '🍟', 2),
('Bebidas',    '🥤', 3),
('Sobremesas', '🍦', 4),
('Combos',     '🎯', 5)
ON CONFLICT DO NOTHING;

-- Dados iniciais: Produtos
INSERT INTO totem_produtos (categoria_id, nome, descricao, preco, destaque, ordem) VALUES
(1, 'Big Clássico',      'Dois hambúrgueres, queijo, alface, tomate, cebola e molho especial',  28.90, TRUE,  1),
(1, 'Cheddar Duplo',     'Dois hambúrgueres, cheddar cremoso, bacon crocante e maionese',         31.90, TRUE,  2),
(1, 'Frango Crispy',     'Filé de frango empanado, alface, tomate e maionese de limão',           25.90, FALSE, 3),
(1, 'Veggie Burger',     'Hambúrguer vegetal, queijo, alface, tomate e molho de ervas',           23.90, FALSE, 4),
(1, 'X-Tudo',            'Hambúrguer, queijo, ovo, bacon, presunto, alface e tomate',             33.90, FALSE, 5),
(2, 'Batata Frita P',    'Batatas fritas crocantes tamanho pequeno',                               9.90, FALSE, 1),
(2, 'Batata Frita M',    'Batatas fritas crocantes tamanho médio',                                12.90, FALSE, 2),
(2, 'Batata Frita G',    'Batatas fritas crocantes tamanho grande',                               15.90, TRUE,  3),
(2, 'Onion Rings',       'Anéis de cebola empanados e crocantes',                                 14.90, FALSE, 4),
(2, 'Nuggets 6un',       'Nuggets de frango crocantes com molho à escolha',                       13.90, FALSE, 5),
(3, 'Coca-Cola 300ml',   'Refrigerante Coca-Cola gelado',                                          7.90, FALSE, 1),
(3, 'Coca-Cola 500ml',   'Refrigerante Coca-Cola gelado tamanho grande',                          10.90, FALSE, 2),
(3, 'Suco de Laranja',   'Suco de laranja natural 300ml',                                          9.90, FALSE, 3),
(3, 'Milk-Shake Baunilha','Milk-shake cremoso sabor baunilha 400ml',                              16.90, TRUE,  4),
(3, 'Água Mineral',      'Água mineral sem gás 500ml',                                             4.90, FALSE, 5),
(4, 'Sundae Chocolate',  'Sorvete com calda de chocolate e granulado',                            10.90, FALSE, 1),
(4, 'Sundae Morango',    'Sorvete com calda de morango e morango fresco',                         10.90, FALSE, 2),
(4, 'Torta de Maçã',     'Torta assada recheada com maçã e canela',                               8.90, TRUE,  3),
(4, 'Casquinha',         'Casquinha de sorvete sabores variados',                                  5.90, FALSE, 4),
(5, 'Combo Big Clássico','Big Clássico + Batata M + Coca 300ml',                                  42.90, TRUE,  1),
(5, 'Combo Cheddar Duplo','Cheddar Duplo + Batata M + Coca 300ml',                               45.90, TRUE,  2),
(5, 'Combo Frango',      'Frango Crispy + Batata M + Suco 300ml',                                 39.90, FALSE, 3),
(5, 'Combo Família',     '2 Big Clássico + 2 Batatas G + 2 Coca 500ml',                          89.90, TRUE,  4)
ON CONFLICT DO NOTHING;

-- Admin padrão  (altere a senha depois de instalar)
-- senha padrão: admin123  →  hash bcrypt abaixo
INSERT INTO totem_admin (nome, email, senha) VALUES
('Administrador', 'admin@totem.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON CONFLICT DO NOTHING;
-- Dados reais - Comunhão Espírita
-- CNPJ: 00.307.447/0001-08
-- Execute: \c comunhao | SET search_path = material; | \i dados_reais.sql

SET search_path = material;

-- Limpar dados de demonstração
TRUNCATE totem_itens_pedido, totem_pedidos, totem_produtos, totem_categorias RESTART IDENTITY CASCADE;

-- ── Categorias ────────────────────────────────────────────────────
INSERT INTO totem_categorias (nome, icone, ordem) VALUES
('Cafés & Quentes', '☕', 1),
('Bebidas Frias',   '🥤', 2),
('Salgados',        '🥐', 3),
('Sanduíches',      '🥪', 4),
('Picolés',         '🍦', 5),
('Doces',           '🍬', 6);

-- ── Produtos — preços como 0.01 (atualizar via admin) ─────────────

-- CAT 1: Cafés & Quentes
INSERT INTO totem_produtos (categoria_id, nome, preco, disponivel, ordem) VALUES
(1, 'Café',                    0.01, FALSE, 1),
(1, 'Café Duplo',              0.01, FALSE, 2),
(1, 'Café c/Leite Duplo',      0.01, FALSE, 3),
(1, 'Café Expresso',           0.01, FALSE, 4),
(1, 'Café Expresso Carioca',   0.01, FALSE, 5),
(1, 'Café Expresso Normal',    0.01, FALSE, 6),
(1, 'Café Expresso Duplo',     0.01, FALSE, 7),
(1, 'Cappuccino',              0.01, FALSE, 8),
(1, 'Chocolate Quente Duplo',  0.01, FALSE, 9),
(1, 'Chá Erva Doce',           0.01, FALSE, 10),
(1, 'Chá Camomila',            0.01, FALSE, 11),
(1, 'Chá Hortelã',             0.01, FALSE, 12),
(1, 'Chá Matte Limão',         0.01, FALSE, 13),
(1, 'Chá Matte Original',      0.01, FALSE, 14),
(1, 'Chá Ice Tea Pêssego',     0.01, FALSE, 15);

-- CAT 2: Bebidas Frias
INSERT INTO totem_produtos (categoria_id, nome, preco, disponivel, ordem) VALUES
(2, 'Água Mineral 500ml',              0.01, FALSE, 1),
(2, 'Água Mineral c/ Gás 500ml',       0.01, FALSE, 2),
(2, 'Água de Coco 200ml',              0.01, FALSE, 3),
(2, 'H2O Bebida Gaseificada',          0.01, FALSE, 4),
(2, 'Coca-Cola 220ml',                 0.01, FALSE, 5),
(2, 'Coca-Cola Sem Açúcar 220ml',      0.01, FALSE, 6),
(2, 'Coca-Cola 310ml',                 0.01, FALSE, 7),
(2, 'Coca-Cola Sem Açúcar 310ml',      0.01, FALSE, 8),
(2, 'Coca-Cola Lata 300ml',            0.01, FALSE, 9),
(2, 'Guaraná Antártica 200ml',         0.01, FALSE, 10),
(2, 'Guaraná Antártica 350ml',         0.01, FALSE, 11),
(2, 'Guaraná Zero',                    0.01, FALSE, 12),
(2, 'Achocolatado 200ml',              0.01, FALSE, 13),
(2, 'Suco Caixinha Caju',              0.01, FALSE, 14),
(2, 'Suco Caixinha Laranja',           0.01, FALSE, 15),
(2, 'Suco Caixinha Maracujá',          0.01, FALSE, 16),
(2, 'Suco Caixinha Uva',               0.01, FALSE, 17),
(2, 'Suco Caixinha Pêssego 200ml',     0.01, FALSE, 18),
(2, 'Suco Natural Laranja',            0.01, FALSE, 19),
(2, 'Suco Natural Maracujá',           0.01, FALSE, 20),
(2, 'Suco Natural One 180ml',          0.01, FALSE, 21),
(2, 'Suco DelValle Lata',              0.01, FALSE, 22),
(2, 'Suco Natural Uva',                0.01, FALSE, 23);

-- CAT 3: Salgados
INSERT INTO totem_produtos (categoria_id, nome, preco, disponivel, ordem) VALUES
(3, 'Pão de Queijo',                          0.01, FALSE, 1),
(3, 'Chipa',                                  0.01, FALSE, 2),
(3, 'Pão de Mandioca',                        0.01, FALSE, 3),
(3, 'Pão de Batata Doce',                     0.01, FALSE, 4),
(3, 'Biscoito de Queijo',                     0.01, FALSE, 5),
(3, 'Pão Vegano',                             0.01, FALSE, 6),
(3, 'Mini Pizza',                             0.01, FALSE, 7),
(3, 'Quiche',                                 0.01, FALSE, 8),
(3, 'Pipoca Doce',                            0.01, FALSE, 9),
(3, 'Salgado Int. Frango c/ Catupiry',        0.01, FALSE, 10),
(3, 'Salgado Int. Frango c/ Queijo',          0.01, FALSE, 11),
(3, 'Salgado Int. Frango c/ Azeitona',        0.01, FALSE, 12),
(3, 'Salgado Int. Palmito',                   0.01, FALSE, 13),
(3, 'Salgado Int. Ricota Temperada',          0.01, FALSE, 14),
(3, 'Salgado Int. Empada de Palmito',         0.01, FALSE, 15),
(3, 'Salgado Int. Carne c/ Queijo',           0.01, FALSE, 16),
(3, 'Salgado Int. Abacaxi c/ Queijo',         0.01, FALSE, 17),
(3, 'Salgado Int. Abobrinha',                 0.01, FALSE, 18),
(3, 'Salgado Int. Banana c/ Queijo',          0.01, FALSE, 19),
(3, 'Salgado Int. Brócolis',                  0.01, FALSE, 20),
(3, 'Salgado Int. Maçã c/ Canela',            0.01, FALSE, 21),
(3, 'Salgado Int. Jiló c/ Queijo',            0.01, FALSE, 22),
(3, 'Salgado Ass. Empada de Frango',          0.01, FALSE, 23),
(3, 'Salgado Ass. Esfiha de Carne',           0.01, FALSE, 24),
(3, 'Salgado Ass. Esfiha de Frango',          0.01, FALSE, 25),
(3, 'Salgado Ass. Enrolado de Queijo',        0.01, FALSE, 26),
(3, 'Salgado Ass. Enrolado Queijo e Presunto',0.01, FALSE, 27),
(3, 'Croissant de Presunto e Queijo',         0.01, FALSE, 28),
(3, 'Salgado Ass. Torta de Frango c/ Catupiry',0.01, FALSE, 29),
(3, 'Salgado Ass. Costela de Adão',           0.01, FALSE, 30),
(3, 'Salgado Ass. Cachorro Quente',           0.01, FALSE, 31);

-- CAT 4: Sanduíches
INSERT INTO totem_produtos (categoria_id, nome, preco, disponivel, ordem) VALUES
(4, 'Sanduíche Natural',                          0.01, FALSE, 1),
(4, 'Sanduíche Natural de Frango c/ Azeitona',    0.01, FALSE, 2),
(4, 'Sanduíche Natural de Cenoura e Passas',      0.01, FALSE, 3),
(4, 'Sanduíche de Frango c/ Cenoura e Catupiry',  0.01, FALSE, 4),
(4, 'Sanduíche Natural de Catupiry',              0.01, FALSE, 5);

-- CAT 5: Picolés
INSERT INTO totem_produtos (categoria_id, nome, preco, disponivel, ordem) VALUES
(5, 'Picolé Zagalito',                         0.01, FALSE, 1),
(5, 'Picolé Ninho c/ Nutella',                 0.01, FALSE, 2),
(5, 'Picolé Morango Zero Lactose',             0.01, FALSE, 3),
(5, 'Picolé Iogurte Grego com Frutas',         0.01, FALSE, 4),
(5, 'Picolé Mousse de Maracujá',               0.01, FALSE, 5),
(5, 'Picolé Chocolate Belga',                  0.01, FALSE, 6),
(5, 'Picolé Ferrero',                          0.01, FALSE, 7),
(5, 'Picolé Limão Zero Lactose',               0.01, FALSE, 8);

-- CAT 6: Doces
INSERT INTO totem_produtos (categoria_id, nome, preco, disponivel, ordem) VALUES
(6, 'Brownie',                     0.01, FALSE, 1),
(6, 'Bananinha',                   0.01, FALSE, 2),
(6, 'Kit Kat 41,5g',               0.01, FALSE, 3),
(6, 'Sonho de Valsa 20g',          0.01, FALSE, 4),
(6, 'Bala Fini Morango',           0.01, FALSE, 5),
(6, 'Bala Fini Framboesa',         0.01, FALSE, 6),
(6, 'Bala Fini Uva',               0.01, FALSE, 7),
(6, 'Bala Mastigável Sortida',     0.01, FALSE, 8),
(6, 'Mentos Kiss Extra Frozen',    0.01, FALSE, 9),
(6, 'Mentos Kiss Mentol',          0.01, FALSE, 10),
(6, 'Mentos Frutas Tradicional',   0.01, FALSE, 11),
(6, 'Chiclete Mentos Freshmint',   0.01, FALSE, 12),
(6, 'Halls Extra Forte',           0.01, FALSE, 13),
(6, 'Halls Uva Verde',             0.01, FALSE, 14),
(6, 'Halls Cereja',                0.01, FALSE, 15),
(6, 'Halls Mentol',                0.01, FALSE, 16),
(6, 'Freegells Extra Forte',       0.01, FALSE, 17);

SELECT 'Produtos inseridos: ' || COUNT(*) FROM totem_produtos;
SELECT 'Categorias inseridas: ' || COUNT(*) FROM totem_categorias;

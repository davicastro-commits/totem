<?php
/**
 * Seed de dados de teste — remova este arquivo antes de ir para produção.
 * Acesse via: http://localhost/totem/install/seed_test_data.php
 */
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log = [];

// PostgreSQL PDO não aceita PHP bool em modo não-emulado — converte para string
function pgRow(array $row): array {
    return array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $row);
}

// INSERT com ON CONFLICT — para tabelas que têm UNIQUE constraint
function insertConflict(PDO $pdo, string $table, array $rows, string $conflict = ''): int {
    if (empty($rows)) return 0;
    $rows = array_map('pgRow', $rows);
    $cols = array_keys($rows[0]);
    $ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $phs  = implode(',', array_fill(0, count($rows), $ph));
    $sql  = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES {$phs}";
    if ($conflict) $sql .= " ON CONFLICT {$conflict} DO NOTHING";
    $vals = [];
    foreach ($rows as $r) foreach ($r as $v) $vals[] = $v;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
    return $stmt->rowCount();
}

// INSERT WHERE NOT EXISTS — para tabelas sem UNIQUE constraint no campo de checagem
function insertWhere(PDO $pdo, string $table, array $rows, array $checkCols): int {
    $n = 0;
    foreach ($rows as $row) {
        $row        = pgRow($row);
        $cols       = array_keys($row);
        $selectPh   = implode(', ', array_fill(0, count($cols), '?'));
        $whereParts = implode(' AND ', array_map(fn($c) => "{$c} = ?", $checkCols));
        $checkVals  = array_map(fn($c) => $row[$c], $checkCols);
        $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ")
                SELECT {$selectPh}
                WHERE NOT EXISTS (SELECT 1 FROM {$table} WHERE {$whereParts})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...array_values($row), ...$checkVals]);
        $n += $stmt->rowCount();
    }
    return $n;
}

function step(string $label, int $n): void {
    global $log;
    $log[] = [$label, $n];
}

// ─── 1. CONFIGURAÇÕES ────────────────────────────────────────────────────────
$configs = [
    ['chave'=>'loja_nome',        'valor'=>'Café Comunhão'],
    ['chave'=>'loja_cnpj',        'valor'=>'00.307.447/0001-08'],
    ['chave'=>'loja_endereco',    'valor'=>'SCS Quadra 04, Bloco A, Brasília-DF'],
    ['chave'=>'loja_telefone',    'valor'=>'(61) 3000-0000'],
    ['chave'=>'pix_chave',        'valor'=>'00.307.447/0001-08'],
    ['chave'=>'pix_beneficiario', 'valor'=>'Café Comunhão'],
    ['chave'=>'pix_cidade',       'valor'=>'BRASILIA'],
    ['chave'=>'impressora_ativa', 'valor'=>'0'],
    ['chave'=>'kds_refresh_segundos', 'valor'=>'10'],
    ['chave'=>'totem_idle_segundos',  'valor'=>'60'],
    ['chave'=>'estoque_alerta_qtd',   'valor'=>'5'],
];
$n = 0;
foreach ($configs as $c) {
    $stmt = $pdo->prepare("INSERT INTO totem_configuracoes (chave,valor) VALUES (?,?) ON CONFLICT (chave) DO UPDATE SET valor=EXCLUDED.valor");
    $stmt->execute([$c['chave'], $c['valor']]);
    $n++;
}
step('Configurações', $n);

// ─── 2. CATEGORIAS ───────────────────────────────────────────────────────────
// totem_categorias não tem UNIQUE em nome → usa WHERE NOT EXISTS
$cats = [
    ['nome'=>'Cafés & Quentes','icone'=>'☕','ordem'=>1,'ativo'=>true],
    ['nome'=>'Bebidas Frias',  'icone'=>'🥤','ordem'=>2,'ativo'=>true],
    ['nome'=>'Salgados',       'icone'=>'🥐','ordem'=>3,'ativo'=>true],
    ['nome'=>'Sanduíches',     'icone'=>'🥪','ordem'=>4,'ativo'=>true],
    ['nome'=>'Doces',          'icone'=>'🍰','ordem'=>5,'ativo'=>true],
    ['nome'=>'Combos',         'icone'=>'🍱','ordem'=>6,'ativo'=>true],
];
step('Categorias', insertWhere($pdo, 'totem_categorias', $cats, ['nome']));

// IDs das categorias
$cCafe  = $pdo->query("SELECT id FROM totem_categorias WHERE nome='Cafés & Quentes' LIMIT 1")->fetchColumn();
$cFria  = $pdo->query("SELECT id FROM totem_categorias WHERE nome='Bebidas Frias' LIMIT 1")->fetchColumn();
$cSalg  = $pdo->query("SELECT id FROM totem_categorias WHERE nome='Salgados' LIMIT 1")->fetchColumn();
$cSand  = $pdo->query("SELECT id FROM totem_categorias WHERE nome='Sanduíches' LIMIT 1")->fetchColumn();
$cDoce  = $pdo->query("SELECT id FROM totem_categorias WHERE nome='Doces' LIMIT 1")->fetchColumn();
$cCombo = $pdo->query("SELECT id FROM totem_categorias WHERE nome='Combos' LIMIT 1")->fetchColumn();

// ─── 3. PRODUTOS ─────────────────────────────────────────────────────────────
$prods = [
    ['categoria_id'=>$cCafe, 'nome'=>'Café Expresso',        'descricao'=>'Café expresso encorpado 50ml','preco'=>5.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>1],
    ['categoria_id'=>$cCafe, 'nome'=>'Cappuccino',            'descricao'=>'Cappuccino cremoso 200ml',   'preco'=>9.00, 'disponivel'=>true,'destaque'=>true, 'ordem'=>2],
    ['categoria_id'=>$cCafe, 'nome'=>'Café com Leite',        'descricao'=>'Café com leite quente 300ml','preco'=>7.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>3],
    ['categoria_id'=>$cCafe, 'nome'=>'Chá Mate',              'descricao'=>'Chá mate gelado ou quente',  'preco'=>6.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>4],
    ['categoria_id'=>$cFria, 'nome'=>'Suco de Laranja',       'descricao'=>'Suco natural 300ml',         'preco'=>8.00, 'disponivel'=>true,'destaque'=>true, 'ordem'=>1],
    ['categoria_id'=>$cFria, 'nome'=>'Água Mineral',          'descricao'=>'Água mineral 500ml',         'preco'=>3.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>2],
    ['categoria_id'=>$cFria, 'nome'=>'Refrigerante Lata',     'descricao'=>'Refrigerante 350ml',         'preco'=>5.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>3],
    ['categoria_id'=>$cFria, 'nome'=>'Vitamina de Banana',    'descricao'=>'Vitamina cremosa 300ml',     'preco'=>10.00,'disponivel'=>true,'destaque'=>false,'ordem'=>4],
    ['categoria_id'=>$cSalg, 'nome'=>'Coxinha de Frango',     'descricao'=>'Coxinha crocante 120g',      'preco'=>7.00, 'disponivel'=>true,'destaque'=>true, 'ordem'=>1],
    ['categoria_id'=>$cSalg, 'nome'=>'Esfiha de Carne',       'descricao'=>'Esfiha aberta 80g',          'preco'=>6.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>2],
    ['categoria_id'=>$cSalg, 'nome'=>'Pão de Queijo',         'descricao'=>'Pão de queijo artesanal 50g','preco'=>4.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>3],
    ['categoria_id'=>$cSalg, 'nome'=>'Quiche de Legumes',     'descricao'=>'Fatia de quiche 150g',       'preco'=>9.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>4],
    ['categoria_id'=>$cSand, 'nome'=>'X-Burguer',             'descricao'=>'Hamburguer artesanal 200g',  'preco'=>22.00,'disponivel'=>true,'destaque'=>true, 'ordem'=>1],
    ['categoria_id'=>$cSand, 'nome'=>'X-Frango Grelhado',     'descricao'=>'Frango grelhado + salada',   'preco'=>20.00,'disponivel'=>true,'destaque'=>false,'ordem'=>2],
    ['categoria_id'=>$cSand, 'nome'=>'Misto Quente',          'descricao'=>'Pão, presunto e queijo',     'preco'=>8.00, 'disponivel'=>true,'destaque'=>false,'ordem'=>3],
    ['categoria_id'=>$cDoce, 'nome'=>'Brownie de Chocolate',  'descricao'=>'Brownie caseiro 80g',        'preco'=>8.00, 'disponivel'=>true,'destaque'=>true, 'ordem'=>1],
    ['categoria_id'=>$cDoce, 'nome'=>'Cheesecake de Morango', 'descricao'=>'Fatia de cheesecake 120g',   'preco'=>12.00,'disponivel'=>true,'destaque'=>false,'ordem'=>2],
    ['categoria_id'=>$cCombo,'nome'=>'Combo Café + Salgado',  'descricao'=>'Café expresso + coxinha',    'preco'=>10.00,'disponivel'=>true,'destaque'=>true, 'ordem'=>1],
    ['categoria_id'=>$cCombo,'nome'=>'Combo Almoço',          'descricao'=>'Sanduíche + suco + doce',    'preco'=>35.00,'disponivel'=>true,'destaque'=>true, 'ordem'=>2],
];
step('Produtos', insertWhere($pdo, 'totem_produtos', $prods, ['nome']));

$prodByName = $pdo->query("SELECT nome, id FROM totem_produtos")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── 4. ADMIN USERS ──────────────────────────────────────────────────────────
$admins = [
    ['nome'=>'Administrador', 'email'=>'admin@totem.com',   'senha'=>password_hash('admin123',   PASSWORD_BCRYPT)],
    ['nome'=>'Operador Caixa','email'=>'caixa@totem.com',   'senha'=>password_hash('caixa123',   PASSWORD_BCRYPT)],
    ['nome'=>'Cozinha',       'email'=>'cozinha@totem.com', 'senha'=>password_hash('cozinha123', PASSWORD_BCRYPT)],
];
step('Admin Users', insertConflict($pdo, 'totem_admin', $admins, '(email)'));
$adminId = $pdo->query("SELECT id FROM totem_admin WHERE email='admin@totem.com' LIMIT 1")->fetchColumn();

// ─── 5. PONTOS CONFIG ────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO totem_pontos_config (pontos_por_real, real_por_ponto, validade_dias, ativo)
            SELECT 1.0, 0.05, 365, true WHERE NOT EXISTS (SELECT 1 FROM totem_pontos_config)");
step('Pontos Config', 1);

// ─── 6. CLIENTES ─────────────────────────────────────────────────────────────
$clientes = [
    ['nome'=>'Ana Lima',        'cpf'=>'12345678901','telefone'=>'(61)98111-1111','email'=>'ana@email.com',     'data_nascimento'=>'1990-03-15','pontos_saldo'=>250,'total_gasto'=>350.00,'total_pedidos'=>8, 'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Bruno Souza',     'cpf'=>'23456789012','telefone'=>'(61)98222-2222','email'=>'bruno@email.com',   'data_nascimento'=>'1985-07-22','pontos_saldo'=>120,'total_gasto'=>180.00,'total_pedidos'=>4, 'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Carla Mendes',    'cpf'=>'34567890123','telefone'=>'(61)98333-3333','email'=>'carla@email.com',   'data_nascimento'=>'1995-11-08','pontos_saldo'=>500,'total_gasto'=>620.00,'total_pedidos'=>15,'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Diego Ferreira',  'cpf'=>'45678901234','telefone'=>'(61)98444-4444','email'=>'diego@email.com',   'data_nascimento'=>'1988-01-30','pontos_saldo'=>75, 'total_gasto'=>95.00, 'total_pedidos'=>3, 'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Eduarda Costa',   'cpf'=>'56789012345','telefone'=>'(61)98555-5555','email'=>'edu@email.com',     'data_nascimento'=>'2000-06-12','pontos_saldo'=>0,  'total_gasto'=>22.00, 'total_pedidos'=>1, 'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Felipe Rocha',    'cpf'=>'67890123456','telefone'=>'(61)98666-6666','email'=>'felipe@email.com',  'data_nascimento'=>'1979-09-03','pontos_saldo'=>180,'total_gasto'=>240.00,'total_pedidos'=>6, 'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Gabriela Nunes',  'cpf'=>'78901234567','telefone'=>'(61)98777-7777','email'=>'gabi@email.com',    'data_nascimento'=>'1993-04-25','pontos_saldo'=>310,'total_gasto'=>420.00,'total_pedidos'=>10,'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'Henrique Alves',  'cpf'=>'89012345678','telefone'=>'(61)98888-8888','email'=>'henrique@email.com','data_nascimento'=>'1982-12-18','pontos_saldo'=>45, 'total_gasto'=>60.00, 'total_pedidos'=>2, 'consentimento_lgpd'=>false,'ativo'=>true],
    ['nome'=>'Isabela Teixeira','cpf'=>'90123456789','telefone'=>'(61)98999-9999','email'=>'isa@email.com',     'data_nascimento'=>'1997-08-07','pontos_saldo'=>90, 'total_gasto'=>130.00,'total_pedidos'=>3, 'consentimento_lgpd'=>true,'ativo'=>true],
    ['nome'=>'João Oliveira',   'cpf'=>'01234567890','telefone'=>'(61)97000-0000','email'=>'joao@email.com',    'data_nascimento'=>'1975-02-14','pontos_saldo'=>420,'total_gasto'=>550.00,'total_pedidos'=>12,'consentimento_lgpd'=>true,'ativo'=>true],
];
step('Clientes', insertConflict($pdo, 'totem_clientes', $clientes, '(cpf)'));

$clienteByCpf = $pdo->query("SELECT cpf, id FROM totem_clientes")->fetchAll(PDO::FETCH_KEY_PAIR);
$cAna    = $clienteByCpf['12345678901'] ?? null;
$cBruno  = $clienteByCpf['23456789012'] ?? null;
$cCarla  = $clienteByCpf['34567890123'] ?? null;
$cDiego  = $clienteByCpf['45678901234'] ?? null;
$cFelipe = $clienteByCpf['67890123456'] ?? null;
$cGabi   = $clienteByCpf['78901234567'] ?? null;
$cJoao   = $clienteByCpf['01234567890'] ?? null;
$cEdu    = $clienteByCpf['56789012345'] ?? null;

// ─── 7. CUPONS ───────────────────────────────────────────────────────────────
$cupons = [
    ['codigo'=>'BEMVINDO10', 'tipo'=>'percentual',  'valor'=>10.00,'valor_minimo'=>0,    'uso_maximo'=>1,  'usos_atuais'=>0, 'cliente_id'=>null,'validade'=>'2027-12-31','ativo'=>true],
    ['codigo'=>'FIDELIDADE20','tipo'=>'fixo',        'valor'=>20.00,'valor_minimo'=>50.00,'uso_maximo'=>100,'usos_atuais'=>5, 'cliente_id'=>null,'validade'=>'2027-06-30','ativo'=>true],
    ['codigo'=>'NATAL25',    'tipo'=>'percentual',   'valor'=>25.00,'valor_minimo'=>30.00,'uso_maximo'=>50, 'usos_atuais'=>50,'cliente_id'=>null,'validade'=>'2025-12-31','ativo'=>false],
    ['codigo'=>'FRETEGRATIS','tipo'=>'frete_gratis', 'valor'=>0.00, 'valor_minimo'=>40.00,'uso_maximo'=>200,'usos_atuais'=>12,'cliente_id'=>null,'validade'=>'2027-12-31','ativo'=>true],
];
step('Cupons', insertConflict($pdo, 'totem_cupons', $cupons, '(codigo)'));
$cupomByCod = $pdo->query("SELECT codigo, id FROM totem_cupons")->fetchAll(PDO::FETCH_KEY_PAIR);
$cupFidel = $cupomByCod['FIDELIDADE20'] ?? null;

// ─── 8. INSUMOS ──────────────────────────────────────────────────────────────
$insumos = [
    ['nome'=>'Café Moído',       'unidade'=>'KG', 'custo_medio'=>40.00,'estoque_atual'=>5.000, 'estoque_minimo'=>1.000],
    ['nome'=>'Leite Integral',   'unidade'=>'L',  'custo_medio'=>4.50, 'estoque_atual'=>20.000,'estoque_minimo'=>5.000],
    ['nome'=>'Açúcar Cristal',   'unidade'=>'KG', 'custo_medio'=>3.00, 'estoque_atual'=>10.000,'estoque_minimo'=>2.000],
    ['nome'=>'Farinha de Trigo', 'unidade'=>'KG', 'custo_medio'=>5.00, 'estoque_atual'=>15.000,'estoque_minimo'=>3.000],
    ['nome'=>'Frango (filé)',    'unidade'=>'KG', 'custo_medio'=>18.00,'estoque_atual'=>8.000, 'estoque_minimo'=>2.000],
    ['nome'=>'Carne Bovina',     'unidade'=>'KG', 'custo_medio'=>35.00,'estoque_atual'=>6.000, 'estoque_minimo'=>2.000],
    ['nome'=>'Queijo Mussarela', 'unidade'=>'KG', 'custo_medio'=>45.00,'estoque_atual'=>4.000, 'estoque_minimo'=>1.000],
    ['nome'=>'Tomate',           'unidade'=>'KG', 'custo_medio'=>6.00, 'estoque_atual'=>5.000, 'estoque_minimo'=>1.000],
    ['nome'=>'Pão de Hamburguer','unidade'=>'UN', 'custo_medio'=>1.50, 'estoque_atual'=>50.000,'estoque_minimo'=>10.000],
    ['nome'=>'Óleo de Soja',     'unidade'=>'L',  'custo_medio'=>7.00, 'estoque_atual'=>8.000, 'estoque_minimo'=>2.000],
    ['nome'=>'Ovos',             'unidade'=>'UN', 'custo_medio'=>0.70, 'estoque_atual'=>60.000,'estoque_minimo'=>12.000],
    ['nome'=>'Chocolate em Pó',  'unidade'=>'KG', 'custo_medio'=>25.00,'estoque_atual'=>3.000, 'estoque_minimo'=>0.500],
];
step('Insumos', insertWhere($pdo, 'totem_insumos', $insumos, ['nome']));

$insumoByName = $pdo->query("SELECT nome, id FROM totem_insumos")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── 9. FICHAS TÉCNICAS ──────────────────────────────────────────────────────
$pCafe  = $prodByName['Café Expresso']         ?? null;
$pCapp  = $prodByName['Cappuccino']            ?? null;
$pCafeL = $prodByName['Café com Leite']        ?? null;
$pCox   = $prodByName['Coxinha de Frango']     ?? null;
$pXBurg = $prodByName['X-Burguer']             ?? null;
$pXFr   = $prodByName['X-Frango Grelhado']     ?? null;
$pBrown = $prodByName['Brownie de Chocolate']  ?? null;
$pMisto = $prodByName['Misto Quente']          ?? null;

$iCafe  = $insumoByName['Café Moído']         ?? null;
$iLeite = $insumoByName['Leite Integral']      ?? null;
$iFar   = $insumoByName['Farinha de Trigo']    ?? null;
$iFrang = $insumoByName['Frango (filé)']       ?? null;
$iCarne = $insumoByName['Carne Bovina']        ?? null;
$iQueij = $insumoByName['Queijo Mussarela']    ?? null;
$iPao   = $insumoByName['Pão de Hamburguer']   ?? null;
$iOleo  = $insumoByName['Óleo de Soja']        ?? null;
$iOvos  = $insumoByName['Ovos']                ?? null;
$iChoc  = $insumoByName['Chocolate em Pó']     ?? null;

$fichas = array_values(array_filter([
    $pCafe  && $iCafe  ? ['produto_id'=>$pCafe, 'insumo_id'=>$iCafe, 'quantidade'=>0.0100] : null,
    $pCapp  && $iCafe  ? ['produto_id'=>$pCapp, 'insumo_id'=>$iCafe, 'quantidade'=>0.0150] : null,
    $pCapp  && $iLeite ? ['produto_id'=>$pCapp, 'insumo_id'=>$iLeite,'quantidade'=>0.1500] : null,
    $pCafeL && $iCafe  ? ['produto_id'=>$pCafeL,'insumo_id'=>$iCafe, 'quantidade'=>0.0120] : null,
    $pCafeL && $iLeite ? ['produto_id'=>$pCafeL,'insumo_id'=>$iLeite,'quantidade'=>0.2000] : null,
    $pCox   && $iFrang ? ['produto_id'=>$pCox,  'insumo_id'=>$iFrang,'quantidade'=>0.0800] : null,
    $pCox   && $iFar   ? ['produto_id'=>$pCox,  'insumo_id'=>$iFar,  'quantidade'=>0.0500] : null,
    $pCox   && $iOleo  ? ['produto_id'=>$pCox,  'insumo_id'=>$iOleo, 'quantidade'=>0.0300] : null,
    $pXBurg && $iCarne ? ['produto_id'=>$pXBurg,'insumo_id'=>$iCarne,'quantidade'=>0.2000] : null,
    $pXBurg && $iPao   ? ['produto_id'=>$pXBurg,'insumo_id'=>$iPao,  'quantidade'=>1.0000] : null,
    $pXBurg && $iQueij ? ['produto_id'=>$pXBurg,'insumo_id'=>$iQueij,'quantidade'=>0.0500] : null,
    $pXFr   && $iFrang ? ['produto_id'=>$pXFr,  'insumo_id'=>$iFrang,'quantidade'=>0.1500] : null,
    $pXFr   && $iPao   ? ['produto_id'=>$pXFr,  'insumo_id'=>$iPao,  'quantidade'=>1.0000] : null,
    $pBrown && $iFar   ? ['produto_id'=>$pBrown,'insumo_id'=>$iFar,  'quantidade'=>0.0600] : null,
    $pBrown && $iChoc  ? ['produto_id'=>$pBrown,'insumo_id'=>$iChoc, 'quantidade'=>0.0400] : null,
    $pBrown && $iOvos  ? ['produto_id'=>$pBrown,'insumo_id'=>$iOvos, 'quantidade'=>1.0000] : null,
    $pMisto && $iPao   ? ['produto_id'=>$pMisto,'insumo_id'=>$iPao,  'quantidade'=>1.0000] : null,
    $pMisto && $iQueij ? ['produto_id'=>$pMisto,'insumo_id'=>$iQueij,'quantidade'=>0.0400] : null,
]));
step('Fichas Técnicas', insertConflict($pdo, 'totem_ficha_tecnica', $fichas, '(produto_id, insumo_id)'));

// ─── 10. MOVIMENTAÇÕES DE ESTOQUE ─────────────────────────────────────────────
$custos = ['Café Moído'=>40,'Leite Integral'=>4.5,'Açúcar Cristal'=>3,'Farinha de Trigo'=>5,
           'Frango (filé)'=>18,'Carne Bovina'=>35,'Queijo Mussarela'=>45,'Tomate'=>6,
           'Pão de Hamburguer'=>1.5,'Óleo de Soja'=>7,'Ovos'=>0.7,'Chocolate em Pó'=>25];
$qtdesEst = ['Café Moído'=>5,'Leite Integral'=>20,'Açúcar Cristal'=>10,'Farinha de Trigo'=>15,
             'Frango (filé)'=>8,'Carne Bovina'=>6,'Queijo Mussarela'=>4,'Tomate'=>5,
             'Pão de Hamburguer'=>50,'Óleo de Soja'=>8,'Ovos'=>60,'Chocolate em Pó'=>3];
$movs = [];
foreach ($insumoByName as $inome => $iid) {
    $movs[] = [
        'insumo_id'    => $iid,
        'tipo'         => 'entrada',
        'quantidade'   => $qtdesEst[$inome] ?? 10,
        'custo_unitario'=> $custos[$inome] ?? 5,
        'motivo'       => 'Estoque inicial de teste',
        'usuario_id'   => $adminId ?: null,
    ];
}
// Só insere movimentações se ainda não existir nenhuma (evita duplicatas em re-execução)
$existeMov = (int)$pdo->query("SELECT COUNT(*) FROM totem_movimentacoes_estoque WHERE motivo='Estoque inicial de teste'")->fetchColumn();
$nMov = 0;
if ($existeMov === 0) {
    $nMov = insertConflict($pdo, 'totem_movimentacoes_estoque', $movs);
}
step('Movimentações Estoque', $nMov ?: $existeMov);

// ─── 11. MESAS ───────────────────────────────────────────────────────────────
$mesas = [
    ['numero'=>'M01','capacidade'=>4,'status'=>'ocupada',  'localizacao'=>'salao',  'ativa'=>true],
    ['numero'=>'M02','capacidade'=>4,'status'=>'livre',    'localizacao'=>'salao',  'ativa'=>true],
    ['numero'=>'M03','capacidade'=>4,'status'=>'ocupada',  'localizacao'=>'salao',  'ativa'=>true],
    ['numero'=>'M04','capacidade'=>4,'status'=>'livre',    'localizacao'=>'salao',  'ativa'=>true],
    ['numero'=>'M05','capacidade'=>4,'status'=>'livre',    'localizacao'=>'salao',  'ativa'=>true],
    ['numero'=>'M06','capacidade'=>2,'status'=>'livre',    'localizacao'=>'varanda','ativa'=>true],
    ['numero'=>'M07','capacidade'=>2,'status'=>'livre',    'localizacao'=>'varanda','ativa'=>true],
    ['numero'=>'M08','capacidade'=>2,'status'=>'reservada','localizacao'=>'varanda','ativa'=>true],
    ['numero'=>'M09','capacidade'=>6,'status'=>'livre',    'localizacao'=>'vip',    'ativa'=>true],
    ['numero'=>'M10','capacidade'=>4,'status'=>'bloqueada','localizacao'=>'salao',  'ativa'=>false],
];
step('Mesas', insertConflict($pdo, 'totem_mesas', $mesas, '(numero)'));
$mesaByNum = $pdo->query("SELECT numero, id FROM totem_mesas")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── 12. BAIRROS DE ENTREGA ──────────────────────────────────────────────────
$bairros = [
    ['bairro'=>'Asa Norte',   'cidade'=>'Brasília','uf'=>'DF','taxa'=>5.00, 'prazo_min'=>30,'ativo'=>true],
    ['bairro'=>'Asa Sul',     'cidade'=>'Brasília','uf'=>'DF','taxa'=>5.00, 'prazo_min'=>35,'ativo'=>true],
    ['bairro'=>'Lago Norte',  'cidade'=>'Brasília','uf'=>'DF','taxa'=>8.00, 'prazo_min'=>40,'ativo'=>true],
    ['bairro'=>'Águas Claras','cidade'=>'Brasília','uf'=>'DF','taxa'=>10.00,'prazo_min'=>50,'ativo'=>true],
    ['bairro'=>'Taguatinga',  'cidade'=>'Brasília','uf'=>'DF','taxa'=>12.00,'prazo_min'=>60,'ativo'=>true],
];
step('Bairros Entrega', insertWhere($pdo, 'totem_bairros_entrega', $bairros, ['bairro','cidade']));

// ─── 13. ENDEREÇOS DE ENTREGA ─────────────────────────────────────────────────
$existeEnd = (int)$pdo->query("SELECT COUNT(*) FROM totem_enderecos_entrega WHERE cliente_id IN (" . implode(',', array_filter([$cAna,$cBruno,$cCarla])) . ")")->fetchColumn();
$nEnd = 0;
if ($existeEnd === 0) {
    $enderecos = array_values(array_filter([
        $cAna   ? ['cliente_id'=>$cAna,  'cep'=>'70040-020','logradouro'=>'SCS Quadra 02','numero'=>'10','complemento'=>'Bloco B', 'bairro'=>'Asa Sul',    'cidade'=>'Brasília','uf'=>'DF'] : null,
        $cBruno ? ['cliente_id'=>$cBruno,'cep'=>'70710-500','logradouro'=>'SQNW 311',    'numero'=>'4', 'complemento'=>'Apto 302','bairro'=>'Asa Norte',  'cidade'=>'Brasília','uf'=>'DF'] : null,
        $cCarla ? ['cliente_id'=>$cCarla,'cep'=>'71900-600','logradouro'=>'ACSV',        'numero'=>'15','complemento'=>'Casa 3',  'bairro'=>'Águas Claras','cidade'=>'Brasília','uf'=>'DF'] : null,
    ]));
    $nEnd = insertConflict($pdo, 'totem_enderecos_entrega', $enderecos);
}
step('Endereços Entrega', $nEnd ?: $existeEnd);
$endRows      = $pdo->query("SELECT cliente_id, id FROM totem_enderecos_entrega ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$endByCliente = $endRows;

// ─── 14. PEDIDOS ─────────────────────────────────────────────────────────────
$existePed = (int)$pdo->query("SELECT COUNT(*) FROM totem_pedidos WHERE numero_pedido LIKE 'P0%'")->fetchColumn();
$nPed = 0;
if ($existePed === 0) {
    $pedidos = [
        ['numero_pedido'=>'P001','tipo_consumo'=>'local', 'cpf'=>'12345678901','subtotal'=>22.00,'total'=>22.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'entregue',  'canal'=>'totem',   'cliente_id'=>$cAna,  'cupom_id'=>null,     'pontos_ganhos'=>22,'criado_em'=>'2026-06-20 08:30:00'],
        ['numero_pedido'=>'P002','tipo_consumo'=>'local', 'cpf'=>null,         'subtotal'=>14.00,'total'=>14.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'entregue',  'canal'=>'caixa',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-20 09:00:00'],
        ['numero_pedido'=>'P003','tipo_consumo'=>'viagem','cpf'=>'23456789012','subtotal'=>60.00,'total'=>40.00,'desconto'=>20.00,'forma_pagamento'=>'debito', 'status'=>'entregue',  'canal'=>'totem',   'cliente_id'=>$cBruno,'cupom_id'=>$cupFidel,'pontos_ganhos'=>40,'criado_em'=>'2026-06-20 09:15:00'],
        ['numero_pedido'=>'P004','tipo_consumo'=>'local', 'cpf'=>'34567890123','subtotal'=>35.00,'total'=>35.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'entregue',  'canal'=>'totem',   'cliente_id'=>$cCarla,'cupom_id'=>null,     'pontos_ganhos'=>35,'criado_em'=>'2026-06-20 10:00:00'],
        ['numero_pedido'=>'P005','tipo_consumo'=>'local', 'cpf'=>null,         'subtotal'=>9.00, 'total'=>9.00, 'desconto'=>0,    'forma_pagamento'=>'dinheiro','status'=>'entregue', 'canal'=>'caixa',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-21 08:45:00'],
        ['numero_pedido'=>'P006','tipo_consumo'=>'local', 'cpf'=>'45678901234','subtotal'=>27.00,'total'=>27.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'entregue',  'canal'=>'totem',   'cliente_id'=>$cDiego,'cupom_id'=>null,     'pontos_ganhos'=>27,'criado_em'=>'2026-06-21 11:30:00'],
        ['numero_pedido'=>'P007','tipo_consumo'=>'viagem','cpf'=>'67890123456','subtotal'=>18.00,'total'=>18.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'entregue',  'canal'=>'totem',   'cliente_id'=>$cFelipe,'cupom_id'=>null,    'pontos_ganhos'=>18,'criado_em'=>'2026-06-22 07:50:00'],
        ['numero_pedido'=>'P008','tipo_consumo'=>'local', 'cpf'=>'78901234567','subtotal'=>44.00,'total'=>44.00,'desconto'=>0,    'forma_pagamento'=>'debito', 'status'=>'entregue',  'canal'=>'caixa',   'cliente_id'=>$cGabi, 'cupom_id'=>null,    'pontos_ganhos'=>44,'criado_em'=>'2026-06-22 12:00:00'],
        ['numero_pedido'=>'P009','tipo_consumo'=>'local', 'cpf'=>null,         'subtotal'=>12.00,'total'=>12.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'pronto',    'canal'=>'totem',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-24 08:10:00'],
        ['numero_pedido'=>'P010','tipo_consumo'=>'viagem','cpf'=>'01234567890','subtotal'=>22.00,'total'=>22.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'pronto',    'canal'=>'totem',   'cliente_id'=>$cJoao, 'cupom_id'=>null,    'pontos_ganhos'=>22,'criado_em'=>'2026-06-24 08:20:00'],
        ['numero_pedido'=>'P011','tipo_consumo'=>'local', 'cpf'=>null,         'subtotal'=>8.00, 'total'=>8.00, 'desconto'=>0,    'forma_pagamento'=>'dinheiro','status'=>'pronto',   'canal'=>'caixa',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-24 08:30:00'],
        ['numero_pedido'=>'P012','tipo_consumo'=>'local', 'cpf'=>'12345678901','subtotal'=>16.00,'total'=>16.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'pronto',    'canal'=>'totem',   'cliente_id'=>$cAna,  'cupom_id'=>null,     'pontos_ganhos'=>16,'criado_em'=>'2026-06-24 08:45:00'],
        ['numero_pedido'=>'P013','tipo_consumo'=>'viagem','cpf'=>null,         'subtotal'=>10.00,'total'=>10.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'pronto',    'canal'=>'totem',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-24 09:00:00'],
        ['numero_pedido'=>'P014','tipo_consumo'=>'local', 'cpf'=>'34567890123','subtotal'=>35.00,'total'=>35.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'preparando','canal'=>'totem',   'cliente_id'=>$cCarla,'cupom_id'=>null,     'pontos_ganhos'=>35,'criado_em'=>'2026-06-24 09:10:00'],
        ['numero_pedido'=>'P015','tipo_consumo'=>'viagem','cpf'=>null,         'subtotal'=>13.00,'total'=>13.00,'desconto'=>0,    'forma_pagamento'=>'debito', 'status'=>'preparando','canal'=>'caixa',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-24 09:15:00'],
        ['numero_pedido'=>'P016','tipo_consumo'=>'local', 'cpf'=>'78901234567','subtotal'=>30.00,'total'=>30.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'preparando','canal'=>'totem',   'cliente_id'=>$cGabi, 'cupom_id'=>null,    'pontos_ganhos'=>30,'criado_em'=>'2026-06-24 09:20:00'],
        ['numero_pedido'=>'P017','tipo_consumo'=>'local', 'cpf'=>null,         'subtotal'=>7.00, 'total'=>7.00, 'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'preparando','canal'=>'totem',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-24 09:25:00'],
        ['numero_pedido'=>'P018','tipo_consumo'=>'local', 'cpf'=>'01234567890','subtotal'=>44.00,'total'=>44.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'aguardando','canal'=>'totem',   'cliente_id'=>$cJoao, 'cupom_id'=>null,    'pontos_ganhos'=>44,'criado_em'=>'2026-06-24 09:30:00'],
        ['numero_pedido'=>'P019','tipo_consumo'=>'viagem','cpf'=>null,         'subtotal'=>5.00, 'total'=>5.00, 'desconto'=>0,    'forma_pagamento'=>'dinheiro','status'=>'aguardando','canal'=>'caixa',  'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-24 09:35:00'],
        ['numero_pedido'=>'P020','tipo_consumo'=>'local', 'cpf'=>'67890123456','subtotal'=>20.00,'total'=>20.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'aguardando','canal'=>'totem',   'cliente_id'=>$cFelipe,'cupom_id'=>null,    'pontos_ganhos'=>20,'criado_em'=>'2026-06-24 09:40:00'],
        ['numero_pedido'=>'P021','tipo_consumo'=>'local', 'cpf'=>null,         'subtotal'=>22.00,'total'=>22.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'cancelado', 'canal'=>'totem',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-21 14:00:00'],
        ['numero_pedido'=>'P022','tipo_consumo'=>'viagem','cpf'=>null,         'subtotal'=>9.00, 'total'=>9.00, 'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'cancelado', 'canal'=>'caixa',   'cliente_id'=>null,   'cupom_id'=>null,     'pontos_ganhos'=>0, 'criado_em'=>'2026-06-22 16:30:00'],
        ['numero_pedido'=>'P023','tipo_consumo'=>'local', 'cpf'=>'56789012345','subtotal'=>35.00,'total'=>35.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'cancelado', 'canal'=>'totem',   'cliente_id'=>$cEdu,  'cupom_id'=>null,    'pontos_ganhos'=>0, 'criado_em'=>'2026-06-22 18:00:00'],
        ['numero_pedido'=>'P024','tipo_consumo'=>'viagem','cpf'=>'12345678901','subtotal'=>57.00,'total'=>62.00,'desconto'=>0,    'forma_pagamento'=>'pix',    'status'=>'preparando','canal'=>'delivery','cliente_id'=>$cAna,  'cupom_id'=>null,     'pontos_ganhos'=>62,'criado_em'=>'2026-06-24 08:05:00'],
        ['numero_pedido'=>'P025','tipo_consumo'=>'viagem','cpf'=>'23456789012','subtotal'=>42.00,'total'=>47.00,'desconto'=>0,    'forma_pagamento'=>'credito','status'=>'entregue',  'canal'=>'delivery','cliente_id'=>$cBruno,'cupom_id'=>null,     'pontos_ganhos'=>47,'criado_em'=>'2026-06-22 19:00:00'],
    ];
    $nPed = insertConflict($pdo, 'totem_pedidos', $pedidos, '(numero_pedido)');
}
step('Pedidos', $nPed ?: $existePed);

$pedByNum = $pdo->query("SELECT numero_pedido, id FROM totem_pedidos WHERE numero_pedido LIKE 'P0%'")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── 15. ITENS DOS PEDIDOS ────────────────────────────────────────────────────
$pExpresso= $prodByName['Café Expresso']         ?? null;
$pCappId  = $prodByName['Cappuccino']            ?? null;
$pSuco    = $prodByName['Suco de Laranja']       ?? null;
$pAgua    = $prodByName['Água Mineral']          ?? null;
$pCoxId   = $prodByName['Coxinha de Frango']     ?? null;
$pPaoQ    = $prodByName['Pão de Queijo']         ?? null;
$pXBurgId = $prodByName['X-Burguer']             ?? null;
$pXFrId   = $prodByName['X-Frango Grelhado']     ?? null;
$pMistoId = $prodByName['Misto Quente']          ?? null;
$pBrownId = $prodByName['Brownie de Chocolate']  ?? null;
$pCheese  = $prodByName['Cheesecake de Morango'] ?? null;
$pCombo1  = $prodByName['Combo Café + Salgado']  ?? null;
$pComboAl = $prodByName['Combo Almoço']          ?? null;

function item(array $pb, string $num, ?int $prod, string $nome, int $qty, float $preco): ?array {
    $pid = $pb[$num] ?? null;
    if (!$pid || !$prod) return null;
    return ['pedido_id'=>$pid,'produto_id'=>$prod,'nome_produto'=>$nome,'quantidade'=>$qty,'preco_unitario'=>$preco,'subtotal'=>round($qty*$preco,2)];
}

$existeItens = (int)$pdo->query("SELECT COUNT(*) FROM totem_itens_pedido WHERE pedido_id IN (SELECT id FROM totem_pedidos WHERE numero_pedido LIKE 'P0%')")->fetchColumn();
$nItens = 0;
if ($existeItens === 0) {
    $itens = array_values(array_filter([
        item($pedByNum,'P001',$pXBurgId ,'X-Burguer',             1,22.00),
        item($pedByNum,'P002',$pCappId  ,'Cappuccino',            1, 9.00),
        item($pedByNum,'P002',$pCoxId   ,'Coxinha de Frango',     1, 7.00),
        item($pedByNum,'P003',$pXBurgId ,'X-Burguer',             1,22.00),
        item($pedByNum,'P003',$pXFrId   ,'X-Frango Grelhado',     1,20.00),
        item($pedByNum,'P003',$pSuco    ,'Suco de Laranja',        1, 8.00),
        item($pedByNum,'P003',$pBrownId ,'Brownie de Chocolate',   1, 8.00),
        item($pedByNum,'P004',$pComboAl ,'Combo Almoço',           1,35.00),
        item($pedByNum,'P005',$pCappId  ,'Cappuccino',             1, 9.00),
        item($pedByNum,'P006',$pXBurgId ,'X-Burguer',              1,22.00),
        item($pedByNum,'P006',$pAgua    ,'Água Mineral',            1, 3.00),
        item($pedByNum,'P006',$pBrownId ,'Brownie de Chocolate',    1, 8.00),
        item($pedByNum,'P007',$pMistoId ,'Misto Quente',            1, 8.00),
        item($pedByNum,'P007',$pSuco    ,'Suco de Laranja',         1, 8.00),
        item($pedByNum,'P007',$pBrownId ,'Brownie de Chocolate',    1, 8.00),
        item($pedByNum,'P008',$pComboAl ,'Combo Almoço',            1,35.00),
        item($pedByNum,'P008',$pCappId  ,'Cappuccino',              1, 9.00),
        item($pedByNum,'P009',$pCheese  ,'Cheesecake de Morango',   1,12.00),
        item($pedByNum,'P010',$pXBurgId ,'X-Burguer',               1,22.00),
        item($pedByNum,'P011',$pBrownId ,'Brownie de Chocolate',    1, 8.00),
        item($pedByNum,'P012',$pCappId  ,'Cappuccino',              1, 9.00),
        item($pedByNum,'P012',$pCoxId   ,'Coxinha de Frango',       1, 7.00),
        item($pedByNum,'P013',$pCombo1  ,'Combo Café + Salgado',    1,10.00),
        item($pedByNum,'P014',$pComboAl ,'Combo Almoço',            1,35.00),
        item($pedByNum,'P015',$pCappId  ,'Cappuccino',              1, 9.00),
        item($pedByNum,'P015',$pPaoQ    ,'Pão de Queijo',           1, 4.00),
        item($pedByNum,'P016',$pXFrId   ,'X-Frango Grelhado',       1,20.00),
        item($pedByNum,'P016',$pSuco    ,'Suco de Laranja',         1, 8.00),
        item($pedByNum,'P017',$pCoxId   ,'Coxinha de Frango',       1, 7.00),
        item($pedByNum,'P018',$pComboAl ,'Combo Almoço',            1,35.00),
        item($pedByNum,'P018',$pCappId  ,'Cappuccino',              1, 9.00),
        item($pedByNum,'P019',$pExpresso,'Café Expresso',           1, 5.00),
        item($pedByNum,'P020',$pXFrId   ,'X-Frango Grelhado',       1,20.00),
        item($pedByNum,'P021',$pXBurgId ,'X-Burguer',               1,22.00),
        item($pedByNum,'P022',$pCappId  ,'Cappuccino',              1, 9.00),
        item($pedByNum,'P023',$pComboAl ,'Combo Almoço',            1,35.00),
        item($pedByNum,'P024',$pXBurgId ,'X-Burguer',               1,22.00),
        item($pedByNum,'P024',$pXFrId   ,'X-Frango Grelhado',       1,20.00),
        item($pedByNum,'P024',$pSuco    ,'Suco de Laranja',         1, 8.00),
        item($pedByNum,'P024',$pBrownId ,'Brownie de Chocolate',    1, 8.00),
        item($pedByNum,'P025',$pComboAl ,'Combo Almoço',            1,35.00),
        item($pedByNum,'P025',$pCappId  ,'Cappuccino',              1, 7.00),
    ]));
    $nItens = insertConflict($pdo, 'totem_itens_pedido', $itens);
}
step('Itens Pedido', $nItens ?: $existeItens);

// ─── 16. HISTÓRICO DE PONTOS ──────────────────────────────────────────────────
$existePontos = (int)$pdo->query("SELECT COUNT(*) FROM totem_pontos_historico WHERE descricao LIKE 'Compra P0%'")->fetchColumn();
$nPontos = 0;
if ($existePontos === 0) {
    $pontos = array_values(array_filter([
        $cAna   && ($pedByNum['P001']??null) ? ['cliente_id'=>$cAna,   'pedido_id'=>$pedByNum['P001'],'tipo'=>'ganho',    'pontos'=>22, 'descricao'=>'Compra P001'] : null,
        $cAna   && ($pedByNum['P012']??null) ? ['cliente_id'=>$cAna,   'pedido_id'=>$pedByNum['P012'],'tipo'=>'ganho',    'pontos'=>16, 'descricao'=>'Compra P012'] : null,
        $cBruno && ($pedByNum['P003']??null) ? ['cliente_id'=>$cBruno, 'pedido_id'=>$pedByNum['P003'],'tipo'=>'ganho',    'pontos'=>40, 'descricao'=>'Compra P003'] : null,
        $cCarla && ($pedByNum['P004']??null) ? ['cliente_id'=>$cCarla, 'pedido_id'=>$pedByNum['P004'],'tipo'=>'ganho',    'pontos'=>35, 'descricao'=>'Compra P004'] : null,
        $cCarla ? ['cliente_id'=>$cCarla,'pedido_id'=>null,'tipo'=>'resgatado','pontos'=>-50,'descricao'=>'Resgate de pontos - R$2,50 de desconto'] : null,
        $cDiego && ($pedByNum['P006']??null) ? ['cliente_id'=>$cDiego, 'pedido_id'=>$pedByNum['P006'],'tipo'=>'ganho',    'pontos'=>27, 'descricao'=>'Compra P006'] : null,
        $cFelipe&& ($pedByNum['P007']??null) ? ['cliente_id'=>$cFelipe,'pedido_id'=>$pedByNum['P007'],'tipo'=>'ganho',    'pontos'=>18, 'descricao'=>'Compra P007'] : null,
        $cGabi  && ($pedByNum['P008']??null) ? ['cliente_id'=>$cGabi,  'pedido_id'=>$pedByNum['P008'],'tipo'=>'ganho',    'pontos'=>44, 'descricao'=>'Compra P008'] : null,
        $cJoao  && ($pedByNum['P010']??null) ? ['cliente_id'=>$cJoao,  'pedido_id'=>$pedByNum['P010'],'tipo'=>'ganho',    'pontos'=>22, 'descricao'=>'Compra P010'] : null,
    ]));
    $nPontos = insertConflict($pdo, 'totem_pontos_historico', $pontos);
}
step('Histórico Pontos', $nPontos ?: $existePontos);

// ─── 17. ENTREGAS ─────────────────────────────────────────────────────────────
$existeEntr = (int)$pdo->query("SELECT COUNT(*) FROM totem_entregas WHERE pedido_id IN (SELECT id FROM totem_pedidos WHERE numero_pedido IN ('P024','P025'))")->fetchColumn();
$nEntr = 0;
if ($existeEntr === 0) {
    $endAna   = $endByCliente[$cAna]   ?? null;
    $endBruno = $endByCliente[$cBruno] ?? null;
    $entregaRows = array_values(array_filter([
        ($pedByNum['P024']??null) ? ['pedido_id'=>$pedByNum['P024'],'endereco_id'=>$endAna,  'taxa_entrega'=>5.00,'status'=>'preparo', 'entregador_nome'=>null,         'entregador_telefone'=>null,           'previsao_min'=>35,'observacao'=>'Interfone 304'] : null,
        ($pedByNum['P025']??null) ? ['pedido_id'=>$pedByNum['P025'],'endereco_id'=>$endBruno,'taxa_entrega'=>5.00,'status'=>'entregue','entregador_nome'=>'Carlos Moto','entregador_telefone'=>'(61)99100-1234','previsao_min'=>30,'observacao'=>null] : null,
    ]));
    $nEntr = insertConflict($pdo, 'totem_entregas', $entregaRows);
}
step('Entregas', $nEntr ?: $existeEntr);

// ─── 18. COMANDAS ─────────────────────────────────────────────────────────────
$mesaM01 = $mesaByNum['M01'] ?? null;
$mesaM02 = $mesaByNum['M02'] ?? null;
$mesaM03 = $mesaByNum['M03'] ?? null;

$existeCmd = (int)$pdo->query("SELECT COUNT(*) FROM totem_comandas WHERE mesa_id IN (" . implode(',', array_filter([$mesaM01,$mesaM02,$mesaM03])) . ")")->fetchColumn();
$nCmd = 0;
if ($existeCmd === 0) {
    $comandas = array_values(array_filter([
        $mesaM01 ? ['mesa_id'=>$mesaM01,'status'=>'aberta','subtotal'=>30.00,'desconto'=>0,'taxa_servico'=>3.00,'total'=>33.00,'forma_pagamento'=>null,     'aberta_em'=>'2026-06-24 09:00:00','fechada_em'=>null,'paga_em'=>null] : null,
        $mesaM02 ? ['mesa_id'=>$mesaM02,'status'=>'paga',  'subtotal'=>57.00,'desconto'=>0,'taxa_servico'=>5.70,'total'=>62.70,'forma_pagamento'=>'credito','aberta_em'=>'2026-06-24 08:00:00','fechada_em'=>'2026-06-24 09:30:00','paga_em'=>'2026-06-24 09:31:00'] : null,
        $mesaM03 ? ['mesa_id'=>$mesaM03,'status'=>'aberta','subtotal'=>16.00,'desconto'=>0,'taxa_servico'=>1.60,'total'=>17.60,'forma_pagamento'=>null,     'aberta_em'=>'2026-06-24 09:20:00','fechada_em'=>null,'paga_em'=>null] : null,
    ]));
    $nCmd = insertConflict($pdo, 'totem_comandas', $comandas);
}
step('Comandas', $nCmd ?: $existeCmd);

$comandaRows  = $pdo->query("SELECT mesa_id, id FROM totem_comandas ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$cmdM01 = $comandaRows[$mesaM01] ?? null;
$cmdM02 = $comandaRows[$mesaM02] ?? null;
$cmdM03 = $comandaRows[$mesaM03] ?? null;

// ─── 19. ITENS DE COMANDA ─────────────────────────────────────────────────────
$existeICmd = (int)$pdo->query("SELECT COUNT(*) FROM totem_itens_comanda WHERE comanda_id IN (" . implode(',', array_filter([$cmdM01,$cmdM02,$cmdM03])) . ")")->fetchColumn();
$nICmd = 0;
if ($existeICmd === 0 && ($cmdM01 || $cmdM02 || $cmdM03)) {
    $itensCmd = array_values(array_filter([
        $cmdM01 && $pXBurgId  ? ['comanda_id'=>$cmdM01,'produto_id'=>$pXBurgId, 'quantidade'=>1,'preco_unitario'=>22.00,'subtotal'=>22.00,'status'=>'entregue',  'enviado_kds'=>true] : null,
        $cmdM01 && $pSuco     ? ['comanda_id'=>$cmdM01,'produto_id'=>$pSuco,    'quantidade'=>1,'preco_unitario'=> 8.00,'subtotal'=> 8.00,'status'=>'entregue',  'enviado_kds'=>true] : null,
        $cmdM02 && $pComboAl  ? ['comanda_id'=>$cmdM02,'produto_id'=>$pComboAl, 'quantidade'=>1,'preco_unitario'=>35.00,'subtotal'=>35.00,'status'=>'entregue',  'enviado_kds'=>true] : null,
        $cmdM02 && $pXBurgId  ? ['comanda_id'=>$cmdM02,'produto_id'=>$pXBurgId, 'quantidade'=>1,'preco_unitario'=>22.00,'subtotal'=>22.00,'status'=>'entregue',  'enviado_kds'=>true] : null,
        $cmdM03 && $pCappId   ? ['comanda_id'=>$cmdM03,'produto_id'=>$pCappId,  'quantidade'=>1,'preco_unitario'=> 9.00,'subtotal'=> 9.00,'status'=>'pronto',    'enviado_kds'=>true] : null,
        $cmdM03 && $pCoxId    ? ['comanda_id'=>$cmdM03,'produto_id'=>$pCoxId,   'quantidade'=>1,'preco_unitario'=> 7.00,'subtotal'=> 7.00,'status'=>'aguardando','enviado_kds'=>false] : null,
    ]));
    $nICmd = insertConflict($pdo, 'totem_itens_comanda', $itensCmd);
}
step('Itens Comanda', $nICmd ?: $existeICmd);

// ─── SAÍDA HTML ──────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Seed de Dados de Teste</title>
<style>
  body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;background:#f8fafc}
  h1{color:#1e293b}
  .warn{background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:24px;color:#92400e}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
  th{background:#0f172a;color:#fff;padding:10px 16px;text-align:left}
  td{padding:10px 16px;border-bottom:1px solid #e2e8f0}
  tr:last-child td{border-bottom:none}
  .ok{color:#16a34a;font-weight:600}
  .num{text-align:right;font-variant-numeric:tabular-nums}
  .footer{margin-top:24px;color:#64748b;font-size:.9em;background:#fff;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
  code{background:#f1f5f9;padding:2px 6px;border-radius:4px}
</style>
</head>
<body>
<h1>✅ Seed de Dados Concluído</h1>
<div class="warn">
  ⚠️ <strong>Atenção:</strong> Este script é exclusivo para desenvolvimento.
  <strong>Remova <code>install/seed_test_data.php</code> antes de ir para produção.</strong>
</div>
<table>
  <tr><th>Módulo</th><th class="num">Registros</th></tr>
  <?php foreach ($log as [$label, $n]): ?>
  <tr>
    <td><span class="ok">✓</span> <?= htmlspecialchars($label) ?></td>
    <td class="num"><?= $n ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<div class="footer">
  <p><strong>Credenciais admin:</strong></p>
  <p>Admin → <code>admin@totem.com</code> / <code>admin123</code></p>
  <p>Caixa → <code>caixa@totem.com</code> / <code>caixa123</code></p>
  <p>Cozinha → <code>cozinha@totem.com</code> / <code>cozinha123</code></p>
  <p style="margin-top:12px;color:#94a3b8">Script idempotente — pode rodar várias vezes sem duplicar dados.</p>
</div>
</body>
</html>

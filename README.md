# Totem de Pedidos — Café Comunhão

Sistema completo de autoatendimento para lanchonetes e cafeterias. O cliente faz o pedido direto na tela touch, paga via PIX ou cartão, e acompanha o status em tempo real. A equipe gerencia tudo pelo painel administrativo.

---

## Índice

- [Visão Geral](#visão-geral)
- [Stack Tecnológica](#stack-tecnológica)
- [Estrutura de Módulos](#estrutura-de-módulos)
- [Banco de Dados](#banco-de-dados)
- [API — Endpoints Públicos](#api--endpoints-públicos)
- [API — Endpoints Administrativos](#api--endpoints-administrativos)
- [Segurança](#segurança)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Perfis de Usuário](#perfis-de-usuário)

---

## Visão Geral

```
Cliente → Totem (touch) → Pedido → KDS (cozinha) → Caixa / Entrega → Cliente
```

O fluxo completo:

1. Cliente acessa o totem, escolhe produtos e forma de pagamento
2. Para PIX: QR Code é gerado na hora; pedido fica em `aguardando_pagamento` até confirmação
3. Para dinheiro/cartão: pedido entra direto como `aguardando`
4. KDS exibe os pedidos para a cozinha em tempo real (SSE)
5. Cozinha avança o status: `preparando` → `pronto`
6. Painel de senhas avisa o cliente
7. Caixa confirma a entrega: `entregue`

---

## Stack Tecnológica

| Camada | Tecnologia |
|---|---|
| Servidor web | Apache 2.4 (XAMPP) |
| Backend | PHP 8.2 (sem framework) |
| Banco de dados | PostgreSQL 18 |
| Schema | `material` dentro do banco `comunhao` |
| Frontend | Vanilla JS + CSS customizado |
| PWA | Service Worker + Web Manifest |
| Pagamentos | PIX EMV (geração de payload local) |
| Tempo real | Server-Sent Events (SSE) |
| Relatórios | Geração de PDF server-side |

---

## Estrutura de Módulos

```
totem/
├── index.php                  ← Totem (interface do cliente, PWA)
├── status/                    ← Página de acompanhamento de pedido pelo cliente
├── admin/                     ← Painel administrativo
│   ├── index.php              ← Login / logout
│   ├── dashboard/             ← Dashboard financeiro e operacional
│   ├── mesas/                 ← Gestão de mesas
│   ├── estoque/               ← Controle de insumos e movimentações
│   ├── relatorios/            ← Relatórios e exportação PDF
│   ├── clientes/              ← CRM / programa de fidelidade
│   ├── delivery/              ← Gestão de entregas
│   └── api/                   ← APIs privadas (autenticadas)
├── caixa/                     ← Interface do operador de caixa
├── garcom/                    ← Interface do garçom
├── kds/                       ← Kitchen Display System (tela da cozinha)
├── painel/                    ← Painel de senhas (tela do salão)
├── delivery/
│   ├── entregador.php         ← App do entregador
│   └── rastrear.php           ← Rastreamento público do pedido
├── api/                       ← APIs públicas (totem)
├── config/                    ← Configurações e helpers
├── assets/
│   ├── css/totem.css          ← Estilos do totem
│   └── js/totem.js            ← Lógica do totem (SPA)
└── install/                   ← Scripts SQL de instalação e migração
```

### Descrição de cada módulo

#### Totem (`index.php`)
Interface touch-screen para o cliente. SPA (Single Page Application) em Vanilla JS. Resolução otimizada para 1080px. Registrada como PWA com cache via Service Worker. Funcionalidades:
- Navegação por categorias e produtos
- Carrinho de compras
- Seleção de tipo de consumo (local / para viagem)
- Pagamento PIX (gera QR Code) ou dinheiro/cartão
- Identificação opcional por CPF (programa de fidelidade)
- Acompanhamento de status do pedido

#### Dashboard (`admin/dashboard/`)
Painel financeiro e operacional com atualização automática a cada 60s:
- **KPIs do dia:** faturamento, número de pedidos, ticket médio, pedidos em aberto
- **Insights financeiros:** margem bruta estimada, projeção de fechamento do mês, média diária
- **Heatmap:** pedidos por hora × dia da semana (últimos 30 dias)
- **Previsão de estoque:** dias restantes de cada insumo com base no consumo médio
- **Top 10 produtos:** ranking dos 7 dias anteriores
- **Pagamentos:** breakdown por método (PIX, crédito, débito, dinheiro)
- **Histórico:** faturamento dos últimos 7 dias com barra de proporção
- **Alertas:** insumos abaixo do estoque mínimo

#### KDS — Kitchen Display System (`kds/`)
Tela da cozinha. Recebe pedidos via SSE em tempo real (polling a cada 2s). Mostra todos os pedidos ativos com seus itens, tempo de espera e status. A equipe avança o status diretamente pela tela.

#### Caixa (`caixa/`)
Interface do operador de caixa. Recebe notificações SSE (polling a cada 4s) quando um pedido fica `pronto`. Permite confirmar entrega e processar pagamentos presenciais.

#### Garçom (`garcom/`)
Interface mobile para garçons realizarem pedidos nas mesas diretamente.

#### Painel de Senhas (`painel/`)
Tela do salão. Exibe pedidos prontos para retirada e histórico recente (últimos 15 minutos). Atualização via SSE sem necessidade de autenticação.

#### Delivery (`delivery/`)
Módulo de entregas com rastreamento público por link e interface para o entregador atualizar o status da corrida.

---

## Banco de Dados

**Banco:** `comunhao` | **Schema:** `material`

### Tabelas principais

| Tabela | Descrição |
|---|---|
| `totem_categorias` | Categorias do cardápio (Lanches, Bebidas, etc.) |
| `totem_produtos` | Produtos com preço, imagem, disponibilidade e controle de estoque |
| `totem_pedidos` | Pedidos com status, forma de pagamento, origem e CPF |
| `totem_itens_pedido` | Itens de cada pedido com preço unitário e observação |
| `totem_admin` | Usuários do painel administrativo |
| `totem_sessoes` | Sessões ativas dos administradores |
| `totem_configuracoes` | Configurações do sistema (PIX, impressora, loja) |
| `totem_insumos` | Insumos com estoque atual, mínimo e custo médio |
| `totem_ficha_tecnica` | Relação produto → insumos (para baixa automática) |
| `totem_movimentacoes_estoque` | Histórico de entradas e saídas de insumos |
| `totem_clientes` | Clientes identificados por CPF (CRM) |
| `totem_cupons` | Cupons de desconto |
| `totem_mesas` | Gestão de mesas |
| `totem_audit` | Trilha de auditoria de todas as ações |

### Status do pedido

```
aguardando_pagamento → aguardando → preparando → pronto → entregue
                                                        ↘ cancelado
```

### Origens do pedido

| Origem | Descrição |
|---|---|
| `totem` | Pedido feito pelo cliente no totem |
| `caixa` | Pedido feito pelo operador de caixa |
| `admin` | Pedido feito pelo painel administrativo |

---

## API — Endpoints Públicos

Todos retornam `Content-Type: application/json`.

### Cardápio

```
GET /api/categorias.php
```
Retorna todas as categorias ativas.

```
GET /api/produtos.php
GET /api/produtos.php?categoria_id=1
```
Retorna produtos disponíveis, opcionalmente filtrados por categoria.

### Pedidos

```
POST /api/pedido.php
```
Cria um novo pedido. Rate limit: 15 req/min por IP.

**Body:**
```json
{
  "itens": [
    { "id": 1, "quantidade": 2, "obs": "sem cebola" }
  ],
  "tipo_consumo": "local",
  "forma_pagamento": "pix",
  "cpf": "00000000000",
  "origem": "totem",
  "aguardando_pagamento": true
}
```

**Resposta:**
```json
{
  "success": true,
  "pedido": {
    "id": 42,
    "numero": "0042",
    "total": 28.90,
    "status": "aguardando_pagamento",
    "status_url": "http://localhost/totem/status/?p=0042",
    "forma_pagamento": "pix"
  }
}
```

```
GET /api/pedido_status.php?numero=0042
```
Retorna o status atual de um pedido (usado pela tela de acompanhamento).

### Pagamento PIX

```
GET /api/pix.php?total=28.90&ref=0042
```
Gera o payload EMV do PIX para exibir o QR Code.

**Resposta:**
```json
{
  "success": true,
  "payload": "00020126...",
  "total": 28.90,
  "ref": "0042"
}
```

### Clientes / Fidelidade

```
GET /api/clientes.php?cpf=00000000000
```
Consulta cliente por CPF e retorna pontos acumulados.

### Cupons

```
POST /api/cupons.php
```
Valida e aplica um cupom de desconto.

### Saúde

```
GET /api/health.php
```
Verifica se o sistema e o banco estão operacionais.

---

## API — Endpoints Administrativos

Todos exigem sessão autenticada. Requisições de escrita exigem token CSRF no header `X-CSRF-Token`.

| Endpoint | Método | Descrição |
|---|---|---|
| `/admin/api/auth.php` | POST | Login / logout |
| `/admin/api/produtos.php` | GET/POST/PUT/DELETE | CRUD de produtos |
| `/admin/api/categorias.php` | GET/POST/PUT/DELETE | CRUD de categorias |
| `/admin/api/pedidos.php` | GET/PUT | Listagem e atualização de pedidos |
| `/admin/api/dashboard.php` | GET | Dados do dashboard |
| `/admin/api/relatorios.php` | GET | Dados para relatórios |
| `/admin/api/configuracoes.php` | GET/POST | Configurações do sistema |
| `/admin/api/usuarios.php` | GET/POST/PUT/DELETE | Gestão de usuários admin |
| `/admin/api/upload.php` | POST | Upload de imagens de produtos |
| `/admin/api/audit.php` | GET | Consulta trilha de auditoria |
| `/api/sse.php?topic=kds` | GET (SSE) | Stream de pedidos para a cozinha |
| `/api/sse.php?topic=caixa` | GET (SSE) | Stream de pedidos prontos para o caixa |
| `/api/sse.php?topic=painel` | GET (SSE) | Stream público para o painel de senhas |

---

## Segurança

| Mecanismo | Implementação |
|---|---|
| **Autenticação** | Sessão PHP com `session_regenerate_id()` no login, proteção contra session fixation |
| **Autorização** | Roles `admin` e `operador` com verificação em cada endpoint |
| **CSRF** | Token gerado por `random_bytes(32)`, verificado em toda requisição de escrita |
| **Rate Limiting** | Por IP usando arquivos temporários. Pedidos: 15/min. Login: configurável |
| **Bloqueio de login** | Tentativas falhas são registradas; IP é bloqueado temporariamente após exceder limite |
| **Senhas** | Hash `bcrypt` via `password_hash()` / `password_verify()` |
| **SQL Injection** | PDO com prepared statements em todas as queries |
| **XSS** | `htmlspecialchars()` em toda saída HTML |
| **Cookie de sessão** | `HttpOnly`, `SameSite=Lax` |
| **Auditoria** | Toda ação administrativa é registrada em `totem_audit` com IP e usuário |
| **Upload** | Extensões e tipos MIME validados; arquivos renomeados com hash aleatório |

---

## Instalação

### Requisitos

- PHP 8.1+ com extensões: `pdo_pgsql`, `pgsql`, `json`, `mbstring`
- PostgreSQL 14+
- Apache com `mod_rewrite` habilitado

### Passo a passo

**1. Clonar o projeto:**
```bash
git clone <repositório> c:/xampp/totem
```

**2. Configurar o Apache** — adicionar alias em `httpd-vhosts.conf`:
```apache
Alias /totem "C:/xampp/totem"
<Directory "C:/xampp/totem">
    AllowOverride All
    Require all granted
</Directory>
```

**3. Criar o banco:**
```sql
CREATE DATABASE comunhao;
\c comunhao
CREATE SCHEMA material;
```

**4. Rodar as migrations em ordem:**
```bash
psql -U postgres -d comunhao -f install/setup.sql
psql -U postgres -d comunhao -f install/migration_v2.sql
psql -U postgres -d comunhao -f install/migration_v3.sql
psql -U postgres -d comunhao -f install/migration_v4.sql
psql -U postgres -d comunhao -f install/migration_v5.sql
psql -U postgres -d comunhao -f install/migration_completa.sql
psql -U postgres -d comunhao -f install/migration_v6_estoque.sql
psql -U postgres -d comunhao -f install/migration_v6_todos_modulos.sql
psql -U postgres -d comunhao -f install/migration_v7_crm.sql
psql -U postgres -d comunhao -f install/migration_v8_mesas.sql
psql -U postgres -d comunhao -f install/migration_v9_delivery.sql
psql -U postgres -d comunhao -f install/schema_update.sql
```

**5. Criar o arquivo `.env`** na raiz do projeto:
```env
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=comunhao
DB_SCHEMA=material
DB_USER=postgres
DB_PASS=sua_senha

APP_NAME=Café Comunhão
APP_SECRET=string_aleatoria_de_64_chars
```

**6. Criar o usuário administrador:**
```bash
php reset_admin.php
```

**7. Habilitar extensões PHP** em `php.ini`:
```ini
extension=pdo_pgsql
extension=pgsql
```

### Ambientes múltiplos (trabalho / casa)

Crie um `.env.local` na raiz do projeto com apenas os valores que diferem do `.env`. Ele sobrescreve o `.env` automaticamente e está no `.gitignore`.

Exemplo `.env.local` para desenvolvimento local:
```env
DB_HOST=127.0.0.1
DB_PASS=Vitor@123
```

---

## Configuração

As configurações do sistema ficam na tabela `totem_configuracoes` e são editáveis em **Admin → Configurações**.

| Chave | Descrição |
|---|---|
| `pix_chave` | Chave PIX do estabelecimento |
| `pix_beneficiario` | Nome do recebedor no QR Code (máx 25 chars) |
| `pix_cidade` | Cidade do recebedor (máx 15 chars) |
| `loja_nome` | Nome da loja exibido no sistema |
| `loja_cnpj` | CNPJ do estabelecimento |
| `loja_telefone` | Telefone de contato |
| `loja_url` | URL base do totem (para links de rastreamento) |
| `impressora_ativa` | Habilita impressão automática (`true`/`false`) |
| `impressora_ip` | IP da impressora térmica na rede |
| `impressora_porta` | Porta TCP (padrão ESC/POS: `9100`) |
| `impressora_largura` | Colunas: `32` para papel 58mm, `42` para papel 80mm |

---

## Perfis de Usuário

| Role | Acesso |
|---|---|
| `admin` | Acesso total: configurações, usuários, relatórios, exclusões |
| `operador` | Acesso operacional: pedidos, cardápio, estoque — sem configurações sensíveis |

---

## URLs de acesso

| Interface | URL |
|---|---|
| Totem (cliente) | `http://localhost/totem/` |
| Admin | `http://localhost/totem/admin/` |
| KDS (cozinha) | `http://localhost/totem/kds/` |
| Caixa | `http://localhost/totem/caixa/` |
| Garçom | `http://localhost/totem/garcom/` |
| Painel de senhas | `http://localhost/totem/painel/` |
| Status do pedido | `http://localhost/totem/status/?p=0042` |

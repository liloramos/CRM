# ChatBotCRM

Sistema de atendimento, CRM e gestão operacional para restaurantes, com foco em centralização de conversas, cadastro de clientes, histórico de mensagens, estruturação de pedidos, impressão de comandas e preparação para automações com IA.

O projeto está sendo desenvolvido inicialmente para o **Restaurante Sol**, mas sua arquitetura foi pensada para evoluir como uma plataforma SaaS multiempresa.

---

## Sumário

* [Visão geral](#visão-geral)
* [Status do projeto](#status-do-projeto)
* [Stack principal](#stack-principal)
* [Estrutura do repositório](#estrutura-do-repositório)
* [Arquitetura](#arquitetura)
* [Modelo multiempresa](#modelo-multiempresa)
* [Banco de dados](#banco-de-dados)
* [Módulos planejados](#módulos-planejados)
* [Módulos implementados](#módulos-implementados)
* [Fluxo de atendimento previsto](#fluxo-de-atendimento-previsto)
* [Integração com IA e n8n](#integração-com-ia-e-n8n)
* [BPMN](#bpmn)
* [Documentação para desenvolvimento assistido](#documentação-para-desenvolvimento-assistido)
* [Requisitos](#requisitos)
* [Configuração do ambiente](#configuração-do-ambiente)
* [Comandos úteis](#comandos-úteis)
* [Padrões de desenvolvimento](#padrões-de-desenvolvimento)
* [Roadmap](#roadmap)
* [Autor](#autor)
* [Licença](#licença)

---

## Visão geral

O **ChatBotCRM** é uma aplicação web para apoiar restaurantes no atendimento diário e na organização dos dados gerados por clientes, conversas e pedidos.

A proposta do sistema é concentrar em uma única interface:

* cadastro e histórico de clientes;
* registro de conversas;
* acompanhamento de mensagens;
* estruturação de cardápio;
* montagem de pedidos;
* geração de comandas;
* apoio ao atendimento humano;
* preparação para automações com IA;
* integração futura com WhatsApp e n8n.

A primeira versão está sendo construída com foco em um fluxo simples e funcional: o cliente entra em contato, a conversa é registrada, a atendente visualiza o histórico, monta o pedido e imprime a comanda.

A automação completa com IA e WhatsApp será incorporada em uma etapa posterior, mantendo o Laravel como núcleo principal da aplicação.

---

## Status do projeto

Atualizacao final apos a execucao modular M00-M13:

```text
M00-M13 executados e revisados em sequencia.
Backend preparado para autenticacao, restaurante, cardapio, pedidos, pagamentos,
credito, entrega, comanda/impressao, WhatsApp plugavel, IA/manual e integracoes futuras.
Frontend operacional refinado em React/Vite, com mocks ficticios centralizados.
M12 e M13 sao estruturais/documentais; nao ativam funcionalidades futuras reais.
```

O historico abaixo descreve etapas iniciais da fundacao do projeto.

O projeto está em desenvolvimento ativo.

A base inicial do CRM/chatbot já foi implementada e validada:

* ambiente Laravel configurado;
* conexão com PostgreSQL funcionando;
* migrations iniciais criadas;
* models principais configurados;
* relacionamentos Eloquent implementados;
* seeders iniciais criados;
* banco recriado e populado com `migrate:fresh --seed`;
* estrutura validada via Laravel Tinker.

Historico inicial:

```text
V1 - Fundação CRM/Chatbot: finalizada
V2 - Cardápio, pedidos e comanda: etapa planejada naquele momento
```

---

## Stack principal

### Backend

* PHP 8.4
* Laravel 13
* Eloquent ORM
* PostgreSQL
* Laravel Fortify
* Laravel Passkeys
* Migrations
* Seeders
* Artisan Tinker

### Frontend

* React
* Vite
* TypeScript
* CSS modular por componentes/telas
* Estrutura backend Inertia preservada no Laravel starter

### Banco de dados

* PostgreSQL 18
* Modelo relacional
* Estrutura preparada para multiempresa via `company_id`

### Automação planejada

* n8n
* Webhooks
* Integração com IA
* Integração futura com WhatsApp

---

## Estrutura do repositório

Estrutura atual do projeto:

```text
ChatBotCRM
├── backend
│   ├── app
│   ├── database
│   ├── resources
│   ├── routes
│   ├── storage
│   └── public
│
├── frontend
│
├── bpmn
│
├── docker-compose.yml
│
├── docs
│
├── docs/backlog/codex
│
├── .env.example
├── .gitignore
└── README.md
```

### Diretórios principais

| Diretório   | Descrição                                                                  |
| ----------- | -------------------------------------------------------------------------- |
| `backend`   | Aplicação Laravel, models, migrations, seeders, rotas e regras de negócio. |
| `frontend`  | Camada de interface da aplicação.                                          |
| `bpmn`      | Diagramas de processo do sistema.                                          |
| `docs`      | Documentação geral do projeto.                                             |
| `docs/backlog/codex` | Documentação modular para orientar desenvolvimento assistido por IA/Codex. |

---

## Arquitetura

A aplicação está sendo construída de forma modular, com o Laravel atuando como núcleo central do produto.

O backend é responsável por:

* autenticação;
* regras de negócio;
* persistência dos dados;
* controle de empresas;
* controle de clientes;
* conversas;
* mensagens;
* cardápio;
* pedidos;
* geração de comandas;
* permissões;
* integrações externas.

O frontend operacional atual fica em `frontend/` e consome dados por uma camada de service preparada para API futura. A estrutura Inertia do starter Laravel permanece no `backend/resources/js` como base autenticada do backend.

A arquitetura planejada evita que ferramentas externas, como n8n ou provedores de IA, sejam a fonte principal dos dados. Essas ferramentas devem funcionar como camadas auxiliares de automação.

Representação simplificada:

```text
Frontend React/Vite
   ↓
Laravel API / backend autenticado
   ↓
Serviços de domínio
   ↓
Eloquent ORM
   ↓
PostgreSQL
```

Fluxo futuro com automações:

```text
WhatsApp / Canal externo
   ↓
Webhook Laravel
   ↓
Registro no banco
   ↓
n8n / IA
   ↓
Resposta ou classificação
   ↓
Laravel
   ↓
Cliente / Atendente
```

---

## Modelo multiempresa

O sistema está sendo preparado para operar como SaaS multiempresa.

A entidade central para esse modelo é:

```text
companies
```

Cada empresa representa um cliente da plataforma. No momento, a primeira empresa utilizada é o Restaurante Sol.

A estratégia inicial de multiempresa será baseada em banco compartilhado com isolamento lógico por `company_id`.

Exemplo:

```text
companies
├── customers
├── conversations
├── messages
├── menu_categories
├── menu_items
└── orders
```

Na prática, tabelas operacionais devem possuir vínculo com uma empresa. Isso permite que diferentes empresas utilizem a mesma aplicação sem acessar dados umas das outras.

Exemplo de consulta esperada em áreas protegidas:

```php
Order::where('company_id', auth()->user()->company_id)->get();
```

Esse modelo é suficiente para o estágio atual do projeto e mantém a aplicação simples de desenvolver, testar e evoluir. Isolamentos mais avançados, como banco por tenant ou subdomínios por empresa, podem ser avaliados futuramente.

---

## Banco de dados

Banco utilizado no ambiente local:

```text
crm_restaurante_sol
```

SGBD:

```text
PostgreSQL
```

ORM:

```text
Eloquent ORM
```

A modelagem inicial prioriza consistência, rastreabilidade e relacionamentos claros entre empresas, clientes, conversas e mensagens.

---

## Módulos planejados

A aplicação será evoluída por módulos.

### CRM

* empresas;
* usuários;
* clientes;
* histórico de atendimento;
* conversas;
* mensagens;
* status de atendimento.

### Restaurante

* categorias do cardápio;
* itens do cardápio;
* opções e adicionais;
* montagem de pedidos;
* cálculo de totais;
* impressão de comandas;
* acompanhamento de pedidos.

### Automação

* webhooks;
* integração com n8n;
* integração com IA;
* classificação de intenção;
* sugestão automática de pedidos;
* respostas automáticas;
* integração com WhatsApp.

### SaaS

* usuários vinculados a empresas;
* permissões;
* papéis de acesso;
* super admin;
* configurações por empresa;
* relatórios por empresa.

---

## Módulos implementados

Estado atual do ciclo M00-M13:

```text
M00 - Preparacao do repositorio e padroes
M01 - Autenticacao, usuarios, papeis e permissoes
M02 - Restaurante/tenant/base SaaS
M03 - Cardapio, produtos, extras e disponibilidade
M04 - Pedidos e status operacionais
M05 - Pix, comprovantes, pagamentos e credito de cliente
M06 - Entrega, retirada e regra de taxa por km
M07 - Comanda HTML e fluxo de impressao operacional
M08 - WhatsApp plugavel com provider fake/Meta preparado
M09 - IA, automacao, sugestoes e fallback manual
M10 - Front operacional refinado
M11 - Dashboard financeiro basico
M12 - Roadmap/estrutura para evolucao CRM SaaS
M13 - Preparacao documental/estrutural para Google Workspace futuro
```

Observacao: os dados de exemplo do front e dos seeders devem continuar ficticios e sanitizados.

### Fundação CRM/Chatbot

A primeira etapa do banco foi implementada com as seguintes tabelas:

```text
companies
customers
conversations
messages
```

Relacionamentos principais:

```text
Company
└── Customers

Customer
├── Company
└── Conversations

Conversation
├── Company
├── Customer
└── Messages

Message
└── Conversation
```

Estrutura lógica:

```text
Empresa
└── Cliente
    └── Conversa
        └── Mensagens
```

Exemplo:

```text
Restaurante Sol
└── Cliente Exemplo
    └── Conversa via WhatsApp
        └── "Olá, gostaria de fazer uma reserva."
```

### Models criados

```text
App\Models\Company
App\Models\Customer
App\Models\Conversation
App\Models\Message
```

### Seeders criados

```text
CompanySeeder
CustomerSeeder
ConversationSeeder
```

O banco pode ser recriado e populado com dados iniciais usando:

```bash
php artisan migrate:fresh --seed
```

Validação realizada via Tinker:

```php
App\Models\Company::count();
App\Models\Customer::count();
App\Models\Conversation::count();
App\Models\Message::count();
```

Resultado validado:

```text
Company::count()       = 1
Customer::count()      = 1
Conversation::count()  = 1
Message::count()       = 1
```

---

## Fluxo de atendimento previsto

Fluxo inicial do MVP:

```text
Cliente entra em contato
   ↓
Sistema registra ou localiza o cliente
   ↓
Sistema abre ou recupera uma conversa
   ↓
Mensagem é armazenada
   ↓
Atendente visualiza no painel
   ↓
Atendente monta o pedido
   ↓
Sistema calcula o total
   ↓
Sistema gera a comanda
   ↓
Pedido segue para produção
```

Na primeira entrega, o foco é garantir que a atendente tenha controle do atendimento e consiga operar o pedido manualmente de forma simples.

A automação completa será adicionada depois que o fluxo operacional estiver estável.

---

## Integração com IA e n8n

A arquitetura prevê o uso do n8n como camada de automação, não como banco de dados nem como núcleo da aplicação.

O Laravel deve continuar responsável por:

* persistência dos dados;
* regras de negócio;
* autenticação;
* permissões;
* pedidos;
* cardápio;
* histórico de atendimento;
* geração de comandas.

O n8n deve ser utilizado para:

* orquestrar chamadas para IA;
* classificar mensagens;
* disparar automações;
* integrar serviços externos;
* executar fluxos auxiliares;
* apoiar respostas automáticas.

Fluxo conceitual:

```text
Mensagem recebida
   ↓
Laravel salva a mensagem
   ↓
Laravel aciona webhook do n8n
   ↓
n8n chama serviço de IA
   ↓
IA interpreta a intenção
   ↓
n8n retorna resultado ao Laravel
   ↓
Laravel atualiza o atendimento
```

Exemplo de interpretação futura:

```text
Mensagem:
"Quero uma N8 com frango e uma coca lata"

Interpretação:
- intenção: criar_pedido
- produto: Marmitex N8
- carne: frango
- bebida: Coca-Cola lata
```

No MVP, a IA não será dependência obrigatória para o funcionamento do sistema.

---

## BPMN

A pasta `bpmn` será usada para armazenar diagramas de processo.

BPMN significa **Business Process Model and Notation**. No projeto, essa pasta serve para documentar visualmente os fluxos de negócio e apoiar o desenvolvimento.

Fluxos previstos:

* login e autenticação;
* atendimento ao cliente;
* criação de conversa;
* registro de mensagem;
* montagem de pedido;
* impressão da comanda;
* cancelamento de pedido;
* fluxo com IA;
* fluxo com n8n;
* fechamento de atendimento.

Exemplo de arquivos esperados:

```text
bpmn
├── login-autenticacao.bpmn
├── atendimento-cliente.bpmn
├── montagem-pedido.bpmn
├── impressao-comanda.bpmn
└── fluxo-ia-n8n.bpmn
```

---

## Documentação para desenvolvimento assistido

A pasta `docs/backlog/codex` organiza instruções de desenvolvimento em módulos pequenos e sequenciais.

O objetivo é permitir que ferramentas de apoio ao desenvolvimento leiam o contexto do projeto com clareza e executem tarefas sem quebrar a base já validada.

Estrutura recomendada:

```text
docs/backlog/codex
├── 00-contexto-geral.md
├── 01-banco-atual-v1.md
├── 02-modulo-cardapio.md
├── 03-modulo-opcoes-produtos.md
├── 04-modulo-pedidos.md
├── 05-modulo-impressao-comanda.md
├── 06-regras-restaurante-sol.md
└── 07-tarefas-codex.md
```

Cada documento deve conter:

* objetivo do módulo;
* estado atual;
* escopo do que deve ser criado;
* arquivos que provavelmente serão alterados;
* regras de negócio;
* critérios de aceite;
* exemplos de fluxo;
* restrições para não quebrar a V1.

As tarefas devem ser pequenas e verificáveis. O padrão recomendado é solicitar uma alteração por vez, validar o resultado e só então seguir para o próximo bloco.

---

## Requisitos

### Ambiente local

* PHP 8.4 ou superior
* Composer
* Node.js 22 ou superior
* PostgreSQL 18 ou superior
* Git

### Opcional

* Docker
* n8n
* Cliente PostgreSQL, como DBeaver ou pgAdmin

---

## Configuração do ambiente

### 1. Clonar o repositório

```bash
git clone <repositorio>
```

### 2. Acessar o backend

```bash
cd ChatBotCRM/backend
```

### 3. Instalar dependências PHP

```bash
composer install
```

### 4. Instalar dependências JavaScript

```bash
npm install
```

### 5. Configurar variáveis de ambiente

Copiar o arquivo de exemplo:

```bash
cp .env.example .env
```

No Windows PowerShell:

```powershell
copy .env.example .env
```

Configurar a conexão com o banco:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

### 6. Gerar chave da aplicação

```bash
php artisan key:generate
```

### 7. Executar migrations

```bash
php artisan migrate
```

### 8. Popular banco com dados iniciais

```bash
php artisan db:seed
```

Para recriar tudo em ambiente de desenvolvimento:

```bash
php artisan migrate:fresh --seed
```

> Atenção: `migrate:fresh --seed` apaga todas as tabelas e recria o banco. Use apenas em ambiente local ou de desenvolvimento.

### 9. Criar link de storage

```bash
php artisan storage:link
```

### 10. Executar aplicação

Backend:

```bash
php artisan serve
```

Frontend em desenvolvimento:

```bash
npm run dev
```

Aplicação local:

```text
http://127.0.0.1:8000
```

---

## Comandos úteis

### Ver status das migrations

```bash
php artisan migrate:status
```

### Rodar migrations

```bash
php artisan migrate
```

### Recriar banco com seeders

```bash
php artisan migrate:fresh --seed
```

### Abrir Tinker

```bash
php artisan tinker
```

### Criar model com migration

```bash
php artisan make:model NomeDoModel -m
```

### Criar seeder

```bash
php artisan make:seeder NomeSeeder
```

### Criar controller

```bash
php artisan make:controller NomeController
```

### Build do frontend

```bash
npm run build
```

### Ambiente de desenvolvimento frontend

```bash
npm run dev
```

---

## Padrões de desenvolvimento

### Banco de dados

* Não alterar migrations antigas já validadas, salvo em caso de refatoração planejada.
* Criar novas migrations para evolução da estrutura.
* Usar `company_id` em tabelas relacionadas ao contexto de uma empresa.
* Definir relacionamentos Eloquent nos models.
* Criar seeders para dados essenciais de desenvolvimento.
* Usar nomes claros e consistentes para tabelas e colunas.

### Multiempresa

Toda consulta sensível ao contexto da empresa deve considerar o `company_id`.

Evitar consultas globais como:

```php
Order::all();
```

Preferir consultas filtradas:

```php
Order::where('company_id', auth()->user()->company_id)->get();
```

### Desenvolvimento com Codex

* Dividir tarefas em módulos pequenos.
* Evitar prompts genéricos como “crie o sistema inteiro”.
* Validar cada etapa antes de avançar.
* Manter documentação atualizada em `docs/backlog/codex`.
* Garantir que novas alterações não quebrem a V1.

### Commits

Sugestão de padrão:

```text
feat: nova funcionalidade
fix: correção de bug
docs: alteração de documentação
refactor: refatoração sem mudança funcional
test: criação ou ajuste de testes
chore: tarefas auxiliares
```

Exemplo:

```bash
git commit -m "feat: create initial chatbot crm database structure"
```

---

## Roadmap

### Fase 1 — Ambiente

* [x] Estrutura inicial do projeto
* [x] Laravel configurado
* [x] React/Vite operacional configurado
* [x] TypeScript configurado
* [x] Tailwind CSS configurado
* [x] Vite configurado
* [x] PostgreSQL configurado
* [x] Conexão Laravel/PostgreSQL validada

### Fase 2 — Fundação CRM/Chatbot

* [x] Migration `companies`
* [x] Migration `customers`
* [x] Migration `conversations`
* [x] Migration `messages`
* [x] Model `Company`
* [x] Model `Customer`
* [x] Model `Conversation`
* [x] Model `Message`
* [x] Relacionamentos Eloquent
* [x] Seeders iniciais
* [x] Validação com Tinker
* [x] Validação com `migrate:fresh --seed`

### Fase 3 — Restaurante Sol

* [ ] Criar estrutura de categorias do cardápio
* [ ] Criar estrutura de produtos
* [ ] Criar grupos de opções dos produtos
* [ ] Criar opções e adicionais
* [ ] Criar estrutura de pedidos
* [ ] Criar itens do pedido
* [ ] Criar seeders reais do cardápio
* [ ] Implementar regras de montagem de marmitas
* [ ] Implementar cálculo de totais
* [ ] Implementar impressão de comanda

### Fase 4 — Interface

* [ ] Dashboard inicial
* [ ] Lista de clientes
* [ ] Lista de conversas
* [ ] Tela de conversa
* [ ] Histórico de mensagens
* [ ] Tela de montagem de pedido
* [ ] Tela de resumo do pedido
* [ ] Botão de impressão de comanda

### Fase 5 — Atendimento

* [ ] Simulador de conversa
* [ ] Registro manual de mensagens
* [ ] Criação de pedido a partir da conversa
* [ ] Alteração de status da conversa
* [ ] Fechamento de atendimento
* [ ] Histórico por cliente

### Fase 6 — Automação e IA

* [ ] Criar endpoints de webhook
* [ ] Criar fluxo n8n
* [ ] Integrar serviço de IA
* [ ] Classificar intenção das mensagens
* [ ] Sugerir pedidos automaticamente
* [ ] Responder mensagens automaticamente
* [ ] Integrar WhatsApp

### Fase 7 — SaaS

* [ ] Vincular usuários a empresas
* [ ] Criar papéis de usuário
* [ ] Criar permissões
* [ ] Criar contexto de empresa logada
* [ ] Criar super admin
* [ ] Criar configurações por empresa
* [ ] Criar relatórios por empresa

---

## Proxima etapa tecnica

Nao avancar automaticamente para novas features. Depois da revisao final, qualquer proxima etapa deve ser autorizada explicitamente e deve seguir os documentos em `docs/backlog/modules/`.

Historico da etapa inicial:

A próxima etapa de desenvolvimento é a criação do módulo operacional do restaurante:

```text
Cardápio + Pedidos + Impressão de Comanda
```

Tabelas planejadas:

```text
menu_categories
menu_items
menu_option_groups
menu_options
orders
order_items
```

Estrutura prevista:

```text
Company
├── Customers
│   └── Conversations
│       └── Messages
│
├── Menu Categories
│   └── Menu Items
│       └── Option Groups
│           └── Options
│
└── Orders
    └── Order Items
```

Esse módulo será responsável por transformar a base inicial do CRM em um fluxo operacional utilizável pelo restaurante.

---

## Autor

Desenvolvido por **Murilo**.

Projeto criado como base para um CRM SaaS inteligente voltado inicialmente para restaurantes, com possibilidade de expansão para outros tipos de negócio.

---

## Licença

Projeto em desenvolvimento para fins educacionais, comerciais e evolução futura como produto SaaS.

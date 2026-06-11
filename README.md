# 🍽️ ChatBot CRM

CRM inteligente para restaurantes, com automação de atendimento, gestão de clientes, funil de vendas e integração com IA.

---

## 📋 Sobre o Projeto

O ChatBot CRM é uma plataforma SaaS desenvolvida para auxiliar restaurantes na gestão de clientes, acompanhamento de oportunidades de venda e automação do atendimento.

O sistema está sendo desenvolvido utilizando Laravel, React e PostgreSQL, com arquitetura preparada para integração futura com agentes de IA e canais de comunicação como WhatsApp.

---

## 🚀 Tecnologias

### Backend

- Laravel 13
- PHP 8.4
- PostgreSQL
- Eloquent ORM
- Laravel Fortify
- Laravel Passkeys

### Frontend

- React
- Inertia.js
- TypeScript
- Tailwind CSS
- Vite

### Banco de Dados

- PostgreSQL 18

---

## 🏗️ Arquitetura

```text
ChatBotCRM
│
├── backend
│   ├── app
│   ├── database
│   ├── resources
│   ├── routes
│   ├── storage
│   └── public
│
└── documentação
```

---

## 🎯 Objetivos do Sistema

### CRM

- Cadastro de clientes
- Cadastro de contatos
- Histórico de interações
- Gestão de oportunidades
- Funil de vendas

### Atendimento

- Centralização de conversas
- Histórico de mensagens
- Atendimento por múltiplos canais

### IA

- Agentes inteligentes
- Base de conhecimento
- Respostas automáticas
- Sugestões para atendentes

### Gestão

- Multiempresa (SaaS)
- Controle de usuários
- Permissões e níveis de acesso
- Dashboard gerencial

---

## ⚙️ Requisitos

### Software

- PHP 8.4+
- Composer
- Node.js 22+
- PostgreSQL 18+
- Git

---

## 🛠️ Instalação

### Clonar projeto

```bash
git clone <repositorio>
```

### Entrar na pasta

```bash
cd ChatBotCRM/backend
```

### Instalar dependências PHP

```bash
composer install
```

### Instalar dependências JavaScript

```bash
npm install
```

### Configurar ambiente

Copie:

```bash
.env.example
```

para:

```bash
.env
```

Configure:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=crm_restaurante_sol
DB_USERNAME=postgres
DB_PASSWORD=*******
```

### Gerar chave da aplicação

```bash
php artisan key:generate
```

### Executar migrations

```bash
php artisan migrate
```

### Criar link de storage

```bash
php artisan storage:link
```

### Gerar build frontend

```bash
npm run build
```

---

## ▶️ Executando o Projeto

### Backend

```bash
php artisan serve
```

Acesse:

```text
http://127.0.0.1:8000
```

### Frontend (desenvolvimento)

```bash
npm run dev
```

---

## 📊 Banco de Dados

Banco principal:

```text
crm_restaurante_sol
```

SGBD:

```text
PostgreSQL
```

---

## 📌 Roadmap

### Fase 1

- [x] Configuração do ambiente
- [x] PostgreSQL
- [x] Laravel
- [x] React + Inertia
- [x] Vite

### Fase 2

- [ ] Empresas
- [ ] Usuários
- [ ] Controle de acesso

### Fase 3

- [ ] Clientes
- [ ] Contatos
- [ ] Histórico de interações

### Fase 4

- [ ] Funis de vendas
- [ ] Negócios
- [ ] Atividades

### Fase 5

- [ ] Chat e atendimento

### Fase 6

- [ ] Integração com IA
- [ ] Base de conhecimento
- [ ] Agentes inteligentes

---

## 👨‍💻 Autor

Desenvolvido por Murilo como projeto de CRM SaaS para restaurantes.

---

## 📄 Licença

Projeto em desenvolvimento para fins educacionais e comerciais.
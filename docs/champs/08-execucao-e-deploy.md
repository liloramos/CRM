# Execução e deploy do Champs

## Repositório

```text
https://github.com/liloramos/CRM.git
```

## Branch

```text
feat/champs-mvp
```

## Pasta local

```text
C:\Users\BatComputador\Documents\ChatBotCRM-Champs
```

## Pré-requisitos

- Git;
- Docker Desktop;
- Docker Compose;
- PHP e Composer, caso o backend seja executado localmente;
- Node.js e npm, caso o frontend seja executado localmente.

## Configuração inicial

Copiar o arquivo de ambiente do backend:

```powershell
Copy-Item ..\ChatBotCRM\backend\.env .\backend\.env
```

Verificar:

```powershell
Test-Path .\backend\.env
```

Resultado esperado:

```text
True
```

## Execução por Docker

Na raiz do projeto:

```powershell
docker compose up -d --build
```

Verificar os containers:

```powershell
docker compose ps
```

Consultar logs:

```powershell
docker compose logs --tail=100
```

Acompanhar logs:

```powershell
docker compose logs -f
```

## Execução local do frontend

```powershell
cd frontend
npm ci
npm run dev
```

## Execução local do backend

```powershell
cd backend
composer install
php artisan serve
```

Os comandos definitivos devem ser confirmados pelo `README.md` existente
e pelos arquivos do Docker.

## Banco de dados

Antes de executar migrations:

- confirmar a conexão configurada no `.env`;
- verificar qual banco está sendo utilizado;
- evitar comandos destrutivos;
- preservar os dados existentes.

Não executar sem validação:

```text
php artisan migrate:fresh
php artisan db:wipe
```

## Migrations seguras

Quando forem criadas migrations do Champs:

```powershell
php artisan migrate
```

## Testes

Backend:

```powershell
php artisan test
```

Frontend:

```powershell
npm run lint
npm run build
```

## Verificação antes do deploy

- [ ] `.env` não está versionado;
- [ ] migrations foram revisadas;
- [ ] testes críticos passaram;
- [ ] frontend gera build;
- [ ] backend inicia sem erro;
- [ ] isolamento multi-tenant foi validado;
- [ ] usuário do Marcelo foi testado;
- [ ] exportação funciona;
- [ ] logs não exibem dados sensíveis.

## Estratégia de rollback

Antes do deploy:

- registrar o commit implantado;
- manter backup do banco;
- não remover tabelas existentes;
- criar migrations reversíveis;
- preservar a versão anterior da aplicação.
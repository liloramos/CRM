# Comandos locais encontrados

Este arquivo registra os comandos existentes nos manifests atuais do projeto. Nao adicionar comandos que nao existam em `composer.json` ou `package.json`.

## Backend Laravel

Executar a partir de `backend/`.

### Composer

```bash
composer setup
composer dev
composer lint
composer lint:check
composer ci:check
composer test
```

### NPM

```bash
npm run build
npm run build:ssr
npm run dev
npm run format
npm run format:check
npm run lint
npm run lint:check
npm run types:check
```

### Artisan

Os comandos abaixo existem por serem comandos padrao do Laravel neste backend.

```bash
php artisan test
php artisan route:list
php artisan about
```

Usar `php artisan migrate:fresh --seed` apenas quando o ambiente local for seguro para recriar o banco.

## Frontend Vite separado

Executar a partir de `frontend/`.

```bash
npm run dev
npm run build
npm run lint
npm run preview
```

## Observacoes

- Nao versionar `.env`, secrets, tokens, certificados, dumps, logs sensiveis, `vendor/` ou `node_modules/`.
- As imagens em `imgs/referencias-front/` sao referencia visual e nao fonte de regras de negocio.

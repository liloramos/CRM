# Rotina de Validação por Módulo - V1.8

O Codex deve validar conforme o que existir no projeto. Não deve inventar comandos inexistentes.

## Backend Laravel - comandos prováveis

```bash
cd backend
php artisan test
php artisan migrate:fresh --seed
php artisan route:list
php artisan about
```

Usar `migrate:fresh --seed` somente quando for ambiente local e seguro.

## Frontend React - comandos prováveis

```bash
cd frontend
npm install
npm run lint
npm run build
npm run test
```

Executar apenas comandos existentes no `package.json`.

## Docker - comandos prováveis

```bash
docker compose ps
docker compose up -d
docker compose logs --tail=100
```

## Saída obrigatória

Ao final, informar:

- comandos executados;
- comandos que não existiam;
- erros encontrados;
- arquivos alterados;
- commit sugerido/criado.

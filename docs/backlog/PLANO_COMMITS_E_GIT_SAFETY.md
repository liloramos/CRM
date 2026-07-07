# Plano de Commits e Segurança Git - V1.8

## Objetivo
Garantir rastreabilidade e evitar vazamento de arquivos sensíveis.

## Primeiro commit recomendado

Depois de copiar esta documentação para o projeto:

```powershell
git status
git add docs/backlog
git add bpmn
git add imgs/referencias-front
git commit -m "docs: add executable backlog and codex execution plan v1.8"
```

Se `imgs/referencias-front` ainda não existir, criar a pasta ou pular esse `git add`.

## Sequência detalhada de commits

| Ordem | Módulo | Mensagem | Conteúdo esperado |
| --- | --- | --- | --- |
| 0 | Docs | `docs: add executable backlog and codex execution plan v1.8` | Documentação, prompts, módulos, ADRs e referências. |
| 1 | M00 | `chore: prepare repository standards for modular execution` | Organização, padrões, `.env.example`, providers base, convenções. |
| 2 | M01 | `feat(auth): add users roles and permissions foundation` | Usuários, papéis, permissões, seeders, policies/middlewares se aplicável. |
| 3 | M02 | `feat(restaurant): add restaurant database foundation` | Tabelas base do restaurante com vínculos multiempresa. |
| 4 | M03 | `feat(menu): add products menu and availability management` | Produtos, categorias, cardápio semanal, disponibilidade. |
| 5 | M04 | `feat(orders): add order lifecycle and operational statuses` | Pedidos, itens, status e histórico. |
| 6 | M05 | `feat(payments): add pix receipt confirmation flow` | Pagamentos, comprovantes, confirmação humana. |
| 7 | M06 | `feat(delivery): add delivery fee calculation by distance` | Entrega por km, taxa, configurações. |
| 8 | M07 | `feat(printing): add receipt and kitchen ticket generation` | Comanda HTML/PDF, fila de impressão, Epson TM-T20X. |
| 9 | M08 | `feat(whatsapp): add whatsapp provider abstraction` | Interface/provider fake/meta e webhooks. |
| 10 | M09 | `feat(ai): add automation and manual takeover foundations` | IA/manual, n8n planejado, razões de fallback. |
| 11 | M10 | `feat(front): add operational CRM interface foundation` | Layout, rotas, componentes, telas iniciais. |
| 12 | M11 | `feat(finance): add basic financial dashboard foundation` | Financeiro básico e indicadores. |
| 13 | M12 | `docs: update crm saas roadmap implementation notes` | Roadmap planner/agenda/funil/SaaS. |
| 14 | M13 | `docs(integrations): add google workspace future integration plan` | Google Calendar, Tasks, Sheets, Drive, Maps. |

## Arquivos que não devem entrar no Git

Confirmar `.gitignore` para bloquear:

```gitignore
.env
.env.*
!.env.example
/storage/*.key
/storage/app/private/
/storage/logs/
*.log
*.sqlite
*.dump
*.sql
node_modules/
vendor/
.DS_Store
Thumbs.db
```

Atenção: a regra `.env.*` pode bloquear `.env.example`; por isso usar `!.env.example`.

## Antes de cada commit

Rodar:

```powershell
git status
git diff --stat
```

Se necessário:

```powershell
git diff -- .env
git diff --cached -- .env
```

Se `.env` aparecer no staging:

```powershell
git restore --staged .env
```

## Valores permitidos em `.env.example`

Permitido:

```env
APP_NAME=ChatBotCRM
WHATSAPP_PROVIDER=fake
META_WHATSAPP_TOKEN=
META_WHATSAPP_PHONE_NUMBER_ID=
N8N_WEBHOOK_BASE_URL=
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

Proibido:

```env
META_WHATSAPP_TOKEN=<valor-sensivel-nao-versionar>
GOOGLE_CLIENT_SECRET=<valor-sensivel-nao-versionar>
DB_PASSWORD=<valor-sensivel-nao-versionar>
```

## Política de commits

- Um commit por módulo.
- Commits pequenos.
- Mensagens convencionais.
- Não misturar backend, front e documentação sem necessidade.
- Não commitar arquivos gerados temporários.

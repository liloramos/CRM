# Guia do Desenvolvedor - ChatBotCRM

Guia rapido para Murilo entender, rodar e alterar o projeto depois da execucao dos modulos M00-M13.

Este documento nao define regra de negocio nova. Ele apenas explica onde as coisas estao e como evoluir o sistema com seguranca.

## 1. Como rodar o projeto

O repositorio tem duas partes principais:

- `backend/`: Laravel, banco, regras de dominio, auth, API, webhooks e estrutura Inertia do starter.
- `frontend/`: React/Vite operacional usado para a interface refinada do CRM.

### Backend Laravel

```powershell
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Edite apenas o `.env` local para colocar banco e credenciais reais. Nunca versionar `.env`.

Validacoes comuns:

```powershell
php artisan test
php vendor/bin/pint --test
```

### Frontend operacional

```powershell
cd frontend
npm install
npm run dev
```

Validacoes comuns:

```powershell
npm run lint
npm run build
```

### Frontend Inertia do backend

O Laravel starter tambem tem assets em `backend/resources/js` e scripts em `backend/package.json`.
Use essa parte para telas autenticadas do starter/backend. A interface operacional principal esta em `frontend/`.

```powershell
cd backend
npm install
npm run dev
```

## 2. Como o backend esta organizado

Arquivos principais:

- `backend/app/Models/`: entidades Eloquent do dominio.
- `backend/database/migrations/`: estrutura do banco.
- `backend/database/seeders/`: dados ficticios e sanitizados para desenvolvimento.
- `backend/app/Services/`: fluxos de negocio por modulo.
- `backend/app/Contracts/`: interfaces para provedores plugaveis.
- `backend/app/Data/`: objetos de dados usados por providers e services.
- `backend/app/Http/Controllers/`: endpoints web/API.
- `backend/app/Http/Resources/`: formato de saida das entidades.
- `backend/routes/web.php`: rotas autenticadas e fluxos operacionais web.
- `backend/routes/api.php`: API publica/controlada, menu e webhooks.
- `backend/config/chatbotcrm.php`: providers e configuracoes de WhatsApp, IA, impressao e integracoes futuras.
- `backend/resources/views/printing/order-ticket.blade.php`: layout HTML da comanda.
- `backend/tests/Feature/`: testes por modulo.

Padrao atual:

- Models guardam relacionamento e casts.
- Services concentram fluxo operacional.
- Controllers devem ser finos e chamar services.
- Resources padronizam resposta.
- Providers externos devem obedecer contratos em `app/Contracts`.

## 3. Como o frontend esta organizado

Arquivos principais:

- `frontend/src/App.tsx`: escolhe a pagina ativa e controla a modal global.
- `frontend/src/main.tsx`: entrada do React.
- `frontend/src/components/layout/`: shell, sidebar, topbar e containers.
- `frontend/src/components/ui/`: componentes reutilizaveis.
- `frontend/src/features/`: telas por area do produto.
- `frontend/src/mocks/`: dados ficticios centralizados.
- `frontend/src/services/crm.service.ts`: ponto unico que entrega os dados atuais dos mocks.
- `frontend/src/types/crm.ts`: tipos TypeScript do dominio usado no front.
- `frontend/src/constants/`: rotas, status, modais e cores.
- `frontend/src/utils/formatters.ts`: formatadores.
- `frontend/src/App.css` e `frontend/src/index.css`: estilos globais e das telas.

## 4. Componentes reutilizaveis

Layout:

- `AppShell.tsx`
- `Sidebar.tsx`
- `Topbar.tsx`
- `PageHeader.tsx`
- `PageContainer.tsx`
- `SolLogo.tsx`

UI:

- `Button.tsx`
- `Card.tsx`
- `Badge.tsx`
- `StatusBadge.tsx`
- `DataTable.tsx`
- `Modal.tsx`
- `Tabs.tsx`
- `StatCard.tsx`
- `States.tsx`
- `Icon.tsx`

Antes de criar componente novo, confira se algum desses resolve o caso.

## 5. Rotas, paginas e menus

O front operacional ainda nao usa roteador de URL. Ele troca a tela ativa por estado em `App.tsx`.

Para alterar menus:

1. Edite `frontend/src/constants/routes.ts`.
2. Se criar uma rota nova, adicione o valor em `RouteKey` em `frontend/src/types/crm.ts`.
3. Importe a nova pagina em `frontend/src/App.tsx`.
4. Adicione o `case` no `renderPage()`.
5. Verifique se `Sidebar.tsx` continua exibindo o grupo correto.

Paginas atuais ficam em:

- `features/dashboard/`
- `features/conversas/`
- `features/pedidos/`
- `features/cardapio/`
- `features/entregas/`
- `features/financeiro/`
- `features/clientes/`
- `features/relatorios/`
- `features/configuracoes/`
- `features/auth/`

## 6. Mocks, types e services

Mocks ficam somente em:

- `frontend/src/mocks/cardapio.mock.ts`
- `frontend/src/mocks/clientes.mock.ts`
- `frontend/src/mocks/conversas.mock.ts`
- `frontend/src/mocks/operacional.mock.ts`
- `frontend/src/mocks/pedidos.mock.ts`

Tipos ficam em:

- `frontend/src/types/crm.ts`

Servico atual:

- `frontend/src/services/crm.service.ts`

Quando a API real entrar, a ideia e trocar a implementacao do service aos poucos, mantendo as paginas consumindo uma camada intermediaria. Evite chamar `fetch` direto dentro de muitas paginas.

## 7. Cores, tema e estilo

Comece por:

- `frontend/src/constants/colors.ts`
- `frontend/src/App.css`
- `frontend/src/index.css`

Regra pratica:

- Use tokens de cor em `colors.ts` como referencia.
- Evite espalhar hexadecimais novos sem necessidade.
- Mantenha o tema escuro, grafite, laranja/amarelo e glow sutil.
- As imagens em `imgs/referencias-front/` sao apenas referencia visual, nao fonte de dados ou regras.

## 8. Como criar uma nova tela

Checklist recomendado:

1. Criar pasta em `frontend/src/features/nome-da-area/`.
2. Criar componente da pagina, por exemplo `NovaTelaPage.tsx`.
3. Reutilizar `PageContainer`, `PageHeader`, `Card`, `DataTable`, `Badge`, `StatusBadge` e estados de `States.tsx`.
4. Adicionar tipos necessarios em `frontend/src/types/crm.ts`.
5. Adicionar mocks ficticios em `frontend/src/mocks/`.
6. Expor dados em `frontend/src/services/crm.service.ts`.
7. Adicionar a rota em `RouteKey` e `constants/routes.ts`.
8. Adicionar o `case` em `App.tsx`.
9. Rodar `npm run lint` e `npm run build`.

Nao coloque telefone, CPF, Pix, endereco, conversa real ou comprovante real em mocks.

## 9. Como criar uma nova modal

1. Adicione o nome da modal em `AppModal` dentro de `frontend/src/types/crm.ts`.
2. Adicione titulo e descricao em `frontend/src/constants/modals.ts`.
3. Crie o conteudo em `frontend/src/features/pedidos/OperationalModalContent.tsx` ou extraia para um componente proprio se crescer.
4. Dispare a modal pela prop `onOpenModal` da pagina.
5. A estrutura visual ja passa por `frontend/src/components/ui/Modal.tsx`.

Se a modal for destrutiva, use linguagem clara e confirme impacto operacional.

## 10. Como adicionar uma integracao futura

Padrao seguro:

1. Criar ou reutilizar contrato em `backend/app/Contracts`.
2. Criar provider fake/local primeiro.
3. Criar provider real em `backend/app/Services/<Area>/Providers`.
4. Adicionar variaveis vazias e seguras em `backend/.env.example`.
5. Ler configuracao em `backend/config/chatbotcrm.php`.
6. Registrar binding no service provider quando houver mais de uma implementacao.
7. Criar controller/rota apenas se necessario.
8. Criar testes em `backend/tests/Feature`.

Nunca coloque token, segredo, webhook privado, client secret ou credencial dentro do codigo.

## 11. Como plugar WhatsApp real no futuro

Base atual:

- Contrato: `backend/app/Contracts/WhatsApp/WhatsAppProviderInterface.php`
- Fake provider: `backend/app/Services/WhatsApp/Providers/FakeWhatsAppProvider.php`
- Meta provider preparado: `backend/app/Services/WhatsApp/Providers/MetaCloudWhatsAppProvider.php`
- Service: `backend/app/Services/WhatsApp/WhatsAppService.php`
- Webhook: `backend/app/Http/Controllers/WhatsApp/MetaWhatsAppWebhookController.php`
- Rotas: `backend/routes/api.php`
- Config: `backend/config/chatbotcrm.php`

Para ativar no futuro:

1. Preencher credenciais reais apenas no `.env` local/servidor.
2. Usar `WHATSAPP_PROVIDER=meta` ou valor suportado pelo binding.
3. Conferir assinatura/verificacao de webhook.
4. Manter logs sanitizados.
5. Testar com payload ficticio antes de receber cliente real.

## 12. Como plugar impressao real no futuro

Base atual:

- Contrato: `backend/app/Contracts/Printing/PrintProviderInterface.php`
- Fluxo: `backend/app/Services/Printing/PrintWorkflowService.php`
- Comanda HTML: `backend/resources/views/printing/order-ticket.blade.php`
- Controllers: `OrderTicketPreviewController` e `PrintJobController`
- Models: `PrinterSetting`, `ReceiptTemplate`, `PrintJob`, `PrintJobEvent`

O padrao aprovado e HTML primeiro. Driver real deve entrar depois, como provider separado, sem quebrar a previa de impressao.

Cuidados:

- Nao versionar serial real de impressora.
- Registrar erro/reimpressao no fluxo.
- Nao liberar preparo sem impressao ou confirmacao manual quando a regra exigir.

## 13. Como plugar Pix real no futuro

Base atual:

- Contrato: `backend/app/Contracts/Payments/PaymentProviderInterface.php`
- Fluxo: `backend/app/Services/Payments/PaymentWorkflowService.php`
- Models: `Payment`, `PaymentProof`, `CustomerCreditMovement`
- Migrations de pagamento e credito em `backend/database/migrations/`

Para integrar provedor real:

1. Criar provider que implemente `PaymentProviderInterface`.
2. Adicionar variaveis vazias em `backend/.env.example`.
3. Guardar segredo somente em `.env`/ambiente seguro.
4. Manter confirmacao humana para comprovantes ambiguos.
5. Registrar valor pago, faltante e credito em historico conferivel.

## 14. Arquivos para estudar primeiro

Ordem sugerida:

1. `README.md`
2. `docs/GUIA_DESENVOLVEDOR.md`
3. `docs/backlog/front/frontend-guidelines.md`
4. `frontend/src/App.tsx`
5. `frontend/src/constants/routes.ts`
6. `frontend/src/types/crm.ts`
7. `frontend/src/services/crm.service.ts`
8. `frontend/src/components/layout/AppShell.tsx`
9. `frontend/src/components/ui/Modal.tsx`
10. `frontend/src/features/pedidos/OrdersPage.tsx`
11. `backend/config/chatbotcrm.php`
12. `backend/routes/web.php`
13. `backend/routes/api.php`
14. `backend/app/Providers/AppServiceProvider.php`
15. `backend/app/Services/Orders/OrderWorkflowService.php`
16. `backend/app/Services/Printing/PrintWorkflowService.php`
17. `backend/app/Services/WhatsApp/WhatsAppService.php`
18. `backend/app/Services/Ai/AiAutomationService.php`
19. `docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md`

## 15. Seguranca antes de qualquer commit

Antes de commitar:

```powershell
git status --short
git diff --check
```

Nao versionar:

- `.env`
- tokens, senhas, secrets e credenciais
- dumps, logs, certificados e arquivos privados
- `vendor/`
- `node_modules/`
- `dist/`
- documentos binarios sensiveis em `docs/`
- dados reais de clientes, pedidos, mensagens, pagamentos, Pix, telefone, CPF, endereco ou comprovantes

Atualize `.env.example` somente com nomes de variaveis e valores vazios, fake, locais ou documentais seguros.

## 16. Resumo mental do projeto

Pense no ChatBotCRM assim:

- Backend Laravel e a fonte de verdade.
- Frontend React/Vite e a experiencia operacional.
- Mocks sao temporarios e centralizados.
- Providers externos sao plugaveis e devem iniciar fake.
- IA ajuda o atendente, mas nao decide caso ambiguo sozinha.
- Impressao e parte do fluxo de pedido, nao apenas configuracao tecnica.
- Imagens sao referencia visual, nunca regra de negocio.

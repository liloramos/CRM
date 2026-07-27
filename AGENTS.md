# Produto

- Champs é uma plataforma de captação, importação, organização e qualificação de leads.
- Cliente inicial: Marcelo, gestor de tráfego.
- Prioridade geográfica: São Paulo e Rio de Janeiro.
- Fluxo principal:
  captar ou importar → normalizar → pontuar → filtrar → revisar → exportar.
- A aplicação reutiliza a base Laravel/React do ChatBotCRM, mas deve apresentar identidade visual própria.

# Stack

- Backend: PHP 8.4, Laravel 13 e PostgreSQL.
- Frontend: React 19, TypeScript e Vite.
- Autenticação e multiempresa existentes.
- Isolamento lógico por company_id.

# Branch e segurança Git

- Trabalhar exclusivamente na branch feat/champs-mvp.
- Nunca executar git reset --hard.
- Nunca executar git clean -fd.
- Nunca trocar para main durante uma tarefa.
- Nunca fazer commit, push, merge ou tag automaticamente.
- Sempre mostrar git status --short antes e depois da tarefa.

# Banco de dados

- Nunca alterar migrations antigas.
- Criar novas migrations para o Champs.
- Nunca executar migrate:fresh.
- Nunca executar migrate:fresh --seed.
- Nunca executar db:wipe.
- Nunca apagar ou recriar o banco do Restaurante Sol.
- Antes de executar php artisan migrate, revisar migrations e listar o que está Pending.
- Todas as tabelas operacionais do Champs devem possuir company_id.
- Toda consulta deve usar o company_id do usuário autenticado.
- Nunca confiar em company_id enviado pelo frontend.

# Segurança e dados

- Nunca versionar .env.
- Nunca expor tokens, credenciais ou senhas.
- Nunca registrar access tokens em logs.
- Usar apenas dados fictícios em mocks e seeders de demonstração.
- Não implementar scraping não autorizado do Instagram.
- Não automatizar navegador, login ou bypass de limitações.
- Integrações com Instagram devem utilizar somente APIs oficiais ou providers autorizados.

# Arquitetura do Champs

Frontend:
- frontend/src/features/champs
- services próprios do Champs
- componentes pequenos
- lógica crítica não deve ficar duplicada no frontend

Backend:
- Controllers finos
- Form Requests para validação
- Services para regras de negócio
- Models com relacionamentos
- Policies, middleware ou consultas com isolamento por company_id
- API Resources quando compatível com o padrão existente

# Score inicial

- SP ou RJ: +25
- site: +15
- telefone ou WhatsApp: +10
- e-mail: +10
- perfil comercial: +10
- pelo menos 6 posts recentes: +10
- pelo menos 5.000 seguidores: +10
- nome e cidade preenchidos: +10
- máximo: 100

Classificação:
- 0 a 39: Baixo potencial
- 40 a 69: Potencial médio
- 70 a 84: Bom potencial
- 85 a 100: Alta prioridade

O score deve ser calculado e persistido pelo backend quando a API real for implementada.

# Estado atual confirmado

- rota champs existe;
- ChampsPage existe;
- importação CSV existe;
- filtro existe;
- score existe no frontend;
- exportação CSV existe;
- persistência atual usa localStorage;
- não existem endpoints /api/champs;
- não existem tabelas do Champs;
- branding antigo ainda existe no login e topbar;
- os testes Laravel falham por ausência do Vite manifest no ambiente de testes.

# Critérios mínimos da entrega

- login funcional;
- identidade visual Champs;
- importação CSV;
- inclusão manual de usernames;
- normalização;
- score;
- filtros;
- exportação;
- persistência PostgreSQL;
- isolamento por company_id;
- histórico básico;
- testes backend;
- lint e build frontend;
- nenhuma referência visível ao Restaurante Sol no fluxo do Champs.

# Processo obrigatório para cada tarefa

Antes de alterar:
1. Ler AGENTS.md.
2. Confirmar branch.
3. Executar git status --short.
4. Ler arquivos relacionados.
5. Apresentar plano curto.

Depois de alterar:
1. Listar arquivos modificados.
2. Explicar decisões.
3. Executar testes aplicáveis.
4. Mostrar resultados.
5. Informar erros restantes.
6. Executar git diff --check.
7. Executar git status --short.
8. Não fazer commit ou push.

# Comandos padrão

Frontend:
- npm ci
- npm run dev
- npm run lint
- npm run build

Backend:
- composer install
- php artisan serve
- php artisan about
- php artisan route:list
- php artisan migrate:status
- php artisan test

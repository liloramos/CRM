# Testes e critérios de aceite

## Objetivo

Garantir que o MVP funcione corretamente nos fluxos essenciais e que
dados de tenants diferentes permaneçam isolados.

## Testes críticos do backend

### Autenticação

- usuário não autenticado não acessa o módulo;
- token inválido retorna erro;
- usuário autenticado acessa apenas seu contexto.

### Feature do Champs

- tenant com Champs habilitado acessa os endpoints;
- tenant sem Champs recebe erro de autorização.

### Multi-tenancy

- usuário do tenant A não lista dados do tenant B;
- usuário do tenant A não acessa lead do tenant B por ID;
- exportação contém somente dados do tenant autenticado;
- atualização respeita o tenant autenticado.

### Importação

- arquivo válido é processado;
- arquivo inválido retorna mensagem clara;
- colunas obrigatórias são verificadas;
- perfis duplicados não são inseridos novamente;
- linhas inválidas são registradas.

### Score

- lead de SP ou RJ recebe pontuação de localização;
- lead com site recebe pontuação;
- lead com telefone recebe pontuação;
- lead com e-mail recebe pontuação;
- score nunca ultrapassa 100;
- motivos correspondem aos pontos aplicados.

### Exportação

- CSV possui cabeçalho;
- caracteres especiais são preservados;
- filtros são aplicados;
- dados de outros tenants não aparecem.

## Testes do frontend

- login abre corretamente;
- menu do Champs aparece para o tenant correto;
- páginas protegidas não abrem sem autorização;
- loading é exibido;
- estado vazio é exibido;
- erros são compreensíveis;
- filtros funcionam;
- tabela possui paginação;
- detalhes do lead são exibidos;
- exportação inicia corretamente.

## Critérios de aceite

O MVP será aprovado quando:

- [ ] o usuário conseguir entrar;
- [ ] o tenant correto for identificado;
- [ ] o menu do Champs for exibido;
- [ ] uma prospecção puder ser criada;
- [ ] uma lista puder ser importada;
- [ ] os leads forem normalizados;
- [ ] os leads receberem score;
- [ ] os motivos da pontuação forem exibidos;
- [ ] filtros funcionarem;
- [ ] a planilha puder ser exportada;
- [ ] um tenant não acessar dados de outro;
- [ ] não houver erro crítico no fluxo principal.

## Definição de erro crítico

São considerados críticos:

- vazamento de dados entre tenants;
- impossibilidade de login;
- perda de registros;
- score incorreto ou inconsistente;
- exportação com dados indevidos;
- sistema indisponível no fluxo principal.
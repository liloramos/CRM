# Champs — Documentação do MVP

## Objetivo

O Champs é um módulo de prospecção e qualificação de leads construído
sobre a plataforma existente do ChatBotCRM.

O sistema reutiliza autenticação, usuários, estrutura multi-tenant,
infraestrutura, componentes visuais e serviços compartilhados do CRM,
adicionando funcionalidades específicas para prospecção.

## Cliente inicial

Marcelo — gestor de tráfego.

## Problema que o sistema resolve

Identificar, organizar e qualificar possíveis clientes localizados
principalmente em São Paulo e Rio de Janeiro, apresentando os resultados
em uma interface própria e permitindo a exportação dos leads.

## Funcionalidades do MVP

- Autenticação pelo núcleo existente do CRM
- Tenant específico do Champs
- Identidade visual própria
- Cadastro ou importação de leads
- Qualificação por critérios objetivos
- Pontuação de potencial comercial
- Filtros de localização e score
- Histórico de prospecções
- Exportação CSV ou XLSX

## Documentos

1. [Visão geral](./01-visao-geral.md)
2. [Escopo do MVP](./02-escopo-mvp.md)
3. [Arquitetura](./03-arquitetura.md)
4. [Plano de execução](./04-plano-execucao.md)
5. [Modelo de dados](./05-modelo-de-dados.md)
6. [Integração de leads](./06-integracao-de-leads.md)
7. [Testes e aceite](./07-testes-e-aceite.md)
8. [Execução e deploy](./08-execucao-e-deploy.md)

## Branch de desenvolvimento

`feat/champs-mvp`

## Situação atual

Projeto criado a partir da branch:

`feature/mvp-operacional-integrado`

Commit-base:

`e6fabeb feat: refinar interface do MVP operacional`
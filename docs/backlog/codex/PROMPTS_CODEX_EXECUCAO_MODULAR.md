# Prompts Codex - Execução Modular V1.8

Use estes prompts um por vez. Não peça para o Codex executar tudo junto.

---

## Prompt inicial - M00

```txt
Você está trabalhando no projeto ChatBotCRM, uma aplicação CRM/chatbot para restaurante, usando Laravel no backend, React no frontend, PostgreSQL e Docker.

Antes de alterar qualquer código, faça uma leitura completa e criteriosa da estrutura atual do projeto e da documentação em:

- README.md
- docs/backlog/README.md
- docs/backlog/codex/README_CODEX.md
- docs/backlog/codex/PLANO_EXECUCAO_CODEX.md
- docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md
- docs/backlog/codex/CHECKLIST_PRE_EXECUCAO_CODEX.md
- docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
- docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md
- docs/backlog/modules/M00_PREPARACAO_REPOSITORIO_PADROES.md
- docs/backlog/adr/

Objetivo inicial:
Executar somente o M00_PREPARACAO_REPOSITORIO_PADROES.md nesta primeira etapa.

Regras obrigatórias:
1. Não implemente tudo de uma vez.
2. Não pule módulos.
3. Não altere regras de negócio sem consultar a documentação.
4. Não use valores, nomes, datas ou endereços das imagens como regra de negócio. As imagens em imgs/ são apenas referência visual.
5. Não versionar .env, tokens, senhas, credenciais, certificados ou arquivos sensíveis.
6. Atualizar .env.example apenas com nomes de variáveis e valores vazios/seguros.
7. Antes de alterar arquivos, apresente um resumo breve do que encontrou na estrutura atual.
8. Depois, execute apenas o M00.
9. Ao finalizar, rode os comandos de validação disponíveis no projeto, sem inventar comandos inexistentes.
10. Ao final, liste todos os arquivos alterados/criados.
11. Crie ou sugira o commit: chore: prepare repository standards for modular execution
12. Pare e aguarde minha autorização para seguir para M01.
```

---

## Prompt de continuação - M01

```txt
Continue a execução do projeto ChatBotCRM.

Antes de alterar código, releia:

- docs/backlog/codex/PLANO_EXECUCAO_CODEX.md
- docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md
- docs/backlog/modules/M01_AUTENTICACAO_USUARIOS_PERMISSOES.md
- docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
- docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md

Objetivo:
Executar somente o M01_AUTENTICACAO_USUARIOS_PERMISSOES.md.

Regras:
1. Não avance para M02 ainda.
2. Implementar autenticação, usuários, perfis e permissões conforme documentação.
3. Perfis mínimos: super_admin, admin_gerente, atendente.
4. Considerar perfis ficticios/sanitizados para super-admin/desenvolvedor, admin/gerente e atendente nos seeders se o módulo solicitar.
5. Não armazenar senhas reais em texto puro.
6. Não alterar .env real.
7. Atualizar .env.example apenas com variáveis seguras.
8. Rodar validações ao final.
9. Listar arquivos alterados/criados.
10. Criar ou sugerir commit: feat(auth): add users roles and permissions foundation
11. Pare e aguarde autorização para M02.
```

---

## Prompt de continuação - M02

```txt
Continue a execução do projeto ChatBotCRM.

Antes de alterar código, releia:

- docs/backlog/modules/M02_RESTAURANTE_BANCO_V1.md
- docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
- docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md
- docs/backlog/adr/

Objetivo:
Executar somente o M02_RESTAURANTE_BANCO_V1.md.

Regras:
1. Não avance para M03 ainda.
2. Criar a base do banco para o módulo restaurante conforme documentação.
3. Respeitar as tabelas existentes: companies, customers, conversations e messages.
4. Não quebrar relacionamentos Eloquent existentes.
5. Criar migrations, models, relacionamentos, seeders e validações necessárias para a base restaurante.
6. Considerar multiempresa/SaaS desde a modelagem inicial, mantendo vínculo com company quando aplicável.
7. Rodar migrate:fresh --seed ao final, se seguro para ambiente local.
8. Listar arquivos alterados/criados.
9. Criar ou sugerir commit: feat(restaurant): add restaurant database foundation
10. Pare e aguarde autorização para M03.
```

---

## Prompt genérico para módulos M03 em diante

```txt
Continue a execução do projeto ChatBotCRM.

Antes de alterar código, releia:

- docs/backlog/codex/PLANO_EXECUCAO_CODEX.md
- docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md
- docs/backlog/modules/<ARQUIVO_DO_MODULO_ATUAL>.md
- docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
- docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md
- docs/backlog/adr/

Objetivo:
Executar somente o módulo atual.

Regras:
1. Não avance para o próximo módulo.
2. Não altere regras de negócio sem consultar documentação.
3. Não versionar .env nem secrets.
4. Não usar imagens como fonte de dados reais.
5. Rodar validações possíveis ao final.
6. Listar arquivos alterados/criados.
7. Criar ou sugerir o commit esperado no plano de commits.
8. Pare e aguarde autorização para o próximo módulo.
```

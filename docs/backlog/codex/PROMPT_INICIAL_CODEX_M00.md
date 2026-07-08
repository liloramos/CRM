# Prompts Codex - Execução Modular V1.8

Use estes prompts um por vez. Não peça para o Codex executar tudo junto.


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
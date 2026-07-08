# Guia do Codex - ChatBotCRM V1.8

Este diretório contém as instruções que o Codex deve seguir para implementar o ChatBotCRM sem perder controle do escopo.

## Antes de programar

O Codex deve primeiro ler:

1. `docs/backlog/README.md`
2. `docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md`
3. `docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md`
4. `docs/backlog/codex/PLANO_EXECUCAO_CODEX.md`
5. `docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md`
6. `docs/backlog/codex/CHECKLIST_PRE_EXECUCAO_CODEX.md`
7. O arquivo do módulo atual em `docs/backlog/modules/`

## Execução

- Executar um módulo por vez.
- Começar pelo M00.
- Parar ao final de cada módulo.
- Listar arquivos alterados/criados.
- Rodar validações possíveis.
- Criar ou sugerir commit pequeno.

## Segurança

Nunca versionar:

- `.env`
- tokens reais
- senhas reais
- chaves privadas
- certificados privados
- dumps de banco
- logs sensíveis
- arquivos de storage privado

`/.env.example` pode receber variáveis vazias/documentadas, nunca valores reais.

# Preparação do repositório e padrões

**Arquivo:** `M00_PREPARACAO_REPOSITORIO_PADROES.md`  
**Commit esperado:** `chore: prepare repository standards for modular execution`

## Objetivo
Organizar estrutura, padrões, .env.example, providers base e convenções.

## Leitura obrigatória

```txt
docs/backlog/README.md
docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md
docs/backlog/codex/PLANO_EXECUCAO_CODEX.md
docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md
```

Além disso, ler ADRs relacionados quando existirem.

## Escopo
- Conferir estrutura atual do projeto sem alterar regra de negócio.
- Garantir que docs/backlog esteja versionado.
- Conferir .gitignore para proteger .env, logs, dumps, node_modules, vendor e storage sensível.
- Atualizar .env.example somente com variáveis vazias/seguras.
- Criar estrutura base para providers se ainda não existir.
- Documentar comandos locais encontrados.

## Fora de escopo deste módulo

- Não avançar para o próximo módulo.
- Não implementar funcionalidades futuras que não sejam necessárias para o escopo atual.
- Não versionar `.env` nem credenciais.
- Não usar imagens como fonte de regra de negócio.

## Validação esperada

O Codex deve executar apenas comandos que existirem no projeto. Exemplos prováveis:

```bash
cd backend
php artisan test
php artisan migrate:fresh --seed
```

```bash
cd frontend
npm run lint
npm run build
```

Se algum comando não existir, informar claramente.

## Saída obrigatória

Ao finalizar:

```txt
Resumo do módulo
Arquivos criados
Arquivos alterados
Comandos executados
Resultado das validações
Pendências
Commit: chore: prepare repository standards for modular execution
```

Depois parar e aguardar autorização do Murilo.

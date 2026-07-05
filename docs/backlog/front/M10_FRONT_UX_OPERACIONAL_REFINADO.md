# Front UX operacional

**Arquivo:** `M10_FRONT_UX_OPERACIONAL_REFINADO.md`  
**Commit esperado:** `feat(front): add operational CRM interface foundation`

## Objetivo
Criar base visual e operacional conforme docs/front.

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
- Ler todos os documentos em docs/backlog/front.
- Usar imagens apenas como guia visual.
- Criar layout escuro premium com laranja/amarelo.
- Implementar sidebar, topbar, cards, tabelas, badges, modais e drawers reutilizáveis.
- Criar telas prioritárias: login, dashboard, conversas, pedidos, cardápio.
- Não copiar dados fictícios das imagens.

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
Commit: feat(front): add operational CRM interface foundation
```

Depois parar e aguardar autorização do Murilo.

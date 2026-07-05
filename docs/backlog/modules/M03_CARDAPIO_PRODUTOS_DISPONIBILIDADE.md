# Cardápio, produtos e disponibilidade

**Arquivo:** `M03_CARDAPIO_PRODUTOS_DISPONIBILIDADE.md`  
**Commit esperado:** `feat(menu): add products menu and availability management`

## Objetivo
Gerenciar produtos, categorias, preços, cardápio semanal e itens esgotados.

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
- Criar categorias de produtos.
- Criar produtos e variações/adicionais.
- Seedar marmitas, bebidas, combos, sucos, feijoadas e adicionais.
- Implementar disponibilidade diária e item esgotado.
- Garantir que IA/front possam consultar apenas itens ativos/disponíveis.
- Criar regras de N5 Casa, N8 Casa, N8 e N9.

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
Commit: feat(menu): add products menu and availability management
```

Depois parar e aguardar autorização do Murilo.

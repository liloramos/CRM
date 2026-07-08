# Restaurante Banco V1

**Arquivo:** `M02_RESTAURANTE_BANCO_V1.md`
**Commit esperado:** `feat(restaurant): add restaurant database foundation`

## Objetivo
Criar fundação do banco do módulo restaurante preservando tabelas existentes.

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
- Preservar companies, customers, conversations e messages.
- Criar tabelas base para configurações do restaurante.
- Criar relacionamento multiempresa por company_id quando aplicável.
- Preparar base para produtos, pedidos, pagamentos, entregas e impressão.
- Criar models, migrations e seeders.
- Validar com migrate/fresh seed em ambiente local seguro.

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
Commit: feat(restaurant): add restaurant database foundation
```

Depois parar e aguardar autorização do Murilo.

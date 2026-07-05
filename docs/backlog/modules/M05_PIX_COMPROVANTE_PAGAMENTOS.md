# Pix, comprovantes e pagamentos

**Arquivo:** `M05_PIX_COMPROVANTE_PAGAMENTOS.md`  
**Commit esperado:** `feat(payments): add pix receipt confirmation flow`

## Objetivo
Implementar Pix por chave textual, comprovante e confirmação humana.

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
- Criar pagamentos vinculados a pedidos.
- Guardar método: pix, dinheiro, debito, credito.
- Criar anexos/comprovantes vinculados.
- Confirmar pagamento por usuário autorizado.
- Registrar recusa, motivo e observação interna.
- Não implementar API bancária na V1.

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
Commit: feat(payments): add pix receipt confirmation flow
```

Depois parar e aguardar autorização do Murilo.

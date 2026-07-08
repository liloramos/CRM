# Entrega e taxa por km

**Arquivo:** `M06_ENTREGA_TAXA_KM.md`
**Commit esperado:** `feat(delivery): add delivery fee calculation by distance`

## Objetivo
Calcular taxa de entrega por distância x R$2 + 10%.

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
- Criar dados de entrega/retirada.
- Implementar fórmula configurável: km x 2.00 x 1.10.
- Permitir distância manual na V1.
- Preparar campos para Google Maps/Routes futuro.
- Registrar endereço, referência e taxa calculada.
- Não chamar API externa ainda sem credenciais.

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
Commit: feat(delivery): add delivery fee calculation by distance
```

Depois parar e aguardar autorização do Murilo.

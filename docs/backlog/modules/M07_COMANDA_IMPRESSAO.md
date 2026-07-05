# Comanda e impressão

**Arquivo:** `M07_COMANDA_IMPRESSAO.md`  
**Commit esperado:** `feat(printing): add receipt and kitchen ticket generation`

## Objetivo
Gerar comanda HTML/PDF e preparar Epson TM-T20X.

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
- Criar visualização de comanda legível.
- Otimizar para impressão térmica.
- Registrar impressora Epson TM-T20X 031 modelo M352A como referência operacional.
- Criar fila/status de impressão.
- Permitir reimpressão e registro de usuário.
- Não depender de impressão USB direta na V1.

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
Commit: feat(printing): add receipt and kitchen ticket generation
```

Depois parar e aguardar autorização do Murilo.

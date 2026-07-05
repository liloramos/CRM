# Autenticação, usuários e permissões

**Arquivo:** `M01_AUTENTICACAO_USUARIOS_PERMISSOES.md`  
**Commit esperado:** `feat(auth): add users roles and permissions foundation`

## Objetivo
Criar base de login, usuários, papéis e permissões.

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
- Implementar/ajustar User conforme Laravel.
- Criar roles mínimos: super_admin, admin_gerente, atendente.
- Criar seeders para usuários iniciais se seguro.
- Nunca usar senhas reais em texto puro.
- Adicionar policies/middlewares quando aplicável.
- Garantir vínculo com company quando fizer sentido.

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
Commit: feat(auth): add users roles and permissions foundation
```

Depois parar e aguardar autorização do Murilo.

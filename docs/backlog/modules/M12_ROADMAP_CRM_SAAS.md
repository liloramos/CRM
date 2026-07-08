# Roadmap CRM/SaaS

**Arquivo:** `M12_ROADMAP_CRM_SAAS.md`
**Commit esperado:** `docs: update crm saas roadmap implementation notes`

**Commit sugerido nesta execucao:** `docs(roadmap): prepare crm saas evolution structure`

## Objetivo
Documentar evolução para SaaS multiempresa.

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
- Planejar planner, agenda, funil, prospecção e dashboards avançados.
- Manter multiempresa desde o banco.
- Planejar permissões customizadas.
- Planejar billing/assinaturas futuro sem implementar agora.
- Planejar white label/branding futuro.
- Não inflar escopo da V1.

## Entregavel tecnico-documental

O planejamento estrutural do M12 fica registrado em:

```txt
docs/backlog/roadmap/CRM_SAAS_EVOLUTION_M12.md
docs/backlog/roadmap/README.md
```

Esse entregavel documenta pontos de extensao e limites de escopo para a evolucao
CRM/SaaS, sem criar funcionalidades grandes ou regras futuras no codigo.

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
Commit: docs(roadmap): prepare crm saas evolution structure
```

Depois parar e aguardar autorização do Murilo.

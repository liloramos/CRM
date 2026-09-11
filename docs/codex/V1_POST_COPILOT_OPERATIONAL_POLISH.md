# V1 Post-Copilot Operational Polish

## Ordem

Este documento NÃO muda a prioridade atual.

Primeiro:

`Copilot customer-facing → parser/state/bursts/alerts → smoke real`

Depois, quando o Copilot estiver verde:

### Rodada operacional curta

1. Seller / attendant attribution
2. CRUD geral de produto
3. CRUD seguro de categoria
4. exclusão/arquivamento seguro
5. consistência em tempo real catálogo → Copilot
6. corrigir sandbox `IA e Automação`
7. corrigir `Assistente` interno
8. pré-comanda bife blank behavior
9. horários reais/configuração
10. Epson smoke

Depois:

`Freeze audit → commit → main → deploy → Meta oficial → produção`

---

## Contratos

- `V1_SELLER_ATTRIBUTION.md`
- `V1_CATALOG_ADMINISTRATION.md`
- `CATALOG_REALTIME_CONSISTENCY.md`
- `AI_AUTOMATION_SANDBOX_CONTRACT.md`
- `SYSTEM_ASSISTANT_CONTRACT.md`

---

## Regra de escopo

Não misturar esta rodada com a correção atual do parser/state/alerts.

O objetivo é evitar uma sessão Codex grande demais e reduzir regressões cruzadas.

---

## Critério de pronto

Catálogo:

- equipe cria/edita/inativa/exclui com segurança;
- IA enxerga alteração nova imediatamente;
- histórico antigo permanece íntegro.

Sandbox:

- fatos comerciais reais;
- paridade com Copilot live;
- zero mutação.

Assistente:

- responde ajuda operacional;
- CTAs corretos;
- RBAC;
- sem fallback genérico em perguntas simples.

Seller:

- simples;
- auditável;
- sem exigir login individual.

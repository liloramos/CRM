# Copilot Input Normalization — V1

## Objetivo

Garantir que o atendimento funcione com a linguagem real usada em WhatsApp, sem exigir pontuação, ortografia perfeita ou uma intenção por mensagem.

A normalização existe para ajudar a entender o cliente.

Ela NÃO pode virar uma segunda fonte de verdade do pedido.

---

## 1. Princípio

Preservar duas visões:

### Texto original

Usado para:

- auditoria
- histórico
- contexto semântico
- eventual revisão humana

### Representação normalizada

Usada apenas para:

- matching
- intent resolution
- synonyms
- slot extraction
- comparação aproximada

Nunca substituir o texto original salvo.

---

## 2. Tolerância linguística

Devem ser entendidas variações como:

- `n8`
- `N8`
- `n 8`
- `n°8`
- `n8 livre`
- `marmita n8`
- `marmitex n8`

E:

- `porco`
- `pode ser porco`
- `quero porco`
- `vai porco`
- `poe porco`

E:

- `pix`
- `piks`
- `no pix`
- `vou de pix`

O resolver deve usar contexto e catálogo canônico para decidir.

---

## 3. Pontuação não é requisito

Estas mensagens devem ser semanticamente equivalentes quando o conteúdo for o mesmo:

`quero n8 livre porco entrega rua x 10 pix`

`Quero uma N8 Livre, com porco. É para entrega na Rua X, 10. Vou pagar no Pix.`

A ausência de pontuação não pode por si só gerar low-confidence/human review.

---

## 4. Acentos

Matching deve tolerar:

- `feijao`
- `feijão`

- `almondega`
- `almôndega`

- `linguica`
- `linguiça`

Mas a resposta customer-facing usa nomes canônicos bem escritos.

---

## 5. Erros de digitação

Aceitar aproximações seguras quando houver confiança suficiente dentro do catálogo/contexto.

Exemplos plausíveis:

- `strogonof`
- `strogonoff`
- `marmitex`
- `marmita`
- `dinherio`

Nunca usar fuzzy matching para escolher silenciosamente entre duas opções plausíveis.

Se duas opções forem próximas:

perguntar.

---

## 6. Abreviações e coloquialismo

Suportar linguagem de restaurante/WhatsApp:

- `refri`
- `coca`
- `zero`
- `retira`
- `buscar`
- `pegar aí`
- `manda entregar`
- `pode ser`
- `fechou`
- `blz`
- `isso`
- `essa`
- `aquela`
- `a primeira`
- `a segunda`

A resolução contextual precisa considerar a última pergunta/opções outbound.

---

## 7. Afirmação

Depois de uma pergunta de confirmação, respostas como:

- `sim`
- `isso`
- `pode`
- `pode ser`
- `beleza`
- `blz`
- `fechou`
- `ok`
- `👍`

podem resolver confirmação quando o contexto for inequívoco.

Não aplicar `sim` globalmente sem saber qual confirmação está pendente.

---

## 8. Negação

Entender:

- `não`
- `nao`
- `não quero`
- `sem`
- `tira`
- `não põe`
- `não vai`

Negação deve ter escopo correto.

Exemplo:

`sem macarrão e pode ser porco`

não significa cancelar porco.

---

## 9. Correção / mudança de ideia

Frases:

- `na verdade troca por frango`
- `melhor almôndega`
- `não quero mais entrega vou buscar`
- `tira a coca`
- `muda pra N9`

devem produzir alteração explícita e auditável.

Antes de pagamento confirmado, usar workflow canônico de atualização.

Depois de proof/pagamento, aplicar regras de proteção financeira descritas em `V1_OPERATIONAL_EDGE_CASES.md`.

---

## 10. Ordinais e referências

Quando o Copilot acabou de listar opções:

- `a primeira`
- `segunda`
- `a de porco`
- `essa`
- `aquela`
- `a maior`
- `a mais barata`

podem ser resolvidas usando o outbound recente.

Nunca resolver referência para uma lista antiga se houver múltiplas listas concorrentes e ambiguidade.

---

## 11. Multi-intent

Não impor uma única intenção por mensagem.

Exemplo:

`quero uma n8 livre porco entrega e vou pagar pix`

Pode preencher:

- product
- meat
- fulfillment
- payment_method

E ainda perguntar apenas o que faltar.

Outro exemplo:

`quero uma N8 Livre qual o buffet de hoje`

Deve:

- selecionar N8 Livre
- responder buffet
- preservar state
- perguntar próximo missing slot

---

## 12. Muitos itens na mesma mensagem

Exemplo:

`manda duas n8 uma com porco outra com frango e uma coca 2l`

O sistema deve separar itens/quantidades quando a estrutura canônica suportar.

Escolhas de um item não podem vazar para outro.

Se a implementação atual não consegue representar customização por item com segurança:

- não inventar
- não colapsar os dois itens
- identificar como blocker de V1
- clarificar até obter uma representação segura

---

## 13. Mensagens em rajada

Cliente pode enviar:

1. `quero uma n8`
2. `porco`
3. `entrega`
4. `rua x 10`

antes de a IA responder.

Requisitos:

- processar em ordem por conversa
- serializar mutações do state
- não responder sobre estado intermediário obsoleto
- não perder nenhuma mensagem
- não duplicar Order
- não abrir review apenas pela velocidade

Se houver mecanismo de coalescing/debounce, ele deve ser determinístico e testado.

---

## 14. Mensagem vazia / só emoji / figurinha

Se não houver conteúdo operacional extraível:

- não inventar pedido
- não criar Order
- não criar review por padrão

Se for uma reação inequívoca a uma pergunta de confirmação, resolver somente quando o contexto permitir.

Caso contrário, responder com pergunta simples.

---

## 15. Áudio

Se V1 não possui transcrição canônica de áudio:

- não fingir que entendeu
- responder amigavelmente pedindo texto
- não abrir human review apenas por áudio

Se houver transcrição implementada futuramente, a transcrição passa pelo mesmo pipeline de normalização/grounding.

---

## 16. Imagem

Imagem pode ser:

- comprovante
- foto sem contexto
- outro conteúdo

Somente classificar como proof quando houver contexto/metadata/workflow suficiente.

Imagem sem Payment correspondente não pode criar confirmação financeira.

---

## 17. Segurança

Normalização nunca pode remover sinais importantes de:

- negação
- prompt injection
- tentativa de alterar preço
- tentativa de confirmar pagamento
- pedido administrativo

Normalizar `não` de maneira errada é crítico.

---

## 18. Critério de sucesso

A pergunta é:

> Uma pessoa que atende WhatsApp do restaurante entenderia essa frase pelo contexto?

Se sim, o sistema deve tentar resolver semanticamente.

Se existirem duas interpretações comercialmente diferentes:

perguntar.

Precisão vence adivinhação.

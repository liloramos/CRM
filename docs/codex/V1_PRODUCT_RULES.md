# V1 Product Rules — Restaurante Sol

Atualização de referência: 2026-09-03.

> Este documento descreve o contrato humano de produto. Em runtime, banco/services continuam sendo fonte de verdade.

## Objetivo
WhatsApp → pedido → pagamento → preparo → retirada/entrega → finalização.

Prioridades: integridade do pedido, segurança financeira, clareza, baixa carga manual, auditabilidade e consistência entre Conversas, Pedidos, Pagamentos, Entregas e Financeiro.

## Copilot
Deve agir como atendente digital do Sol: mandar cardápio, explicar produtos, responder preços/disponibilidade, manter contexto, montar pedido e conduzir o cliente. Nunca inventar preço, ingrediente, carne, disponibilidade, taxa, Pix, horário ou regra.

## Integridade
Escolha explícita do cliente é protegida. Produto posterior não pode sobrescrever componentes anteriores sem confirmação.

## Casa x Livre
Produto Casa segue composição/restrições próprias. Produto Livre permite escolhas segundo regra canônica. Se escolhas anteriores forem incompatíveis com Casa: preservar, explicar conflito e perguntar se aceita Casa ou muda para modalidade compatível.

## Regras de carne conhecidas
Devem ser conferidas no domínio antes do freeze:
- Feijoada conta como carne.
- Carne padrão extra além do permitido: +R$ 4,00.
- Churrasco + outra carne padrão: +R$ 4,00.
- Bife não é carne padrão.
- Bife adicional: +R$ 7,00.
- N9 somente bife: R$ 23,00.
- N9 Livre base: R$ 19,00.

Runtime sempre pelo catálogo/pricing.

## Produtos conhecidos
Valores atuais conhecidos no projeto, a confirmar no catálogo antes do freeze:
- N5 Casa — R$ 8,00
- N8 Casa — R$ 13,00
- N8 Livre — R$ 16,00
- N9 Livre — R$ 19,00
- Combo N8 Casa Baby — R$ 15,00
- Separadinha — R$ 20,00

### N5 Casa
Conhecido: arroz, feijão, macarrão, mandioca; salada N5 definida pela casa entre beterraba/cenoura; 1 opção de carne permitida; 1 pedaço de carne.

### N8 Casa
Base Casa; salada dentro das opções permitidas; carnes Casa permitidas; 2 pedaços de carne.

### Livres
Buffet variável por dia. A resposta ao cliente deve informar buffet do dia, carnes do dia, quantidade de carnes permitidas e adicionais aplicáveis.

## Cardápio do dia
Quando o cliente pedir cardápio, combinar: produtos, preços, composição/regra, buffet real do dia, carnes do dia, quantidade de carnes e adicionais relevantes. Não responder apenas “buffet disponível no dia”.

## Balcão / Caixa
Venda de balcão usa Order, OrderItem e Payment.

Regras conhecidas:
- Self Service R$ 19,00.
- Bife +R$ 7,00.
- Comida por kg comum R$ 55/kg.
- Somente carne R$ 70/kg.
- Peso em gramas inteiras; cálculo no backend.

## Comandas
Draft operacional, sem Payment até finalização, código diário, cancelável/finalizável, não fiscal quando aplicável.

Pendência V1: bife selecionado → marcar Sim; bife não selecionado → deixar Sim/Não em branco.

## Responsável / vendedor simples
V1 deve permitir atribuir venda a atendente sem login individual obrigatório. Nomes iniciais: Beatriz, Larissa, Helton, Calebe. Preferir lista configurável por empresa, persistida no pedido/histórico e sem apagar origem automática/manual.

## Fulfillment
Retirada ou entrega. Entrega: address/LOCATION → dados mínimos → taxa canônica → subtotal + adicionais + taxa → total → resumo → pagamento.

## Pix / proof
Pix usa configuração pública da empresa. Criar/reutilizar Payment, informar total, solicitar proof, alertar equipe. IA não confirma pagamento. Humano confirma e o workflow continua.

## Pós-pagamento
Retirada: preparo → pronto → retirada → finished.
Entrega: workflow existente equivalente a `ready_for_pickup → out_for_delivery → finished`.

## Horários
Horários são configuráveis. Sem configuração, não afirmar aberto/fechado. Timezone: `America/Sao_Paulo`.

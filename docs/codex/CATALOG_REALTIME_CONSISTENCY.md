# Catalog Real-Time Consistency Contract — V1

## Objetivo

Garantir que o Copilot consulte o estado comercial atual do Restaurante Sol.

A regra é:

**PROMPT ENSINA COMO ATENDER.
BANCO DIZ O QUE O RESTAURANTE VENDE AGORA.
ORDER REGISTRA O QUE FOI VENDIDO NAQUELE MOMENTO.**

---

## 1. Fonte da verdade

Produtos, preços, categorias, status, disponibilidade, buffet, carnes e regras comerciais vêm do backend/banco/services canônicos.

Não duplicar fatos comerciais em:

- prompt;
- código do Copilot;
- orientação textual fixa;
- cache sem invalidação;
- frontend.

---

## 2. Atualização imediata

Após operação administrativa concluída com sucesso:

- create;
- update;
- activate;
- deactivate;
- availability change;
- category change;

a próxima consulta relevante do Copilot deve enxergar o estado novo.

Não exigir:

- deploy;
- restart do worker;
- restart do servidor;
- edição de prompt;
- limpeza manual de cache pelo operador.

---

## 3. Cache

Cache é permitido por performance somente se mantiver consistência operacional.

Requisito:

```text
CATALOG MUTATION
      ↓
INVALIDATE / VERSION CACHE
      ↓
NEXT COPILOT READ = NEW STATE
```

TTL longo sem invalidação explícita é inadequado para preço/disponibilidade.

---

## 4. Produto novo

Cenário:

1. admin cria `H2O Lata`;
2. define categoria `Bebidas`;
3. define preço;
4. ativa produto.

Pergunta posterior:

`tem H2O lata?`

Esperado:

- Copilot encontra o produto se comercialmente disponível;
- usa nome/preço atuais;
- não depende de seed/prompt novo.

---

## 5. Alteração de preço

Cenário:

1. produto tem preço X;
2. simulador/Copilot consulta e retorna X;
3. admin altera para Y;
4. nova consulta.

Esperado:

- nova consulta usa Y.

---

## 6. Inativação

Produto inativo:

- não deve ser oferecido como disponível;
- não deve ser usado para montar novo pedido;
- pode continuar existindo em histórico.

Se cliente pergunta especificamente:

responder de forma coerente com indisponibilidade, sem fingir que não existe historicamente quando isso não for necessário.

---

## 7. Reativação

Após reativar e satisfazer regras de disponibilidade:

- Copilot pode voltar a oferecer na próxima consulta.

---

## 8. Mudança no meio da conversa

Catálogo pode mudar durante uma conversa aberta.

### Apenas consulta / item ainda não confirmado

Usar estado comercial atual.

### Proposta ainda aberta

Antes de materializar/fechar:

- revalidar preço/disponibilidade;
- se mudou materialmente, informar cliente;
- recalcular por workflow canônico.

### Order materializado

O Order/OrderItem deve preservar o valor aplicado/snapshot conforme modelo atual.

Mudança futura no catálogo não reescreve pedido histórico.

### Proof ou Payment confirmado

Nunca atualizar retroativamente preço/total por mudança posterior no catálogo.

---

## 9. Disponibilidade diária

Mudança em:

- buffet;
- carnes do dia;
- dias do produto;
- status;

deve refletir na próxima leitura do Copilot.

---

## 10. Tenant isolation

Toda leitura/mutação deve respeitar `company/tenant`.

Produto criado em uma empresa não aparece em outra.

---

## 11. Acceptance gate

### Price freshness

- consultar preço atual;
- editar preço;
- consultar novamente;
- resultado novo sem restart.

### Product freshness

- criar produto;
- ativar;
- Copilot encontra.

### Status freshness

- inativar;
- Copilot deixa de oferecer;
- reativar;
- Copilot volta a oferecer.

### Historical stability

- criar Order com preço A;
- alterar catálogo para B;
- Order antigo continua com A.

### Cache safety

- se houver cache, mutation invalida;
- nenhum stale commercial fact após update administrativo.

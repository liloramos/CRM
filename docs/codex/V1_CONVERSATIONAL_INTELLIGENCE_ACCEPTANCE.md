# V1 Conversational Intelligence Acceptance Gate

## Objetivo

Validar capacidade de compreensão contextual e generalização.

Este gate NÃO deve ser implementado como lista de frases especiais.

Cada classe deve aceitar paráfrases não vistas.

---

## 1. Generic order intent

Paráfrases:

- `qro uma marmita`
- `queria pedir uma marmitex`
- `me vê uma marmita`
- `quero almoço`

Esperado:

- intenção de compra;
- apresentar/clarificar opções relevantes;
- não tratar `marmita` como SKU inexistente;
- zero review.

---

## 2. Product comparison

Paráfrases:

- `qual a diferença das n8`
- `o que muda da casa pra livre`
- `essa é diferente da casa como`
- `qual diferença dessa pra outra`

Esperado:

- resolver referentes;
- facts canônicos;
- explicar diferença real;
- não reduzir automaticamente a `price lookup`;
- nenhuma mutação sem escolha explícita.

---

## 3. Follow-up reference

Pré-condição:

N8 Livre acabou de ser discutida.

Cliente:

- `e a casa?`
- `essa pra casa muda oq`
- `a outra é quanto`
- `qual é maior`

Esperado:

- resolver referência pelo contexto;
- responder pergunta;
- preservar pedido.

Se houver dois referentes plausíveis:

- clarification curta.

---

## 4. Ask options for pending slot

Pré-condição:

IA perguntou:

`Qual salada você deseja?`

Paráfrases:

- `quais que tem`
- `quais tem ai`
- `oq tem de salada`
- `me fala as opções`
- `e as saladas?`

Esperado:

- subject=salad;
- listar opções válidas daquele produto;
- não voltar para escolha de marmita;
- zero review.

Repetir a mesma classe para:

- carne;
- pagamento;
- variante;
- bebidas quando aplicável.

---

## 5. Answer pending slot

Pré-condição:

slot=salad e Beterraba é válida.

Entradas:

- `beterraba`
- `pode ser beterraba`
- `a beterraba`
- `essa de beterraba`

Esperado:

- state delta `salad=Beterraba`;
- backend valida;
- próximo passo;
- zero review.

---

## 6. Invalid pending-slot value

Pré-condição:

slot=salad.

Cliente informa opção inexistente/indisponível.

Esperado:

- não gravar valor;
- explicar;
- oferecer opções válidas;
- sem inventar;
- clarification, não review imediato.

---

## 7. Information question during order

Pedido parcialmente montado.

Cliente:

`quanto custa a n9?`

Esperado:

- responder N9;
- pedido atual continua igual.

Cliente depois:

`continua a minha`

Esperado:

- retomar state anterior.

---

## 8. Recommendation

Perguntas:

- `qual vale mais a pena`
- `qual vc acha melhor`
- `qual é mais em conta`
- `qual dá pra escolher mais coisa`

Esperado:

- facts reais;
- separar critério objetivo de preferência;
- perguntar preferência quando útil;
- não inventar avaliação.

---

## 9. Correção

Pedido tem Coca.

Cliente:

- `não quero mais coca coloca água`
- `troca a coca por agua`
- `tira a coca`

Esperado:

- interpretar correction/negation;
- validar;
- atualizar state corretamente;
- recalcular quando aplicável;
- proteger estágio financeiro.

---

## 10. Reference to options

IA listou:

1. Almôndega
2. Porco
3. Frango

Cliente:

- `a segunda`
- `essa segunda`
- `2`
- `porco mesmo`

Esperado:

- mesma escolha final;
- sem hardcode por frase.

---

## 11. Semantic recovery

Forçar mensagem que o fast path determinístico não reconhece, mas que é semanticamente clara no contexto.

Esperado:

- semantic recovery resolve;
- backend valida;
- continua.

Human review não deve ocorrer apenas porque o fast path falhou.

---

## 12. True ambiguity

Mensagem tem duas interpretações comerciais plausíveis.

Esperado:

- modelo identifica ambiguidade;
- pergunta curta;
- nenhuma mutação silenciosa;
- preserva state.

---

## 13. Provider failure

Semantic recovery indisponível.

Esperado:

- preservar state;
- usar fallback determinístico se aplicável;
- clarification segura;
- sem mutação incerta;
- não inventar facts.

---

## 14. Safety regression

Mesmo com mais liberdade conversacional, continuar bloqueando:

- alteração arbitrária de preço;
- auto-confirmação de Payment;
- ação administrativa;
- prompt injection;
- produto inexistente como válido;
- disponibilidade inventada.

---

## 15. Generalization criterion

Para cada classe principal, testar pelo menos:

- uma frase usada no QA;
- duas paráfrases diferentes que NÃO estejam hardcodadas no resolver.

Se somente a frase original funcionar, considerar FAIL.

---

## 16. Meta smoke livre

Após testes automatizados:

duas pessoas diferentes conversam sem roteiro rígido.

Devem conseguir:

- perguntar;
- comparar;
- mudar de assunto e voltar;
- perguntar opções;
- responder slots;
- corrigir escolhas;
- pedir preço;
- completar pedido;

sem precisar aprender a "linguagem do bot".

Somente após isso:

`Conversational Intelligence = PASS`.

# Atendimento Real via WhatsApp — Requisitos Operacionais

> Documento derivado da análise de conversas reais de atendimento do Sol Restaurante.
>
> Este documento não deve conter dados pessoais, telefones, CPFs, endereços, chaves Pix, comprovantes ou transcrições completas de conversas. O objetivo é transformar comportamentos reais de clientes em requisitos seguros para o sistema.

---

## 1. Objetivo

Este documento descreve situações reais que o ChatBotCRM deve suportar no atendimento via WhatsApp, especialmente no contexto de pedidos de marmitex, pagamentos, retirada por terceiros, crédito, observações alimentares e mudanças durante a conversa.

O foco é garantir que o sistema funcione bem mesmo quando o cliente:

- manda mensagens incompletas;
- envia várias mensagens em sequência;
- muda o pedido no meio do atendimento;
- paga antes de confirmar tudo;
- paga a mais ou a menos;
- pede para outra pessoa buscar;
- usa termos informais como “o de sempre” ou “igual ontem”;
- pede restrições alimentares específicas;
- solicita urgência;
- se confunde sobre valores, crédito ou itens.

---

## 2. Princípios do atendimento real

### 2.1 O cliente nem sempre envia um pedido perfeito

O sistema deve aceitar pedidos em construção.

Exemplo sanitizado:

```txt
Cliente: Quero 2 marmitas
Cliente: Uma é para mim
Cliente: A outra é para outra pessoa
Cliente: Coloca pouco arroz na minha
Cliente: Mais uma Coca
Cliente: Quanto ficou?
```

O pedido deve poder ficar com status interno como:

- `rascunho`;
- `em_conferencia`;
- `aguardando_confirmacao`;
- `pronto_para_imprimir`.

### 2.2 O pedido precisa aceitar alterações durante a conversa

O cliente pode adicionar bebida, mudar quantidade, trocar carne ou alterar entrega depois de já ter começado o pedido.

O sistema deve permitir:

- adicionar item depois;
- remover item;
- editar observação de item;
- recalcular total;
- registrar histórico da alteração;
- avisar quando a alteração muda o valor final.

### 2.3 O atendente precisa confirmar ambiguidades

Quando houver dúvida, a IA ou o atendente deve perguntar de forma simples.

Exemplos:

```txt
Só confirmando: são 3 marmitas e 1 refrigerante, certo?
```

```txt
A senhora quer entrega ou retirada?
```

```txt
Essa marmita é para você ou para outra pessoa?
```

```txt
Deseja usar o crédito disponível neste pedido?
```

---

## 3. Cliente pagador, beneficiário e retirante

Nas conversas reais, uma pessoa pode pagar, outra pode buscar e outra pode consumir a marmita.

O sistema deve separar:

- cliente pagador;
- pessoa que vai retirar;
- pessoa para quem o item foi preparado;
- observação de retirada;
- autorização de retirada.

### 3.1 Campos recomendados

```ts
interface PedidoPessoaRelacionada {
  clientePagadorId: string;
  nomeRetirante?: string;
  telefoneRetirante?: string;
  observacaoRetirada?: string;
  itensPorPessoa?: Array<{
    nomePessoa: string;
    itemPedidoId: string;
    observacao: string;
  }>;
}
```

### 3.2 Exemplo sanitizado

```txt
Cliente pagador: Cliente A
Retirante: Pessoa B
Item 1: Marmita da Cliente A, com pouca massa e mais salada
Item 2: Marmita da Pessoa B, normal
Observação: Pessoa B vai buscar no balcão
```

---

## 4. Preferências recorrentes

Clientes recorrentes usam frases como:

- “igual ontem”;
- “o de sempre”;
- “minha marmita você já sabe”;
- “a do Fulano ele monta”;
- “faz a minha do jeito que gosto”.

O sistema deve mostrar no perfil do cliente:

- últimos pedidos;
- preferências recorrentes;
- restrições alimentares;
- ingredientes preferidos;
- ingredientes rejeitados;
- histórico de reclamações;
- crédito atual;
- observações fixas.

### 4.1 Preferência por pessoa

Nem toda preferência pertence ao cliente pagador. Às vezes a pessoa que consome tem preferência própria.

Exemplo:

```txt
Cliente A: pouca massa, mais carne, sem fritura
Pessoa B: marmita normal com macarrão e maionese
Pessoa C: deixa montar no balcão
```

---

## 5. Observações por item

Não basta ter uma observação geral no pedido.

Cada item precisa aceitar sua própria observação, porque clientes pedem marmitas diferentes dentro do mesmo pedido.

### 5.1 Exemplo de estrutura

```ts
interface ItemPedido {
  id: string;
  produtoId: string;
  nome: string;
  quantidade: number;
  observacoes: string;
  preferencias: string[];
  pessoaDestino?: string;
}
```

### 5.2 Exemplos de observações sanitizadas

```txt
Pouco arroz, sem macarrão, carne com caldo.
```

```txt
Caprichar nas carnes, sem salada crua.
```

```txt
Essa marmita é para outra pessoa retirar.
```

---

## 6. Crédito, saldo e diferenças de pagamento

A conversa real mostra casos frequentes de:

- cliente pagar a mais;
- cliente pagar a menos;
- restaurante deixar diferença para próxima compra;
- cliente perguntar quanto tem de crédito;
- desconto de crédito no próximo pedido;
- necessidade de devolver valor.

O sistema deve possuir um módulo simples de crédito do cliente.

### 6.1 Regras recomendadas

- Todo pagamento acima do total deve gerar opção de crédito ou devolução.
- Todo pagamento abaixo do total deve gerar pendência.
- O atendente deve conseguir aplicar crédito no pedido atual.
- O histórico de crédito deve ser auditável.
- O sistema deve mostrar saldo atual no perfil do cliente.
- O comprovante ou observação de pagamento deve ficar vinculado ao pedido.

### 6.2 Tipos de movimento

```ts
export type TipoMovimentoCredito =
  | 'credito_gerado'
  | 'credito_utilizado'
  | 'debito_pendente'
  | 'ajuste_manual'
  | 'devolucao';
```

---

## 7. Restrições alimentares e saúde

O sistema deve permitir destacar restrições importantes.

Exemplos sanitizados:

- pouco arroz;
- sem macarrão;
- sem fritura;
- sem determinado ingrediente;
- preferência por verduras e carnes;
- bebida sem açúcar;
- observação de dieta.

### 7.1 Exibição no perfil

No perfil do cliente, restrições devem aparecer em destaque, por exemplo:

```txt
Atenção: cliente costuma pedir pouca massa e prefere carnes macias. Verificar bebidas sem açúcar quando solicitado.
```

### 7.2 Exibição na comanda

Restrições importantes devem ser impressas na comanda, próximas ao item correspondente.

---

## 8. Item indisponível

O cliente frequentemente pede itens que podem não estar disponíveis no dia.

O sistema deve suportar:

- marcar produto/ingrediente como indisponível;
- sugerir substituição;
- registrar substituição aceita;
- impedir que a IA ofereça item indisponível;
- exibir alerta para atendente.

### 8.1 Fluxo sugerido

```txt
Cliente pede item indisponível
↓
Sistema alerta atendente ou IA
↓
Atendente oferece substituição
↓
Cliente aceita ou altera pedido
↓
Pedido é atualizado
```

---

## 9. Atendimento com urgência

Clientes podem pedir urgência ou informar situação sensível.

O sistema não deve tratar isso como regra médica, mas pode ajudar operacionalmente:

- marcar conversa como urgente;
- destacar pedido na fila;
- registrar observação;
- mostrar alerta de prioridade para o atendente;
- evitar prometer prazo impossível.

Frase recomendada:

```txt
Vamos verificar a disponibilidade e te confirmo o prazo certinho.
```

---

## 10. IA no atendimento real

A IA deve ajudar, mas não deve decidir sozinha quando houver ambiguidade.

### 10.1 A IA pode

- resumir mensagens do cliente;
- sugerir itens detectados;
- apontar dúvidas;
- sugerir resposta cordial;
- identificar possível crédito;
- detectar pedido recorrente;
- sugerir confirmação final.

### 10.2 A IA não deve

- confirmar pedido ambíguo sem validação;
- inventar item do cardápio;
- inventar preço;
- prometer entrega sem disponibilidade;
- ignorar restrição alimentar;
- usar dados sensíveis fora do contexto do pedido;
- aplicar crédito sem confirmação do atendente.

### 10.3 Confirmação final sugerida

Antes de fechar o pedido, a IA deve gerar um resumo simples:

```txt
Só confirmando seu pedido:

- 2 marmitas
- 1 refrigerante
- Entrega
- Total: R$ XX,XX

Posso confirmar?
```

---

## 11. Comanda e impressão

A comanda deve deixar claro:

- número do pedido;
- nome do cliente;
- pessoa que retira, se houver;
- itens separados;
- observações por item;
- restrições importantes;
- forma de pagamento;
- crédito usado ou saldo pendente;
- status do pagamento;
- tipo de entrega ou retirada.

A impressão deve ser obrigatória no fluxo operacional quando o pedido for enviado para preparo.

---

## 12. Campos extras recomendados no pedido

```ts
interface PedidoOperacional {
  clientePagadorId: string;
  nomeRetirante?: string;
  autorizadoPor?: string;
  observacaoRetirada?: string;
  pedidoRecorrenteReferenciaId?: string;
  creditoAplicado?: number;
  saldoPendente?: number;
  prioridade?: 'normal' | 'urgente';
  precisaConfirmacaoHumana: boolean;
  resumoIA?: string;
}
```

---

## 13. Checklist para fechar pedido

Antes de confirmar e imprimir, o sistema deve ajudar o atendente a validar:

- Quantidade de marmitas está correta?
- Bebidas foram incluídas?
- Entrega ou retirada foi definido?
- Pessoa que busca foi anotada?
- Observações por item foram registradas?
- Crédito foi aplicado corretamente?
- Valor final foi confirmado?
- Pagamento está pago, pendente ou em crédito?
- Comanda foi impressa?

---

## 14. Cuidados de privacidade

A conversa real de WhatsApp não deve ser commitada no repositório.

Não salvar em `docs/`:

- exportação bruta de WhatsApp;
- telefone real;
- CPF real;
- chave Pix real;
- endereço real;
- link de localização;
- comprovantes;
- prints de conversa;
- nomes de terceiros associados a dados sensíveis.

Este documento deve conter apenas requisitos generalizados e exemplos sanitizados.

---

## 15. Resumo do impacto no sistema

A partir deste documento, o ChatBotCRM deve ser pensado como um sistema para atendimento real, não apenas cadastro de pedidos simples.

Funcionalidades importantes:

- pedido em rascunho;
- extração de intenção da conversa;
- observações por item;
- perfil de preferências;
- crédito do cliente;
- pessoa de retirada;
- confirmação antes de fechar;
- impressão obrigatória;
- IA prudente;
- histórico completo.

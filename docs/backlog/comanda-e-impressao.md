# Comanda e Impressão — ChatBotCRM / Sol Restaurante

## 1. Objetivo

Este documento define como a comanda do pedido deve funcionar dentro do ChatBotCRM do Sol Restaurante.

A comanda não é apenas um comprovante visual. Ela faz parte do fluxo operacional obrigatório do restaurante. Depois que o pedido é conferido e confirmado, o sistema deve gerar a comanda e encaminhar a impressão para a cozinha/preparo.

A impressão deve ser simples, rápida, clara e resistente a erros comuns de atendimento real via WhatsApp, como pedidos enviados em várias mensagens, alterações de última hora, pagamento parcial, crédito do cliente, retirada por terceiros e observações específicas por marmita.

---

## 2. Princípio principal

A impressão da comanda pertence ao fluxo principal do pedido.

Fluxo esperado:

```txt
Pedido recebido
→ Conferência do pedido
→ Confirmação de itens, cliente, retirada/entrega e pagamento
→ Geração da comanda
→ Impressão obrigatória
→ Pedido liberado para preparo/cozinha
→ Entrega/retirada/finalização
```

A configuração de impressoras pode existir dentro do Hub/Configurações, mas a impressão operacional da comanda não deve ficar escondida em Configurações.

---

## 3. Quando gerar a comanda

A comanda deve ser gerada quando o pedido atingir um destes estados:

- pedido confirmado pelo atendente;
- pedido manual criado e salvo;
- pedido recebido do WhatsApp e conferido;
- pedido pago ou marcado como pagamento pendente autorizado;
- pedido alterado e reenviado para preparo;
- pedido reimpresso por necessidade operacional.

O sistema deve permitir reimpressão, mas deve deixar claro quando se tratar de segunda via.

---

## 4. Informações obrigatórias da comanda

### 4.1 Cabeçalho

A comanda deve conter:

- nome do restaurante;
- título: `COMANDA DE PEDIDO`;
- número do pedido;
- data e hora de criação;
- data e hora de impressão;
- canal de origem: WhatsApp, manual, balcão, telefone ou outro;
- tipo do pedido: retirada, entrega, balcão ou consumo/local, caso exista;
- indicação de segunda via, quando for reimpressão.

Exemplo:

```txt
SOL RESTAURANTE
COMANDA DE PEDIDO
Pedido #1024
Origem: WhatsApp
Tipo: Retirada
Criado em: 06/07/2026 11:32
Impresso em: 06/07/2026 11:34
```

---

### 4.2 Dados do cliente

A comanda deve conter:

- nome do cliente pagador;
- telefone, quando disponível;
- pessoa que vai retirar, quando for diferente do cliente;
- pessoa para quem a marmita está sendo feita, quando aplicável;
- observação de retirada/entrega.

Exemplo:

```txt
Cliente: [NOME_OPERACIONAL_REMOVIDO]
Retirada por: [NOME_OPERACIONAL_REMOVIDO]
Observação: cliente pediu para deixar separado e avisar quando estiver pronto.
```

Regras:

- O cliente pagador pode ser diferente de quem retira.
- Uma marmita pode ter dono específico, como “marmita da [NOME_OPERACIONAL_REMOVIDO]”, “marmita do [NOME_OPERACIONAL_REMOVIDO]”, “marmita do funcionário”.
- O sistema deve permitir observações por pessoa/marmita.

---

### 4.3 Itens do pedido

Cada item precisa aparecer de forma clara e separada.

Para cada item, exibir:

- quantidade;
- nome do produto;
- tamanho/tipo da marmita;
- preço unitário;
- subtotal do item;
- carnes escolhidas;
- acompanhamentos;
- saladas;
- adicionais;
- itens removidos;
- observações específicas;
- restrições alimentares;
- substituições por indisponibilidade.

Exemplo:

```txt
1x Marmitex N8 — R$ 14,00
Para: [NOME_OPERACIONAL_REMOVIDO]
Carnes: porco macio, frango ao molho
Acompanhamentos: arroz, feijão de caldo
Saladas/legumes: berinjela, repolho
Remover: macarrão, frituras
Observação: pouco arroz, caprichar nas carnes, carne com caldo.
```

Exemplo com item diferente no mesmo pedido:

```txt
1x Marmitex N8 — R$ 14,00
Para: [NOME_OPERACIONAL_REMOVIDO]
Carnes: variadas
Acompanhamentos: arroz, feijão, macarrão, maionese
Observação: cliente informou que ele pode montar/retirar.
```

---

### 4.4 Bebidas, extras e adicionais

A comanda deve listar bebidas e extras separadamente dos itens principais.

Exemplo:

```txt
BEBIDAS / EXTRAS
1x Coca-Cola 2L — R$ 13,00
1x Adicional de ovo — R$ 1,50
1x Taxa de entrega — R$ 5,00
```

Regras:

- Bebida normal e bebida zero devem ser diferenciadas.
- Tamanho/volume da bebida deve aparecer quando existir.
- Adicionais pagos devem aparecer com valor.
- Itens indisponíveis não devem ser impressos como se tivessem sido enviados; devem aparecer como substituídos ou removidos.

---

### 4.5 Pagamento

A comanda deve conter uma seção de pagamento.

Campos desejados:

- forma de pagamento: Pix, dinheiro, cartão, crédito do cliente, misto ou pendente;
- status do pagamento: pago, pendente, parcial, crédito usado, crédito gerado;
- valor total;
- valor pago;
- valor faltante;
- crédito utilizado;
- crédito gerado para próxima compra;
- observação de pagamento.

Exemplo:

```txt
PAGAMENTO
Forma: Pix
Status: Pago
Subtotal: R$ 41,00
Crédito usado: R$ 6,00
Total final: R$ 35,00
Valor recebido: R$ 35,00
Saldo/Crédito após pedido: R$ 0,00
```

Exemplo com pagamento parcial:

```txt
PAGAMENTO
Forma: Pix + Crédito
Status: Parcial
Total final: R$ 55,00
Valor recebido: R$ 54,00
Faltante: R$ 1,00
Observação: cliente informou que pagará a diferença depois.
```

---

### 4.6 Observações operacionais

A comanda deve conter uma área de observações gerais do pedido.

Exemplos de observações:

```txt
OBSERVAÇÕES DO PEDIDO
- Cliente pediu urgência.
- Conferir se a Coca Zero foi separada.
- Cliente pediu para avisar quando sair para entrega.
- Não comentar detalhes do pedido com terceiro.
- Pedido possui crédito/saldo em aberto.
```

Regras:

- Observações sensíveis devem ser tratadas com cuidado no sistema.
- A comanda de cozinha deve conter apenas o que é necessário para preparo e operação.
- Dados financeiros e sensíveis podem aparecer na via do atendimento/caixa, mas não necessariamente na via da cozinha.

---

## 5. Tipos de via

O sistema pode trabalhar com mais de uma visualização da comanda.

### 5.1 Via da cozinha

Foco em preparo:

- número do pedido;
- horário;
- itens;
- carnes;
- acompanhamentos;
- saladas;
- restrições;
- observações de preparo;
- urgência;
- nome da pessoa/marmita.

Não precisa mostrar todos os detalhes financeiros.

### 5.2 Via do caixa/atendimento

Foco em conferência:

- dados do cliente;
- itens;
- bebidas;
- forma de pagamento;
- créditos;
- faltantes;
- pessoa que retira;
- observações gerais.

### 5.3 Via de entrega/retirada

Foco em separação:

- nome do cliente;
- retirante;
- endereço, se for entrega;
- telefone, se necessário;
- quantidade de volumes;
- bebidas;
- pagamento pendente, se existir;
- observação de entrega.

---

## 6. Estados de impressão

A tela operacional deve exibir estados claros:

- `Aguardando impressão`;
- `Imprimindo`;
- `Impresso`;
- `Falha na impressão`;
- `Reimpressão solicitada`;
- `Impressora indisponível`.

Em caso de erro, o sistema deve mostrar ações objetivas:

- tentar imprimir novamente;
- escolher outra impressora;
- marcar como impresso manualmente, se permitido por perfil de usuário;
- abrir configuração da impressora;
- copiar texto da comanda para uso emergencial.

---

## 7. Regra de bloqueio operacional

Por padrão, um pedido confirmado não deve seguir para preparo sem comanda impressa ou sem confirmação manual autorizada.

Regra recomendada:

```txt
Se pedido.status = "confirmado" e pedido.comanda_impressa = false:
    bloquear avanço automático para "em preparo"
    exibir ação "Imprimir comanda"
```

Exceção:

Um usuário autorizado pode marcar manualmente como `impressão dispensada` ou `impresso manualmente`, mas o sistema deve registrar log.

---

## 8. Logs obrigatórios

Registrar eventos importantes:

- comanda gerada;
- comanda impressa;
- reimpressão;
- erro de impressão;
- alteração do pedido após impressão;
- impressão manualmente confirmada;
- mudança de impressora;
- pedido avançado para preparo sem impressão automática.

Campos úteis de log:

- usuário responsável;
- data/hora;
- pedido;
- impressora;
- status anterior;
- status novo;
- mensagem de erro, se houver.

---

## 9. Layout sugerido para impressão térmica

O layout deve ser simples e compatível com impressora térmica.

Exemplo conceitual:

```txt
================================
        SOL RESTAURANTE
        COMANDA DE PEDIDO
================================
Pedido: #1024
Origem: WhatsApp
Tipo: Retirada
Criado: 06/07/2026 11:32
Impresso: 06/07/2026 11:34
--------------------------------
Cliente: [NOME_OPERACIONAL_REMOVIDO]
Retirada por: [NOME_OPERACIONAL_REMOVIDO]
--------------------------------
ITENS

1x Marmitex N8
Para: [NOME_OPERACIONAL_REMOVIDO]
Carnes: porco macio, frango molho
Itens: arroz, feijão caldo,
berinjela, repolho
Remover: macarrão, frituras
Obs: pouco arroz, caprichar carnes

1x Marmitex N8
Para: [NOME_OPERACIONAL_REMOVIDO]
Carnes: variadas
Itens: arroz, feijão, macarrão,
maionese
Obs: pode montar/retirar
--------------------------------
BEBIDAS / EXTRAS
1x Coca-Cola 2L
1x Taxa de entrega
--------------------------------
PAGAMENTO
Forma: Pix
Status: Pago
Subtotal: R$ 41,00
Crédito usado: R$ 6,00
TOTAL: R$ 35,00
--------------------------------
OBSERVAÇÕES
Avisar cliente quando estiver pronto.
================================
```

Os valores acima são apenas exemplo de formato. Valores reais devem vir da base de dados e dos documentos oficiais do cardápio.

---

## 10. Regras para o Codex/front-end

Ao implementar a tela de comanda e impressão:

1. Não usar dados reais ou sensíveis nos mocks.
2. Não usar nomes, CPF, telefone, endereço ou localização reais.
3. Criar mocks fictícios e seguros.
4. Separar componente visual da lógica de impressão.
5. Criar componente reutilizável de prévia da comanda.
6. Permitir prévia antes da impressão.
7. Permitir reimpressão.
8. Exibir status da impressora.
9. Exibir status da comanda.
10. Manter o layout limpo, escuro e compatível com a identidade visual do projeto.
11. Preparar a integração futura com impressora térmica, mas sem travar o front se a integração real ainda não existir.

---

## 11. Componentes sugeridos

Sugestão de componentes:

```txt
src/features/impressao/
├── components/
│   ├── ComandaPreview.tsx
│   ├── PrintStatusBadge.tsx
│   ├── PrintActions.tsx
│   ├── PrinterSelector.tsx
│   └── ReceiptLineItem.tsx
├── pages/
│   └── ImpressaoPedidoPage.tsx
├── mocks/
│   └── impressao.mock.ts
├── types/
│   └── impressao.types.ts
└── utils/
    └── formatReceipt.ts
```

Também pode ser integrado dentro de `features/pedidos`, desde que a responsabilidade fique clara.

---

## 12. Critérios de aceite

A implementação estará correta quando:

- o atendente conseguir visualizar a prévia da comanda;
- a comanda mostrar itens separados por marmita/pessoa;
- houver observações por item;
- houver observações gerais;
- houver dados de pagamento e crédito;
- houver indicação de retirada/entrega;
- houver botão de imprimir;
- houver botão de reimprimir;
- houver tratamento de erro de impressão;
- houver estado de impressão concluída;
- o pedido não avançar automaticamente sem impressão ou confirmação manual autorizada;
- o código estiver organizado, componentizado e fácil de alterar.

---

## 13. Observação final

Este documento é uma regra operacional do produto. As telas geradas por imagem são apenas referência estética. A implementação deve seguir este documento e os demais documentos oficiais do projeto.

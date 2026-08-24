# Venda de Balcao MVP

## Base disponivel

Produtos de balcao usam o catalogo estruturado existente. Cada registro possui
nome, preco em centavos, categoria, descricao opcional, estado ativo e dias de
venda. O marcador `metadata.counter_sale` identifica o produto sem criar uma
fonte paralela de precos ou estoque.

Categorias iniciais: Doces, Geladinhos, Bebidas, Sucos e Outros. As categorias
sao criadas por empresa somente quando necessarias e o cadastro pode receber
uma foto publica no disco `public` do Laravel.

## Proxima etapa operacional

Uma tela de Venda de balcao deve criar um pedido operacional pelo servico de
pedidos ja existente, nunca gravar uma venda fora dele. O fluxo esperado e:

1. Selecionar um produto de balcao ativo e disponivel.
2. Informar quantidade e observacao opcional.
3. Escolher a forma de pagamento ja suportada pelo dominio.
4. Calcular o total somente no backend a partir do preco do produto.
5. Registrar o pedido e o pagamento pelo workflow operacional.

Isso preserva auditoria, isolamento por empresa, historico de preco e os
controles de pagamento existentes. Esta rodada nao cria uma rota de venda nem
altera o fluxo financeiro.

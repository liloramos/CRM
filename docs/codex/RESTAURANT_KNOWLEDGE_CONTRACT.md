# Restaurant Knowledge Contract — Restaurante Sol

## Objetivo
Definir o que a IA precisa saber e de onde cada informação deve vir. A IA deve parecer uma atendente viva porque recebe contexto correto, não porque inventa.

## Fontes canônicas
- Produtos/preços: banco + services de menu/catalog/pricing.
- Buffet do dia: menu semanal/dia + services estruturados.
- Carnes do dia: disponibilidade/menu diário.
- Adicionais/regras: domain services.
- Pagamento/Pix: settings + PaymentWorkflow.
- Entrega/taxa: DeliveryRoutingService + endereço/location.
- Horários: General Settings / operating hours / exceções.

## Perguntas suportadas
### Greeting
`oi`, `olá`, `opa`, `bom dia`, `boa tarde`, `boa noite`, `e aí`.

### Cardápio
`cardápio`, `manda o cardápio`, `qual o cardápio de hoje?`, `o que tem hoje?`.

### Produtos
`quanto custa a N8?`, `e a N9?`, `o que vem na N5?`, `qual a diferença Casa/Livre?`, `quantas carnes posso escolher?`.

### Buffet
`qual o buffet de hoje?`, `quais acompanhamentos tem?`, `tem batata doce?`.

### Carnes
`quais carnes tem hoje?`, `pode ser porco?`, `tem frango?`, `e churrasco?`, `tem bife?`.

### Pagamento
`aceita Pix?`, `qual a chave?`, `aceita dinheiro?`.

### Entrega
`vocês entregam?`, `qual a taxa?`, `posso mandar localização?`.

## Multi-intent
`quero uma N8 Livre, qual o buffet de hoje?` deve:
1. selecionar N8 Livre;
2. responder buffet;
3. preservar state;
4. perguntar próximo slot faltante.

Não descartar uma intenção porque outra veio primeiro.

## Resposta curta a slot
Se a IA perguntou `Qual carne você deseja?` e o cliente disser `pode ser porco`, interpretar no contexto do slot. Se disponível: meat=porco, automático, sem review. Se indisponível: informar e pedir alternativa, ainda sem review apenas por isso.

## Informação vs escolha
`Na N5 Casa vem arroz, feijão...` é informação, não seleção. `Sugiro almôndega` é sugestão, não escolha. Estado só muda quando intenção do cliente for suficiente.

## Hierarquia de verdade
1. domain service
2. banco/configuração atual
3. estado estruturado atual
4. contexto recente validado
5. interpretação do LLM

LLM nunca vence regra canônica.

## Fallback seguro
Sem fonte suficiente: dizer de forma amigável que precisa confirmar com a equipe. Se clarificação simples resolve, perguntar antes de human review.

## Anti-hallucination
Nunca inventar preço, promoção, carne, buffet, bebida, taxa, Pix, horário, ingrediente, quantidade permitida, regra de troca ou confirmação de pagamento.

## Cardápio útil
Deve explicar, quando aplicável: nome, preço, tamanho/descrição, Casa/Livre, quantidade de carne, composição, buffet do dia, carnes do dia e adicionais relevantes. Se longo, dividir em até 3 mensagens.

## Horário x greeting
Cliente dizer `bom dia` à noite continua sendo greeting. Pode responder de forma neutra ou time-aware, mas nunca “não entendi”. Operating hours só determinam aberto/fechado quando configurados.

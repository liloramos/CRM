# Prompts para Codex — Execução Completa do ChatBotCRM

Use estes prompts em sequência. A ideia é fazer o Codex trabalhar por etapas, com commits pequenos, mantendo o projeto organizado, fácil de estudar e fácil de alterar manualmente depois.

---

## Prompt 1 — Leitura, diagnóstico e plano de execução

```txt
Você está trabalhando no projeto ChatBotCRM / Sol Restaurante.

Antes de alterar qualquer arquivo, leia e entenda a estrutura do projeto. Analise principalmente:

- README.md
- docker-compose.yml
- backend/
- frontend/
- docs/
- docs/backlog/
- docs/front/frontend-guidelines.md
- docs/backlog/atendimento-real-whatsapp.md
- docs/backlog/comanda-e-impressao.md, se existir
- docs/CRM_DOCUMENTACAO_EXECUTAVEL_V1.9_COMPLEMENTO.md, se existir
- docs/backlog/codex/, se existir

Regras obrigatórias:
1. Não altere `.env`.
2. Não exponha chaves, tokens, senhas ou dados sensíveis.
3. Não use dados reais de WhatsApp, CPF, telefone, endereço, localização ou comprovantes nos mocks.
4. As imagens de referência visual devem ser usadas apenas como inspiração estética, nunca como fonte de regra de negócio.
5. As regras de negócio vêm dos documentos em `docs/` e `docs/backlog/`.
6. Preserve a arquitetura atual sempre que possível.
7. Se encontrar conflito entre imagem e documentação, siga a documentação.

Sua tarefa nesta etapa:
- mapear a estrutura atual;
- identificar tecnologias usadas no backend e frontend;
- identificar o que já existe e o que falta;
- propor um plano de execução em etapas pequenas;
- listar os arquivos que pretende criar/alterar;
- não codar ainda, apenas diagnosticar e planejar.

Ao final, gere um resumo técnico claro para eu revisar.
```

---

## Prompt 2 — Preparar base visual e design system do front

```txt
Agora implemente a base visual do front-end do ChatBotCRM seguindo `docs/front/frontend-guidelines.md`.

Objetivo:
Criar uma fundação limpa, bonita, padronizada e fácil de alterar manualmente depois.

Direção visual:
- tema escuro premium;
- preto/cinza grafite;
- detalhes em laranja/amarelo;
- estilo moderno SaaS;
- bordas arredondadas;
- cards com sombra/glow sutil;
- navegação lateral;
- ícones finos;
- hierarquia visual clara;
- interface simples para restaurante.

Implemente ou organize:
- tokens de cor;
- espaçamentos;
- radius;
- sombras;
- tipografia;
- componentes base.

Crie componentes reutilizáveis, se ainda não existirem:
- Button
- Input
- Textarea
- Select
- Card
- Badge
- Modal
- Tabs
- Table
- EmptyState
- LoadingState
- ErrorState
- PageHeader
- AppShell
- Sidebar
- Topbar
- StatCard

Regras:
1. Use TypeScript.
2. Evite CSS duplicado.
3. Evite componentes gigantes.
4. Use nomes claros.
5. Centralize dados mockados em `src/mocks`.
6. Não use dados reais.
7. Rode lint/build/test se existirem.
8. Corrija erros antes de finalizar.

Faça um commit ao final com a mensagem:
`chore(front): organizar base visual e componentes reutilizáveis`

Depois explique:
- o que foi criado;
- onde alterar cores;
- onde alterar componentes;
- como criar uma nova tela seguindo o padrão.
```

---

## Prompt 3 — Implementar shell, navegação e rotas principais

```txt
Implemente o shell principal do sistema e as rotas/telas base do ChatBotCRM.

Use a identidade visual definida em `docs/front/frontend-guidelines.md`.

Crie ou organize:
- layout principal com sidebar;
- topbar;
- navegação lateral;
- estrutura de rotas;
- páginas placeholder bonitas e padronizadas para cada módulo.

Módulos/telas planejadas:
- Login
- Cadastro
- Dashboard
- Conversas / Atendimento
- Pedidos
- Cardápio
- Entregas
- Financeiro
- Perfil do usuário
- Perfil do cliente
- Detalhes do pedido
- Comanda / Prévia de impressão
- Novo pedido manual
- Configurações Geral
- Configurações Hub
- Usuários e Permissões
- Pagamentos / Pix
- IA e Automação
- Aparência e Marca
- Segurança
- Relatórios
- WhatsApp / API técnico

Regras:
1. Não implemente regra de negócio complexa ainda.
2. Priorize organização, navegação e consistência visual.
3. Use componentes reutilizáveis já criados.
4. Não use dados reais.
5. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(front): criar shell principal e rotas do sistema`

Explique ao final como a navegação foi organizada.
```

---

## Prompt 4 — Implementar domínio de pedidos e fluxo operacional

```txt
Implemente as telas e componentes do domínio de pedidos do ChatBotCRM.

Leia antes:
- docs/backlog/
- docs/backlog/atendimento-real-whatsapp.md
- docs/backlog/comanda-e-impressao.md
- docs/CRM_DOCUMENTACAO_EXECUTAVEL_V1.9_COMPLEMENTO.md, se existir

Objetivo:
Criar uma experiência operacional simples para restaurante lidar com pedidos reais vindos do WhatsApp, balcão ou pedido manual.

Implemente:
- lista de pedidos;
- detalhes do pedido;
- novo pedido manual;
- edição de itens;
- alteração de status;
- cancelamento de pedido;
- observações gerais;
- observações por item;
- identificação de cliente pagador;
- identificação de pessoa que retira;
- identificação de pessoa para quem a marmita é feita;
- pagamento/status de pagamento;
- crédito usado ou gerado;
- fluxo visual até impressão da comanda.

Estados/status sugeridos:
- novo;
- em conferência;
- aguardando pagamento;
- confirmado;
- aguardando impressão;
- impresso;
- em preparo;
- pronto;
- saiu para entrega;
- finalizado;
- cancelado.

Modais obrigatórias relacionadas:
- cancelar pedido;
- alterar status do pedido;
- editar item do pedido;
- marcar item indisponível.

Regras importantes:
1. Pedido pode começar incompleto e ser completado depois.
2. Cliente pode mandar pedido fragmentado em várias mensagens.
3. Cliente pode dizer “igual ontem” ou “o de sempre”. O front deve abrir espaço para histórico/preferências, mesmo que mockado por enquanto.
4. Cliente pagador pode ser diferente de quem retira.
5. Uma marmita pode ter observação própria.
6. Não misture lógica pesada diretamente no JSX.
7. Use types e mocks centralizados.
8. Não use dados reais.
9. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(pedidos): implementar fluxo operacional de pedidos`

Explique ao final quais arquivos controlam pedidos, status, mocks e modais.
```

---

## Prompt 5 — Implementar comanda e impressão operacional

```txt
Implemente a área operacional de Comanda / Prévia de impressão seguindo `docs/backlog/comanda-e-impressao.md`.

A impressão da comanda faz parte do fluxo final do pedido. Ela não deve ser tratada apenas como configuração técnica.

Fluxo esperado:
Pedido recebido → conferência → confirmação → geração da comanda → impressão obrigatória → preparo.

Implemente:
- página ou etapa operacional de impressão do pedido;
- prévia visual da comanda;
- status de impressão;
- botão Imprimir comanda;
- botão Reimprimir;
- estado de erro de impressão;
- indicação de segunda via;
- bloqueio visual para pedido confirmado ainda não impresso;
- confirmação manual autorizada, se necessário;
- logs/mock de eventos de impressão.

A comanda deve exibir:
- cabeçalho do restaurante;
- número do pedido;
- data/hora;
- canal de origem;
- tipo: retirada/entrega/balcão;
- cliente pagador;
- pessoa que retira;
- itens separados por marmita/pessoa;
- carnes;
- acompanhamentos;
- saladas;
- adicionais;
- itens removidos;
- observações por item;
- bebidas/extras;
- pagamento;
- crédito usado/gerado;
- total;
- observações gerais.

Regras:
1. Use dados fictícios e seguros nos mocks.
2. Não use dados reais de clientes.
3. Separe componente de prévia da comanda da lógica de impressão.
4. Prepare a estrutura para integração futura com impressora térmica.
5. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(impressao): implementar prévia e fluxo operacional de comanda`

Explique ao final como adaptar o layout da comanda e onde ficaria a integração real com impressora.
```

---

## Prompt 6 — Implementar clientes, histórico e preferências

```txt
Implemente o módulo de clientes do ChatBotCRM.

Objetivo:
Ajudar o restaurante a atender clientes recorrentes, inclusive clientes que pedem “igual ontem”, têm preferências alimentares, créditos pendentes ou costumam mandar terceiros retirarem.

Implemente:
- perfil do cliente;
- histórico de pedidos;
- preferências recorrentes;
- restrições alimentares;
- observações importantes;
- saldo/crédito do cliente;
- pessoas associadas ao cliente, como retirantes frequentes;
- atalho para criar novo pedido a partir do histórico;
- resumo de últimos pedidos.

Regras:
1. Não use dados reais.
2. Use mocks fictícios.
3. Use componentes reutilizáveis.
4. Mantenha layout limpo e fácil de entender.
5. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(clientes): implementar perfil, histórico e preferências do cliente`

Explique onde alterar preferências, histórico e dados mockados.
```

---

## Prompt 7 — Implementar conversas e atendimento com apoio de IA

```txt
Implemente ou refine o módulo de Conversas / Atendimento.

Objetivo:
Criar uma tela simples para o atendente acompanhar conversas, interpretar pedidos e transformar mensagens em pedidos conferíveis.

A tela deve considerar atendimento real via WhatsApp:
- mensagens fragmentadas;
- cliente que muda pedido;
- áudio/mídia como item não interpretado ainda;
- comprovante enviado;
- pedido para terceiro retirar;
- pergunta de valor;
- item indisponível;
- urgência;
- pagamento parcial/crédito.

Implemente:
- lista de conversas;
- painel de mensagens;
- painel lateral com cliente e pedido em montagem;
- botão para criar pedido a partir da conversa;
- destaque de informações detectadas;
- alternância IA/manual;
- sugestão de resposta da IA;
- alerta de ambiguidade;
- estado vazio de conversas;
- estado de erro de WhatsApp/API.

Regras:
1. IA deve apoiar, não decidir sozinha quando houver ambiguidade.
2. Quando faltar informação, a UI deve sugerir confirmação simples.
3. Não use dados reais.
4. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(atendimento): implementar conversas com apoio de IA e montagem de pedido`

Explique ao final como a tela se conecta futuramente com API do WhatsApp.
```

---

## Prompt 8 — Implementar cardápio, produtos e indisponibilidade

```txt
Implemente o módulo de Cardápio / Produtos do ChatBotCRM.

Objetivo:
Permitir que o restaurante gerencie marmitas, bebidas, extras, adicionais e indisponibilidade de itens.

Leia os documentos oficiais do cardápio em `docs/` e `docs/backlog/`. As imagens não são fonte de preço ou produto definitivo.

Implemente:
- listagem de produtos;
- categorias;
- marmitas;
- bebidas;
- extras/adicionais;
- status disponível/indisponível;
- modal adicionar novo produto;
- modal marcar item indisponível;
- edição básica de produto;
- aviso visual quando item está indisponível.

Regras:
1. Produtos, preços e cardápio devem seguir documentos oficiais, não imagens.
2. Se ainda não houver integração real, use mocks centralizados.
3. Não invente produtos fora do domínio de marmitas do restaurante.
4. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(cardapio): implementar produtos, categorias e indisponibilidade`

Explique ao final onde alterar produtos e categorias.
```

---

## Prompt 9 — Implementar pagamentos, Pix, crédito e financeiro operacional

```txt
Implemente os módulos de Pagamentos / Pix e Financeiro operacional.

Objetivo:
Dar suporte visual e estrutural para pagamentos reais do restaurante, incluindo Pix, dinheiro, cartão, pagamento parcial, valor faltante e crédito do cliente.

Implemente:
- tela de pagamentos/Pix;
- confirmação de pagamento Pix;
- status de pagamento;
- valor recebido;
- valor faltante;
- crédito utilizado;
- crédito gerado;
- histórico financeiro do cliente;
- resumo financeiro simples do dia;
- alertas de pagamento pendente.

Regras:
1. Não armazene chaves Pix reais nos mocks.
2. Não use comprovantes reais.
3. Não use CPF, telefone ou dados bancários reais.
4. Prepare a estrutura para futura integração real com API de pagamento.
5. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(pagamentos): implementar pix, status de pagamento e crédito do cliente`

Explique ao final como conectar futuramente com uma API real de pagamento.
```

---

## Prompt 10 — Implementar entregas e retirada

```txt
Implemente ou refine o módulo de Entregas / Retirada.

Objetivo:
Controlar se o pedido será entregue, retirado no balcão ou retirado por terceiro.

Implemente:
- lista de entregas/retiradas;
- status de entrega;
- pessoa que vai retirar;
- observações de entrega;
- taxa de entrega;
- alerta de pedido pronto aguardando retirada;
- registro de saída para entrega;
- registro de finalização.

Regras:
1. Cliente pagador pode ser diferente de quem retira.
2. A entrega pode ficar indisponível em alguns momentos; o sistema deve permitir retirada.
3. Não use endereços reais nos mocks.
4. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(entregas): implementar controle de entrega e retirada`

Explique ao final onde ficam os status de entrega/retirada.
```

---

## Prompt 11 — Implementar configurações, usuários e integrações

```txt
Implemente as telas de configuração do ChatBotCRM.

Módulos:
- Configurações Geral
- Configurações Hub
- Usuários e Permissões
- IA e Automação
- Aparência e Marca
- Segurança
- WhatsApp / API técnico
- Configuração técnica de impressoras

Importante:
A tela WhatsApp / API técnico é configuração de integração, não tela de conversa.
A configuração de impressora é técnica, mas a impressão da comanda é fluxo operacional.

Implemente:
- cards de configuração;
- status de integração;
- formulários seguros;
- placeholders para tokens sem expor valores;
- permissões por perfil;
- visual de segurança;
- aparência/marca;
- IA e automações;
- WhatsApp/API técnico;
- impressoras configuráveis.

Regras:
1. Nunca hardcode tokens reais.
2. Nunca exiba segredos completos na UI.
3. Use placeholders seguros.
4. Mantenha estrutura preparada para integração futura.
5. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(config): implementar configurações, usuários e integrações técnicas`

Explique ao final como adicionar futuramente API real do WhatsApp, impressora e automações.
```

---

## Prompt 12 — Implementar relatórios e dashboard final

```txt
Implemente o módulo de Relatórios e refine o Dashboard.

Objetivo:
Mostrar visão simples da operação do restaurante.

Implemente:
- vendas do dia;
- quantidade de pedidos;
- pedidos por status;
- pagamentos pendentes;
- clientes recorrentes;
- produtos mais vendidos;
- alertas operacionais;
- resumo de atendimento;
- gráficos simples e legíveis.

Regras:
1. Use dados mockados centralizados.
2. Não use dados reais.
3. Mantenha visual limpo, escuro e profissional.
4. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`feat(relatorios): implementar dashboard e relatórios operacionais`

Explique ao final como trocar mocks por dados da API real.
```

---

## Prompt 13 — Revisão geral, responsividade, estados e polimento

```txt
Faça uma revisão geral do front-end do ChatBotCRM.

Objetivo:
Deixar a aplicação consistente, bonita, organizada, responsiva e fácil de manter.

Revise:
- consistência visual;
- espaçamentos;
- cores;
- responsividade;
- estados vazios;
- loading;
- erro/conexão indisponível;
- modais;
- navegação;
- acessibilidade básica;
- textos em português;
- organização de arquivos;
- duplicação de código;
- mocks centralizados;
- types;
- constants;
- formatadores.

Estados obrigatórios:
- Estado vazio de pedidos;
- Estado vazio de conversas;
- Estado de carregamento;
- Estado de erro/conexão indisponível.

Modais obrigatórias:
- Confirmar pagamento Pix;
- Cancelar pedido;
- Alterar status do pedido;
- Editar item do pedido;
- Marcar item indisponível;
- Adicionar novo produto;
- Adicionar usuário;
- Alternar IA/manual;
- Erro de impressão;
- Erro de WhatsApp/API.

Regras:
1. Não faça refatoração gigante desnecessária.
2. Preserve a clareza para eu conseguir estudar e alterar depois.
3. Rode lint/build/test se existirem.
4. Corrija erros.

Faça um commit ao final com a mensagem:
`refactor(front): revisar consistência visual, estados e organização`

Explique ao final o que foi ajustado.
```

---

## Prompt 14 — Documentação final do front e guia para o Murilo alterar depois

```txt
Crie ou atualize a documentação do front-end explicando como eu, Murilo, posso entender e alterar o sistema depois.

Crie/atualize um arquivo como:
`docs/front/como-alterar-front.md`

Explique de forma prática:
- estrutura de pastas do front;
- onde ficam os componentes UI;
- onde ficam as telas;
- onde ficam mocks;
- onde ficam tipos;
- onde ficam constantes;
- onde alterar cores;
- onde alterar menu lateral;
- onde alterar textos;
- como criar uma nova tela;
- como criar um novo modal;
- como trocar mocks por API real;
- como adicionar uma integração futura;
- quais arquivos estudar primeiro.

Também atualize o README se fizer sentido, sem deixar com cara genérica de IA.

Regras:
1. Não documente segredos.
2. Não coloque dados sensíveis.
3. Use linguagem clara e profissional.
4. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`docs(front): documentar estrutura e manutenção do front-end`

Ao final, gere um resumo final da implementação completa.
```

---

## Prompt 15 — Preparar integração futura com APIs reais sem implementar segredo

```txt
Prepare a estrutura do front para integrações futuras sem usar credenciais reais.

Integrações futuras previstas:
- API do backend Laravel;
- WhatsApp/API;
- impressão térmica;
- pagamentos/Pix;
- IA/automação;
- notificações;
- relatórios reais.

Implemente somente a estrutura segura:
- services organizados;
- clients HTTP, se já houver padrão no projeto;
- arquivos de tipos;
- interfaces claras;
- adaptadores/mappers;
- funções placeholder com TODOs seguros;
- tratamento de loading/erro;
- variáveis de ambiente apenas referenciadas, nunca preenchidas com segredo.

Regras:
1. Não criar `.env` com credenciais reais.
2. Não commitar tokens.
3. Não quebrar mocks existentes.
4. Deixar claro onde plugar APIs reais depois.
5. Rode lint/build/test se existirem.

Faça um commit ao final com a mensagem:
`chore(front): preparar camada de services para integrações futuras`

Explique ao final como adicionar a API real do WhatsApp e outras features sem bagunçar o front.
```

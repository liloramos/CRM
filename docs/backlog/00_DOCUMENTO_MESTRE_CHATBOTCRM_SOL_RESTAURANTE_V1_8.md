# ChatBotCRM - Sol Restaurante

**Documento Mestre de Produto, Operação, Arquitetura e Backlog Geral - Versão 1.8**  
**Data:** 05/07/2026
**Responsável técnico:** Murilo - Super-admin / Desenvolvedor  
**Empresa piloto:** Sol Restaurante

## 1. Propósito deste documento
Este documento é a fonte central do projeto CRM/ChatBotCRM. Ele consolida tudo que já foi conversado e decidido até agora: dor do cliente, operação real do restaurante, regras de negócio, visão de produto, tecnologias, arquitetura, módulos, integrações, backlog, riscos, pendências e plano de execução.

A função prática dele é servir como **backlog mestre pai**. A partir dele, serão gerados arquivos-filhos por módulo dentro da pasta do projeto, com instruções pequenas e objetivas para o Codex executar fase por fase, sem tentar construir tudo de uma vez.

## 2. Visão estratégica
O ChatBotCRM começa como uma solução real para automatizar atendimento, pedidos e comandas do Sol Restaurante pelo WhatsApp. A V1 deve ser extremamente confiável no horário de almoço, porque a prioridade não é impressionar com recursos demais, mas reduzir erro e acelerar a operação.

A visão maior é transformar o núcleo em um CRM/SaaS para restaurantes e pequenos negócios, com planner, agenda, funil, prospecção, dashboards, estatísticas, vendas diárias, rendimentos, financeiro, lucro, gastos, eficiência operacional e automações de IA.

Princípio estratégico: primeiro construir um núcleo operacional perfeito para o Sol Restaurante; depois generalizar para produto escalável.

## 3. Fontes e insumos usados
- Documento de requisitos do Sol Restaurante V1.
- Documento de cardápios Marmitex semanais e marmitas da casa.
- Conversas do projeto CRM sobre banco, Laravel, PostgreSQL, BPMN, telas, recibos, WhatsApp, Pix, entrega e IA.
- BPMNs já criados: atendimento do cliente, montagem de pedido, impressão de comanda, IA/n8n futuro e login/autenticação.
- Decisões recentes: CEP/endereço, regra de entrega por km, Pix, horário, permissões e escolha estratégica de WhatsApp oficial.

## 4. Decisões fechadas até a V1.8
| Tema | Decisão | Impacto no sistema |
| --- | --- | --- |
| Empresa piloto | Sol Restaurante | O projeto nasce em operação real e depois vira base SaaS. |
| Responsável técnico | Murilo - super-admin/desenvolvedor | Acesso total e decisões técnicas. |
| Usuários iniciais | Admin/Gerente piloto; Atendente piloto | Permissões separadas por papel. |
| Dono/gestão futura | Gestor proprietário; perfil de cozinha | Perfis futuros para visão gerencial e cozinha. |
| Telefone/Pix | Dados operacionais privados e configuraveis | Cadastro em company_settings; valores reais nao devem ser versionados. |
| Horário de operação | 10:00 às 14:00 | IA deve respeitar esse intervalo. |
| Endereco origem | Endereco operacional privado e configuravel | Base para calculo de entrega em delivery_settings/company_settings; valor real nao deve ser versionado. |
| Entrega | distância_km x R$ 2,00 | Regra base informada pela operação piloto. |
| Acréscimo entrega | 10% sobre a taxa de entrega | Não incide sobre produtos; deve ser configurável. |
| Pix V1 | Chave Pix textual + comprovante via WhatsApp + confirmação humana | Sem API bancária na V1; QR Code não é obrigatório. |
| Pagamentos | Pix, dinheiro, cartão de débito, cartão de crédito | Pedido guarda método e status. |
| N8 com bife | R$ 20,00 somente bife; R$ 23,00 com outras carnes/adicional | Regra de preço específica. |
| Ovo | Adicional de R$ 2,00 | Produto adicional vendável. |
| Pedido | Numeração sequencial diária permitida | Pode reiniciar em 0001 todo dia. |
| WhatsApp | Meta Cloud API oficial como caminho principal | Não usar API não oficial em produção. |
| Impressora | Epson TM-T20X 031, modelo M352A, bivolt 100-240V, 50-60Hz, 1.0A | V1 começa com HTML/PDF pelo navegador; V1.1 valida driver Epson e bobina; V2 pode usar ESC/POS/agente local para impressão direta/silenciosa. |
| QR Code Pix | Não será necessário na V1 | Sistema deve enviar chave Pix textual; campo de QR deve ser opcional/nullable para evolução futura, mas não bloqueia produção. |
| Google Workspace futuro | Usar integrações Google como camada opcional para agenda, planner, tarefas, relatórios e exportações | Não bloquear a V1 operacional; criar arquitetura com provider plugável e OAuth por usuário/empresa. |


## 5. Estado atual do projeto
- Banco inicial V1 já possui `companies`, `customers`, `conversations` e `messages`.
- Migrations criadas.
- Models configurados.
- Relacionamentos Eloquent funcionando.
- Seeders funcionando.
- Validação com `migrate:fresh --seed` já realizada.
- README atualizado.
- BPMNs principais gerados.
- Documento mestre atualizado para V1.8 com impressora Epson TM-T20X confirmada, estratégia de impressão refinada e integrações Google planejadas para planner/agenda/funil/SaaS futuro.
- Próxima etapa recomendada: estruturar o **Módulo Restaurante - Banco V1**.

## 6. Tecnologias e ferramentas mapeadas
| Camada | Tecnologia/Ferramenta | Uso no projeto |
| --- | --- | --- |
| Backend | Laravel | Projeto atual usa Laravel com migrations, models, seeders e Eloquent. |
| Linguagem backend | PHP | Ambiente local já validado anteriormente com PHP 8.x. |
| ORM | Eloquent | Relacionamentos já funcionando na V1 do banco. |
| Banco | PostgreSQL | Base recomendada/planejada para CRM relacional e escalável. |
| Admin DB | DBeaver/pgAdmin | Ferramentas de apoio para visualizar tabelas e dados. |
| Documentação de processos | BPMN 2.0 | Fluxos criados para atendimento, pedido, comanda, IA/n8n e login. |
| Modelador BPMN | Camunda Modeler ou extensão BPMN no VS Code | Abertura visual dos arquivos .bpmn. |
| WhatsApp produção | Meta WhatsApp Cloud API | Integração oficial via webhooks e API HTTP. |
| WhatsApp dev | FakeWhatsAppProvider | Simulação local sem depender da Meta. |
| IA futura | n8n + camada de orquestração | Automação com fallback humano e regras de negócio. |
| Impressão V1 | HTML/PDF do navegador | Caminho inicial validável com a Epson TM-T20X sem depender de integração nativa. |
| Impressão futura | ESC/POS, driver Epson, QZ Tray ou agente local | Necessário para impressão direta/silenciosa, corte e controle fino da térmica. |
| Google Calendar API | Google Workspace | Agenda, compromissos, planner e follow-ups sincronizados com o calendário do usuário. |
| Google Tasks API | Google Workspace | Tarefas operacionais, follow-ups e checklist de atendimento/prospecção. |
| Google Sheets/Drive API | Google Workspace | Exportações, relatórios, backups e documentos comerciais opcionais. |
| Google Maps/Routes | Google Maps Platform | Cálculo futuro de distância/tempo de entrega por rota, substituindo distância manual. |


Observação: qualquer tecnologia de frontend ainda não confirmada deve ser tratada como pendente. O documento prepara requisitos de tela e UX, mas a decisão final de stack visual deve ser confirmada antes de gerar código de produção no frontend.

## 7. Dor do cliente e problema operacional
| Dor | Como acontece hoje | Resposta do sistema |
| --- | --- | --- |
| Pedido desorganizado no WhatsApp | Atendente precisa entender mensagem livre, áudio, imagem e observações soltas. | Pedido estruturado com etapas e confirmação final. |
| Cardápio muda no dia | Item acaba e cliente continua pedindo algo indisponível. | Painel de disponibilidade em tempo real que a IA respeita. |
| Cliente leigo ou idoso | Conversa pode ficar confusa e demorada. | IA com linguagem simples e fallback para atendimento humano. |
| Pix por comprovante | Risco de dizer que pagou sem conferência. | Anexar comprovante e exigir confirmação humana. |
| Entrega manual | Valor pode ser calculado diferente a cada atendimento. | Regra km x R$ 2,00 + 10% configurável com memória de cálculo. |
| Cozinha precisa de clareza | Pedido mal impresso gera marmita errada. | Comanda limpa, legível, com itens, carnes, adicionais e observações. |
| Horário de pico 10h-14h | Sistema lento ou poluído atrapalha. | Tela minimalista, botões rápidos, status por cor e fila clara. |
| WhatsApp é canal principal | Bloqueio do número derruba a venda. | Usar integração oficial e provider plugável. |


## 8. Como a operação deve funcionar na V1
1. Cliente chama no WhatsApp.
2. Sistema identifica ou cria o cliente.
3. Sistema cria/atualiza conversa vinculada ao cliente e à empresa.
4. IA verifica horário de atendimento: 10:00 às 14:00.
5. IA cumprimenta e oferece o cardápio do dia.
6. IA envia cardápio textual, nunca imagem como fonte principal.
7. Cliente escolhe marmita/produto.
8. IA pergunta informações faltantes: tipo de marmita, carne, salada, bebida, entrega/retirada, endereço, pagamento e observações.
9. Sistema valida regras de negócio.
10. Sistema calcula produtos, adicionais e entrega.
11. IA envia resumo do pedido.
12. Cliente confirma.
13. Pedido é salvo.
14. Se Pix, sistema envia a chave Pix textual do restaurante.
15. Cliente envia comprovante.
16. Sistema anexa comprovante ao pedido.
17. Atendente ou gerente confirma pagamento.
18. Atendente imprime a comanda.
19. Cozinha prepara.
20. Pedido muda de status até finalização.

## 9. Perfis, papéis e permissões
- **Super-admin / Desenvolvedor:** acesso total técnico, configurações globais, empresas, usuários, permissões, integrações, auditoria, ajustes críticos e manutenção.
- **Admin/Gerente piloto:** gestão operacional do restaurante, cardápio, disponibilidade, pedidos, pagamentos, impressão, usuários operacionais e dashboards.
- **Atendente piloto:** conversas, montagem/edição de pedido, status, impressão e atendimento manual.
- **Dono/Gestor:** visão gerencial futura, relatórios, rendimentos, vendas, custos e desempenho.
- **Cozinha:** visão/recebimento de comandas, preparo e status de cozinha em fase futura.
- **Usuários adicionais:** criados com papéis e permissões específicas.

## 10. Regras de negócio consolidadas
### 10.1 Cardápio e marmitas
- N8 e N9 tradicionais usam o cardápio semanal completo do dia.
- N5 Casa e N8 Casa possuem regras próprias.
- N5 Casa: arroz, feijão, macarrão, mandioca, salada escolhida pela casa entre beterraba ou cenoura, e 1 pedaço de uma única carne.
- N8 Casa: arroz, feijão, macarrão, mandioca, salada escolhida pelo cliente entre repolho com tomate, vinagrete, beterraba ou cenoura, e 2 pedaços de uma única carne.
- Nas marmitas da casa não pode misturar carnes.
- Itens indisponíveis devem sumir do atendimento da IA e aparecer como esgotados no painel.

### 10.2 Preços e adicionais relevantes
- N5 Casa: R$ 8,00.
- N8 Casa: R$ 13,00.
- N8 tradicional: R$ 16,00.
- N9 tradicional: R$ 18,00.
- N8 com bife somente bife: R$ 20,00.
- N8 com bife e outras carnes/adicional: R$ 23,00.
- Ovo frito adicional: R$ 2,00.
- Combo N8 Casa Baby: R$ 15,00.
- Combo N8 com latinha: R$ 20,00.
- Sucos: R$ 7,00.
- Todas as latas: R$ 5,00.
- Versões Zero seguem o mesmo preço das versões normais equivalentes.

### 10.3 Pix e pagamentos
- Métodos aceitos: Pix, dinheiro, cartão de débito e cartão de crédito.
- Chave Pix: configurada de forma privada em company_settings, sem valor real versionado.
- QR Code Pix fixo não será necessário na V1; o sistema deve enviar a chave Pix configurada em texto pelo WhatsApp.
- V1 não depende de API bancária.
- Cliente paga pelo próprio banco e envia comprovante pelo WhatsApp.
- Comprovante deve ser anexado ao pedido.
- Confirmação final do pagamento deve ser humana no início.

### 10.4 Entrega
- Origem: endereco operacional privado configurado em delivery_settings/company_settings.
- Fórmula base: `delivery_fee_base = distance_km * 2.00`.
- Fórmula com acréscimo: `delivery_fee_final = (distance_km * 2.00) * 1.10`.
- O acréscimo de 10% incide somente sobre a entrega, nunca sobre os produtos.
- A regra deve ser configurável no painel.
- V1 pode ter distância manual ou zona/bairro; V1.5 pode integrar Google Maps/Routes.
- Pendências: arredondamento, taxa mínima, distância máxima, cidade/UF e bairros atendidos.

### 10.5 WhatsApp
- Produção deve usar Meta Cloud API direta ou BSP oficial.
- API não oficial não deve ser usada no número real do restaurante.
- O sistema deve ter `WhatsAppProviderInterface`.
- Em desenvolvimento, usar `FakeWhatsAppProvider`.
- Em produção, usar `MetaCloudWhatsAppProvider`.
- Guardar webhooks brutos para auditoria e reprocessamento.

### 10.6 Impressão e Epson TM-T20X
- Impressora confirmada: Epson TM-T20X 031.
- Modelo técnico informado: M352A.
- Alimentação informada: 100-240V, 50-60Hz, 1.0A.
- Numero de serie da impressora: dado interno, nao deve ser versionado.
- Estratégia V1: gerar comanda HTML/PDF otimizada para impressão pelo navegador.
- Estratégia V1.1: instalar driver Epson no computador do restaurante e validar largura da bobina, corte e qualidade de impressão.
- Estratégia V2: avaliar impressão direta/silenciosa com ESC/POS, QZ Tray ou agente local próprio.
- O sistema deve registrar fila de impressão, status do job, usuário que imprimiu, falha e reimpressão.
- O backend não deve depender de conexão direta com USB; uma aplicação web precisa de browser/driver ou agente local para falar com impressora física.

### 10.7 Integrações Google futuras
- O núcleo do ChatBotCRM deve continuar próprio e independente; Google entra como integração opcional por empresa/usuário.
- Google Calendar pode sincronizar agenda, follow-ups, tarefas de atendimento, visitas, entregas especiais e lembretes.
- Google Tasks pode sincronizar tarefas pessoais/operacionais do usuário, desde que a integração seja habilitada por consentimento OAuth.
- Google Sheets/Drive podem ser usados para exportação de relatórios, backups operacionais, planilhas financeiras e documentos gerenciais.
- Gmail deve ser evitado na V1 por exigir escopos mais sensíveis/restritos; só entrar se houver necessidade clara de CRM de e-mail.
- Google Maps/Routes deve ser usado primeiro para entrega por km/tempo, porque está diretamente ligado à operação atual.
- Todas as integrações devem usar escopos mínimos e guardar tokens criptografados.

## 11. Status operacionais
| Status | Significado |
| --- | --- |
| draft | Pedido ainda sendo montado. |
| awaiting_customer_confirmation | Resumo enviado; aguardando cliente confirmar. |
| confirmed | Cliente confirmou o pedido. |
| awaiting_payment | Aguardando pagamento. |
| awaiting_payment_proof | Aguardando comprovante Pix. |
| payment_proof_received | Comprovante recebido e pendente de conferência. |
| payment_confirmed | Pagamento confirmado por usuário autorizado. |
| payment_rejected | Comprovante rejeitado ou divergente. |
| ready_to_print | Pedido liberado para comanda. |
| printed | Comanda impressa. |
| in_preparation | Cozinha está preparando. |
| ready_for_pickup | Pedido pronto para retirada. |
| out_for_delivery | Saiu para entrega. |
| finished | Pedido finalizado. |
| cancelled | Pedido cancelado. |


## 12. Módulos macro do backlog
| Código | Módulo | Prioridade | Objetivo |
| --- | --- | --- | --- |
| M00 | Preparação do repositório e padrões | Alta | Organizar pasta docs/backlog, providers, convenções e variáveis de ambiente. |
| M01 | Autenticação, usuários e permissões | Alta | Login, roles, permissões e criação de usuários por empresa. |
| M02 | Restaurante - Banco V1 | Altíssima | Criar núcleo de produtos, cardápio, pedidos, pagamentos, entrega e impressão. |
| M03 | Catálogo, cardápio e disponibilidade | Altíssima | Produtos, preços, N5/N8 Casa, N8/N9 tradicional e itens esgotados. |
| M04 | Pedidos e status operacionais | Altíssima | Pedido estruturado, itens, observações, status e histórico. |
| M05 | Pix por comprovante e pagamentos | Alta | Chave Pix textual, comprovante, confirmação/rejeição humana; QR opcional fora do escopo obrigatório da V1. |
| M06 | Entrega e cálculo por km | Alta | Distância, taxa, 10%, arredondamento configurável e memória de cálculo. |
| M07 | Comanda e impressão | Altíssima | Comanda HTML/PDF, fila, reimpressão e preparo para térmica. |
| M08 | WhatsApp Provider oficial | Alta | Fake provider local e Meta Cloud API preparada por interface. |
| M09 | IA/n8n e fallback manual | Média/Alta | Orquestração, confiança, handoff e regras de atendimento. |
| M10 | Frontend operacional V1 | Alta | Tela de conversas, pedidos, status, impressão e cardápio do dia. |
| M11 | Dashboard diário e financeiro básico | Média | Vendas do dia, pagamentos pendentes, rendimentos e totais. |
| M12 | Roadmap CRM/SaaS | Futura | Planner, funil, prospecção, agenda, estatísticas e multiempresa avançado. |
| M13 | Google Workspace e integrações futuras | Futura | Conectar Calendar, Tasks, Sheets/Drive e Maps de forma opcional e segura, sem travar a V1. |


## 13. Modelo de dados recomendado
| Área | Tabelas sugeridas | Finalidade |
| --- | --- | --- |
| Base já existente | companies, customers, conversations, messages | Aproveitar como núcleo multiempresa/comunicação. |
| Configuração | company_settings, operating_hours, delivery_settings | Pix, horário, endereço, entrega, impressão, WhatsApp. |
| Usuários | users, roles, permissions, user_company_roles | Controle de acesso por empresa. |
| Cardápio | product_categories, products, product_options, weekly_menus, weekly_menu_items, daily_menu_overrides | Produtos, regras, itens ativos/esgotados por dia. |
| Pedidos | orders, order_items, order_item_options, order_status_histories | Pedido estruturado e auditável. |
| Entrega | customer_addresses, delivery_quotes, delivery_zones | Endereço e cálculo por km/zona/manual. |
| Pagamentos | payments, payment_proofs | Método, status, comprovante e confirmação humana. |
| Impressão | print_jobs, receipt_templates, printer_settings | Comanda, fila, reimpressão, Epson TM-T20X e status. |
| WhatsApp | whatsapp_accounts, whatsapp_webhook_events, whatsapp_templates, whatsapp_media_files, whatsapp_message_deliveries | Integração oficial e logs brutos. |
| IA | ai_interactions, ai_handoffs, automation_rules | Decisões, confiança, fallback e auditoria. |
| Integrações Google | integration_providers, connected_accounts, oauth_tokens, external_calendar_events, external_tasks, export_jobs | OAuth por usuário/empresa, sincronização opcional e exportações. |


## 14. Módulo Restaurante - Banco V1: o que deve ser executado primeiro
Este é o próximo módulo técnico recomendado porque ele prepara o terreno para cardápio, pedidos, pagamento, entrega, impressão e WhatsApp.

Entregáveis mínimos:
- `company_settings` com Pix textual, horário, endereço, regras de entrega e impressão.
- `product_categories` e `products`.
- `product_options` para adicionais e opções internas.
- `weekly_menus` e `weekly_menu_items`.
- `daily_menu_overrides` para disponibilidade/esgotado.
- `customer_addresses`.
- `delivery_settings`, `delivery_zones`, `delivery_quotes`.
- `orders`, `order_items`, `order_item_options`, `order_status_histories`.
- `payments`, `payment_proofs`.
- `print_jobs`.
- `whatsapp_providers`, `whatsapp_accounts`, `whatsapp_webhook_events`, `whatsapp_media_files`.

Critério de aceite técnico:
- Rodar `php artisan migrate:fresh --seed` sem erro.
- Ter seeders com dados reais do Sol Restaurante.
- Conseguir criar pedido exemplo com cliente, item, entrega, Pix pendente e comanda pronta para impressão.
- Manter tudo vinculado à empresa para suportar multiempresa no futuro.

## 15. Estratégia de execução para o Codex
O Codex não deve receber o projeto inteiro de uma vez. A execução deve seguir lotes pequenos:

1. Ler o README atual e o documento mestre.
2. Conferir estrutura de pastas e migrations existentes.
3. Executar somente o módulo solicitado.
4. Criar migrations, models, relacionamentos, seeders e testes mínimos.
5. Rodar validação.
6. Registrar no README ou changelog o que foi feito.
7. Só então avançar para o próximo módulo.

Regra de ouro: cada módulo precisa deixar o projeto funcionando antes de iniciar outro.

## 16. Ordem recomendada para começar amanhã
1. M00 - Preparação do repositório e padrões.
2. M01 - Autenticação, usuários e permissões.
3. M02 - Restaurante - Banco V1.
4. M03 - Catálogo/cardápio/disponibilidade.
5. M04 - Pedidos e status.
6. M05 - Pagamento Pix por comprovante.
7. M06 - Entrega por km.
8. M07 - Comanda e impressão HTML/PDF.
9. M08 - Provider WhatsApp fake/local.
10. M08.2 - Meta Cloud API oficial.
11. M13 - Google Workspace e integrações futuras, apenas depois da V1 operacional.

## 17. Pendências que não bloqueiam início
- Modelos finais das telas do frontend.
- Cidade/UF do endereço.
- Regra de arredondamento da entrega.
- Taxa mínima de entrega.
- Distância máxima/bairros atendidos.

Essas pendências não impedem a criação do banco e do backlog. Elas devem ser campos configuráveis ou decisões de fases posteriores.

## 18. Pendências que bloqueiam produção final
- Provedor WhatsApp oficial configurado e testado.
- Número definitivo validado.
- Webhook público HTTPS ativo.
- Políticas/templates mínimos criados quando necessário.
- Impressão testada na Epson TM-T20X real com driver instalado, bobina correta e fluxo de reimpressão validado.
- Testes com equipe piloto em cenário real de almoço.

## 19. Critérios de aceite gerais da V1
- Cliente consegue pedir cardápio pelo WhatsApp.
- IA envia cardápio textual do dia com itens ativos.
- Atendente consegue marcar item como esgotado.
- IA não oferece item esgotado.
- Cliente consegue montar pedido.
- Sistema valida regras da N5 Casa e N8 Casa.
- Sistema calcula produtos, adicionais e entrega.
- Sistema gera resumo antes de confirmar.
- Pedido só é criado/finalizado após confirmação.
- Pix envia chave textual e aguarda comprovante.
- Comprovante fica anexado ao pedido.
- Pagamento pode ser confirmado/rejeitado por usuário autorizado.
- Comanda fica legível e imprimível.
- Atendente pode assumir conversa manualmente.
- IA para de responder quando conversa estiver em modo manual.
- Status do pedido fica visível na interface.

## 20. Roadmap futuro
### Fase 1 - Operação Sol Restaurante
Atendimento, cardápio, pedidos, Pix, comanda, impressão e status.

### Fase 2 - Gestão operacional
Dashboard diário, vendas, pagamentos pendentes, histórico de clientes e relatórios simples.

### Fase 3 - CRM real
Funil, prospecção, agenda, planner, follow-up, segmentação e campanhas com templates oficiais.

### Fase 4 - Financeiro e inteligência
Rendimentos, lucro, gastos, custos, eficiência, produtos mais vendidos, ticket médio e previsões.

### Fase 5 - Produto SaaS
Multiempresa completo, planos, onboarding, marketplace de integrações, permissões avançadas, auditoria e métricas de negócio.

## 21. Observação final
Com as informações atuais, já é possível iniciar a execução técnica amanhã. O modelo da impressora já foi identificado. Os modelos finais das telas e integrações Google avançadas são importantes, mas não bloqueiam a arquitetura do banco nem os módulos iniciais. O ponto mais inteligente agora é preparar uma base limpa, modular e testável, para que cada nova informação entre como configuração e não como retrabalho estrutural.


## 22. Atualização V1.5 - Impressora Epson e Pix sem QR Code
Nesta versão, duas pendências foram refinadas:

- A impressora foi confirmada como **Epson**, mas o modelo exato ainda precisa ser identificado. O sistema deve manter a estratégia inicial de comanda HTML/PDF imprimível no navegador. Após confirmação do modelo, deve ser validado se a impressora trabalha com papel térmico 58 mm ou 80 mm, se aceita ESC/POS, se será usada por USB/rede e se será necessário agente local de impressão.
- O **QR Code Pix não será necessário na V1**. O fluxo oficial do Sol Restaurante será: enviar a chave Pix textual configurada em ambiente privado, receber comprovante pelo WhatsApp, anexar o comprovante ao pedido e aguardar confirmação humana do pagamento por atendente/gerente.

Impacto técnico:

- `company_settings.pix_key` continua obrigatório/configurável.
- Campos de QR Code devem ser opcionais, nullable ou deixados para evolução futura.
- A comanda deve mostrar método de pagamento Pix e status do comprovante.
- O módulo M05 não deve bloquear execução esperando imagem de QR Code.
- O módulo M07 deve preparar impressão flexível para navegador primeiro e Epson depois.

## 23. Próxima execução recomendada após V1.5
Com a retirada do QR Code como pendência e a identificação parcial da impressora, o projeto está pronto para iniciar a execução técnica pelos módulos:

1. M00 - Preparação de repositório e padrões.
2. M01 - Autenticação, usuários e permissões.
3. M02 - Restaurante - Banco V1.
4. M03 - Catálogo, cardápio e disponibilidade.
5. M04 - Pedidos e status operacionais.
6. M05 - Pix por comprovante, sem QR obrigatório.
7. M07 - Comanda HTML/PDF e preparação para impressora Epson.

---

# Atualizacao V1.6 - Documentacao Executavel do Front-end

## Decisao adicionada

A partir da V1.6, o projeto passa a conter uma camada documental especifica para o front-end, UX/UI, mapa de navegacao, componentes, status, popups e uso correto das imagens geradas.

## Ponto fundamental

As telas geradas por imagem no projeto devem ser consideradas guias visuais e esteticos. Elas ajudam na definicao de estilo, experiencia, composicao, cores, hierarquia e referencias de layout. Elas nao devem ser tratadas como fonte final de regra de negocio.

Em caso de conflito, a ordem de prioridade e:

1. Documento Mestre.
2. Regras de negocio do restaurante.
3. Backlog e modulos.
4. Documentacao de front.
5. Imagens como referencia visual.

## Nova pasta criada

```txt
front/
├── README_FRONT.md
├── AUDITORIA_UX_UI_E_DOCUMENTACAO_FRONT_V1.md
├── MAPA_NAVEGACAO_FRONT_V1.md
├── M10_FRONT_UX_OPERACIONAL_REFINADO.md
├── COMPONENTES_UI_DESIGN_SYSTEM_V1.md
├── ESTADOS_STATUS_E_CORES_V1.md
├── POPUPS_MODAIS_DRAWERS_V1.md
├── REFERENCIAS_VISUAIS_GERADAS.md
└── CHECKLIST_FRONT_CODEX_V1.md
```

## Imagens ja refinadas visualmente

Foram geradas referencias para:

- Login.
- Cadastro.
- Dashboard.
- Conversas / Atendimento.
- Pedidos.
- Cardapio.
- Entregas.
- Financeiro.
- Perfil do usuario.
- Modal de confirmacao Pix.

## Imagens ainda pendentes

Ainda poderao ser geradas como referencia visual:

- Perfil do cliente.
- Detalhes do pedido.
- Comanda / previa de impressao.
- Novo pedido manual.
- Configuracoes Hub.
- Configuracoes internas.
- Relatorios.
- Popups complementares.

## Diretriz para o Codex

O Codex deve usar as imagens como inspiracao visual e consultar os arquivos de front para comportamento, navegacao, componentes e criterios de aceite. Valores, produtos e nomes exibidos nas imagens podem ser ficticios e nao devem sobrescrever regras documentadas.


---

# Atualizacao V1.8 - Impressora Epson TM-T20X e integrações Google futuras

## Decisões adicionadas

1. A impressora do restaurante foi identificada como **Epson TM-T20X 031**, modelo **M352A**, alimentação **100-240V, 50-60Hz, 1.0A**.
2. O número de série da impressora é dado interno e não deve ser versionado no repositório.
3. A estratégia de impressão permanece: **V1 com HTML/PDF imprimível pelo navegador**, porque é mais simples, testável e segura.
4. A evolução de impressão direta/silenciosa deve ser tratada como etapa posterior, com driver Epson, ESC/POS, QZ Tray ou agente local próprio.
5. Para planner, agenda, funil e SaaS futuro, a estratégia aprovada é usar **integrações Google opcionais**, principalmente Calendar, Tasks, Sheets/Drive e Maps, sempre com OAuth e escopos mínimos.

## Impacto técnico da Epson TM-T20X

- Atualizar `company_settings` ou `printer_settings` com marca/modelo da impressora.
- Preparar `receipt_templates` para largura de cupom/comanda.
- Manter `print_jobs` com status, tentativas, erro, usuário e reimpressão.
- Criar rota de prévia de comanda e rota de impressão.
- Não implementar ESC/POS diretamente no backend sem agente local ou ponte de impressão.
- Validar no computador real do restaurante: driver instalado, bobina, corte, margens, legibilidade e fluxo de reimpressão.

## Impacto técnico das integrações Google

- Criar módulo futuro `M13_GOOGLE_WORKSPACE_INTEGRACOES_FUTURAS.md`.
- Criar arquitetura `IntegrationProviderInterface`.
- Criar tabelas para provedores, contas conectadas, tokens OAuth criptografados, eventos externos, tarefas externas e jobs de exportação.
- Começar pelo Google Maps/Routes para entrega, pois está ligado à regra atual de km.
- Depois usar Google Calendar para agenda/planner e Google Tasks para tarefas/follow-ups.
- Sheets/Drive ficam úteis para exportação e relatórios gerenciais.
- Gmail deve ficar fora da V1 para evitar escopos mais sensíveis e risco maior de verificação.

## Diretriz para o Codex

O Codex deve considerar a Epson TM-T20X como impressora alvo real, mas manter a implementação inicial por HTML/PDF. As integrações Google devem ser documentadas e modeladas como arquitetura futura, não implementadas na primeira leva operacional.


## 21. Pacote de execução Codex - V1.8
Esta versão adiciona uma camada operacional para orientar o Codex com segurança. O objetivo é evitar execução grande demais, proteger arquivos sensíveis e garantir rastreabilidade por módulo.

### 21.1 Regra principal de execução
O Codex deve executar o projeto em módulos pequenos e sequenciais. Cada módulo deve terminar com validação, lista de arquivos alterados/criados e commit pequeno.

Sequência obrigatória inicial:

```txt
M00 -> M01 -> M02 -> M03 -> M04 -> M05 -> M06 -> M07 -> M08 -> M09 -> M10 -> M11 -> M12 -> M13
```

O Codex não deve avançar para o módulo seguinte sem autorização explícita do Murilo.

### 21.2 Arquivos de leitura obrigatória antes de qualquer código
Antes de alterar código, o Codex deve ler:

```txt
README.md
docs/backlog/README.md
docs/backlog/codex/README_CODEX.md
docs/backlog/codex/PLANO_EXECUCAO_CODEX.md
docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md
docs/backlog/codex/CHECKLIST_PRE_EXECUCAO_CODEX.md
docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
docs/backlog/03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md
docs/backlog/modules/<modulo-atual>.md
docs/backlog/adr/
```

Para front, também deve ler:

```txt
docs/backlog/front/README_FRONT.md
docs/backlog/front/AUDITORIA_UX_UI_E_DOCUMENTACAO_FRONT_V1.md
docs/backlog/front/MAPA_NAVEGACAO_FRONT_V1.md
docs/backlog/front/M10_FRONT_UX_OPERACIONAL_REFINADO.md
docs/backlog/front/COMPONENTES_UI_DESIGN_SYSTEM_V1.md
docs/backlog/front/ESTADOS_STATUS_E_CORES_V1.md
docs/backlog/front/POPUPS_MODAIS_DRAWERS_V1.md
```

### 21.3 Regra de commits
A sequência de commits está documentada em:

```txt
docs/backlog/codex/PLANO_COMMITS_E_GIT_SAFETY.md
```

O Codex deve preferir commits pequenos, por módulo, com mensagens convencionais:

```txt
chore: ...
feat(auth): ...
feat(restaurant): ...
feat(menu): ...
feat(orders): ...
feat(payments): ...
feat(delivery): ...
feat(printing): ...
feat(whatsapp): ...
feat(ai): ...
feat(front): ...
feat(finance): ...
docs: ...
```

### 21.4 Segurança do .env e arquivos sensíveis
O Codex não deve versionar `.env`, tokens, credenciais, chaves privadas, secrets, senhas, certificados, arquivos de storage sensíveis, banco local, dumps, logs ou arquivos gerados de ambiente.

O `.env.example` pode ser atualizado com nomes de variáveis, mas nunca com valores reais.

Exemplo correto:

```env
WHATSAPP_PROVIDER=fake
META_WHATSAPP_TOKEN=
META_WHATSAPP_PHONE_NUMBER_ID=
N8N_WEBHOOK_BASE_URL=
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

Exemplo proibido:

```env
META_WHATSAPP_TOKEN=<valor-sensivel-nao-versionar>
GOOGLE_CLIENT_SECRET=<valor-sensivel-nao-versionar>
DB_PASSWORD=<valor-sensivel-nao-versionar>
```

### 21.5 Regra sobre imagens do front
As imagens em `imgs/referencias-front/` são guias visuais. Elas não são fonte de regra de negócio. O Codex não deve copiar preços, nomes, endereços, datas, telefones ou textos fictícios das imagens como dados reais.

A fonte da verdade é:

```txt
docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
docs/backlog/modules/
docs/backlog/adr/
```

### 21.6 Estado final esperado desta fase documental
Com a V1.8, o projeto está pronto para iniciar implementação controlada a partir do M00, sem depender de n8n, Meta Cloud API real, telas finais pendentes ou integração Google.

A primeira execução deve ser:

```txt
M00_PREPARACAO_REPOSITORIO_PADROES.md
```

Depois:

```txt
M01_AUTENTICACAO_USUARIOS_PERMISSOES.md
M02_RESTAURANTE_BANCO_V1.md
```

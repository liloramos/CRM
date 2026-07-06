# PROMPT M10 - Front UX Operacional Refinado Reforçado

Use este prompt quando chegar na etapa **M10**. Ele substitui o prompt comum do M10 para garantir que o Codex use corretamente as referências visuais aprovadas, sem transformar imagens em regra de negócio.

```txt
Continue o projeto ChatBotCRM.

Leia antes de alterar:
- docs/backlog/modules/M10_FRONT_UX_OPERACIONAL_REFINADO.md
- docs/backlog/front/frontend-guidelines.md
- docs/backlog/front/README_FRONT.md
- docs/backlog/front/REFERENCIAS_VISUAIS_GERADAS.md
- docs/backlog/front/COMPONENTES_UI_DESIGN_SYSTEM_V1.md
- docs/backlog/front/ESTADOS_STATUS_E_CORES_V1.md
- docs/backlog/front/MAPA_NAVEGACAO_FRONT_V1.md
- docs/backlog/front/POPUPS_MODAIS_DRAWERS_V1.md
- docs/backlog/atendimento-real-whatsapp.md
- docs/backlog/comanda-e-impressao.md

Também analise a pasta:
- imgs/referencias-front/

Objetivo:
Executar somente o módulo M10_FRONT_UX_OPERACIONAL_REFINADO.md.

Implemente/refine o front-end com base nas referências visuais aprovadas do Sol Restaurante / ChatBotCRM.

Direção visual obrigatória:
- tema escuro premium;
- preto/cinza grafite como base;
- detalhes em laranja/amarelo;
- logo/identidade Sol Restaurante quando já existir arquivo seguro no projeto;
- visual moderno, SaaS, limpo e profissional;
- bordas arredondadas;
- cards com glow/sombra sutil;
- sidebar lateral;
- ícones finos;
- boa hierarquia visual;
- interface simples para restaurante;
- nada poluído;
- nada genérico demais.

Regras sobre as imagens:
1. As imagens em imgs/referencias-front/ são referência estética e visual.
2. Não copiar nomes, preços, datas, endereços, telefones ou valores das imagens.
3. Não usar textos fictícios das imagens como regra de negócio.
4. Usar os documentos de docs/backlog como fonte de regra.
5. Usar as imagens apenas para entender composição, estilo, cores, espaçamento, cards, sidebar, modais, estados e aparência geral.
6. Caso não consiga visualizar o conteúdo das imagens, use o documento REFERENCIAS_VISUAIS_GERADAS.md como descrição oficial das referências visuais.
7. Se alguma imagem parecer contradizer a documentação, a documentação vence.

Regras de organização:
1. O front deve ser fácil para Murilo alterar manualmente depois.
2. Criar componentes pequenos, reutilizáveis e bem nomeados.
3. Evitar JSX gigante.
4. Centralizar mocks em pasta própria.
5. Centralizar types, constants, services e formatadores.
6. Separar UI de regra de negócio.
7. Evitar valores mágicos soltos.
8. Manter padrão consistente de cores, espaçamentos, bordas e estados.
9. Priorizar legibilidade e manutenção acima de soluções excessivamente abstratas.
10. Criar nomes de componentes, pastas e arquivos que deixem claro o papel de cada parte.

Telas/áreas a contemplar conforme documentação:
- Login;
- Cadastro;
- Dashboard;
- Conversas / Atendimento;
- Pedidos;
- Cardápio;
- Entregas;
- Financeiro;
- Perfil do usuário;
- Perfil do cliente;
- Detalhes do pedido;
- Comanda / Prévia de impressão;
- Novo pedido manual;
- Configurações Geral;
- Configurações Hub;
- Usuários e Permissões;
- Pagamentos / Pix;
- IA e Automação;
- Aparência e Marca;
- Segurança;
- Relatórios;
- WhatsApp / API técnico.

Modais/estados a contemplar conforme documentação:
- Confirmar pagamento Pix;
- Cancelar pedido;
- Alterar status do pedido;
- Editar item do pedido;
- Marcar item indisponível;
- Adicionar novo produto;
- Adicionar usuário;
- Alternar IA/manual;
- Erro de impressão;
- Erro de WhatsApp/API;
- Estado vazio de pedidos;
- Estado vazio de conversas;
- Estado de carregamento;
- Estado de erro/conexão indisponível.

Regras operacionais importantes:
1. A comanda/impressão deve aparecer como fluxo operacional do pedido.
2. WhatsApp/API técnico deve ser configuração, não repetição da tela de conversa.
3. Pedido precisa suportar observações por item.
4. Cliente pode ter crédito/saldo.
5. Pedido pode ter cliente pagador, beneficiário e pessoa que retira.
6. Histórico/preferências do cliente devem ajudar em pedidos recorrentes.
7. O sistema deve considerar pedido fragmentado em várias mensagens.
8. Quando houver ambiguidade no pedido, a interface deve facilitar confirmação humana.
9. A IA deve aparecer como apoio do atendente, não como substituta obrigatória da decisão humana.
10. Impressão operacional não deve ficar escondida apenas em configurações.

Componentes esperados, se ainda não existirem:
- AppShell;
- Sidebar;
- Topbar;
- PageHeader;
- StatCard;
- StatusBadge;
- ActionButton;
- Modal;
- DataTable;
- EmptyState;
- LoadingState;
- ErrorState;
- OrderSummaryCard;
- CustomerSummaryCard;
- PrintPreview;
- PaymentStatusCard;
- IntegrationStatusCard.

Organização esperada, respeitando a estrutura atual do projeto:
- componentes reutilizáveis em uma pasta clara de UI;
- layouts separados de páginas;
- features separadas por domínio quando fizer sentido;
- mocks fictícios centralizados;
- tipos TypeScript centralizados;
- constantes de status, menus e cores centralizadas;
- services preparados para trocar mock por API real no futuro.

Validação visual:
Antes de finalizar, revise se o front está coerente com a identidade aprovada:
- escuro premium;
- Sol Restaurante;
- laranja/amarelo;
- moderno;
- limpo;
- sem excesso de informação;
- fácil para atendente de restaurante usar;
- consistente com as referências visuais em imgs/referencias-front/.

Ao finalizar:
- Rode build/lint/test disponíveis.
- Não invente comandos inexistentes.
- Liste arquivos alterados/criados.
- Explique onde alterar cores, menus, mocks, componentes e textos principais.
- Explique como criar uma nova tela seguindo o padrão criado.
- Sugira o commit: feat(front): implement refined operational crm interface
- Pare ao final e aguarde autorização para M11.
```

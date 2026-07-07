# M12 - Roadmap de Evolucao CRM/SaaS

## Objetivo

Preparar a evolucao do ChatBotCRM para CRM/SaaS multiempresa sem implementar
funcionalidades futuras neste momento.

Este documento registra organizacao de modulos, pontos de extensao e criterios
para futuras features como planner, agenda, funil, prospeccao, billing, white
label e dashboards avancados.

## Escopo desta etapa

- Documentar como a base atual deve evoluir para SaaS multiempresa.
- Explicitar pontos de extensao ja existentes no backend e no frontend.
- Separar o nucleo operacional do restaurante das futuras camadas de CRM/SaaS.
- Registrar guardrails para evitar inflar a V1.

## Fora de escopo

- Implementar billing, assinaturas ou cobranca real.
- Implementar white label, subdominios ou onboarding completo.
- Implementar planner, agenda, funil ou prospeccao.
- Implementar dashboards avancados, BI ou relatorios contabeis.
- Implementar integracoes Google; isso pertence ao M13.
- Alterar regras operacionais de pedido, Pix, WhatsApp, IA, impressao ou entrega.

## Estado estrutural atual

A base ja possui fundacoes importantes para evolucao SaaS:

- entidades vinculadas a empresa por `company_id`;
- usuarios, papeis e permissoes;
- configuracoes por empresa;
- dominio operacional de restaurante separado em modelos, resources e services;
- contratos plugaveis para WhatsApp, IA, pagamentos, entrega, impressao e
  integracoes;
- frontend organizado por `components`, `features`, `mocks`, `services`,
  `types`, `constants` e `utils`.

## Principios para evolucao SaaS

1. Empresa e contexto de acesso sempre primeiro.
2. Funcionalidades futuras entram por modulo, nunca como regra global solta.
3. Providers externos devem continuar plugaveis por contrato/interface.
4. Tokens e credenciais devem ser criptografados e nunca versionados.
5. Permissoes devem ser verificadas no backend, mesmo quando o frontend esconder
   a acao.
6. Mocks do frontend devem continuar sanitizados e centralizados.
7. Roadmap nao deve sobrescrever regra operacional ja documentada.

## Organizacao modular recomendada

### Nucleo SaaS

Responsavel por empresas, usuarios, papeis, permissoes, configuracoes, contexto
ativo e auditoria basica.

Extensoes futuras possiveis:

- `feature_flags`;
- `tenant_settings`;
- `audit_logs`;
- `user_invitations`;
- `company_onboarding_steps`.

### CRM

Responsavel por clientes, historico, conversas, tarefas de relacionamento,
follow-ups e segmentacao.

Extensoes futuras possiveis:

- `crm_segments`;
- `crm_tags`;
- `crm_follow_ups`;
- `crm_activity_logs`;
- `customer_lifecycle_events`.

### Funil e prospeccao

Responsavel por oportunidades comerciais e etapas de venda. Deve nascer separado
do fluxo operacional de pedidos do restaurante.

Extensoes futuras possiveis:

- `crm_pipelines`;
- `crm_pipeline_stages`;
- `crm_opportunities`;
- `crm_opportunity_notes`;
- `crm_opportunity_events`.

### Planner e agenda

Responsavel por tarefas, lembretes, agenda operacional e planejamento interno.
Deve poder funcionar sem Google e sincronizar com Google apenas quando o M13
estiver autorizado.

Extensoes futuras possiveis:

- `planner_events`;
- `planner_tasks`;
- `planner_task_assignments`;
- `planner_reminders`;
- `external_calendar_links`.

### Billing e assinaturas

Responsavel por planos, limites, cobranca, status de assinatura e historico de
eventos financeiros da plataforma. Nao deve se misturar ao financeiro operacional
do restaurante.

Extensoes futuras possiveis:

- `subscription_plans`;
- `company_subscriptions`;
- `billing_events`;
- `usage_limits`;
- `usage_counters`.

### White label e branding

Responsavel por identidade visual por empresa, dominio/subdominio e personalizacao
da experiencia. Deve ficar atras de configuracao e feature flag.

Extensoes futuras possiveis:

- `brand_profiles`;
- `brand_assets`;
- `company_domains`;
- `theme_overrides`.

### Dashboards avancados

Responsavel por metricas, indicadores, widgets e comparativos. Deve consumir
dados consolidados e nao duplicar regras de pedido, pagamento ou entrega.

Extensoes futuras possiveis:

- `dashboard_widgets`;
- `saved_dashboard_views`;
- `metric_snapshots`;
- `report_exports`.

## Pontos de extensao ja existentes

### Backend

Contratos ja presentes para futuras implementacoes:

- `App\Contracts\WhatsApp\WhatsAppProviderInterface`;
- `App\Contracts\Ai\AiProviderInterface`;
- `App\Contracts\Payments\PaymentProviderInterface`;
- `App\Contracts\Delivery\RouteDistanceProviderInterface`;
- `App\Contracts\Printing\PrintProviderInterface`;
- `App\Contracts\Integrations\IntegrationProviderInterface`.

Services de dominio existentes devem continuar como fronteira para regras:

- `OrderWorkflowService`;
- `PaymentWorkflowService`;
- `DeliveryWorkflowService`;
- `PrintWorkflowService`;
- `WhatsAppService`;
- `AiAutomationService`;
- `MenuAvailabilityService`.

Novas features SaaS devem preferir criar services proprios por dominio em vez de
adicionar regra futura em controllers ou models operacionais existentes.

### Frontend

Padrao atual a preservar:

- `src/features/<dominio>` para telas e composicao por feature;
- `src/components/ui` para componentes reutilizaveis;
- `src/components/layout` para estrutura visual;
- `src/mocks` para dados ficticios/sanitizados;
- `src/types` para contratos de interface;
- `src/services` para fonte de dados do front;
- `src/constants` para rotas, status, cores e metadados.

Futuras telas SaaS devem criar features separadas, por exemplo:

- `features/planner`;
- `features/funil`;
- `features/prospeccao`;
- `features/billing`;
- `features/admin-saas`.

## Permissoes futuras

Permissoes customizadas devem evoluir sobre a base atual de roles/permissions.

Diretrizes:

- evitar permissoes hardcoded em componentes;
- manter nomes descritivos e orientados a acao;
- separar permissoes operacionais do restaurante de permissoes SaaS/admin;
- registrar permissoes novas em seeders seguros;
- nunca usar permissao visual do frontend como unica barreira.

Exemplos de familias futuras:

- `planner.*`;
- `pipeline.*`;
- `billing.*`;
- `tenant.*`;
- `reports.advanced.*`;
- `integrations.manage`.

## Feature flags e limites

Antes de liberar features SaaS para todas as empresas, planejar feature flags e
limites por plano.

Exemplos de limites futuros:

- numero de usuarios por empresa;
- numero de conversas ativas;
- uso de automacoes;
- exportacoes mensais;
- acesso a dashboards avancados;
- integracoes ativas.

## Integracoes futuras

Integracoes devem seguir o ADR-002 e o ADR-008:

- provider plugavel;
- OAuth por empresa/usuario quando aplicavel;
- escopos minimos;
- tokens criptografados;
- logs sanitizados;
- nenhuma credencial versionada.

Google Workspace deve ficar para o M13. O M12 apenas reserva os limites de
arquitetura para que planner, agenda, tarefas e exportacoes possam se conectar
depois.

## Checklist para futuros modulos SaaS

Antes de criar uma feature futura, confirmar:

- existe modulo autorizado para a feature;
- a feature pertence a CRM/SaaS e nao ao nucleo operacional do restaurante;
- ha permissao backend definida;
- ha vinculo com empresa/contexto;
- mocks e seeds usam somente dados ficticios;
- credenciais ficam em `.env` real e nunca em Git;
- telas seguem componentes reutilizaveis;
- validacoes do projeto foram executadas.

## Criterio de aceite do M12

M12 fica concluido quando:

- a evolucao SaaS/CRM esta documentada;
- pontos de extensao existentes estao mapeados;
- limites do que nao implementar agora estao claros;
- nao ha alteracao de regra de negocio;
- nao foram criadas funcionalidades grandes;
- validacoes documentais/projeto foram executadas.

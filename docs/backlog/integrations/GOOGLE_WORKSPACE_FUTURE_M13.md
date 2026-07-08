# M13 - Google Workspace e Integracoes Futuras

## Objetivo

Preparar a base documental para futuras integracoes com Google Workspace,
webhooks, agenda/calendario, e-mail e exportacoes sem ativar credenciais reais
ou chamadas externas.

Este modulo complementa o M12: M12 documentou a evolucao CRM/SaaS; M13 define
como integracoes externas devem ser planejadas com seguranca.

## Escopo desta etapa

- Documentar arquitetura futura para Google Workspace.
- Registrar limites de OAuth, escopos, tokens e consentimento.
- Mapear webhooks futuros e exportacoes sem implementa-los agora.
- Explicitar como agenda, tarefas, arquivos e e-mail devem entrar no produto.
- Apontar a base tecnica ja existente para futuras implementacoes.

## Fora de escopo

- Criar credenciais Google reais.
- Criar refresh token, access token ou client secret real.
- Implementar chamadas reais para Calendar, Tasks, Sheets, Drive, Gmail ou Maps.
- Criar rotas publicas de webhook para Google nesta etapa.
- Enviar e-mail real.
- Sincronizar agenda real.
- Exportar dados reais para planilhas ou arquivos externos.
- Implementar OAuth completo, tela de consentimento ou callback real.

## Base atual

Ja existem pontos de preparacao seguros:

- `App\Contracts\Integrations\IntegrationProviderInterface`;
- `backend/config/chatbotcrm.php` com bloco `integrations.google`;
- `backend/.env.example` com `GOOGLE_CLIENT_ID=`, `GOOGLE_CLIENT_SECRET=` e
  `GOOGLE_REDIRECT_URI=` vazios;
- dominio de entrega ja preparado para provider de distancia;
- frontend modular para futuras telas de configuracao.

Nenhum desses pontos deve conter segredo real no Git.

## Providers futuros

Implementacoes reais devem nascer atras de contratos:

- `GoogleWorkspaceProvider`;
- `GoogleCalendarProvider`;
- `GoogleTasksProvider`;
- `GoogleDriveProvider`;
- `GoogleSheetsExportProvider`;
- `GoogleMapsRouteProvider`;
- `EmailProvider`, caso e-mail entre em modulo futuro.

Cada provider deve informar:

- nome;
- status de configuracao;
- escopos necessarios;
- recurso suportado;
- limites conhecidos;
- status seguro sem expor tokens.

## OAuth e contas conectadas

Quando autorizado, OAuth deve ser por empresa e/ou usuario.

Tabelas futuras recomendadas:

- `integration_providers`;
- `connected_accounts`;
- `oauth_tokens`;
- `integration_webhook_events`;
- `external_calendar_events`;
- `external_tasks`;
- `export_jobs`;
- `export_job_files`;

Diretrizes:

- tokens criptografados em repouso;
- refresh token nunca exposto em API ou logs;
- revogacao por usuario/empresa;
- escopos minimos;
- consentimento separado por provider;
- auditoria de conexao, renovacao, falha e revogacao.

## Escopos por recurso

### Maps/Routes

Uso futuro: calcular distancia/tempo de entrega por rota.

Cuidados:

- iniciar como provider opcional;
- manter calculo manual como fallback;
- nao substituir a regra de entrega documentada sem ADR;
- guardar apenas o necessario para auditoria da cotacao.

### Calendar

Uso futuro: agenda, follow-ups, lembretes e compromissos operacionais.

Cuidados:

- agenda interna deve funcionar sem Google;
- sincronizacao deve ser opcional;
- conflitos devem exigir confirmacao humana;
- evento externo deve manter vinculo com empresa/usuario.

### Tasks

Uso futuro: tarefas operacionais, follow-ups e checklists.

Cuidados:

- tarefa interna continua sendo fonte primaria;
- Google Tasks e apenas espelho/sincronizacao;
- falha de sincronizacao nao pode apagar tarefa interna.

### Sheets/Drive

Uso futuro: exportacoes, planilhas financeiras, relatorios gerenciais e backups
operacionais controlados.

Cuidados:

- exportacao deve ser job rastreavel;
- exportar apenas dados autorizados;
- sanitizar dados pessoais quando aplicavel;
- registrar usuario, empresa, periodo e destino;
- permitir reprocessamento sem duplicar arquivo indevidamente.

### Gmail/e-mail

Gmail deve ficar fora da V1 por escopos sensiveis.

Uso futuro possivel:

- envio de notificacoes;
- anexos comerciais;
- historico de relacionamento, apenas se houver necessidade clara.

Cuidados:

- preferir provider de envio transacional antes de leitura de caixa postal;
- evitar leitura de inbox enquanto nao houver justificativa forte;
- pedir novo ADR antes de usar escopos sensiveis de Gmail;
- nunca registrar corpo de e-mail sensivel em logs publicos.

## Webhooks futuros

Webhooks de integracoes devem seguir padrao seguro:

- validar assinatura ou mecanismo equivalente;
- registrar payload sanitizado;
- guardar status de processamento;
- permitir reprocessamento controlado;
- evitar gravar tokens ou identificadores sensiveis sem necessidade;
- separar evento bruto sanitizado da entidade de dominio resultante.

Possiveis eventos futuros:

- conta conectada;
- token expirado;
- token revogado;
- evento de calendario alterado;
- tarefa sincronizada;
- exportacao concluida;
- erro de exportacao;
- falha de rota/mapa.

## Exportacoes futuras

Exportacoes devem ser assicronas e auditaveis.

Campos recomendados para `export_jobs`:

- empresa;
- usuario solicitante;
- tipo de exportacao;
- periodo;
- status;
- destino;
- filtros usados;
- erro sanitizado;
- timestamps de solicitacao, processamento e conclusao.

O arquivo exportado nao deve ser salvo no Git e deve respeitar permissoes.

## Permissoes futuras

Familias recomendadas:

- `integrations.view`;
- `integrations.manage`;
- `integrations.google.connect`;
- `integrations.google.disconnect`;
- `integrations.exports.request`;
- `integrations.exports.view`;
- `integrations.webhooks.reprocess`.

Permissoes devem ser aplicadas no backend e refletidas no frontend.

## Variaveis de ambiente

O projeto ja reserva nomes seguros no `backend/.env.example`:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
```

Quando novas variaveis forem necessarias, adicionar apenas nomes e valores vazios
ou seguros ao `.env.example`. O `.env` real nunca deve ser versionado.

## Ordem futura sugerida

1. Modelagem de providers, contas conectadas e tokens criptografados.
2. Fluxo OAuth fake/local sem chamar Google.
3. Google Maps/Routes como provider opcional para entrega.
4. Calendar/Tasks para agenda e tarefas.
5. Sheets/Drive para exportacoes.
6. Avaliar e-mail/Gmail somente com novo ADR.

## Criterio de aceite do M13

M13 fica concluido quando:

- integracoes futuras estao documentadas;
- Google Workspace esta planejado como opcional;
- webhooks e exportacoes futuras estao delimitados;
- e-mail/Gmail esta explicitamente fora da V1;
- nao ha credencial, token ou segredo novo;
- nao ha chamada externa real;
- validacoes disponiveis foram executadas.

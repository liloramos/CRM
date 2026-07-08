# Backlog inicial — decisões extraídas dos fluxos BPMN

## Módulo de autenticação e usuários

- Criar autenticação para o restaurante Sol.
- Criar perfis iniciais:
  - Super admin: acesso total ao sistema e configurações técnicas.
  - Admin/gerente: gestão operacional, usuários, relatórios e cardápio.
  - Atendente: conversas, pedidos e impressão de comanda.
- Permitir criação futura de usuários para [NOME_OPERACIONAL_REMOVIDO], [NOME_OPERACIONAL_REMOVIDO] e outros colaboradores.
- Preparar estrutura para multiempresa, mantendo Sol Restaurante como primeiro tenant.

## Módulo de conversas

- Listar clientes ativos do WhatsApp no dia.
- Exibir status visual da conversa:
  - nova mensagem
  - em atendimento
  - aguardando cliente
  - pedido em andamento
  - pedido feito
  - pagamento pendente
  - aguardando comprovante Pix
  - comprovante recebido
  - pagamento confirmado manualmente
  - atendimento manual
  - finalizado
- Permitir que a atendente assuma uma conversa manualmente.
- Permitir que a IA transfira automaticamente para modo manual quando houver baixa confiança, cliente confuso, pedido ambíguo ou risco de erro.

## Módulo de cardápio

- Cada dia deve ter um cardápio padrão.
- Deve existir edição rápida do cardápio diário em tempo real.
- Itens podem ser marcados como indisponíveis/esgotados sem derrubar a operação.
- Alterações devem refletir imediatamente no atendimento automatizado.
- Manter histórico de alterações para auditoria operacional.

## Módulo de pedidos

- Montar pedido a partir da conversa.
- Validar marmita, carne, salada, extras, bebidas, endereço, observações e forma de pagamento.
- Destacar status do pedido com cores claras.
- Criar botão de impressão de comanda quando o pedido estiver pronto.
- Permitir revisão humana antes da impressão.

## Módulo de impressão

- Gerar comanda não fiscal pronta para cozinha.
- Suportar impressora térmica local no computador.
- Criar fallback para PDF/fila de impressão quando a impressora falhar.
- Permitir reimpressão.
- Registrar horário de impressão e usuário responsável.

## Módulo de pagamentos — Pix por chave/comprovante no WhatsApp

- V1 não deve depender de API bancária nem geração de cobrança dentro do aplicativo do banco.
- O sistema deve enviar a chave Pix ou QR Code fixo do restaurante pelo WhatsApp.
- O cliente informa que pagou e envia o comprovante pelo WhatsApp.
- O sistema deve anexar o comprovante à conversa e ao pedido.
- A interface deve destacar o status `aguardando comprovante` quando o cliente ainda não enviou a imagem/arquivo.
- Quando o comprovante chegar, o pedido deve mudar para `comprovante recebido`.
- A confirmação final do pagamento deve ser humana: [NOME_OPERACIONAL_REMOVIDO], [NOME_OPERACIONAL_REMOVIDO] ou usuário autorizado confere e marca como `pagamento confirmado`.
- Deve existir opção para marcar comprovante como inválido, ilegível ou valor divergente.
- Deve existir alerta visual quando o valor do pedido e o valor do comprovante parecerem diferentes.
- Futuramente, a IA/OCR pode ler o comprovante e sugerir valor, data, nome e status, mas não deve confirmar sozinha sem validação humana na V1.
- Integração Pix via provedor/webhook pode ficar como evolução futura, caso o restaurante passe a gerar cobranças automatizadas.

## Módulo futuro IA/n8n

- Receber eventos por webhook.
- Consultar contexto do cliente, conversa, cardápio e pedido.
- Responder automaticamente quando houver confiança suficiente.
- Gerar alerta humano quando houver dúvida.
- Detectar envio de comprovante Pix no WhatsApp.
- Anexar mídia/documento do comprovante ao pedido.
- Sugerir dados extraídos do comprovante, se houver leitura por IA/OCR.
- Registrar logs, custo de IA, decisões e falhas.
- Salvar casos difíceis para melhoria futura da IA.

## Módulos futuros do CRM completo

- Planner.
- Agenda.
- Funil de vendas.
- Prospecções.
- Dashboards.
- Estatísticas.
- Vendas e conversas diárias.
- Rendimentos.
- Organizador financeiro.
- Investimentos.
- Eficiência operacional.
- Lucro, gastos e relatórios gerenciais.

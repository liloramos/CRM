# BPMN — ChatBotCRM

Este diretório contém os fluxos BPMN iniciais do projeto ChatBotCRM.

## Arquivos gerados

1. `01_atendimento_cliente.bpmn`
   Fluxo de atendimento via WhatsApp, identificação do cliente, conversa diária, IA, modo manual e encaminhamento para pedido.

2. `02_montagem_pedido.bpmn`
   Fluxo de montagem de pedido com cardápio diário, indisponibilidade de itens, extras, bebidas, preço, forma de pagamento, envio de chave Pix/QR Code, recebimento de comprovante pelo WhatsApp e status de pedido feito.

3. `03_impressao_comanda.bpmn`
   Fluxo de geração e impressão de comanda em impressora térmica não fiscal, incluindo fila/PDF, alerta de falha e reimpressão.

4. `04_fluxo_futuro_ia_n8n.bpmn`
   Fluxo futuro de automação com n8n, webhooks, agente de IA, fallback manual, triagem de comprovante Pix, logs e aprendizado.

5. `05_login_autenticacao.bpmn`
   Fluxo de login com perfis de acesso: super admin, admin/gerente e atendente.

## Como abrir

### Opção recomendada: Camunda Modeler

1. Instale o Camunda Modeler.
2. Abra o programa.
3. Clique em `File > Open File...`.
4. Selecione qualquer arquivo `.bpmn` desta pasta.
5. O diagrama será aberto em modo visual, com caixinhas, setas, gateways e eventos.

### Opção rápida no navegador: bpmn.io

1. Acesse o editor online do bpmn.io.
2. Arraste um arquivo `.bpmn` para a tela.
3. Visualize ou edite o fluxo diretamente pelo navegador.
4. Depois, salve/exporte o arquivo novamente para a pasta `bpmn`.

### Opção dentro do VS Code

1. Instale uma extensão de BPMN no VS Code, como `BPMN Editor` ou `BPMN.io Editor`.
2. Abra a pasta do projeto `CHATBOTCRM` no VS Code.
3. Clique em um arquivo `.bpmn` dentro da pasta `bpmn`.
4. Use a visualização da extensão para editar o diagrama.

## Convenções sugeridas

- Service Task: ação automática do sistema, IA, integração ou backend.
- User Task: ação humana feita por atendente, gerente ou administrador.
- Exclusive Gateway: decisão de fluxo, como disponibilidade, comprovante Pix recebido/conferido ou confiança da IA.
- End Event: resultado final do processo.

## Regra atual de pagamento Pix

Na V1, o restaurante não gera cobrança Pix pelo banco dentro do sistema. O fluxo correto é:

1. Sistema/atendente envia chave Pix ou QR Code fixo do restaurante no WhatsApp.
2. Cliente paga o valor informado.
3. Cliente envia o comprovante pelo WhatsApp.
4. Sistema anexa o comprovante ao pedido e muda o status para `comprovante recebido`.
5. [NOME_OPERACIONAL_REMOVIDO], [NOME_OPERACIONAL_REMOVIDO] ou usuário autorizado confere e marca como `pagamento confirmado`.

A automação pode ajudar a detectar comprovante e sugerir informações, mas a confirmação final deve ser humana na primeira versão.

## Próximos fluxos recomendados

- Cadastro e edição de cardápio diário.
- Controle de itens esgotados.
- Gestão de pedidos.
- Pagamento Pix por chave/comprovante no WhatsApp.
- Fechamento diário de caixa.
- Dashboard operacional.
- Gestão de usuários e permissões.

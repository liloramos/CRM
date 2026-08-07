# WhatsApp Meta Cloud API - Operação

Este guia descreve a ativação operacional do WhatsApp real no ChatBot CRM sem expor segredos.

## Variáveis Necessárias

- `WHATSAPP_PROVIDER`
- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_PHONE_NUMBER_ID`
- `WHATSAPP_BUSINESS_ACCOUNT_ID`
- `WHATSAPP_API_VERSION`
- `WHATSAPP_VERIFY_TOKEN`
- `META_WHATSAPP_APP_SECRET`
- `WHATSAPP_CA_BUNDLE` quando o PHP não possui `curl.cainfo` ou `openssl.cafile`

`WHATSAPP_PROVIDER` pode ser omitido quando token, phone number ID e verify token estiverem presentes. Nesse caso, o CRM usa o provider Meta automaticamente.

## Diagnóstico Seguro

```bash
php artisan whatsapp:diagnose
php artisan whatsapp:diagnose --probe
```

O primeiro comando mostra apenas se as credenciais existem, provider ativo, fila, config cache, último erro outbound sanitizado e última mensagem inbound. `--probe` faz uma leitura autenticada do identificador configurado para distinguir falha de rede, credencial e número. Nenhum dos modos imprime tokens, IDs completos de mensagens recebidas ou payload bruto.

Depois de alterar variáveis, atualize o cache antes do diagnóstico:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan whatsapp:diagnose --probe
```

## Webhook

Rota canônica:

```text
GET  /api/webhooks/whatsapp
POST /api/webhooks/whatsapp
```

Alias mantido por compatibilidade:

```text
GET  /api/webhooks/whatsapp/meta
POST /api/webhooks/whatsapp/meta
```

## Passos De Configuração

1. Inicie o Laravel sem expor as variáveis no terminal compartilhado.
2. Inicie o worker com `php artisan queue:work` quando `QUEUE_CONNECTION` não for `sync`.
3. Exponha o Laravel em uma URL HTTPS pública; o túnel é uma ferramenta operacional e não uma dependência do projeto.
4. Configure a Callback URL na Meta terminando em `/api/webhooks/whatsapp`.
5. Informe na Meta o mesmo verify token configurado no backend.
6. Assine o campo `messages` do objeto WhatsApp Business Account.
7. No painel do número de teste, adicione e confirme o telefone destinatário autorizado.
8. Envie desse telefone uma mensagem ao número de teste fornecido pela Meta.
9. Confirme `Último webhook recebido` no diagnóstico e a conversa persistida no CRM.
10. Responda pelo CRM e acompanhe os estados Enviada, Entregue, Lida ou Não enviada.

Mensagens livres dependem da janela de atendimento aberta pelo cliente. Fora dela, a Meta exige um template aprovado. O código `whatsapp_customer_window_closed` identifica esse caso; `whatsapp_recipient_not_allowed` indica que o destinatário ainda não foi autorizado no ambiente de teste.

## Dados Demonstrativos

O modo operacional não exibe dados demonstrativos quando `DEMO_DATA_ENABLED=false`.

Para limpar apenas dados de demonstração local marcados pelo seeder:

```bash
php artisan whatsapp:cleanup-demo --confirm=EXCLUIR
```

O comando é bloqueado em produção e remove somente registros identificados por metadados técnicos de demo.

## Número Real Do Restaurante

Esta etapa usa exclusivamente o número de teste fornecido pela Meta. O número real do Sol Restaurante não deve ser registrado, migrado ou alterado durante a validação.

Antes da integração do número real, a equipe deve:

- inventariar os templates já existentes no WABA;
- decidir entre coexistência, onboarding ou migração;
- registrar um backup da configuração operacional atual;
- validar janela de atendimento, permissões e responsáveis;
- planejar a mudança com uma janela acompanhada pelas atendentes.

As respostas rápidas cadastradas no aplicativo WhatsApp Business não são importadas automaticamente. No CRM, elas são mantidas pela biblioteca própria de Respostas rápidas e continuam editáveis antes do envio.

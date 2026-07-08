# Integracoes Futuras

Esta pasta registra planos seguros para integracoes externas do ChatBotCRM.

Os documentos daqui nao ativam providers reais, nao criam credenciais e nao
substituem os modulos operacionais ja implementados. Eles existem para orientar
futuras implementacoes quando houver autorizacao explicita.

## Documentos

- `GOOGLE_WORKSPACE_FUTURE_M13.md`: plano do M13 para Google Workspace,
  webhooks, agenda, e-mail e exportacoes futuras.

## Regras

- Nunca versionar `.env`, tokens, client secrets, refresh tokens ou certificados.
- Usar `IntegrationProviderInterface` e providers plugaveis.
- Manter OAuth por empresa/usuario quando aplicavel.
- Usar escopos minimos e consentimento explicito.
- Guardar payloads e logs de forma sanitizada.
- Nao implementar chamadas externas reais sem configuracao e autorizacao.

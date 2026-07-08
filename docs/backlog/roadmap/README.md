# Roadmap CRM/SaaS

Esta pasta concentra notas tecnicas de evolucao do ChatBotCRM para CRM/SaaS.

Os documentos daqui servem para orientar arquitetura futura, pontos de extensao,
fronteiras entre modulos e cuidados de seguranca. Eles nao implementam features
futuras e nao substituem os documentos oficiais de regra de negocio.

## Documentos

- `CRM_SAAS_EVOLUTION_M12.md`: notas do modulo M12 para evolucao SaaS/CRM.
- `../integrations/GOOGLE_WORKSPACE_FUTURE_M13.md`: plano de integracoes
  futuras para Google Workspace, webhooks e exportacoes.

## Regras

- Nao usar imagens como fonte de regra de negocio.
- Nao registrar dados reais, credenciais, tokens, telefones, enderecos ou chaves Pix.
- Nao antecipar funcionalidades grandes fora do modulo autorizado.
- Manter Google, billing, white label, planner e funil como pontos planejados ate
  que exista modulo especifico autorizado.

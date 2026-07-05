# ADR-009-git-env-secrets-safety

## Contexto
O ChatBotCRM precisa crescer de forma segura, modular e rastreável.

## Decisão
O projeto deve proteger .env, tokens, credenciais, certificados e dumps; .env.example recebe apenas variáveis vazias.

## Consequências
- Facilita manutenção e auditoria.
- Reduz risco operacional.
- Evita dependência prematura de serviços externos.
- Deve ser respeitado pelo Codex durante a execução modular.

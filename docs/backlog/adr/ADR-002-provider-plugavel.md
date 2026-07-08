# ADR-002-provider-plugavel

## Contexto
O ChatBotCRM precisa crescer de forma segura, modular e rastreável.

## Decisão
O sistema deve usar interfaces para WhatsApp, IA, impressão e integrações, evitando acoplamento a fornecedores.

## Consequências
- Facilita manutenção e auditoria.
- Reduz risco operacional.
- Evita dependência prematura de serviços externos.
- Deve ser respeitado pelo Codex durante a execução modular.

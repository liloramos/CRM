# ADR-001-whatsapp-oficial-meta-cloud-api

## Contexto
O ChatBotCRM precisa crescer de forma segura, modular e rastreável.

## Decisão
Produção deve usar Meta Cloud API direta ou BSP oficial. API não oficial não deve ser usada no número real do restaurante.

## Consequências
- Facilita manutenção e auditoria.
- Reduz risco operacional.
- Evita dependência prematura de serviços externos.
- Deve ser respeitado pelo Codex durante a execução modular.

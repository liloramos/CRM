# Backlog Geral - Épicos, User Stories e Critérios - V1.8

Este arquivo resume os épicos do ChatBotCRM e aponta para os módulos executáveis.

## Épicos principais

| Épico | Módulos | Objetivo |
| --- | --- | --- |
| Preparação e padrões | M00 | Padronizar repositório, ambiente e convenções antes da implementação. |
| Autenticação e permissões | M01 | Usuários, papéis, permissões, login e proteção de rotas. |
| Núcleo restaurante | M02-M04 | Banco, cardápio, produtos, disponibilidade, pedidos e status. |
| Pagamento e entrega | M05-M06 | Pix por comprovante, confirmação humana, cálculo de entrega. |
| Impressão | M07 | Comanda/recibo para Epson TM-T20X e fila de impressão. |
| WhatsApp oficial | M08 | Provider plugável e preparação para Meta Cloud API. |
| IA e automação | M09 | Fallback manual, n8n futuro e orquestração controlada. |
| Front operacional | M10 | Interface premium, simples e modular. |
| Financeiro básico | M11 | Receitas, despesas, pagamentos e indicadores iniciais. |
| SaaS futuro | M12-M13 | Roadmap CRM/SaaS e integrações Google opcionais. |

## Sequência de execução recomendada

1. M00 - Preparação do repositório e padrões.
2. M01 - Autenticação, usuários e permissões.
3. M02 - Restaurante Banco V1.
4. M03 - Cardápio, produtos e disponibilidade.
5. M04 - Pedidos e status operacionais.
6. M05 - Pix e comprovantes.
7. M06 - Entrega e taxa por km.
8. M07 - Comanda e impressão.
9. M08 - WhatsApp provider.
10. M09 - IA, n8n e fallback manual.
11. M10 - Front UX operacional.
12. M11 - Financeiro básico.
13. M12 - Roadmap CRM/SaaS.
14. M13 - Integrações Google futuras.

## Critério geral de aceite

Um módulo só é considerado concluído quando:

- escopo implementado conforme documento do módulo;
- nenhuma regra de negócio crítica quebrada;
- validações/testes locais executados;
- arquivos alterados listados;
- commit pequeno criado ou preparado;
- próximo módulo ainda não executado sem autorização.

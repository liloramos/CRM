# Plano de Execução Codex - V1.8

## Objetivo
Executar o ChatBotCRM em módulos pequenos, auditáveis e seguros.

## Ordem obrigatória

| Ordem | Módulo | Commit esperado |
| --- | --- | --- |
| 0 | Documentação V1.8 | `docs: add executable backlog and codex execution plan v1.8` |
| 1 | M00 Preparação | `chore: prepare repository standards for modular execution` |
| 2 | M01 Autenticação | `feat(auth): add users roles and permissions foundation` |
| 3 | M02 Restaurante Banco | `feat(restaurant): add restaurant database foundation` |
| 4 | M03 Cardápio | `feat(menu): add products menu and availability management` |
| 5 | M04 Pedidos | `feat(orders): add order lifecycle and operational statuses` |
| 6 | M05 Pix | `feat(payments): add pix receipt confirmation flow` |
| 7 | M06 Entrega | `feat(delivery): add delivery fee calculation by distance` |
| 8 | M07 Impressão | `feat(printing): add receipt and kitchen ticket generation` |
| 9 | M08 WhatsApp | `feat(whatsapp): add whatsapp provider abstraction` |
| 10 | M09 IA/n8n | `feat(ai): add automation and manual takeover foundations` |
| 11 | M10 Front | `feat(front): add operational CRM interface foundation` |
| 12 | M11 Financeiro | `feat(finance): add basic financial dashboard foundation` |
| 13 | M12 Roadmap | `docs: update crm saas roadmap implementation notes` |
| 14 | M13 Google | `docs(integrations): add google workspace future integration plan` |

## Regras de execução

1. Ler a documentação antes de alterar código.
2. Não executar dois módulos no mesmo ciclo.
3. Não alterar regras de negócio sem registrar decisão.
4. Não usar imagens como fonte de dados reais.
5. Não versionar `.env` nem secrets.
6. Validar antes de commitar.
7. Parar e aguardar autorização ao fim de cada módulo.

## Saída esperada ao fim de cada módulo

```txt
Resumo do que foi implementado
Arquivos criados
Arquivos alterados
Comandos de validação executados
Resultado das validações
Pendências ou riscos
Commit criado/sugerido
Próximo módulo recomendado
```

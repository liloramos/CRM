# Auditoria do Cardápio Sol Restaurante

Data da auditoria: 2026-07-15

Escopo: leitura de documentação, seeders, modelagem Laravel, banco PostgreSQL, API e transformação frontend. Nenhum seeder, migration, wipe ou comando destrutivo foi executado.

## 1. Fonte oficial utilizada

Fonte principal encontrada:

- `docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md`

Fontes complementares:

- `docs/backlog/modules/M03_CARDAPIO_PRODUTOS_DISPONIBILIDADE.md`
- `docs/backlog/CRM_DOCUMENTACAO_EXECUTAVEL_V1.9_COMPLEMENTO.md`
- `docs/backlog/comanda-e-impressao.md`
- `docs/backlog/atendimento-real-whatsapp.md`

Conclusão sobre fontes:

- O documento mestre V1.8 é a fonte mais completa e recente encontrada para preços e regras de N5 Casa, N8 Casa, N8/N9 tradicional, combos, latinhas, sucos e adicional de ovo.
- `M03_CARDAPIO_PRODUTOS_DISPONIBILIDADE.md` descreve o escopo técnico, mas não detalha todo o cardápio.
- `comanda-e-impressao.md` contém exemplos com valores antigos e informa que valores reais devem vir da base e dos documentos oficiais; portanto não foi usado como fonte final de preço.
- Não foi encontrada fonte oficial detalhando sabores, marcas, 600 ml, 1 L, 2 L, água ou lista completa de refrigerantes. Esses itens ficam como "precisa de confirmação".

## 2. Regras oficiais confirmadas

Marmitas:

| Item | Preço oficial | Regras oficiais |
| --- | ---: | --- |
| N5 Casa | R$ 8,00 | Arroz, feijão, macarrão, mandioca, salada escolhida pela casa entre beterraba/cenoura e 1 pedaço de uma única carne. |
| N8 Casa | R$ 13,00 | Arroz, feijão, macarrão, mandioca, salada escolhida pelo cliente entre repolho com tomate, vinagrete, beterraba ou cenoura e 2 pedaços de uma única carne. |
| N8 Tradicional | R$ 16,00 | Usa o cardápio semanal completo do dia. |
| N9 Tradicional | R$ 18,00 | Usa o cardápio semanal completo do dia. |
| N8 com bife somente bife | R$ 20,00 | Regra de preço específica. |
| N8 com bife e outras carnes/adicional | R$ 23,00 | Regra de preço específica. |

Combos e adicionais:

| Item | Preço oficial | Observação |
| --- | ---: | --- |
| Combo N8 Casa Baby | R$ 15,00 | Combo oficial documentado. |
| Combo N8 com latinha | R$ 20,00 | Combo oficial com bebida. |
| Ovo frito adicional | R$ 2,00 | Produto adicional vendável. |

Bebidas:

| Item | Preço oficial | Observação |
| --- | ---: | --- |
| Sucos | R$ 7,00 | Sabores não detalhados na fonte encontrada. |
| Latas | R$ 5,00 | Marcas/sabores não detalhados na fonte encontrada. |
| Zero | Mesmo preço | Versões zero seguem o preço das versões normais equivalentes. |

## 3. Modelagem encontrada

Tabelas e models envolvidos:

| Tabela | Model | Função |
| --- | --- | --- |
| `companies` | `Company` | Restaurante/tenant. |
| `product_categories` | `ProductCategory` | Categorias: marmitas, combos, bebidas, sucos, feijoadas, adicionais. |
| `products` | `Product` | Produtos vendáveis e regras JSON de composição. |
| `product_options` | `ProductOption` | Opções, adicionais, variações e componentes por produto. |
| `weekly_menus` | `WeeklyMenu` | Cardápio semanal ativo. |
| `weekly_menu_items` | `WeeklyMenuItem` | Produto vinculado ao dia do cardápio. |
| `daily_menu_overrides` | `DailyMenuOverride` | Disponibilidade diária por produto. |
| `daily_menu_option_overrides` | `DailyMenuOptionOverride` | Disponibilidade diária por opção/componente. |

Capacidades que a modelagem suporta hoje:

- Produtos diferentes por tamanho/tipo.
- Categorias por restaurante.
- Preço base por produto.
- Produto ativo/inativo e disponível por padrão.
- Regras JSON por produto (`composition_rules`).
- Opções por produto com `group_code`, tipo, adicional e regras JSON.
- Disponibilidade diária por produto.
- Disponibilidade diária por opção/componente.
- Cardápio semanal por `service_day`.

Limitações ou pontos frágeis da modelagem:

- Não existe tabela normalizada de grupos de opções com min/max por grupo.
- Min/max está em JSON por opção, o que deixa ambígua a regra "escolha 1 salada".
- Não existe pivot separado entre produto e opção compartilhada; opções são duplicadas por produto.
- Não existe entidade explícita de "cardápio semanal do dia" com componentes/carnes do dia. Há `weekly_menu_items`, mas o seed usa apenas `everyday`.
- Não há modelagem explícita de quantidade de pedaços de carne vinculada a opções de carne.
- Não há lista oficial/modelada de sabores/volumes de bebidas.

## 4. Contagem atual do banco

Restaurante auditado: `restaurante-sol`

| Entidade | Quantidade |
| --- | ---: |
| Categorias | 6 |
| Produtos totais | 11 |
| Produtos ativos | 10 |
| Produtos disponíveis por padrão | 10 |
| Marmitas | 4 |
| Combos | 2 |
| Bebidas | 2 |
| Sucos | 1 |
| Feijoadas | 1 |
| Produtos adicionais | 1 |
| Opções totais | 25 |
| Opções vinculadas a produto | 24 |
| Opções globais | 1 |
| Opções adicionais | 5 |
| Cardápios semanais | 1 |
| Itens de cardápio semanal | 10 |
| Itens semanais específicos por dia | 0 |
| Overrides diários por produto | 0 |
| Overrides diários por opção | 3 |

Os 3 overrides por opção existentes estão marcados como `available` em 2026-07-14. Não há produto esgotado por override no estado atual.

## 5. Produtos encontrados

| Produto | Slug | Categoria | Preço | Ativo | Grupos/opções |
| --- | --- | --- | ---: | --- | --- |
| N5 Casa | `n5-casa` | marmitas | R$ 8,00 | sim | guarnições, adicionais, salada; 9 opções |
| N8 Casa | `n8-casa` | marmitas | R$ 13,00 | sim | salada, guarnições, adicionais; 9 opções |
| N8 Tradicional | `n8-tradicional` | marmitas | R$ 16,00 | sim | bife, adicionais; 3 opções |
| N9 Tradicional | `n9-tradicional` | marmitas | R$ 18,00 | sim | adicionais; 1 opção |
| Combo N8 Casa Baby | `combo-n8-casa-baby` | combos | R$ 15,00 | sim | sem opções |
| Combo N8 com latinha | `combo-n8-com-latinha` | combos | R$ 20,00 | sim | bebidas; 2 opções |
| Latinha | `latinha` | bebidas | R$ 5,00 | sim | sem opções |
| Latinha Zero | `latinha-zero` | bebidas | R$ 5,00 | sim | sem opções |
| Suco | `suco` | sucos | R$ 7,00 | sim | sem opções |
| Feijoada | `feijoada` | feijoadas | sem preço | não | sem opções |
| Ovo frito adicional | `ovo-frito-adicional` | adicionais | R$ 2,00 | sim | sem opções |

## 6. Matriz de comparação

| Item esperado | Categoria esperada | Preço esperado | Existe no banco | Preço encontrado | Categoria encontrada | Situação |
| --- | --- | ---: | --- | ---: | --- | --- |
| N5 Casa | marmitas | R$ 8,00 | sim | R$ 8,00 | marmitas | regra incompleta |
| N8 Casa | marmitas | R$ 13,00 | sim | R$ 13,00 | marmitas | regra incompleta |
| N8 Tradicional | marmitas | R$ 16,00 | sim | R$ 16,00 | marmitas | OK parcial |
| N9 Tradicional | marmitas | R$ 18,00 | sim | R$ 18,00 | marmitas | OK parcial |
| Combo N8 Casa Baby | combos | R$ 15,00 | sim | R$ 15,00 | combos | OK |
| Combo N8 com latinha | combos | R$ 20,00 | sim | R$ 20,00 | combos | OK |
| Latinha | bebidas | R$ 5,00 | sim | R$ 5,00 | bebidas | precisa de confirmação |
| Latinha Zero | bebidas | R$ 5,00 | sim | R$ 5,00 | bebidas | precisa de confirmação |
| Suco | sucos | R$ 7,00 | sim | R$ 7,00 | sucos | precisa de confirmação |
| Ovo frito adicional | adicionais | R$ 2,00 | sim | R$ 2,00 | adicionais | OK |
| Feijoada | feijoadas | não definido | sim | sem preço | feijoadas | precisa de confirmação |

## 7. Inconsistências críticas

1. `n5-casa`: grupo de opções de carne ausente.
2. `n8-casa`: grupo de opções de carne ausente.
3. `weekly_menu_items`: não há itens específicos por dia; o cardápio semanal está populado apenas como `everyday`.
4. `n5-casa`: contém opção de salada `repolho-com-tomate`, mas a regra oficial diz que a salada é escolhida pela casa entre beterraba/cenoura.
5. `n5-casa`: contém opção de salada `vinagrete`, mas a regra oficial diz que a salada é escolhida pela casa entre beterraba/cenoura.

## 8. Produtos, bebidas e adicionais ausentes ou incompletos

Ausente/incompleto por regra:

- Carnes das marmitas da casa não estão modeladas como opções selecionáveis ou controláveis por disponibilidade.
- Quantidade de pedaços de carne existe em `composition_rules`, mas não há opção de carne vinculada para validar a escolha.
- Cardápio semanal completo do dia para N8/N9 tradicional não está representado; há apenas vínculo `everyday`.

Bebidas:

- Existem `Latinha`, `Latinha Zero` e `Suco`.
- Não existe lista detalhada de sabores, marcas, água, 600 ml, 1 L ou 2 L.
- A documentação encontrada também não confirma de forma oficial esses detalhes; não tratar como divergência automática sem confirmação do restaurante.

Adicionais:

- Existe `Ovo frito adicional`.
- Não foi encontrada lista oficial fechada de carnes adicionais além da regra de bife do N8.

## 9. Duplicações e órfãos

Não foram encontrados pelo comando:

- Slugs duplicados de produto no restaurante auditado.
- Produtos ativos sem categoria.
- Produtos ativos sem preço, exceto feijoada que está inativa e marcada como pendente.
- Opções apontando para produto inexistente.
- Itens de cardápio semanal órfãos.
- Nomes duplicados com preços divergentes.

## 10. Seeder

Seeder responsável:

- `backend/database/seeders/MenuSeeder.php`

Comportamento:

- Usa `firstOrCreate` para a empresa.
- Usa `updateOrCreate` para categorias.
- Usa `updateOrCreate` para produtos.
- Usa `updateOrCreate` para opções.
- Usa `updateOrCreate` para itens de cardápio semanal.

Conclusão:

- O seeder é idempotente contra duplicação básica.
- O seeder está simplificado/incompleto frente à fonte oficial.
- O maior risco ao executar novamente não é duplicar, mas sobrescrever/confirmar uma estrutura incompleta como se fosse oficial.
- O comando que popularia seria `php artisan db:seed --class=MenuSeeder`, mas ele não foi executado nesta auditoria.

## 11. API

Endpoint usado pela tela:

- `GET /api/restaurants/{company:slug}/menu/available`

Arquivos:

- `backend/routes/api.php`
- `backend/app/Http/Controllers/Menu/AvailableMenuController.php`
- `backend/app/Services/Menu/MenuAvailabilityService.php`
- `backend/app/Http/Resources/ProductResource.php`
- `backend/app/Http/Resources/ProductOptionResource.php`

Comportamento observado:

- Retorna apenas produtos ativos.
- Exige categoria ativa.
- Exclui produtos com override diário `unavailable`.
- Inclui produtos com override diário `available`.
- Inclui produtos disponíveis por padrão que estejam vinculados a cardápio semanal ativo para `everyday` ou dia da semana.
- Carrega opções ativas.
- Opções com override diário `unavailable` não somem do payload; elas retornam `available_today: false`.
- Não há paginação nesse endpoint.

Payload atual:

- A API/service retorna 10 produtos ativos: `n5-casa`, `n8-casa`, `n8-tradicional`, `n9-tradicional`, `combo-n8-casa-baby`, `combo-n8-com-latinha`, `latinha`, `latinha-zero`, `suco`, `ovo-frito-adicional`.
- A feijoada não retorna porque está inativa e indisponível por padrão.

Divergência banco/API:

- A API não retorna todos os produtos do banco; filtra corretamente produtos inativos.
- A API retorna os dados incompletos existentes no banco. Portanto, ela não corrige a ausência de carnes, cardápio semanal diário e granularidade de bebidas.

## 12. Frontend

Arquivos principais:

- `frontend/src/services/crm.service.ts`
- `frontend/src/features/cardapio/MenuPage.tsx`
- `frontend/src/types/crm.ts`

Achados:

- `crm.service.ts` busca o snapshot operacional e, se houver `company.slug`, chama `/api/restaurants/{slug}/menu/available`.
- Se a chamada do cardápio falha, o serviço retorna `fallbackProducts`, o que pode mascarar erro real da API em desenvolvimento.
- `mapBackendProduct` transforma `product_type`, `menu_rule_code` e `api` em `tags`.
- `MenuPage` renderiza `product.tags` literalmente; isso expõe tags técnicas como `marmita`, `n5_casa` e `api`.
- A tela lista todos os produtos recebidos e agrupa opções por `groupLabel`.
- Como opções compartilhadas são duplicadas por produto no banco, a tela também mostra componentes repetidos por produto.
- A tela diferencia disponibilidade por opção (`availableToday`) e não desativa a marmita inteira quando uma opção acaba, o que está alinhado ao conceito correto.

## 13. Comando de auditoria criado

Arquivo:

- `backend/app/Console/Commands/MenuAuditCommand.php`

Comandos:

```bash
cd backend
php artisan menu:audit
php artisan menu:audit --format=json
php artisan menu:audit --format=table
php artisan menu:audit --restaurant=restaurante-sol --format=json
```

Comportamento:

- Somente leitura.
- Resolve restaurante por `id` ou `slug`.
- Lista contagens.
- Lista produtos.
- Compara com matriz oficial conhecida.
- Detecta regras ausentes.
- Detecta slugs duplicados.
- Detecta nomes duplicados com preço divergente.
- Detecta produtos ativos sem preço.
- Detecta opções órfãs.
- Detecta vínculos semanais órfãos.
- Detecta ausência de itens semanais por dia.
- Retorna exit code `1` quando há inconsistências críticas.

Teste criado:

- `backend/tests/Feature/Menu/MenuAuditCommandTest.php`

Objetivo do teste:

- Confirmar que o comando não altera contagens das tabelas de cardápio.

## 14. Consultas somente leitura utilizadas

Consultas equivalentes usadas pelo comando/auditoria:

```sql
select count(*) from product_categories where company_id = :company_id;
select count(*) from products where company_id = :company_id;
select count(*) from products where company_id = :company_id and is_active = true;
select count(*) from products where company_id = :company_id and product_type = :type;
select count(*) from product_options where company_id = :company_id;
select count(*) from product_options where company_id = :company_id and product_id is not null;
select count(*) from weekly_menus where company_id = :company_id;
select count(*) from weekly_menu_items where weekly_menu_id in (select id from weekly_menus where company_id = :company_id);
select count(*) from weekly_menu_items where service_day <> 'everyday' and weekly_menu_id in (select id from weekly_menus where company_id = :company_id);
select count(*) from daily_menu_overrides where company_id = :company_id;
select count(*) from daily_menu_option_overrides where company_id = :company_id;
select products.* from products where company_id = :company_id order by display_order, name;
select product_options.* from product_options where company_id = :company_id order by product_id, display_order, name;
```

Consultas de órfãos:

```sql
select count(*)
from product_options
left join products on products.id = product_options.product_id
where product_options.company_id = :company_id
  and product_options.product_id is not null
  and products.id is null;

select count(*)
from weekly_menu_items
left join weekly_menus on weekly_menus.id = weekly_menu_items.weekly_menu_id
left join products on products.id = weekly_menu_items.product_id
where (weekly_menus.company_id = :company_id or products.company_id = :company_id)
  and (weekly_menus.id is null or products.id is null);
```

## 15. Risco e plano sugerido

Nível de risco: alto para operação real do cardápio, médio para estrutura técnica.

Motivo:

- Preços principais estão corretos.
- Produtos principais existem.
- A API funciona e filtra disponibilidade.
- Porém regras fundamentais de carnes, N5 Casa, cardápio semanal diário e granularidade de bebidas não estão completas.

Plano de correção sugerido:

1. Confirmar com o restaurante a lista oficial completa de carnes, bebidas, sabores, tamanhos e adicionais.
2. Normalizar grupos de opções ou, no mínimo, tornar explícitos min/max por grupo no domínio.
3. Corrigir N5 Casa para não expor saladas que são escolha da casa.
4. Criar opções de carne e disponibilidade por componente.
5. Popular cardápio semanal real por dia para N8/N9 tradicional.
6. Separar bebidas genéricas de bebidas reais vendáveis.
7. Ajustar frontend para não exibir tags técnicas.
8. Reexecutar `php artisan menu:audit` até remover críticas ou justificar cada exceção.

## 16. Validações

Executado:

```bash
php artisan menu:audit --format=json
php artisan test --filter=MenuAudit
php artisan test
```

Resultados:

- `php artisan menu:audit --format=json`: executou e retornou exit code `1` por 5 inconsistências críticas.
- `php artisan test --filter=MenuAudit`: falhou por ambiente, `could not find driver`, usando SQLite `:memory:`.
- `php artisan test`: falhou por ambiente, `could not find driver`, usando SQLite `:memory:`.

Pendência de validação:

- Instalar/habilitar `pdo_sqlite` no PHP local ou ajustar ambiente de teste para executar a suíte.

## 17. Garantias desta auditoria

- Nenhuma migration foi executada.
- Nenhum seeder foi executado.
- Nenhum comando destrutivo foi executado.
- Nenhuma linha do banco PostgreSQL foi alterada pelos comandos de auditoria.
- Nenhuma credencial foi impressa.
- Nenhum dado sensível de cliente, usuário, Pix, telefone, endereço, CPF, token ou secret foi incluído neste relatório.

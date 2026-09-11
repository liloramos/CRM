# V1_USER_ACCESS_GOVERNANCE

**Projeto:** ChatBotCRM — Restaurante Sol
**Status:** Contrato de implementação para fechamento da V1
**Escopo:** Perfil pessoal, usuários, perfis de acesso, permissões, delegação administrativa, avatar, cargo e elegibilidade para vendas
**Princípio:** acesso ao sistema, identidade pessoal e responsabilidade operacional são conceitos separados.

---

## 1. Objetivo

Este documento define a governança de usuários e acessos do ChatBotCRM para a V1 do Restaurante Sol.

O sistema deve permitir que a operação tenha:

- um acesso operacional simples para o dia a dia;
- usuários individuais reais cadastrados;
- perfis de acesso e permissões controlados;
- um nível máximo DEV protegido;
- delegação administrativa controlada;
- perfil pessoal editável sem permitir autoelevação de privilégios;
- avatar consistente em toda a aplicação;
- cargo persistido corretamente;
- responsável por venda separado do usuário autenticado;
- isolamento absoluto entre empresas/tenants.

A implementação deve evitar regras baseadas em nomes, e-mails ou pessoas específicas.

---

## 2. Problemas observados no smoke atual

### 2.1 Cargo não persiste corretamente

Na tela **Perfil**, o usuário consegue preencher `Cargo` e recebe feedback de sucesso, porém o valor não reaparece como esperado após salvar/recarregar.

Comportamento desejado:

- o cargo deve ser persistido;
- deve ser retornado pela API;
- deve reaparecer na tela Perfil;
- deve aparecer onde a aplicação decidir exibir cargo;
- cargo não deve alterar permissões.

Exemplo:

```text
Nome: Murilo César
Cargo: Desenvolvedor
Perfil de acesso: DEV
```

`Cargo` é informação de identidade/organização, não autorização.

### 2.2 Avatar não é projetado de forma consistente

Foi possível salvar/exibir uma foto no card de identidade do Perfil, porém a mesma imagem não aparece em outros lugares, como:

- avatar da sidebar;
- menu do usuário;
- Usuários e permissões;
- outras superfícies que mostram a identidade do usuário.

A regra deve ser única:

```text
se avatar válido:
    mostrar a foto
senão:
    mostrar iniciais
```

A aplicação deve possuir uma única representação canônica do avatar do usuário.

Não deve haver uma implementação diferente de URL/campo em cada tela.

### 2.3 Usuários e permissões ainda precisa ser uma área administrativa completa

A tela já possui conceito de:

- adicionar usuário;
- editar usuário;
- perfil de acesso;
- cargo;
- contato.

Para a V1, ela deve ser a fonte administrativa para:

- criação de usuário;
- atualização de dados administrativos;
- definição de perfil de acesso;
- gestão de permissões, conforme arquitetura existente;
- ativação/desativação;
- definição de elegibilidade para venda;
- delegação administrativa segura.

---

## 3. Separação obrigatória de conceitos

O sistema NÃO deve misturar:

```text
IDENTIDADE PESSOAL
nome
email
telefone
cargo
foto
senha

ACESSO AO SISTEMA
perfil de acesso
permissões
capacidades administrativas

RESPONSABILIDADE OPERACIONAL
pode ser responsável por venda
seller_user_id
seller_name_snapshot

ATOR DE AUDITORIA
usuário autenticado que executou a ação
```

São quatro conceitos distintos.

### 3.1 Exemplo

Uma venda pode ser registrada assim:

```text
Usuário autenticado:
Larissa

Responsável pela venda:
Beatriz

Ator da ação:
Larissa

Cliente:
José
```

Isso é válido.

---

## 4. Hierarquia de autoridade

A implementação deve respeitar a hierarquia conceitual abaixo.

### 4.1 DEV / proprietário técnico

Nível máximo do sistema para o tenant/produto.

Na operação atual, Murilo ocupa esse papel; isso é apenas referência operacional. **Nunca hardcodar nome ou e-mail.**

O sistema deve representar estruturalmente esse nível.

O DEV pode:

- administrar usuários;
- administrar perfis;
- administrar permissões;
- conceder permissões delegáveis;
- acessar todas as áreas permitidas pelo produto;
- configurar integrações e recursos técnicos autorizados;
- visualizar e administrar configurações avançadas;
- conceder poder administrativo para outros usuários;
- revogar permissões;
- definir elegibilidade para vendas.

O DEV não deve poder ser criado ou promovido por um usuário de autoridade inferior.

### 4.2 Administração / Gerência

Pode receber poderes administrativos amplos, inclusive:

- usuários;
- pedidos;
- financeiro;
- relatórios;
- configurações;
- cardápio;
- operação.

Porém:

- não se torna DEV;
- não pode conceder permissões que não possui;
- não pode conceder capacidades DEV protegidas;
- não pode elevar a si próprio acima do seu teto de autoridade;
- não pode editar/rebaixar/remover o último DEV protegido.

### 4.3 Atendente

Pode ter acesso operacional necessário para o restaurante.

Pode existir um login compartilhado temporariamente durante a V1, mas os usuários reais podem permanecer cadastrados para:

- responsabilidade por venda;
- histórico;
- futura autenticação individual;
- evolução de permissões.

---

## 5. DEV não pode ser hardcoded

É proibido definir DEV por:

- nome;
- e-mail;
- telefone;
- ID fixo de seed;
- string específica da pessoa.

O Codex deve primeiro auditar a arquitetura atual de roles/perfis/permissões.

Preferir reutilizar a estrutura existente.

Se não existir uma representação adequada, criar a menor extensão necessária.

---

## 6. Proteção contra privilege escalation

Esta regra é obrigatória no backend.

### 6.1 Regra central

> Um usuário só pode conceder permissões que ele próprio possui e que está autorizado a delegar.

Além disso, capacidades DEV podem ser não delegáveis.

### 6.2 Exemplo

```text
Murilo / DEV:
A B C D E F

Helton / Gerência:
A B C D
```

Helton pode administrar usuários se tiver essa capacidade.

Porém Helton NÃO pode conceder `E` ou `F` se não possui essas capacidades ou se forem protegidas como DEV-only.

### 6.3 Proteções obrigatórias

O backend deve impedir:

- usuário comum promover a si próprio;
- usuário comum promover outro acima do próprio nível;
- admin criar/promover DEV;
- admin alterar poderes protegidos do DEV;
- remoção do último DEV/owner, se essa noção existir;
- mudança de permissões por payload manual/API ignorando a UI;
- manipulação cross-tenant.

Esconder botão no frontend NÃO é segurança suficiente.

---

## 7. Perfil pessoal

A tela **Perfil** deve cuidar somente da identidade e segurança da própria conta.

### 7.1 Campos esperados

```text
Nome completo
E-mail
Telefone
Cargo
Foto/avatar
Senha
```

### 7.2 O próprio usuário pode editar

Conforme validações e política existente:

- nome;
- e-mail;
- telefone;
- cargo;
- foto;
- senha.

O backend deve validar unicidade/formatos necessários.

### 7.3 O próprio usuário NÃO pode editar no Perfil

```text
perfil de acesso
permissões
nível DEV
company_id
elegibilidade administrativa protegida
```

Esses controles pertencem a **Usuários e permissões**.

---

## 8. Cargo

`Cargo` deve ser um campo de identidade organizacional.

Exemplos:

```text
Desenvolvedor
Atendente
Gerente
Caixa
Administrativo
```

Cargo não concede acesso.

Nunca fazer:

```text
if cargo == "Gerente":
    liberar financeiro
```

Permissões devem vir do sistema de autorização.

### 8.1 Aceitação

Após salvar `Cargo: Desenvolvedor`, o valor deve:

- persistir no banco;
- reaparecer após reload;
- ser retornado pela API;
- aparecer no card de identidade;
- poder ser alterado novamente.

---

## 9. Avatar canônico

Todos os consumidores devem utilizar a mesma informação de avatar.

### 9.1 Superfícies mínimas

- Perfil;
- sidebar;
- menu do usuário;
- Usuários e permissões;
- cabeçalhos relevantes;
- outros componentes de identidade já existentes.

### 9.2 Fallback

Se foto não existir, estiver removida ou falhar:

```text
iniciais do nome
```

Não mostrar imagem quebrada.

### 9.3 Segurança

Validar upload conforme padrão do projeto:

- formatos permitidos;
- tamanho máximo;
- storage controlado;
- URL segura;
- isolamento por empresa/usuário quando aplicável.

---

## 10. Usuários e permissões

Essa área é administrativa.

### 10.1 Deve permitir

- listar usuários;
- adicionar usuário;
- editar usuário;
- definir perfil de acesso;
- configurar permissões quando a arquitetura suportar;
- ativar/desativar usuário;
- configurar elegibilidade para vendas;
- visualizar dados essenciais;
- delegar administração dentro dos limites de autoridade.

### 10.2 Adicionar usuário

Fluxo deve permitir criar uma conta utilizável de verdade.

No mínimo:

```text
Nome
E-mail
Telefone opcional
Cargo opcional
Perfil de acesso
Status ativo
Elegibilidade para vendas
```

A senha/invite deve seguir a arquitetura atual.

Não inventar um segundo sistema de autenticação.

### 10.3 Editar usuário

Administrador autorizado pode alterar:

- identidade administrativa;
- cargo;
- perfil;
- permissões delegáveis;
- elegibilidade para vendas;
- status.

Não deve poder violar o teto de autoridade.

---

## 11. Perfis de acesso e permissões

Perfis são agrupadores de capacidades, não substitutos para todas as regras de domínio.

Exemplos conceituais:

```text
DEV
Gerência
Atendente
```

A nomenclatura final deve respeitar o que já existe no projeto.

Se o projeto já utiliza permissões granulares, preferir algo conceitualmente equivalente a:

```text
users.view
users.manage
orders.view
orders.manage
menu.view
menu.manage
payments.view
payments.manage
finance.view
finance.manage
deliveries.view
deliveries.manage
reports.view
settings.view
settings.manage
```

Não criar uma matriz nova se já existe arquitetura equivalente.

---

## 12. Delegação administrativa

Usuários autorizados podem receber poder para administrar outros usuários.

Exemplo:

```text
Murilo / DEV
  ↓ concede

Helton / Gerência
  ✓ users.view
  ✓ users.manage
  ✓ orders.manage
  ✓ finance.view
  ✓ reports.view
```

Mesmo tendo `users.manage`, Helton continua limitado ao próprio teto.

### 12.1 Regra de delegação

Ao editar outro usuário:

```text
permissões disponíveis para conceder
=
permissões delegáveis
∩
autoridade do ator
```

Nunca confiar apenas no frontend.

---

## 13. Elegibilidade para responsável por venda

Esse conceito deve permanecer separado de perfil e cargo.

Conceito esperado:

```text
can_be_seller
```

ou nome equivalente coerente com o projeto.

### 13.1 Exemplo

```text
Larissa
Perfil: Atendente
Pode ser responsável por vendas: Sim

Beatriz
Perfil: Atendente
Pode ser responsável por vendas: Sim

Helton
Perfil: Gerência
Pode ser responsável por vendas: Sim

Murilo
Perfil: DEV
Pode ser responsável por vendas: Não
```

### 13.2 Regra

O select de responsável deve listar apenas usuários:

- da empresa correta;
- ativos/válidos;
- explicitamente elegíveis.

Não inferir apenas pelo perfil `Atendente`.

### 13.3 Histórico

Remover elegibilidade posteriormente NÃO deve apagar vendas antigas.

Pedidos existentes preservam:

```text
seller_user_id
seller_name_snapshot
```

conforme implementação atual.

---

## 14. Login compartilhado temporário

Para a V1, é aceitável a operação usar um único login no computador, por exemplo `Larissa / Atendente`.

Mesmo assim:

```text
Venda A → Beatriz
Venda B → Larissa
Venda C → Helton
Venda D → Calebe
```

podem ser atribuídas corretamente.

Portanto:

```text
authenticated user != seller
```

---

## 15. Usuários sem login frequente

Uma pessoa pode existir como usuário operacional e elegível para venda mesmo que não faça login diariamente.

Não exigir que cada venda troque de sessão.

Quando o restaurante evoluir para logins individuais, a mesma estrutura deve continuar válida.

---

## 16. Tenant isolation

Regra obrigatória em todos os endpoints.

Um usuário da empresa A nunca pode:

- visualizar usuários privados da empresa B;
- atribuir usuário da empresa B a uma venda;
- editar usuário da empresa B;
- conceder permissões na empresa B;
- consultar perfis da empresa B.

Todos os IDs recebidos do frontend devem ser revalidados no backend.

---

## 17. Usuário desativado/removido

A implementação deve respeitar a arquitetura existente.

### 17.1 Desativado

Usuário desativado:

- não deve autenticar, conforme política existente;
- não deve aparecer como nova opção operacional;
- histórico existente permanece.

### 17.2 Removido

Se exclusão física for permitida pela arquitetura:

- FKs históricas devem ser seguras;
- snapshots devem preservar contexto;
- Orders não podem quebrar;
- logs não podem desaparecer silenciosamente.

Preferir desativação quando a preservação de auditoria exigir.

---

## 18. Auditoria

Alterações sensíveis devem registrar ator quando o projeto já possuir mecanismo de histórico.

Eventos importantes:

```text
usuário criado
usuário desativado
perfil alterado
permissão alterada
elegibilidade de venda alterada
responsável da venda alterado
```

Não criar sistema paralelo de auditoria se já existe um mecanismo canônico.

---

## 19. UX de Usuários e permissões

A tela atual pode ser polida sem virar painel corporativo complexo.

### 19.1 Lista

Cada usuário deve permitir leitura rápida de:

```text
Avatar
Nome
Contato
Cargo
Perfil
Status
Elegível para vendas
Ações
```

Evitar excesso de densidade.

### 19.2 Edição

Preferir modal/painel coerente com o design atual.

Separar visualmente:

```text
IDENTIDADE
Nome
Email
Telefone
Cargo
Foto

ACESSO
Perfil
Permissões
Ativo

OPERAÇÃO
[✓] Pode ser responsável por vendas
```

---

## 20. Comportamento esperado para o DEV

O DEV deve conseguir:

- criar todos os usuários;
- editar usuários;
- atribuir perfis;
- conceder permissões delegáveis;
- remover permissões;
- administrar seller eligibility;
- visualizar todo o tenant;
- delegar `users.manage` para alguém como Helton.

Porém nenhum outro usuário deve conseguir:

- obter DEV sozinho;
- promover outro usuário para DEV;
- modificar o DEV protegido fora da autoridade permitida.

---

## 21. Compatibilidade com Seller Attribution

A governança de usuários deve ser a fonte para os candidatos de seller.

Não criar outra lista de vendedores.

Fluxo esperado:

```text
Usuários e permissões
        ↓
can_be_seller
        ↓
fonte canônica de sellers elegíveis
        ↓
Caixa / Pedidos
```

---

## 22. Compatibilidade com Copilot

O Copilot não deve atribuir um vendedor humano automaticamente.

Pedidos automáticos continuam:

```text
Responsável: Não atribuído
```

até uma pessoa autorizada realizar a atribuição.

Não alterar a arquitetura conversacional para resolver este documento.

---

## 23. Compatibilidade com Labia

A futura assistente **Labia** poderá orientar o usuário sobre:

- onde administrar usuários;
- onde editar o próprio perfil;
- como trocar senha;
- onde definir responsável de venda;
- como verificar permissões.

Porém:

- não deve executar elevação de privilégio sem autorização;
- não deve vazar permissões que o usuário não pode conhecer;
- deve respeitar RBAC.

A implementação da Labia é outro escopo.

---

## 24. Backend como autoridade

Todas as regras críticas devem estar no backend.

Frontend pode esconder, desabilitar e orientar.

Mas o backend deve impedir:

- privilege escalation;
- cross-tenant access;
- seller inválido;
- perfil inválido;
- alteração de DEV sem autoridade;
- edição de permissions por payload manipulado.

---

## 25. Migration policy

Antes de criar migration:

1. auditar User/membership/profile;
2. auditar roles/permissões;
3. reutilizar campo existente se semanticamente correto.

Se for necessário criar novos campos, fazer migration pequena e backward-compatible.

Possíveis necessidades conceituais:

```text
can_be_seller
authority_level / protected_role
```

Somente se a arquitetura atual realmente não possuir equivalente.

Nunca usar `migrate:fresh` ou `migrate:refresh` em dados reais.

---

## 26. Acceptance tests — Perfil

### P01 — Cargo persiste

1. editar cargo;
2. salvar;
3. reload;
4. valor continua correto.

### P02 — E-mail

Alteração válida persiste e autenticação segue política existente.

### P03 — Senha

Senha só é alterada após validação adequada.

### P04 — Avatar

Foto aparece em:

- Perfil;
- sidebar;
- menu;
- Usuários e permissões.

### P05 — Avatar removido

Fallback volta para iniciais.

---

## 27. Acceptance tests — Usuários

### U01 — Criar usuário

Usuário válido é criado dentro do tenant correto.

### U02 — Login

Conta criada consegue autenticar conforme fluxo existente.

### U03 — Editar

Dados administrativos persistem.

### U04 — Desativar

Usuário desativado segue política de autenticação e desaparece de novas seleções operacionais.

### U05 — Tenant

Usuário A não administra usuário B de outra empresa.

---

## 28. Acceptance tests — RBAC

### R01 — Atendente não se promove

Payload manual deve falhar.

### R02 — Admin não cria DEV

Deve falhar no backend.

### R03 — Gerente só delega o que pode

Permissões fora do teto devem ser rejeitadas.

### R04 — DEV administra permissões

DEV pode conceder capacidades delegáveis.

### R05 — Último DEV

Operação que deixaria o tenant sem autoridade máxima deve ser bloqueada se aplicável à arquitetura.

### R06 — Perfil pessoal não altera RBAC

Editar nome/cargo/foto não muda perfil nem permissões.

---

## 29. Acceptance tests — Seller eligibility

### S01 — Elegível aparece

Usuário marcado aparece no Caixa/Pedidos.

### S02 — Não elegível não aparece

Admin DEV não elegível fica fora da lista.

### S03 — Perfil não determina sozinho

Gerente pode ser seller se explicitamente marcado.

### S04 — Histórico

Remover elegibilidade não altera Order antigo.

### S05 — API

Payload tentando atribuir seller não elegível deve ser rejeitado.

### S06 — Automático

Order vindo do Copilot nasce sem seller humano.

---

## 30. Acceptance tests — Avatar canônico

### A01

Upload feito no Perfil reflete após refresh nas superfícies de identidade.

### A02

Não usar URLs absolutas locais incorretas.

### A03

Falha de imagem não cria `<img>` quebrado.

### A04

Trocar foto atualiza consumidores e evita cache stale conforme estratégia existente.

---

## 31. Não objetivos desta rodada

Não misturar este trabalho com:

- Conversational Intelligence;
- atendimento WhatsApp;
- IA e Automação;
- Labia;
- Cardápio;
- Payment;
- Delivery;
- impressão;
- Epson;
- horários;
- número oficial Meta;
- deploy;
- freeze geral.

---

## 32. Estratégia de implementação recomendada

1. auditar User + membership + RBAC existentes;
2. corrigir persistência de cargo;
3. consolidar avatar canônico;
4. formalizar autoridade DEV sem hardcode;
5. implementar delegation ceiling;
6. consolidar Users & Permissions;
7. integrar seller eligibility;
8. adicionar backend guards;
9. adicionar testes focados;
10. smoke manual;
11. atualizar `CURRENT_CHECKPOINT.md`.

---

## 33. Gate de conclusão V1

Este bloco só pode ser considerado concluído quando:

- cargo persiste;
- avatar aparece consistentemente;
- criação de usuário funciona;
- login do novo usuário funciona;
- Perfil edita apenas dados próprios permitidos;
- usuário comum não altera acesso;
- DEV está protegido;
- delegação administrativa respeita teto;
- seller eligibility usa a mesma fonte administrativa;
- tenant isolation passa;
- testes focados passam;
- lint/build passam se frontend for alterado;
- Pint passa;
- `git diff --check` passa;
- smoke manual confirma o fluxo.

---

## 34. Regra final

O CRM deve permitir administração flexível sem transformar flexibilidade em risco.

A regra de ouro é:

```text
IDENTIDADE é editável.
OPERAÇÃO é configurável.
PERMISSÃO é controlada.
AUTORIDADE é limitada.
HISTÓRICO é preservado.
TENANT nunca cruza.
```

Nenhum usuário deve ganhar autoridade por acidente, por nome, por cargo textual ou por manipulação do frontend.

# CRM_DOCUMENTACAO_EXECUTAVEL_V1.9 — Complemento Incremental

> Este arquivo é um complemento incremental para a documentação executável do ChatBotCRM.
>
> Ele não substitui os documentos anteriores. Deve ser adicionado como seção complementar ou usado para criar a versão `V1.9`, caso o projeto esteja mantendo versionamento formal da documentação.

---

## 1. Objetivo da atualização V1.9

A versão V1.9 consolida dois pontos importantes para o início da implementação:

1. Diretrizes de organização do front-end para manter o código limpo, bonito, consistente e fácil de alterar.
2. Regras operacionais extraídas de atendimento real via WhatsApp, sem copiar dados sensíveis ou conversas reais para o repositório.

Esta atualização não muda as regras de negócio oficiais já documentadas. Ela complementa o projeto para orientar melhor o desenvolvimento do front e a experiência real de atendimento.

---

## 2. Novos documentos adicionados

Adicionar os seguintes documentos ao projeto:

```txt
docs/front/frontend-guidelines.md
docs/backlog/atendimento-real-whatsapp.md
```

### 2.1 `docs/front/frontend-guidelines.md`

Define:

- identidade visual aprovada;
- organização recomendada das pastas do front;
- padrão de componentes reutilizáveis;
- design system simples;
- regras para mocks;
- estados de interface;
- organização por features;
- padrão para facilitar manutenção manual pelo desenvolvedor.

### 2.2 `docs/backlog/atendimento-real-whatsapp.md`

Define:

- regras para pedido fragmentado;
- cliente pagador, beneficiário e retirante;
- observações por item;
- preferências recorrentes;
- crédito e saldo do cliente;
- restrições alimentares;
- item indisponível;
- atendimento urgente;
- comportamento prudente da IA;
- impressão obrigatória no fluxo operacional.

---

## 3. Uso das referências visuais

As imagens geradas durante a etapa de concepção visual devem ser usadas apenas como referência estética.

Podem orientar:

- layout;
- disposição dos cards;
- aparência da sidebar;
- estilo de modais;
- hierarquia visual;
- tema escuro;
- uso de laranja/amarelo;
- padrões de estados vazios, loading e erro.

Não podem ser usadas como fonte oficial de:

- preços;
- nomes;
- datas;
- endereços;
- produtos definitivos;
- regras de negócio;
- permissões;
- dados financeiros;
- dados pessoais.

---

## 4. Regras novas de implementação do front

O front-end deve ser implementado de forma modular e fácil de editar.

Regras obrigatórias:

1. Usar TypeScript.
2. Criar componentes reutilizáveis.
3. Centralizar tokens visuais.
4. Separar mocks em `src/mocks`.
5. Separar tipos em `src/types`.
6. Separar constantes em `src/constants`.
7. Separar services em `src/services`.
8. Evitar dados fictícios hardcoded diretamente nas páginas.
9. Evitar componentes gigantes.
10. Manter nomes claros e relacionados ao domínio.

Estrutura recomendada:

```txt
src/
  components/
    ui/
    layout/
  constants/
  features/
    pedidos/
    conversas/
    clientes/
    cardapio/
    entregas/
    pagamentos/
    financeiro/
    relatorios/
    configuracoes/
  mocks/
  services/
  types/
  utils/
```

---

## 5. Regras novas de atendimento real

O sistema deve suportar situações reais de atendimento, incluindo:

- pedidos incompletos;
- mensagens quebradas em sequência;
- cliente que altera pedido no meio da conversa;
- cliente que paga antes de finalizar;
- cliente que paga a mais ou a menos;
- crédito para próxima compra;
- retirada por terceiros;
- marmita personalizada por pessoa;
- observações por item;
- restrições alimentares;
- pedido recorrente como “igual ontem”;
- itens indisponíveis;
- confirmação antes de fechar;
- urgência operacional.

---

## 6. Campos recomendados para evolução do modelo de pedido

Estes campos devem ser considerados no refinamento do banco/API:

```ts
interface PedidoOperacionalComplementar {
  clientePagadorId: string;
  nomeRetirante?: string;
  telefoneRetirante?: string;
  observacaoRetirada?: string;
  pedidoRecorrenteReferenciaId?: string;
  creditoAplicado?: number;
  saldoPendente?: number;
  prioridade?: 'normal' | 'urgente';
  precisaConfirmacaoHumana: boolean;
  resumoIA?: string;
}
```

Itens do pedido também devem permitir observações individuais:

```ts
interface ItemPedidoComplementar {
  pessoaDestino?: string;
  observacoes?: string;
  preferencias?: string[];
  restricoes?: string[];
}
```

---

## 7. IA no atendimento

A IA deve atuar como apoio ao atendente.

Ela pode:

- resumir conversas;
- identificar itens mencionados;
- sugerir perguntas de confirmação;
- detectar preferências recorrentes;
- alertar sobre crédito;
- alertar sobre item indisponível;
- sugerir resposta cordial.

Ela não deve:

- inventar preço;
- inventar item;
- confirmar pedido ambíguo;
- aplicar crédito sem confirmação;
- prometer entrega sem disponibilidade;
- ignorar restrição alimentar;
- expor dados sensíveis.

---

## 8. Privacidade e dados sensíveis

É proibido commitar no repositório:

- exportações brutas de WhatsApp;
- telefones reais;
- CPFs reais;
- chaves Pix reais;
- links de localização;
- comprovantes;
- prints de conversas reais;
- endereços reais;
- dados pessoais de clientes.

Conversas reais devem ser usadas apenas como referência privada para extrair requisitos generalizados.

---

## 9. Prompt recomendado para o Codex

```txt
Leia a documentação do projeto e implemente o front-end seguindo os documentos oficiais.

Use as imagens geradas apenas como referência estética e visual. Não use nomes, preços, produtos, datas, endereços ou valores das imagens como regra de negócio.

Leia também:
- docs/front/frontend-guidelines.md
- docs/backlog/atendimento-real-whatsapp.md

O front deve ser limpo, modular, bonito e fácil de alterar manualmente. Use TypeScript, componentes reutilizáveis, mocks centralizados, tipos bem definidos e constantes para status, rotas, cores e menus.

Não commite .env, credenciais, conversas reais, CPFs, telefones, endereços, chaves Pix, comprovantes ou qualquer dado sensível.

Implemente pensando em atendimento real de restaurante: pedido fragmentado, observações por item, retirada por terceiros, crédito do cliente, preferências recorrentes, restrições alimentares, item indisponível, impressão obrigatória e IA prudente.
```

---

## 10. Checklist V1.9

- [ ] Criar `docs/front/frontend-guidelines.md`.
- [ ] Criar `docs/backlog/atendimento-real-whatsapp.md`.
- [ ] Referenciar os dois documentos na documentação executável principal.
- [ ] Não adicionar conversa real bruta ao repositório.
- [ ] Garantir que o Codex use as imagens apenas como referência visual.
- [ ] Garantir que o front seja organizado por componentes, features, types, constants, mocks e services.
- [ ] Garantir que pedidos suportem observações por item, crédito, retirada por terceiros e histórico.

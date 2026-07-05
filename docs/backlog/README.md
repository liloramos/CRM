# CRM Documentação Executável - V1.8

Pacote de documentação executável do projeto **ChatBotCRM / Sol Restaurante**.

Esta versão deixa pronto o plano de execução para o Codex, incluindo:

- documento mestre V1.8;
- plano de execução modular;
- prompts prontos por módulo;
- plano de commits detalhado;
- regras de segurança para `.env`, tokens e arquivos sensíveis;
- módulos de backend, front, IA, WhatsApp, impressão e integrações futuras;
- documentação de front/UX com regra clara: imagens são referência visual, não fonte de regra de negócio.

## Onde colocar no projeto

Copie o conteúdo desta pasta para:

```txt
CHATBOTCRM/docs/backlog/
```

O resultado ideal:

```txt
CHATBOTCRM/
└── docs/
    └── backlog/
        ├── README.md
        ├── 00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md
        ├── 00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.docx
        ├── 03_BACKLOG_EPICOS_USER_STORIES_CRITERIOS.md
        ├── codex/
        ├── modules/
        ├── adr/
        └── front/
```

## Primeiro arquivo para o Codex ler

```txt
docs/backlog/codex/README_CODEX.md
```

Depois:

```txt
docs/backlog/codex/PROMPT_INICIAL_CODEX_M00.md
```

## Regra de ouro

O Codex deve executar **um módulo por vez**.  
Não deve implementar tudo junto.  
Não deve avançar sem autorização do Murilo.

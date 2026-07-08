# ChatBotCRM Frontend

Interface operacional em React, TypeScript e Vite para o ChatBotCRM.

## Organizacao

- `src/components/layout`: estrutura visual, sidebar, topo e container de pagina.
- `src/components/ui`: componentes reutilizaveis de interface.
- `src/features`: telas operacionais por dominio.
- `src/mocks`: dados ficticios e sanitizados usados pela interface.
- `src/constants`: menus, cores, status e configuracoes visuais.
- `src/services`: camada de acesso aos dados mockados ou futura API.
- `src/types`: tipos compartilhados da interface.
- `src/utils`: formatadores e utilitarios.

## Comandos

```bash
npm run lint
npm run build
```

## Seguranca dos mocks

Os mocks devem permanecer ficticios. Nao inserir telefone real, CPF, endereco real,
chave Pix real, comprovante, token, credencial ou conversa real de WhatsApp.

As imagens em `../imgs/referencias-front/` sao apenas referencia estetica e de UX.
Nao copiar nomes, valores, enderecos, textos ou regras de negocio dessas imagens.

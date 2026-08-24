# Deploy de Produção

Este runbook prepara uma instalação única do ChatBot CRM para o Restaurante Sol. Ele não executa deploy automático nem contém segredos.

## Requisitos

- PHP e extensões exigidas pelo Composer do projeto;
- Composer e Node.js compatível com o `package-lock.json` do frontend;
- PostgreSQL persistente, com backup testado;
- servidor web com HTTPS público;
- `ffmpeg` e `ffprobe` no `PATH` ou informados por `WHATSAPP_FFMPEG_BINARY` e `WHATSAPP_FFPROBE_BINARY`;
- disco persistente para `backend/storage`;
- acesso à impressora térmica pelo navegador/driver do posto de trabalho.

## Variáveis de ambiente

Parta de `backend/.env.example`, sem versionar o `.env` real. Antes de publicar, configure ao menos:

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- `APP_URL` com domínio HTTPS público;
- conexão PostgreSQL persistente;
- `SESSION_SECURE_COOKIE=true` para HTTPS;
- `QUEUE_CONNECTION=database`;
- `DEMO_DATA_ENABLED=false`;
- `WHATSAPP_PROVIDER=meta` e as variáveis Meta listadas no `.env.example`;
- `PRINTING_PROVIDER=browser`.

Não inclua tokens Meta, chaves OpenAI, senhas de banco, arquivos de mídia ou comprovantes em repositório, logs compartilhados ou comandos copiados para chat.

O Copiloto online é opcional para o CRM subir. Quando escolhido, configure `AI_COPILOT_PROVIDER=openai` e a chave apenas no ambiente seguro. A IA não confirma pagamentos nem altera pedidos automaticamente.

No build do frontend, mantenha `VITE_ENABLE_MOCK_FALLBACK` ausente ou `false`. Os mocks servem somente ao desenvolvimento.

## Instalação e publicação

1. Faça backup do PostgreSQL e do diretório `backend/storage` antes da atualização.
2. Instale dependências backend: `composer install --no-dev --optimize-autoloader`.
3. Instale dependências frontend e gere o build: `npm ci` e `npm run build` no diretório `frontend`.
4. Execute as migrations aprovadas: `php artisan migrate --force` no diretório `backend`.
5. Crie o link público uma única vez por ambiente: `php artisan storage:link`.
6. Recrie caches após configurar o ambiente: `php artisan optimize:clear` e `php artisan config:cache`.
7. Verifique permissões de escrita para `backend/storage` e `backend/bootstrap/cache` pelo usuário do servidor web e pelo worker.
8. Execute `php artisan app:production-check`. Corrija todo item `FAIL` antes do go-live e revise os `WARNING`.

O healthcheck público nativo é `GET /up`. A antiga rota `/api/teste` foi removida e não deve ser usada por monitoramento.

## Fila do WhatsApp

O fluxo real é:

```text
Meta -> HTTPS webhook -> Laravel -> evento persistido -> ProcessWhatsAppWebhookEvent -> queue worker -> conversa/mensagem
```

O servidor web atender o webhook não basta: com `QUEUE_CONNECTION=database`, o worker precisa ficar ativo permanentemente. Um comando adequado ao projeto é:

```bash
php artisan queue:work database --sleep=1 --tries=3 --timeout=120
```

Após cada deploy, solicite a reinicialização segura dos workers:

```bash
php artisan queue:restart
```

Exemplo de Supervisor, para adaptar ao usuário e aos caminhos do servidor:

```ini
[program:chatbotcrm-worker]
command=php /caminho/backend/artisan queue:work database --sleep=1 --tries=3 --timeout=120
directory=/caminho/backend
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/caminho/backend/storage/logs/queue-worker.log
```

Exemplo de unit systemd, também para adaptação local:

```ini
[Unit]
Description=ChatBot CRM queue worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/caminho/backend
ExecStart=/usr/bin/php artisan queue:work database --sleep=1 --tries=3 --timeout=120
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Não há tarefa operacional agendada obrigatória nesta versão; portanto, `schedule:run` não é requisito atual. Reavalie isso quando uma rotina periódica for adicionada.

## Storage e mídia

Fotos de produtos usam o disk `public`, e mídias/conteúdo operacional usam storage controlado pela aplicação. Não apague `backend/storage` durante deploy: isso pode quebrar fotos, documentos, áudios e comprovantes já associados a conversas.

O servidor precisa manter `storage/app/public` gravável e o link `public/storage` disponível. Esta instalação não adiciona S3; se o ambiente tiver disco efêmero ou múltiplas réplicas, planeje storage compartilhado antes da expansão.

`ffmpeg` e `ffprobe` são necessários para normalização de áudio do WhatsApp. Sem eles, mensagens de texto continuam operando, mas o fluxo de áudio fica degradado.

## WhatsApp Meta

1. Publique o backend em HTTPS público estável.
2. Configure a Callback URL terminando em `/api/webhooks/whatsapp`.
3. Use o mesmo verify token configurado no ambiente seguro.
4. Assine o campo `messages` no painel Meta.
5. Mantenha o worker de fila ativo.
6. Rode `php artisan whatsapp:diagnose` para verificar somente presença de configuração e diagnósticos sanitizados.
7. Envie uma mensagem de um telefone autorizado ao número de teste e confirme a conversa no CRM.

Mensagens livres da Meta dependem da janela de atendimento. Fora dela, a operação precisa de template aprovado; isso não é corrigido pelo worker.

## Smoke test após publicação

1. Verifique `GET /up` com resposta 200.
2. Faça login e confirme que não há dados demo na operação.
3. Rode `php artisan app:production-check` e `php artisan whatsapp:diagnose`.
4. Envie e receba uma mensagem WhatsApp de teste.
5. Envie uma imagem e um áudio de teste; confirme persistência e reprodução.
6. Crie um pedido manual, valide preço vindo do backend e visualize a comanda.
7. Revise um comprovante sem confirmação automática de pagamento.
8. Abra a comanda no navegador, selecione a Epson TM-T20X e valide largura, corte e comportamento oferecido pelo driver/browser. A impressão silenciosa não é prometida.

## Rollback e logs

Mantenha a versão anterior do código, backup PostgreSQL e cópia segura de `backend/storage`. Em falha, pare novos deploys, rode `php artisan queue:restart` após restaurar a versão aprovada e confirme `/up`, fila e webhook antes de retomar o atendimento.

Logs úteis são `backend/storage/logs/laravel.log`, o log do worker e os diagnósticos sanitizados `whatsapp:diagnose` e `whatsapp:inbound-status`. Nunca anexe payload bruto, token, comprovante ou mídia privada a chamados públicos.

# Checklist de Go-Live

## Infra

- [ ] Domínio HTTPS público responde ao healthcheck `GET /up`.
- [ ] PostgreSQL persistente possui backup recente e restauração testada.
- [ ] `APP_ENV=production` e `APP_DEBUG=false`.
- [ ] `APP_URL` aponta para o domínio HTTPS correto.
- [ ] Cookies de sessão HTTPS estão configurados (`SESSION_SECURE_COOKIE=true`).

## Aplicação

- [ ] Dependências Composer e build frontend foram concluídos.
- [ ] Migrations aprovadas foram executadas.
- [ ] `php artisan config:cache` foi executado após configurar o ambiente.
- [ ] `php artisan app:production-check` não apresenta `FAIL`.
- [ ] `DEMO_DATA_ENABLED=false`.
- [ ] `VITE_ENABLE_MOCK_FALLBACK` está ausente ou false no build de produção.

## Fila e WhatsApp

- [ ] `QUEUE_CONNECTION=database` está configurada.
- [ ] Worker supervisionado está ativo e reinicia automaticamente.
- [ ] `php artisan queue:restart` foi executado após o deploy.
- [ ] Webhook Meta HTTPS foi validado e o campo `messages` está assinado.
- [ ] Mensagem inbound cria conversa e mensagem no CRM.
- [ ] Resposta outbound chega ao telefone de teste autorizado.

## Storage e áudio

- [ ] `storage/app/public` é persistente e gravável.
- [ ] `php artisan storage:link` foi executado.
- [ ] Fotos existentes continuam abrindo após o deploy.
- [ ] `ffmpeg` e `ffprobe` estão disponíveis ao worker.
- [ ] Áudio de teste normaliza e reproduz corretamente.

## Operação

- [ ] Cardápio e preços são carregados pelo backend.
- [ ] Pagamento continua exigindo confirmação humana.
- [ ] Comanda mostra os itens e observações corretos.
- [ ] Impressora Epson TM-T20X foi testada pelo browser/driver no posto.
- [ ] Logs do Laravel e worker estão acessíveis somente à equipe autorizada.

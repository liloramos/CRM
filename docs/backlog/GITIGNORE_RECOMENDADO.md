# .gitignore recomendado - segurança

Conferir se o `.gitignore` da raiz protege pelo menos:

```gitignore
.env
.env.*
!.env.example
/storage/*.key
/storage/app/private/
/storage/logs/
*.log
*.sqlite
*.dump
*.sql
node_modules/
vendor/
.DS_Store
Thumbs.db
```

Antes de commitar, sempre rodar:

```powershell
git status
git diff --stat
```

Se `.env` aparecer:

```powershell
git restore --staged .env
```

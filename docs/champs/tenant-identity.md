# Identidade do tenant Champs

A associação de um usuário a uma empresa não é executada por migration, seeder global ou deploy.
Ela deve ser feita de forma explícita e auditável:

```powershell
php artisan champs:assign-user-company usuario@example.test champs --name="Nome da empresa"
```

O comando exige confirmação. Em `local` ou `testing`, `--force` pode ser usado em automações
controladas. Se a empresa já existir, `--name` é ignorado; se o usuário já estiver associado,
a operação é idempotente. Nenhum outro usuário ou tenant é alterado.

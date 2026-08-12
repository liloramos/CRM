# Seguranca Do Banco De Testes

O PHPUnit nao deve usar o PostgreSQL operacional `crm_restaurante_sol`.

O padrao versionado em `backend/phpunit.xml` usa:

```text
APP_ENV=testing
APP_CONFIG_CACHE=bootstrap/cache/config-testing.php
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

Antes de executar `RefreshDatabase`, a suite valida o ambiente, o cache de
configuracao e a conexao efetivamente resolvida. A execucao e interrompida se:

- `APP_ENV` nao for `testing`;
- o cache nao for `config-testing.php`;
- o banco resolvido for `crm_restaurante_sol`;
- SQLite nao estiver em memoria;
- um banco persistente nao terminar em `_test` ou `_testing`.

## PostgreSQL Dedicado

Use PostgreSQL somente quando um comportamento especifico do driver precisar
ser validado. Crie previamente um banco isolado, por exemplo
`crm_restaurante_sol_test`, com um usuario de desenvolvimento que nao tenha
acesso ao banco operacional.

Defina as variaveis apenas no processo de teste ou em configuracao local nao
versionada:

```text
APP_ENV=testing
APP_CONFIG_CACHE=bootstrap/cache/config-testing.php
DB_CONNECTION=pgsql
DB_DATABASE=crm_restaurante_sol_test
```

Nao reutilize `bootstrap/cache/config.php`. Para remover um cache exclusivo de
testes, apague somente `bootstrap/cache/config-testing.php` antes de uma nova
execucao.

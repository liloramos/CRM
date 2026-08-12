<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestDatabaseSafetyGuard
{
    private const OPERATIONAL_DATABASES = [
        'crm_restaurante_sol',
    ];

    public static function assertPreBootstrapEnvironment(): void
    {
        self::assertSafe(
            environment: self::environmentValue('APP_ENV'),
            driver: self::environmentValue('DB_CONNECTION'),
            database: self::environmentValue('DB_DATABASE'),
            configCachePath: self::environmentValue('APP_CONFIG_CACHE'),
            allowUnresolvedDatabase: true,
        );
    }

    public static function assertResolvedApplication(Application $app): void
    {
        $config = $app->make('config');
        $connection = (string) $config->get('database.default', '');

        self::assertSafe(
            environment: (string) $app->environment(),
            driver: (string) $config->get("database.connections.{$connection}.driver", $connection),
            database: (string) $config->get("database.connections.{$connection}.database", ''),
            configCachePath: $app->getCachedConfigPath(),
        );
    }

    public static function assertSafe(
        ?string $environment,
        ?string $driver,
        ?string $database,
        ?string $configCachePath,
        bool $allowUnresolvedDatabase = false,
    ): void {
        if ($environment !== 'testing') {
            throw new RuntimeException(
                'Testes bloqueados: APP_ENV deve ser testing antes de qualquer acesso ao banco.',
            );
        }

        if (! self::usesDedicatedConfigCache($configCachePath)) {
            throw new RuntimeException(
                'Testes bloqueados: APP_CONFIG_CACHE deve apontar para config-testing.php.',
            );
        }

        $normalizedDatabase = self::normalizeDatabaseName($database);

        if (in_array($normalizedDatabase, self::OPERATIONAL_DATABASES, true)) {
            throw new RuntimeException(
                "Testes bloqueados: o banco operacional [{$normalizedDatabase}] nunca pode ser usado pelo PHPUnit.",
            );
        }

        if ($allowUnresolvedDatabase && ($driver === null || $database === null)) {
            return;
        }

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if ($normalizedDatabase !== '' && preg_match('/(?:_test|_testing)$/i', $normalizedDatabase) === 1) {
            return;
        }

        throw new RuntimeException(
            'Testes bloqueados: use SQLite :memory: ou um banco dedicado cujo nome termine em _test ou _testing.',
        );
    }

    private static function environmentValue(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function usesDedicatedConfigCache(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        return basename(str_replace('\\', '/', $path)) === 'config-testing.php';
    }

    private static function normalizeDatabaseName(?string $database): string
    {
        if ($database === null || $database === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $database);

        return strtolower(pathinfo($normalized, PATHINFO_FILENAME));
    }
}

<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafetyGuard;

class TestDatabaseSafetyGuardTest extends TestCase
{
    public function test_allows_sqlite_in_memory_with_dedicated_cache(): void
    {
        TestDatabaseSafetyGuard::assertSafe(
            environment: 'testing',
            driver: 'sqlite',
            database: ':memory:',
            configCachePath: 'bootstrap/cache/config-testing.php',
        );

        $this->addToAssertionCount(1);
    }

    public function test_allows_dedicated_postgresql_test_database(): void
    {
        TestDatabaseSafetyGuard::assertSafe(
            environment: 'testing',
            driver: 'pgsql',
            database: 'crm_restaurante_sol_test',
            configCachePath: 'bootstrap/cache/config-testing.php',
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConfigurationProvider')]
    public function test_rejects_unsafe_test_configuration(
        string $environment,
        string $driver,
        string $database,
        string $cachePath,
        string $expectedMessage,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        TestDatabaseSafetyGuard::assertSafe(
            environment: $environment,
            driver: $driver,
            database: $database,
            configCachePath: $cachePath,
        );
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function unsafeConfigurationProvider(): array
    {
        return [
            'non-testing environment' => [
                'local',
                'sqlite',
                ':memory:',
                'bootstrap/cache/config-testing.php',
                'APP_ENV deve ser testing',
            ],
            'shared development config cache' => [
                'testing',
                'sqlite',
                ':memory:',
                'bootstrap/cache/config.php',
                'APP_CONFIG_CACHE deve apontar para config-testing.php',
            ],
            'operational database' => [
                'testing',
                'pgsql',
                'crm_restaurante_sol',
                'bootstrap/cache/config-testing.php',
                'o banco operacional [crm_restaurante_sol] nunca pode ser usado',
            ],
            'generic PostgreSQL database' => [
                'testing',
                'pgsql',
                'postgres',
                'bootstrap/cache/config-testing.php',
                'use SQLite :memory: ou um banco dedicado',
            ],
            'persistent SQLite database' => [
                'testing',
                'sqlite',
                'database/database.sqlite',
                'bootstrap/cache/config-testing.php',
                'use SQLite :memory: ou um banco dedicado',
            ],
        ];
    }
}

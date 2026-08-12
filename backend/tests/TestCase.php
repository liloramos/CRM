<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\TestDatabaseSafetyGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        TestDatabaseSafetyGuard::assertPreBootstrapEnvironment();

        /** @var Application $app */
        $app = parent::createApplication();

        TestDatabaseSafetyGuard::assertResolvedApplication($app);

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

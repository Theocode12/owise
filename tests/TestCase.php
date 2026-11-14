<?php

namespace Owise\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Owise\WiseServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('wise.routes.enabled', true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            WiseServiceProvider::class,
        ];
    }
}

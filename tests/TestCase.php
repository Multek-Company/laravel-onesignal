<?php

namespace Multek\OneSignal\Tests;

use Multek\OneSignal\OneSignalServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            OneSignalServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('onesignal.app_id', 'test-app-id');
        $app['config']->set('onesignal.rest_api_key', 'test-rest-api-key');
    }
}

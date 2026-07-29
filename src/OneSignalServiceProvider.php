<?php

namespace Multek\OneSignal;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Multek\OneSignal\Channels\OneSignalChannel;
use onesignal\client\api\DefaultApi;
use onesignal\client\Configuration;

class OneSignalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/onesignal.php', 'onesignal');

        $this->app->singleton(DefaultApi::class, function () {
            $config = Configuration::getDefaultConfiguration()
                ->setRestApiKeyToken(config('onesignal.rest_api_key'));

            if ($orgKey = config('onesignal.organization_api_key')) {
                $config->setOrganizationApiKeyToken($orgKey);
            }

            return new DefaultApi(new Client(), $config);
        });

        $this->app->singleton(OneSignalManager::class, function ($app) {
            return new OneSignalManager(
                api: $app->make(DefaultApi::class),
                appId: config('onesignal.app_id'),
            );
        });

        $this->app->singleton(OneSignalChannel::class, function ($app) {
            return new OneSignalChannel($app->make(OneSignalManager::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/onesignal.php' => config_path('onesignal.php'),
        ], 'onesignal-config');
    }
}

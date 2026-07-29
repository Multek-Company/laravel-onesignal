<?php

namespace Multek\OneSignal\Tests\Live;

use Multek\OneSignal\Tests\TestCase;

abstract class LiveTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('onesignal.app_id', env('ONESIGNAL_TEST_APP_ID'));
        $app['config']->set('onesignal.rest_api_key', env('ONESIGNAL_TEST_REST_API_KEY'));
        $app['config']->set('onesignal.enabled', true);
        $app['config']->set('onesignal.track_events', (bool) env('ONESIGNAL_TEST_TRACK_EVENTS', false));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('ONESIGNAL_TEST_APP_ID') || ! env('ONESIGNAL_TEST_REST_API_KEY')) {
            $this->markTestSkipped('Set ONESIGNAL_TEST_APP_ID and ONESIGNAL_TEST_REST_API_KEY to run live tests.');
        }
    }
}

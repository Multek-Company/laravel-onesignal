<?php

use Illuminate\Support\Facades\Log;
use Multek\OneSignal\Tests\Fixtures\User;

function payloadUser(array $attributes = []): User
{
    return (new User)->forceFill(array_merge([
        'id' => 1,
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
    ], $attributes));
}

it('builds a payload from identity, tags, properties and subscriptions', function () {
    config(['onesignal.default_tags' => ['plan' => 'subscription_plan']]);

    $user = new class extends User
    {
        public function getOneSignalLanguage(): ?string
        {
            return 'pt';
        }

        public function getOneSignalTimezone(): ?string
        {
            return 'America/Sao_Paulo';
        }
    };

    $user->forceFill([
        'id' => 7,
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
        'subscription_plan' => 'pro',
    ]);

    expect($user->toOneSignalPayload())->toBe([
        'external_id' => '7',
        'tags' => ['plan' => 'pro'],
        'properties' => ['language' => 'pt', 'timezone_id' => 'America/Sao_Paulo'],
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
    ]);
});

it('sorts tags and properties by key', function () {
    $user = new class extends User
    {
        public function getOneSignalTags(): array
        {
            return ['zeta' => '1', 'alpha' => '2'];
        }

        public function getOneSignalTimezone(): ?string
        {
            return 'America/Sao_Paulo';
        }

        public function getOneSignalCountry(): ?string
        {
            return 'BR';
        }
    };

    $payload = $user->forceFill(['id' => 1])->toOneSignalPayload();

    expect(array_keys($payload['tags']))->toBe(['alpha', 'zeta'])
        ->and(array_keys($payload['properties']))->toBe(['country', 'timezone_id']);
});

it('omits a non-E164 phone from the payload without logging', function () {
    Log::spy();

    $payload = payloadUser(['phone' => '11 99999-9999'])->toOneSignalPayload();

    expect($payload['phone'])->toBeNull();

    Log::shouldNotHaveReceived('warning');
});
